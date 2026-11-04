# Shared by the existing AgriReach launchers. No system DNS or service changes.
$devRoot = Split-Path -Parent $PSScriptRoot
$devRuntime = Join-Path $devRoot 'var\cloudflare-tunnel'
$devEnvPath = Join-Path $devRoot 'frontend\mobile\.env.local'
# TEMPORARY cross-network test only: makes invitation links use the verified quick tunnel (read by backend/config/email.php).
$devTemporaryOverlay = Join-Path (Split-Path (Split-Path $devRoot -Parent) -Parent) 'agrireach-secrets\app.temporary-test.php'

function Remove-DevTemporaryOverlay {
    if (Test-Path -LiteralPath $devTemporaryOverlay) { Remove-Item -LiteralPath $devTemporaryOverlay -Force }
}

function Enter-DevLock {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $key = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($devRoot.ToLowerInvariant())))).Replace('-', '') } finally { $sha.Dispose() }
    $mutex = New-Object Threading.Mutex($false, "Local\AgriReachDev_$key")
    try { $acquired = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $acquired = $true }
    if (!$acquired) { $mutex.Dispose(); throw 'Another AgriReach start/stop command is still running. Wait for it to finish.' }
    return $mutex
}

function Set-DevApiUrl([string]$Url = '') {
    $lines = if (Test-Path -LiteralPath $devEnvPath) { @(Get-Content -LiteralPath $devEnvPath -Encoding UTF8) } else { @() }
    $lines = @($lines | Where-Object { $_ -notmatch '^\s*(?:export\s+)?EXPO_PUBLIC_API_URL\s*=' })
    $lines += "EXPO_PUBLIC_API_URL=$Url"
    [IO.File]::WriteAllLines($devEnvPath, [string[]]$lines, (New-Object Text.UTF8Encoding($false)))
}

function Assert-DevApiConfig([string]$ExpectedUrl) {
    if ($ExpectedUrl -notmatch '^https://[a-z0-9-]+\.trycloudflare\.com/CAPSTONE%20AGRICULTURE/api$') {
        throw 'The recorded API URL is not a valid current HTTPS Quick Tunnel API address. Expo was not started.'
    }
    if (!(Test-Path -LiteralPath $devEnvPath)) { throw "Missing Expo environment file: $devEnvPath. Expo was not started." }
    $entries = @(Get-Content -LiteralPath $devEnvPath -Encoding UTF8 | Where-Object { $_ -match '^\s*(?:export\s+)?EXPO_PUBLIC_API_URL\s*=' })
    if ($entries.Count -ne 1 -or $entries[0] -cne "EXPO_PUBLIC_API_URL=$ExpectedUrl") {
        throw "EXPO_PUBLIC_API_URL in $devEnvPath is blank, duplicated, or differs from the verified tunnel. Expo was not started. Rerun the root start command."
    }
}

function Resolve-DevIPv4([string]$Name) {
    foreach ($resolver in @('', '1.1.1.1', '8.8.8.8')) {
        $label = if ($resolver) { $resolver } else { 'Windows default DNS' }
        try {
            $options = @{ Name = $Name; Type = 'A'; DnsOnly = $true; QuickTimeout = $true; ErrorAction = 'Stop' }
            if ($resolver) { $options.Server = $resolver }
            $addresses = @(Resolve-DnsName @options | Where-Object { $_.IPAddress -match '^\d+\.\d+\.\d+\.\d+$' } | Select-Object -ExpandProperty IPAddress -Unique)
            if (!$addresses.Count) { throw 'No IPv4 answer' }
            Write-Host "DNS OK: $Name via $label."
            return $addresses
        } catch { Write-Host "DNS failed: $Name via $label ($($_.Exception.Message))." -ForegroundColor Yellow }
    }
    throw "DNS failed for $Name using Windows and public resolvers. The hostname may be unpublished or DNS may be unavailable; inspect tunnel logs and retry with a fresh hostname. No LAN fallback will be used."
}

