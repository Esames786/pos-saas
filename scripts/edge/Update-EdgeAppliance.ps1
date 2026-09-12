# P4 WINDOWS APPLIANCE - apply a SIGNED update package to an installed Branch Server (P4 section 9).
#
# Sequence: verify the new package (hashes) -> cooperative drain/stop of the services -> `edge:local:update`
# (Ed25519 signature, tamper check, product/version/schema/target checks, PRE-UPDATE BACKUP, atomic stage into
# runtime\versions\<to> + `current` pointer switch, forward-only schema upgrade, rollback of the pointer on failure;
# the outbox and every local table are preserved) -> start services -> health. Never run against a LIVE branch
# while it is LOCAL_ACTIVE with an open shift: hand back first (the health report shows the authority state).
#
# Usage (elevated):
#   .\Update-EdgeAppliance.ps1 -InstallRoot "C:\Program Files\Bingoo Edge" -PackageRoot D:\pkg\BingooEdge-0.2.0
param(
    [string]$InstallRoot = 'C:\Program Files\Bingoo Edge',
    [Parameter(Mandatory = $true)][string]$PackageRoot,
    [string]$UpdateFile,
    [switch]$NoServices,
    [switch]$NoGateway,
    [switch]$AllowLocalActive
)
$ErrorActionPreference = 'Stop'
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
function Fail($text) { Write-Host "UPDATE FAILED: $text" -ForegroundColor Red; exit 1 }

$layout = Get-Content -Path (Join-Path $InstallRoot 'appliance.json') -Raw | ConvertFrom-Json
$php = [string]$layout.php
$launcher = Join-Path $InstallRoot 'artisan'
$planFile = Join-Path ([string]$layout.data_root) 'gateway\service-plan.json'

# 1. verify the new package
$manifestPath = Join-Path $PackageRoot 'package-manifest.json'
if (-not (Test-Path $manifestPath)) { Fail 'package-manifest.json not found' }
$manifest = Get-Content -Path $manifestPath -Raw | ConvertFrom-Json
$bad = 0
foreach ($prop in $manifest.files.PSObject.Properties) {
    $rel = $prop.Name
    if ($rel -like 'app/vendor/*') { continue }
    $full = Join-Path $PackageRoot ($rel -replace '/', '\')
    if (-not (Test-Path $full)) { Write-Host "  MISSING  $rel"; $bad++; continue }
    if ((Get-FileHash -Path $full -Algorithm SHA256).Hash.ToLower() -ne ([string]$prop.Value).ToLower()) { Write-Host "  TAMPERED $rel"; $bad++ }
}
if ($bad -gt 0) { Fail "$bad file(s) missing or tampered" }
if (-not $UpdateFile) {
    $candidates = Get-ChildItem -Path (Join-Path $PackageRoot 'update') -Filter 'edge-update-*.json' -ErrorAction SilentlyContinue
    if (-not $candidates) { Fail 'the package carries no signed update file (update\edge-update-<version>.json)' }
    $UpdateFile = $candidates[0].FullName
}
Write-Host "Package OK: Bingoo Edge $($manifest.edge_app_version); update file $UpdateFile"

# 2. authority guard
$healthJson = & $php $launcher edge:local:health --json
$health = ($healthJson -join "`n") | ConvertFrom-Json
if ($health.authority.state -eq 'local_active' -and -not $AllowLocalActive) {
    Fail 'the appliance is LOCAL_ACTIVE (serving the branch). Hand back to the Cloud first, or pass -AllowLocalActive for a deliberate in-place update.'
}

# 3. drain / stop
if (-not $NoServices -and (Test-Path $planFile)) {
    $stopArgs = @{ Action = 'Stop'; PlanFile = $planFile }
    if ($NoGateway) { $stopArgs['NoGateway'] = $true }
    & (Join-Path $scriptDir 'Register-EdgeServices.ps1') @stopArgs
}

# 4. apply (signature -> tamper -> version/schema/target -> pre-update backup -> stage -> switch -> schema upgrade)
& $php $launcher edge:local:update $UpdateFile (Join-Path $PackageRoot 'app') --no-interaction
$code = $LASTEXITCODE
if ($code -ne 0) {
    if (-not $NoServices -and (Test-Path $planFile)) { & (Join-Path $scriptDir 'Register-EdgeServices.ps1') -Action Start -PlanFile $planFile }
    Fail 'edge:local:update refused or failed (the previous runtime stays active; see the message above)'
}
# Dev/test packages carry app\vendor as a junction: the staged version needs the same link (a release copies a real vendor).
$pkgVendor = Get-Item (Join-Path $PackageRoot 'app\vendor') -ErrorAction SilentlyContinue
$current = (Get-Content -Path (Join-Path $layout.runtime_root 'current') -Raw).Trim()
$newDir = Join-Path $layout.runtime_root ("versions\" + $current)
if ($pkgVendor -and ($pkgVendor.Attributes -band [IO.FileAttributes]::ReparsePoint) -and -not (Test-Path (Join-Path $newDir 'vendor'))) {
    $target = $pkgVendor.Target; if ($target -is [array]) { $target = $target[0] }
    & cmd /c mklink /J "$(Join-Path $newDir 'vendor')" "$target" | Out-Null
}
foreach ($d in @('bootstrap\cache', 'storage\framework\cache\data', 'storage\framework\views', 'storage\framework\sessions', 'storage\logs', 'storage\app')) {
    New-Item -ItemType Directory -Path (Join-Path $newDir $d) -Force | Out-Null
}
$layout.edge_app_version = $current
[System.IO.File]::WriteAllText((Join-Path $InstallRoot 'appliance.json'), ($layout | ConvertTo-Json), (New-Object System.Text.UTF8Encoding($false)))

# 5. re-render the plan for the new version, start, health
$planArgs = @('edge:local:service-plan', "--php=$php", "--app-root=$InstallRoot", "--data-root=$($layout.data_root)", '--write-gateway-config', '--json')
if ($layout.gateway) { $planArgs += "--gateway=$($layout.gateway)" }
$planJson = & $php $launcher @planArgs
if ($LASTEXITCODE -eq 0) { [System.IO.File]::WriteAllText($planFile, ($planJson -join "`n"), (New-Object System.Text.UTF8Encoding($false))) }
if (-not $NoServices -and (Test-Path $planFile)) {
    $startArgs = @{ Action = 'Start'; PlanFile = $planFile }
    if ($NoGateway) { $startArgs['NoGateway'] = $true }
    & (Join-Path $scriptDir 'Register-EdgeServices.ps1') @startArgs
}
& $php $launcher edge:local:health
Write-Host "UPDATE COMPLETE - active runtime version: $current" -ForegroundColor Green
exit 0
