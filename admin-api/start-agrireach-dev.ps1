# -WithInvitationPage: TEMPORARY cross-network test of the admin invitation page too (see scripts\start-cloudflare-quick-tunnel.ps1).
param([int]$ApachePort = 80, [switch]$SkipExpo, [switch]$WithInvitationPage)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'scripts\agrireach-dev-common.ps1')
$lock = Enter-DevLock
$expoStatePath = Join-Path $devRuntime 'expo-process.json'
$expoState = $null
$started = $false
try {
    & (Join-Path $PSScriptRoot 'stop-agrireach-dev.ps1')
    $started = $true
    if (!$SkipExpo) {
        if (!(Get-Command npx.cmd -ErrorAction SilentlyContinue)) { throw 'npx is missing. Install Node.js and reopen PowerShell.' }
        if (!(Test-Path -LiteralPath (Join-Path $devRoot 'frontend\mobile\node_modules\.bin\expo.cmd'))) { throw 'Expo dependencies missing. Run npm install in frontend\mobile, then retry.' }
    }
    $apiStarted = $false
    for ($apiAttempt = 1; $apiAttempt -le 3; $apiAttempt++) {
        try {
            & (Join-Path $PSScriptRoot 'scripts\start-cloudflare-quick-tunnel.ps1') -ApachePort $ApachePort -WithInvitationPage:$WithInvitationPage
            $apiStarted = $true
            break
        } catch {
            if ($apiAttempt -eq 3 -or $_.Exception.Message -notmatch 'DNS failed for .*trycloudflare\.com|Cloudflare public PHP check failed|Cloudflare provisioning failed') { throw }
            Write-Host "Cloudflare Quick Tunnel was not publicly reachable. Requesting a new hostname ($($apiAttempt + 1)/3)..." -ForegroundColor Yellow
            Start-Sleep -Seconds 3
        }
    }
    if (!$apiStarted) { throw 'Cloudflare did not provide a verified public API tunnel. Expo was not started.' }
    $apiState = Read-DevState (Join-Path $devRuntime 'processes.json')
    if (!$apiState.publicApiUrl) { throw 'No verified current API URL. Expo was not started.' }
    Assert-DevApiConfig $apiState.publicApiUrl
    if ($SkipExpo) {
        Write-Host 'API-only diagnostic passed. Expo is NOT running. Omit -SkipExpo for the full session.' -ForegroundColor Yellow
        return
    }
    $port = 8081
    while ($port -le 8099 -and (Test-DevPort $port)) { $port++ }
    if ($port -gt 8099) { throw 'No free Metro port in 8081-8099. Inspect netstat -ano. Unrelated processes were not stopped.' }
    $runner = Join-Path $PSScriptRoot 'scripts\run-agrireach-expo.ps1'
    $window = Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + $runner + '"'), '-Port', "$port") -WorkingDirectory (Join-Path $devRoot 'frontend\mobile') -WindowStyle Normal -PassThru
    $expoState = @{ schema = 2; root = $devRoot; runner = (Get-DevIdentity $window.Id); children = @(); port = $port; publicApiUrl = $apiState.publicApiUrl; startedAt = (Get-Date).ToString('o') }
    if (!$expoState.runner) { throw 'Expo QR console exited immediately. Check var\cloudflare-tunnel\expo.log.' }
    Save-DevState $expoStatePath $expoState
    $ready = $false
    $deadline = (Get-Date).AddSeconds(150)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 2
        if (!(Test-DevIdentity $expoState.runner)) { break }
        $expoState.children = @(Get-DevTree $expoState.runner)
        Save-DevState $expoStatePath $expoState
        $listeners = @(Get-DevPortOwners $port)
        if (!$listeners.Count) { continue }
        foreach ($listener in $listeners) {
            if ($listener -notin @($expoState.children | ForEach-Object { $_.pid })) { throw "Metro port $port was taken by an unrelated process during startup. Refusing to use its QR/session." }
        }
        # The manifest from this Metro carries the actual tunnel host; localhost /status alone is insufficient.
        $manifestProbe = Get-DevProbe "http://127.0.0.1:$port/" @() 3 @('Expo-Platform: ios', 'Accept: application/expo+json')
        $body = ($manifestProbe.Raw -split '\r?\n\r?\n', 2 | Select-Object -Last 1) -replace '\s*HTTP_CODE:\d+\s*$', ''
        try { $manifest = $body | ConvertFrom-Json -ErrorAction Stop } catch { continue }
        $hostUri = [string]$manifest.extra.expoClient.hostUri
        if (!$hostUri -or $hostUri -match '^(localhost|127\.|192\.168\.|10\.|\[|172\.(1[6-9]|2\d|3[01])\.)' -or $hostUri -notmatch '^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}(:\d+)?$') { continue }
        # The localhost manifest always says http:// (no forwarded proto). The SDK 58 Expo tunnel (*.on.expo.app)
        # serves HTTPS only and answers HTTP with 301, while the older ngrok tunnel (*.exp.direct) served HTTP.
        # Use whichever transport actually works publicly: Expo Go loads exps:// over HTTPS and exp:// over HTTP.
        # The independent PHP API always stays HTTPS.
        $bundleUri = [uri]$manifest.launchAsset.url
        if ($bundleUri.Scheme -notin @('http', 'https') -or $bundleUri.Authority -ne $hostUri) { continue }
        $expoAddresses = @(Resolve-DevIPv4 $bundleUri.DnsSafeHost)
        foreach ($scheme in @('https', 'http')) {
            $expoOrigin = "${scheme}://$hostUri"
            Write-Host "Checking PUBLIC Expo Metro tunnel: $expoOrigin"
            $publicMetro = Get-DevProbe "$expoOrigin/status" $expoAddresses
            $publicManifest = Get-DevProbe "$expoOrigin/" $expoAddresses 15 @('Expo-Platform: ios', 'Accept: application/expo+json')
            if ($publicMetro.Status -eq '200' -and $publicMetro.Raw -match 'packager-status:running' -and $publicManifest.Status -eq '200' -and $publicManifest.Raw.Contains($hostUri)) {
                $expoState.expoUrl = $(if ($scheme -eq 'https') { "exps://$hostUri" } else { "exp://$hostUri" })
                $expoState.verifiedAt = (Get-Date).ToString('o')
                Save-DevState $expoStatePath $expoState
                $ready = $true
                break
            }
            Write-Host "Expo public check ($scheme): status HTTP $($publicMetro.Status), manifest HTTP $($publicManifest.Status). $($publicMetro.Error) $($publicManifest.Error)" -ForegroundColor Yellow
        }
        if ($ready) { break }
    }
    if (!$ready) { throw 'Expo PUBLIC tunnel did not become ready. Inspect the QR console and var\cloudflare-tunnel\expo.log for ngrok/network errors; a local Metro server is not proof of mobile-data connectivity.' }
    # Recheck API after Expo readiness: never announce a session whose API died during startup.
    $apiProbe = Get-DevProbe "$($apiState.publicApiUrl)/auth/me.php" @($apiState.publicAddresses)
    if (!(Test-DevIdentity $apiState.tunnel) -or $apiProbe.Status -ne '401' -or !$apiProbe.Gateway) { throw 'API tunnel died while Expo started. Restart after checking cloudflared.err.log.' }
    Assert-DevApiConfig $apiState.publicApiUrl
    & node.exe (Join-Path $PSScriptRoot 'tests\dev\dev-public-metro.mjs')
    if ($LASTEXITCODE -ne 0) { throw 'Expo public bundle check failed or does not contain the verified API URL. The QR session was stopped.' }
    Assert-DevApiConfig $apiState.publicApiUrl
    & node.exe (Join-Path $PSScriptRoot 'scripts\write-agrireach-expo-qr.mjs')
    if ($LASTEXITCODE -ne 0) { throw 'Could not generate the verified Expo QR image. Check the visible Expo console.' }
    Write-Host ''
    Write-Host 'PUBLIC API and PUBLIC Expo tunnel checks passed. Phone verification is still pending.' -ForegroundColor Green
    Write-Host "Expo QR/app URL: $($expoState.expoUrl)"
    Write-Host "Cloudflare PHP API: $($apiState.publicApiUrl)"
    if ($apiState.publicInvitationBase) { Write-Host "TEMPORARY invitation page: $($apiState.publicInvitationBase) (invitation emails link here; admin login stays private)" }
    Write-Host 'Turn phone Wi-Fi OFF and mobile data ON; scan the NEW QR in the Expo console, open AgriReach, and log in.'
    Write-Host 'Keep laptop, Internet, Apache, MySQL and both tunnels running. After changing laptop Wi-Fi, rerun this start command and scan its QR.'
    Write-Host 'Stop: powershell -NoProfile -ExecutionPolicy Bypass -File .\stop-agrireach-dev.ps1'
} catch {
    $failure = $_
    Set-DevApiUrl
    if ($started) {
        try { & (Join-Path $PSScriptRoot 'stop-agrireach-dev.ps1') } catch { Write-Warning "Cleanup needs attention: $($_.Exception.Message)" }
    }
    Write-Host "SESSION NOT RUNNING: $($failure.Exception.Message)" -ForegroundColor Red
    throw $failure
} finally { $lock.ReleaseMutex(); $lock.Dispose() }
