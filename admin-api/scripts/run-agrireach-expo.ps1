param([int]$Port = 8081)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'agrireach-dev-common.ps1')
$state = Read-DevState (Join-Path $devRuntime 'processes.json')
if (!$state.publicApiUrl -or !(Test-DevIdentity $state.tunnel)) { throw 'No verified running API tunnel. Use the root start command.' }
Assert-DevApiConfig $state.publicApiUrl
Set-Location -LiteralPath (Join-Path $devRoot 'frontend\mobile')
$Host.UI.RawUI.WindowTitle = 'AgriReach Expo Go - CURRENT API AND QR'
# Inherited shell values must never override the newly verified dotenv value.
$env:EXPO_PUBLIC_API_URL = $state.publicApiUrl
Remove-Item Env:EXPO_PACKAGER_PROXY_URL, Env:REACT_NATIVE_PACKAGER_HOSTNAME -ErrorAction SilentlyContinue
Start-Transcript -LiteralPath (Join-Path $devRuntime 'expo.log') -Force | Out-Null
try {
    Write-Host 'AgriReach Expo Go - scan this NEW QR after the root launcher confirms public readiness.' -ForegroundColor Green
    Write-Host "Cloudflare API (NOT the QR): $($state.publicApiUrl)"
    Write-Host 'Keep this window open. Stop with the root stop-agrireach-dev.ps1 command.'
    & npx.cmd expo start --clear --tunnel --go --port $Port
    if ($LASTEXITCODE -ne 0) { throw "Expo exited with code $LASTEXITCODE. See this console and var\cloudflare-tunnel\expo.log." }
} finally { Stop-Transcript | Out-Null }
