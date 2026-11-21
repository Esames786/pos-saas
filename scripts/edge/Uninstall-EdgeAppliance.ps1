# P4 WINDOWS APPLIANCE - uninstall a Branch Server SAFELY (P4 section 15).
#
# Default behaviour PRESERVES every piece of branch state: the local database (sales, outbox, print history), the
# encrypted backups, the appliance configuration (device identity, recovery key reference) and the certificates.
# Only the runtime (install root: php, gateway, scripts, versions, launcher) and the Windows tasks are removed.
#
# Data removal is an EXPLICIT, typed choice:
#   -DropDatabase  -ConfirmPhrase 'REMOVE ALL BRANCH DATA'   drops the LOCAL Edge database (refuses while events are
#                                                            unsynced unless -ForceLosePending is also given)
#   -RemoveData    -ConfirmPhrase 'REMOVE ALL BRANCH DATA'   deletes the data root (config, certs, logs, BACKUPS)
#
# Usage (elevated):
#   .\Uninstall-EdgeAppliance.ps1 -InstallRoot "C:\Program Files\Bingoo Edge"                # runtime only
#   .\Uninstall-EdgeAppliance.ps1 -InstallRoot ... -DropDatabase -RemoveData -ConfirmPhrase 'REMOVE ALL BRANCH DATA'
param(
    [string]$InstallRoot = 'C:\Program Files\Bingoo Edge',
    [switch]$DropDatabase,
    [switch]$RemoveData,
    [switch]$ForceLosePending,
    [string]$ConfirmPhrase,
    [switch]$NoServices
)
$ErrorActionPreference = 'Stop'
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
function Fail($text) { Write-Host "UNINSTALL FAILED: $text" -ForegroundColor Red; exit 1 }

$layoutPath = Join-Path $InstallRoot 'appliance.json'
if (-not (Test-Path $layoutPath)) { Fail "no appliance.json under $InstallRoot - nothing to uninstall here" }
$layout = Get-Content -Path $layoutPath -Raw | ConvertFrom-Json
$php = [string]$layout.php
$launcher = Join-Path $InstallRoot 'artisan'
$dataRoot = [string]$layout.data_root
$planFile = Join-Path $dataRoot 'gateway\service-plan.json'

if (($DropDatabase -or $RemoveData) -and $ConfirmPhrase -ne 'REMOVE ALL BRANCH DATA') {
    Fail "data removal requires -ConfirmPhrase 'REMOVE ALL BRANCH DATA' (nothing was changed)"
}

# 1. stop + unregister the Windows tasks
if (-not $NoServices -and (Test-Path $planFile)) {
    & (Join-Path $scriptDir 'Register-EdgeServices.ps1') -Action Stop -PlanFile $planFile
    & (Join-Path $scriptDir 'Register-EdgeServices.ps1') -Action Unregister -PlanFile $planFile
}

# 2. explicit database removal (BEFORE the runtime goes: the guarded command needs the runtime + config)
if ($DropDatabase) {
    $args = @('edge:local:uninstall-data', "--confirm=$ConfirmPhrase", '--no-interaction')
    if ($ForceLosePending) { $args += '--force-lose-pending' }
    & $php $launcher @args
    if ($LASTEXITCODE -ne 0) { Fail 'the local database was NOT dropped (unsynced events present, or the guard refused). Runtime left in place.' }
}

# 3. remove the runtime (install root) - junctions are removed as links, never followed
Get-ChildItem -Path $InstallRoot -Directory -Recurse -Force | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint } | ForEach-Object { & cmd /c rmdir "$($_.FullName)" | Out-Null }
Remove-Item -Path $InstallRoot -Recurse -Force
Write-Host "Runtime removed: $InstallRoot"

# 4. data root: preserved unless explicitly removed
if ($RemoveData) {
    Remove-Item -Path $dataRoot -Recurse -Force
    Write-Host "Data root removed by explicit choice: $dataRoot (configuration, certificates, logs and BACKUPS are gone)"
} else {
    Write-Host "PRESERVED (not deleted): data root $dataRoot" -ForegroundColor Yellow
    Write-Host '  - config\appliance.env : device identity, DB credentials, recovery key reference'
    Write-Host '  - backups\             : encrypted appliance backups (restorable on a fresh machine with the recovery key)'
    Write-Host '  - certs\, logs\, gateway\'
    if (-not $DropDatabase) { Write-Host '  - the local Edge database (sales, outbox, print history) - drop it only with -DropDatabase -ConfirmPhrase ...' }
    Write-Host "  To remove everything: re-run with -DropDatabase -RemoveData -ConfirmPhrase 'REMOVE ALL BRANCH DATA' BEFORE uninstalling the runtime, or drop the database manually."
}
Write-Host 'UNINSTALL COMPLETE' -ForegroundColor Green
exit 0