function Get-QuickTunnelLogStatus([string]$LogPath) {
    $log = if (Test-Path -LiteralPath $LogPath) { Get-Content -LiteralPath $LogPath -Raw -ErrorAction SilentlyContinue } else { '' }
    # Inspect ONLY this attempt's log and ONLY cloudflared's issuance banner.
    $issued = [regex]::Match($log, '(?im)^.*Your quick Tunnel has been created!.*\r?\n[^\r\n]*?https://([a-z0-9-]+\.trycloudflare\.com)\b')
    $origin = if ($issued.Success) { "https://$($issued.Groups[1].Value)" } else { $null }
    $registered = $log -match '(?im)\bRegistered tunnel connection\b.*\bprotocol=http2\b'
    $errors = @($log -split '\r?\n' | Where-Object { $_ -match '\b(ERR|error=|Failed to)\b' } | Select-Object -Last 3)
    return @{ Origin = $origin; Registered = $registered; ErrorTail = ($errors -join ' | ') }
}

function Get-DevProbe([string]$Url, [string[]]$Addresses = @(), [int]$Seconds = 8, [string[]]$Headers = @(), [string]$JsonBody = $null) {
    $uri = [uri]$Url
    # Try ordinary public resolution first, then live DNS answers with original TLS SNI/Host.
    foreach ($address in @('') + $Addresses) {
        $arguments = @('--silent', '--show-error', '--stderr', '-', '--noproxy', '*', '--connect-timeout', '4', '--max-time', "$Seconds", '--include', '--write-out', "\nHTTP_CODE:%{http_code}")
        foreach ($header in $Headers) { $arguments += @('--header', $header) }
        if ($address) { $arguments += @('--resolve', "$($uri.DnsSafeHost):$($uri.Port):$address") }
        if ($JsonBody) {
            # A BOM-free temporary file: avoids Windows argument quoting stripping JSON quotes, and the UTF-8 BOM some
            # PowerShell consoles add when piping to native programs (PHP rejects such a body as invalid JSON).
            $bodyFile = [IO.Path]::GetTempFileName()
            try {
                [IO.File]::WriteAllText($bodyFile, $JsonBody, (New-Object Text.UTF8Encoding($false)))
                $arguments += @('--header', 'Content-Type: application/json', '--data-binary', "@$bodyFile")
                $raw = (@(& curl.exe @arguments -- $Url) -join [Environment]::NewLine)
            } finally { Remove-Item -LiteralPath $bodyFile -Force -ErrorAction SilentlyContinue }
        } else {
            $raw = (@(& curl.exe @arguments -- $Url) -join [Environment]::NewLine)
        }
        $code = $LASTEXITCODE
        $match = [regex]::Match($raw, 'HTTP_CODE:(\d{3})')
        $result = @{ Status = $(if ($match.Success) { $match.Groups[1].Value } else { '000' }); ExitCode = $code; Raw = $raw; Gateway = ($raw -match '(?im)^X-AgriReach-Gateway:\s*(api-only|api-and-invitation)\s*$'); DnsRecovery = [bool]$address; Error = [regex]::Match($raw, 'curl: [^\r\n]+').Value }
        if ($code -eq 0) { return $result }
    }
    return $result
}

function Assert-DevPinEndpoint([string]$ApiUrl, [string[]]$Addresses = @()) {
    # Non-mutating controlled city points, not farmer/device coordinates. No auth is
    # required for registration contexts; damage validation stays authenticated.
    foreach ($context in @('residential', 'farm')) {
        $body = @{ latitude = 9.12; longitude = 125.53; context = $context } | ConvertTo-Json -Compress
        $probe = Get-DevProbe "$ApiUrl/location/validate.php" $Addresses 15 @('Accept: application/json') $body
        if ($probe.ExitCode -ne 0 -or $probe.Status -ne '200' -or $probe.Raw -notmatch '(?im)^X-AgriReach-Boundary-Validator:\s*local-geojson-v1\s*$' -or $probe.Raw -notmatch '"status"\s*:\s*"inside"') {
            throw "Registration $context pin endpoint check failed: POST $ApiUrl/location/validate.php, HTTP $($probe.Status), curl $($probe.ExitCode). $($probe.Error) Check PHP boundary data and gateway route. API URL was not accepted."
        }
    }
    $outside = @{ latitude = 14.59; longitude = 120.98; context = 'residential' } | ConvertTo-Json -Compress
    $probe = Get-DevProbe "$ApiUrl/location/validate.php" $Addresses 15 @('Accept: application/json') $outside
    if ($probe.Status -ne '422' -or $probe.Raw -notmatch 'PIN_OUTSIDE_CABADBARAN') {
        throw "Registration out-of-city rejection check failed: HTTP $($probe.Status). Check the existing Cabadbaran server rule. API URL was not accepted."
    }
    Write-Host 'Registration boundary endpoint: home/farm HTTP 200; outside-city HTTP 422.'
}

