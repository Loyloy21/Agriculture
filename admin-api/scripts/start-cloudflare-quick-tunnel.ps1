# -WithInvitationPage (TEMPORARY cross-network testing): the gateway also serves the public admin invitation page (and only
# its two stylesheets), and invitation links point to this tunnel until it is stopped or 12 hours pass. Admin login stays 404.
param([int]$GatewayPort = 8090, [int]$ApachePort = 80, [int]$ProvisioningProxyPort = 8091, [switch]$WithInvitationPage)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'agrireach-dev-common.ps1')
$lock = Enter-DevLock
$statePath = Join-Path $devRuntime 'processes.json'
$state = @{ schema = 2; root = $devRoot; startedAt = (Get-Date).ToString('o'); gateway = $null; proxy = $null; tunnel = $null }
function Start-OwnedHelper([string]$Executable, [string[]]$Arguments, [string]$Name) {
    $process = Start-Process -FilePath $Executable -ArgumentList $Arguments -WorkingDirectory $devRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $devRuntime "$Name.out.log") -RedirectStandardError (Join-Path $devRuntime "$Name.err.log")
    $identity = Get-DevIdentity $process.Id
    if (!$identity) { throw "$Name exited before startup. See $devRuntime\$Name.err.log." }
    return $identity
}
try {
    # Every start is fresh. Invalidate old configuration even when a prerequisite fails.
    & (Join-Path $PSScriptRoot 'stop-cloudflare-quick-tunnel.ps1')
    $missing = @()
    if (!(Get-Process httpd -ErrorAction SilentlyContinue)) { $missing += "Apache: stopped. Open XAMPP Control Panel and click Start beside Apache (port $ApachePort)." }
    # The configured database (Supabase PostgreSQL, or the MySQL copy) is verified by check-database.php below.
    foreach ($tool in @('node.exe', 'curl.exe')) { if (!(Get-Command $tool -ErrorAction SilentlyContinue)) { $missing += "$tool is missing from PATH." } }
    $php = Join-Path $devRoot '..\..\php\php.exe'
    $cloudflared = Join-Path $devRoot 'tools\cloudflared\cloudflared.exe'
    if (!(Test-Path -LiteralPath $php)) { $missing += 'XAMPP PHP missing: expected C:\xampp\php\php.exe.' }
    if (!(Test-Path -LiteralPath $cloudflared)) { $missing += 'cloudflared missing: place official Windows executable in tools\cloudflared\cloudflared.exe.' }
    if ($missing.Count) { throw ($missing -join [Environment]::NewLine) }
    & $php (Join-Path $PSScriptRoot 'check-database.php')
    if ($LASTEXITCODE -ne 0) { throw 'MySQL connection/schema check failed. Check XAMPP MySQL and AgriReach database configuration.' }
    $apiPath = '/CAPSTONE%20AGRICULTURE/api'
    $local = Get-DevProbe "http://127.0.0.1:$ApachePort$apiPath/auth/me.php"
    if ($local.Status -ne '401' -or $local.Raw -notmatch 'Authentication is required') { throw "Apache/PHP API check failed: HTTP $($local.Status) on port $ApachePort. Check the Apache port, project path and PHP error log." }
    Assert-DevPinEndpoint "http://127.0.0.1:$ApachePort$apiPath"
    Write-Host 'Apache, MySQL connection and PHP auth/me: healthy.'
    foreach ($port in @($GatewayPort, $ProvisioningProxyPort)) {
        if (Test-DevPort $port) { throw "Port $port is occupied by an unowned process. Inspect netstat -ano; this launcher will not stop it." }
    }
    New-Item -ItemType Directory -Path $devRuntime -Force | Out-Null
    $gatewayArgs = @(('"' + (Join-Path $PSScriptRoot 'agrireach-api-gateway.mjs') + '"'), "$GatewayPort", "$ApachePort")
    if ($WithInvitationPage) { $gatewayArgs += 'invitation' }
    $state.gateway = Start-OwnedHelper 'node.exe' $gatewayArgs 'gateway'
    Save-DevState $statePath $state
    Start-Sleep -Milliseconds 700
    $gateway = Get-DevProbe "http://127.0.0.1:$GatewayPort$apiPath/auth/me.php"
    if ($gateway.Status -ne '401' -or !$gateway.Gateway) { throw 'Restricted gateway health check failed. See var\cloudflare-tunnel\gateway.err.log.' }
    $blockedPaths = @('/CAPSTONE%20AGRICULTURE/admin/login.php', '/phpmyadmin/', '/CAPSTONE%20AGRICULTURE/config/database.php', '/CAPSTONE%20AGRICULTURE/backend/config/database.php', '/CAPSTONE%20AGRICULTURE/storage/', '/CAPSTONE%20AGRICULTURE/api/bootstrap.php', '/CAPSTONE%20AGRICULTURE/api/includes/bootstrap.php', '/CAPSTONE%20AGRICULTURE/api/includes/farmer_auth.php', '/CAPSTONE%20AGRICULTURE/backend/api/auth/me.php', '/CAPSTONE%20AGRICULTURE/frontend/admin/login.php', '/CAPSTONE%20AGRICULTURE/frontend/mobile/package.json', '/unrelated-project/')
    foreach ($path in $blockedPaths) {
        $denied = Get-DevProbe "http://127.0.0.1:$GatewayPort$path"
        if ($denied.Status -ne '404' -or !$denied.Gateway) { throw "Gateway restriction check failed for $path. Refusing public exposure." }
    }
    $serviceAddresses = @(Resolve-DevIPv4 'api.trycloudflare.com')
    $state.proxy = Start-OwnedHelper 'node.exe' @(('"' + (Join-Path $PSScriptRoot 'cloudflare-quick-ipv4-proxy.mjs') + '"'), "$ProvisioningProxyPort", ($serviceAddresses -join ',')) 'provisioning-proxy'
    Save-DevState $statePath $state
    Start-Sleep -Milliseconds 500
    $origin = $null
    $publicAddresses = @()
    $public = $null
    $verified = $false
    $failureReasons = @()
    $issuedHosts = @()
    for ($tunnelAttempt = 1; $tunnelAttempt -le 3; $tunnelAttempt++) {
        $attemptName = "cloudflared-attempt-$tunnelAttempt-$([guid]::NewGuid().ToString('N'))"
        $attemptLog = Join-Path $devRuntime "$attemptName.err.log"
        Write-Host "Starting fresh Cloudflare Quick Tunnel ($tunnelAttempt/3)..."
        try {
            $state.tunnel = Start-OwnedHelper $cloudflared @('tunnel', '--no-autoupdate', '--protocol', 'http2', '--edge-ip-version', '4', '--quick-service', "http://127.0.0.1:$ProvisioningProxyPort", '--url', "http://127.0.0.1:$GatewayPort", '--loglevel', 'info') $attemptName
            Save-DevState $statePath $state
            $status = $null
            for ($poll = 0; $poll -lt 50; $poll++) {
                Start-Sleep -Seconds 1
                $status = Get-QuickTunnelLogStatus $attemptLog
                if (!(Test-DevIdentity $state.tunnel)) { throw "cloudflared exited before connecting. $($status.ErrorTail)" }
                if ($status.Origin -and $status.Registered) { break }
                if ($poll % 10 -eq 9) { Write-Host "Waiting for hostname AND HTTP/2 connector registration ($tunnelAttempt/3)..." }
            }
            if (!$status.Origin) { throw "No hostname in this attempt's creation banner. $($status.ErrorTail)" }
            if ($status.Origin -in $issuedHosts) { throw "Cloudflare repeated the previous hostname $($status.Origin); this is not a fresh Quick Tunnel." }
            $issuedHosts += $status.Origin
            if (!$status.Registered) { throw "Hostname $($status.Origin) was issued, but no HTTP/2 connector registration appeared. $($status.ErrorTail)" }
            $origin = $status.Origin
            Write-Host "Hostname issued and connector registered: $origin"
            # Publication may lag connector registration; bounded DNS checks precede any env write.
            $publicAddresses = @()
            for ($dnsAttempt = 1; $dnsAttempt -le 6; $dnsAttempt++) {
                if (!(Test-DevIdentity $state.tunnel)) { throw 'cloudflared disconnected during DNS publication check.' }
                try { $publicAddresses = @(Resolve-DevIPv4 ([uri]$origin).DnsSafeHost); break } catch {
                    if ($dnsAttempt -eq 6) { throw "Hostname $origin remained unresolved after 6 DNS checks. $($_.Exception.Message)" }
                    Write-Host "Waiting for Quick Tunnel DNS publication ($dnsAttempt/6)..."
                    Start-Sleep -Seconds 5
                }
            }
            # A DNS answer alone is insufficient: HTTPS must reach the restricted PHP API.
            for ($publicAttempt = 1; $publicAttempt -le 12; $publicAttempt++) {
                if (!(Test-DevIdentity $state.tunnel)) { throw 'cloudflared disconnected before the public API was verified.' }
                Write-Host "Checking public Cloudflare API ($publicAttempt/12)..."
                $public = Get-DevProbe "$origin$apiPath/auth/me.php" $publicAddresses
                if ($public.Status -eq '401' -and $public.Gateway -and $public.Raw -match 'Authentication is required') { $verified = $true; break }
                if ($public.Status -notin @('000', '502', '503', '520', '522', '530')) { break }
                if ($publicAttempt -lt 12) { Start-Sleep -Seconds 3 }
            }
            if (!$verified) { throw "Public API did not return gateway HTTP 401 (HTTP $($public.Status), curl $($public.ExitCode)). $((Get-QuickTunnelLogStatus $attemptLog).ErrorTail)" }
            Assert-DevPinEndpoint "$origin$apiPath" $publicAddresses
            break
        } catch {
            $failureReasons += "Attempt $tunnelAttempt`: $($_.Exception.Message) [log: $attemptLog]"
            Write-Warning $failureReasons[-1]
            Stop-DevOwned $state.tunnel
            $state.tunnel = $null
            $origin = $null
            $publicAddresses = @()
            $verified = $false
            Save-DevState $statePath $state
            if ($tunnelAttempt -lt 3) { Start-Sleep -Seconds 3 }
        }
    }
    if (!$verified) { throw "Cloudflare tunnel failed after 3 fresh attempts. $($failureReasons -join ' ') API URL remains empty; Expo was not started." }
    foreach ($path in $blockedPaths) {
        $denied = Get-DevProbe "$origin$path" $publicAddresses
        if ($denied.Status -ne '404' -or !$denied.Gateway) { throw "Public restriction check failed for $path. Stopping tunnel." }
    }
    if ($WithInvitationPage) {
        $invitationPage = Get-DevProbe "$origin/CAPSTONE%20AGRICULTURE/admin/admin-invitation.php" $publicAddresses
        if ($invitationPage.Status -ne '200' -or !$invitationPage.Gateway -or $invitationPage.Raw -notmatch 'Administrator Invitation') { throw "Public invitation page check failed (HTTP $($invitationPage.Status)). Invitation links were not changed." }
        # Invitation links point to this tunnel only while it runs (removed by the stop script; ignored after 12 hours).
        $expires = [DateTimeOffset]::UtcNow.AddHours(12).ToUnixTimeSeconds()
        $overlay = "<?php`n// TEMPORARY cross-network test (written by start-cloudflare-quick-tunnel.ps1 -WithInvitationPage, removed on stop). Not a deployment.`nreturn ['APP_URL' => '$origin/CAPSTONE%20AGRICULTURE', 'EXPIRES_AT' => $expires];`n"
        [IO.File]::WriteAllText($devTemporaryOverlay, $overlay, (New-Object Text.UTF8Encoding($false)))
        $state.publicInvitationBase = "$origin/CAPSTONE%20AGRICULTURE/admin/admin-invitation.php"
        Write-Host "TEMPORARY invitation links now use $origin/CAPSTONE%20AGRICULTURE (admin login remains private)." -ForegroundColor Yellow
    }
    Stop-DevOwned $state.proxy
    $state.proxy = $null
    $state.publicOrigin = $origin
    $state.publicApiUrl = "$origin$apiPath"
    $state.publicAddresses = $publicAddresses
    $state.gatewayPort = $GatewayPort
    $state.apachePort = $ApachePort
    $state.verifiedAt = (Get-Date).ToString('o')
    Save-DevState $statePath $state
    Set-DevApiUrl $state.publicApiUrl
    Assert-DevApiConfig $state.publicApiUrl
    Write-Host 'API URL changed: stop any old Metro session, restart Expo with --clear --tunnel (and --dev-client for the installed development app), then reload/scan its new QR. An existing bundle retains its old API URL.' -ForegroundColor Yellow
    Write-Host "Verified PUBLIC PHP API (401, restricted paths 404): $($state.publicApiUrl)" -ForegroundColor Green
    if ($public.DnsRecovery) { Write-Host 'Laptop probe used live public DNS recovery with HTTPS verification. Phone DNS still requires the mobile-data test.' -ForegroundColor Yellow }
} catch {
    Set-DevApiUrl
    Remove-DevTemporaryOverlay
    foreach ($identity in @($state.tunnel, $state.proxy, $state.gateway)) { Stop-DevOwned $identity }
    # Preserve unknown/legacy state for manual inspection rather than discarding its evidence.
    if ($state.gateway -and (Test-Path -LiteralPath $statePath)) { Remove-Item -LiteralPath $statePath -Force }
    throw
} finally { $lock.ReleaseMutex(); $lock.Dispose() }
