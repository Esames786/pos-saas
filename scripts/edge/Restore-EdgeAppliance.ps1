# P4 WINDOWS APPLIANCE - restore the local appliance state from an encrypted backup (fresh machine or recovery).
#
# Prerequisites on the target machine: the appliance installed (Install-EdgeAppliance.ps1 with -SkipWarmSync is fine),
# a PAIRED device identity for the same branch (a replacement machine pairs anew after the dead device is revoked), and
# the Cloud CONFIG present locally (-PullConfig runs edge:local:bootstrap-pull first: products, users, printers ... must
# exist before the local state is restored). -PullConfig / -PullRecoveryKey then fetch the branch backup recovery
# material (current + retired keys) from the Cloud recovery authority, so the key that sealed the backup is available
# even though the dead appliance is gone (P5B). The restore refuses a backup of another branch
# (RESTORE_WRONG_IDENTITY), an incompatible schema, or unresolved references; pending outbox events are preserved.
#   .\Restore-EdgeAppliance.ps1 -InstallRoot "C:\Program Files\Bingoo Edge" -BackupFile D:\recover\edge-....enc -Branch 12
param(
    [string]$InstallRoot = 'C:\Program Files\Bingoo Edge',
    [Parameter(Mandatory = $true)][string]$BackupFile,
    [int]$Branch,
    [switch]$PullConfig,
    [switch]$PullRecoveryKey,
    [switch]$NoServices
)
$ErrorActionPreference = 'Stop'
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$layout = Get-Content -Path (Join-Path $InstallRoot 'appliance.json') -Raw | ConvertFrom-Json
$php = [string]$layout.php
$launcher = Join-Path $InstallRoot 'artisan'
$planFile = Join-Path ([string]$layout.data_root) 'gateway\service-plan.json'
if (-not (Test-Path $BackupFile)) { Write-Host "Backup file not found: $BackupFile" -ForegroundColor Red; exit 1 }
if (-not $NoServices -and (Test-Path $planFile)) { & (Join-Path $scriptDir 'Register-EdgeServices.ps1') -Action Stop -PlanFile $planFile }
if ($PullConfig) {
    & $php $launcher edge:local:bootstrap-pull --no-interaction
    if ($LASTEXITCODE -ne 0) { Write-Host 'Config bootstrap failed - the restore needs the Cloud configuration present first.' -ForegroundColor Red; exit 1 }
}
if ($PullConfig -or $PullRecoveryKey) {
    # P5B: the dead appliance is never the only holder of its backup key - the paired replacement pulls the branch
    # material (current + retired keys) from the Cloud recovery authority into appliance.env (ids printed, never material).
    & $php $launcher edge:local:recovery-key --no-interaction
    if ($LASTEXITCODE -ne 0) { Write-Host 'Recovery-key provisioning failed - the Cloud recovery authority must release this branch material to this paired device.' -ForegroundColor Red; exit 1 }
}
$args = @('edge:local:restore', $BackupFile, '--no-interaction')
if ($Branch) { $args += "--branch=$Branch" }
& $php $launcher @args
$code = $LASTEXITCODE
if (-not $NoServices -and (Test-Path $planFile)) { & (Join-Path $scriptDir 'Register-EdgeServices.ps1') -Action Start -PlanFile $planFile }
if ($code -ne 0) { Write-Host 'RESTORE REFUSED/FAILED (see the message above)' -ForegroundColor Red; exit $code }
& $php $launcher edge:local:health
Write-Host 'RESTORE COMPLETE' -ForegroundColor Green
exit 0