function Get-DevIdentity([int]$ProcessId) {
    $process = Get-CimInstance Win32_Process -Filter "ProcessId=$ProcessId" -ErrorAction SilentlyContinue
    if (!$process -or !$process.ExecutablePath -or !$process.CommandLine) { return $null }
    return @{ pid = $ProcessId; created = $process.CreationDate.ToUniversalTime().ToString('o'); executable = $process.ExecutablePath; command = $process.CommandLine }
}

function Test-DevIdentity($Identity) {
    if (!$Identity -or !$Identity.pid -or !$Identity.created -or !$Identity.command) { return $false }
    $current = Get-DevIdentity ([int]$Identity.pid)
    return $current -and $current.created -eq $Identity.created -and $current.executable -eq $Identity.executable -and $current.command -ceq $Identity.command
}

function Get-DevTree($Identity) {
    if (!(Test-DevIdentity $Identity)) { return @() }
    $all = @(Get-CimInstance Win32_Process)
    $ids = @([int]$Identity.pid)
    for ($index = 0; $index -lt $ids.Count; $index++) {
        foreach ($child in @($all | Where-Object { [int]$_.ParentProcessId -eq $ids[$index] -and [int]$_.ProcessId -notin $ids })) { $ids += [int]$child.ProcessId }
    }
    return @($ids | ForEach-Object { Get-DevIdentity $_ } | Where-Object { $_ })
}

function Stop-DevOwned($Identity, [switch]$Tree) {
    if (!(Test-DevIdentity $Identity)) { return }
    if ($Tree) {
        & taskkill.exe /PID ([int]$Identity.pid) /T /F | Out-Null
        if ($LASTEXITCODE -ne 0 -and (Test-DevIdentity $Identity)) { throw "Could not stop managed PID $($Identity.pid). Close its window, then retry." }
    } else { Stop-Process -Id ([int]$Identity.pid) -Force -ErrorAction Stop }
}

function Save-DevState([string]$Path, $State) {
    New-Item -ItemType Directory -Path $devRuntime -Force | Out-Null
    [IO.File]::WriteAllText("$Path.next", ($State | ConvertTo-Json -Depth 8), (New-Object Text.UTF8Encoding($false)))
    Move-Item -LiteralPath "$Path.next" -Destination $Path -Force
}

function Read-DevState([string]$Path) {
    if (!(Test-Path -LiteralPath $Path)) { return $null }
    try { $state = Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json } catch { throw "Invalid process state: $Path. Inspect this file; do not kill processes by guessed PID." }
    if ($state.schema -ne 2 -or $state.root -ne $devRoot) { throw "Legacy/unrecognized process state: $Path. Close the previously managed windows and inspect/remove this state file before retrying; unrelated processes will not be stopped." }
    return $state
}

function Get-DevPortOwners([int]$Port) {
    foreach ($line in @(& netstat.exe -ano -p TCP)) {
        if ($line -match "^\s*TCP\s+\S+:$Port\s+\S+\s+LISTENING\s+(\d+)\s*$") { [int]$Matches[1] }
    }
}

function Test-DevPort([int]$Port) {
    return @(Get-DevPortOwners $Port).Count -gt 0
}
