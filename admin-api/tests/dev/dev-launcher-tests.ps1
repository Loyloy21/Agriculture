# No Pester/install required. Uses isolated temporary env/state, never real app records.
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\..\scripts\agrireach-dev-common.ps1')
function Assert-Dev([bool]$Condition, [string]$Message) {
    if (!$Condition) { throw "FAIL: $Message" }
    Write-Host "PASS: $Message"
}
$realEnv = $devEnvPath
$testDir = Join-Path $devRuntime ('launcher-test-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $testDir -Force | Out-Null
$devEnvPath = Join-Path $testDir '.env.local'
try {
    [IO.File]::WriteAllLines($devEnvPath, @('KEEP_THIS=preserved', 'EXPO_PUBLIC_API_URL=https://old.invalid', 'export EXPO_PUBLIC_API_URL=https://also-old.invalid'))
    Set-DevApiUrl 'https://fixture.trycloudflare.com/CAPSTONE%20AGRICULTURE/api'
    $lines = @(Get-Content -LiteralPath $devEnvPath)
    Assert-Dev (@($lines | Where-Object { $_ -match '^EXPO_PUBLIC_API_URL=' }).Count -eq 1) 'exactly one central API variable'
    Assert-Dev ($lines -contains 'KEEP_THIS=preserved') 'unrelated dotenv values preserved'
    Assert-DevApiConfig 'https://fixture.trycloudflare.com/CAPSTONE%20AGRICULTURE/api'
    Assert-Dev $true 'Expo launch guard accepts the verified nonempty API value'
    Set-DevApiUrl
    Assert-Dev ((Get-Content -LiteralPath $devEnvPath) -contains 'EXPO_PUBLIC_API_URL=') 'failed/stopped session has no stale URL'
    $rejectedEmpty = $false
    try { Assert-DevApiConfig 'https://fixture.trycloudflare.com/CAPSTONE%20AGRICULTURE/api' } catch { $rejectedEmpty = $true }
    Assert-Dev $rejectedEmpty 'Expo launch guard rejects an empty API environment value'
    $script:pinProbeStatus = '200'
    $script:pinProbeContexts = @()
    function Get-DevProbe {
        param($Url, $Addresses, $Seconds, $Headers, $JsonBody)
        $body = $JsonBody | ConvertFrom-Json
        $script:pinProbeContexts += $body.context
        if ($script:pinProbeStatus -ne '200') { return @{ Status = $script:pinProbeStatus; ExitCode = 0; Raw = '{}'; Error = '' } }
        if ($body.latitude -gt 14) { return @{ Status = '422'; ExitCode = 0; Raw = '{"code":"PIN_OUTSIDE_CABADBARAN"}'; Error = '' } }
        return @{ Status = '200'; ExitCode = 0; Raw = "X-AgriReach-Boundary-Validator: local-geojson-v1`r`n`r`n{`"data`":{`"status`":`"inside`"}}"; Error = '' }
    }
    Assert-DevPinEndpoint 'https://fixture.trycloudflare.com/CAPSTONE%20AGRICULTURE/api'
    Assert-Dev ($script:pinProbeContexts[0] -eq 'residential' -and $script:pinProbeContexts[1] -eq 'farm') 'both registration contexts verified before API configuration is accepted'
    foreach ($statusCode in @('401', '404', '503')) {
        $script:pinProbeStatus = $statusCode
        $rejectedPinEndpoint = $false
        try { Assert-DevPinEndpoint 'https://fixture.trycloudflare.com/CAPSTONE%20AGRICULTURE/api' } catch { $rejectedPinEndpoint = $_.Exception.Message -match "HTTP $statusCode" }
        Assert-Dev $rejectedPinEndpoint "pin endpoint HTTP $statusCode fails startup with its exact status"
    }
    Remove-Item Function:Get-DevProbe
    $script:lookups = @()
    function Resolve-DnsName {
        param($Name, $Type, [switch]$DnsOnly, [switch]$QuickTimeout, $ErrorAction, $Server)
        $script:lookups += [string]$Server
        if (!$Server) { throw 'Bad DNS packet' }
        [pscustomobject]@{ IPAddress = '192.0.2.25' } # Documentation-only mock address.
    }
    $answer = @(Resolve-DevIPv4 'api.trycloudflare.com')
    Assert-Dev ($answer.Count -eq 1 -and $answer[0] -eq '192.0.2.25' -and $script:lookups[1] -eq '1.1.1.1') 'Bad DNS packet recovers via explicit public resolver'
    Remove-Item Function:Resolve-DnsName
    function Resolve-DnsName { param($Name, $Type, [switch]$DnsOnly, [switch]$QuickTimeout, $ErrorAction, $Server); throw 'DNS unavailable' }
    $didThrow = $false
    try { Resolve-DevIPv4 'api.trycloudflare.com' | Out-Null } catch { $didThrow = $true }
    Assert-Dev $didThrow 'all DNS failures stop startup instead of falling back to LAN'
    Remove-Item Function:Resolve-DnsName
    $tunnelLog = Join-Path $testDir 'attempt.err.log'
    [IO.File]::WriteAllLines($tunnelLog, @(
        'INF unrelated old URL https://old.trycloudflare.com',
        'INF Your quick Tunnel has been created! Visit it at (it may take some time to be reachable):',
        'INF |  https://fresh.trycloudflare.com  |',
        'INF Registered tunnel connection connIndex=0 protocol=http2'
    ))
    $status = Get-QuickTunnelLogStatus $tunnelLog
    Assert-Dev ($status.Origin -eq 'https://fresh.trycloudflare.com' -and $status.Registered) 'hostname comes from this attempt creation banner with HTTP/2 registration'
    [IO.File]::WriteAllLines($tunnelLog, @(
        'INF Your quick Tunnel has been created! Visit it at (it may take some time to be reachable):',
        'INF |  https://unconnected.trycloudflare.com  |'
    ))
    $status = Get-QuickTunnelLogStatus $tunnelLog
    Assert-Dev ($status.Origin -eq 'https://unconnected.trycloudflare.com' -and !$status.Registered) 'issued hostname alone is not a connected tunnel'
    [IO.File]::WriteAllLines($tunnelLog, @('INF unrelated old URL https://old.trycloudflare.com', 'ERR failed before provisioning'))
    $status = Get-QuickTunnelLogStatus $tunnelLog
    Assert-Dev (!$status.Origin -and !$status.Registered) 'stray URL without creation banner is never accepted'
    $self = Get-DevIdentity $PID
    Assert-Dev (Test-DevIdentity $self) 'process identity records PID, creation time, executable and command'
    $wrong = $self.Clone(); $wrong.created = 'different-process'
    Stop-DevOwned $wrong -Tree
    Assert-Dev ((Test-DevIdentity $self) -and !(Test-DevIdentity $wrong)) 'reused/stale PID cannot terminate unrelated process'
    $statePath = Join-Path $testDir 'state.json'
    Save-DevState $statePath @{ schema = 2; root = $devRoot; runner = $self }
    $state = Read-DevState $statePath
    Assert-Dev (Test-DevIdentity $state.runner) 'state survives JSON roundtrip'
    $firstLock = Enter-DevLock
    $nestedLock = Enter-DevLock
    $nestedLock.ReleaseMutex(); $nestedLock.Dispose()
    $firstLock.ReleaseMutex(); $firstLock.Dispose()
    Assert-Dev $true 'nested launcher/stop locks can be released'
} finally {
    $devEnvPath = $realEnv
    Remove-Item Function:Resolve-DnsName -ErrorAction SilentlyContinue
    Remove-Item Function:Get-DevProbe -ErrorAction SilentlyContinue
    # Only these named fixture files; never recursively delete any directory.
    foreach ($name in @('.env.local', 'state.json', 'state.json.next', 'attempt.err.log')) {
        $fixture = Join-Path $testDir $name
        if (Test-Path -LiteralPath $fixture) { Remove-Item -LiteralPath $fixture -Force }
    }
    Remove-Item -LiteralPath $testDir
}
