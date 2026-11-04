$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'agrireach-dev-common.ps1')
$lock = Enter-DevLock
try {
    Set-DevApiUrl
    # Invitation links return to the normal configuration (APP_URL in email.local.php).
    Remove-DevTemporaryOverlay
    $statePath = Join-Path $devRuntime 'processes.json'
    $state = Read-DevState $statePath
    if ($state) {
        foreach ($identity in @($state.tunnel, $state.proxy, $state.gateway)) { Stop-DevOwned $identity }
        Remove-Item -LiteralPath $statePath -Force
    }
    Write-Host 'Managed API tunnel stopped; EXPO_PUBLIC_API_URL cleared; temporary invitation address removed. Apache/MySQL were not stopped.'
} finally { $lock.ReleaseMutex(); $lock.Dispose() }
