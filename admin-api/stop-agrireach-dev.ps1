$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'scripts\agrireach-dev-common.ps1')
$lock = Enter-DevLock
try {
    Set-DevApiUrl
    $qrPath = Join-Path $devRuntime 'expo-qr.png'
    if (Test-Path -LiteralPath $qrPath) { Remove-Item -LiteralPath $qrPath -Force }
    $expoStatePath = Join-Path $devRuntime 'expo-process.json'
    $state = Read-DevState $expoStatePath
    if ($state) {
        Stop-DevOwned $state.runner -Tree
        # Recorded identities also clean orphaned children after a QR window has died.
        foreach ($child in @($state.children)) { Stop-DevOwned $child -Tree }
        Remove-Item -LiteralPath $expoStatePath -Force
    }
    & (Join-Path $PSScriptRoot 'scripts\stop-cloudflare-quick-tunnel.ps1')
    Write-Host 'AgriReach managed session stopped. Unrelated Expo, Apache, MySQL and services were left alone.'
} finally { $lock.ReleaseMutex(); $lock.Dispose() }
