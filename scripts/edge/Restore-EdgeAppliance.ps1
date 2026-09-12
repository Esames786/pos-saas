# P4 WINDOWS APPLIANCE - restore the local appliance state from an encrypted backup (fresh machine or recovery).
#
# Prerequisites on the target machine: the appliance installed (Install-EdgeAppliance.ps1 with -SkipWarmSync is fine),
# appliance.env holding the SAME device identity and the SAME branch recovery key (EDGE_BACKUP_RECOVERY_KEY / _ID) that
# sealed the backup, and the Cloud CONFIG present locally (-PullConfig runs edge:local:bootstrap-pull first: products,
# users, printers ... must exist before the local state is restored). The restore refuses a backup of another branch
# (RESTORE_WRONG_IDENTITY), an incompatible schema, or unresolved references; pending outbox events are preserved.
#   .\Restore-EdgeAppliance.ps1 -InstallRoot "C:\Program Files\Bingoo Edge" -BackupFile D:\recover\edge-....enc -Branch 12
param(
    [string]$InstallRoot = 'C:\Program Files\Bingoo Edge',
    [Parameter(Mandatory = $true)][string]$BackupFile,
    [int]$Branch,
    [switch]$PullConfig,
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
$args = @('edge:local:restore', $BackupFile, '--no-interaction')
if ($Branch) { $args += "--branch=$Branch" }
& $php $launcher @args
$code = $LASTEXITCODE
if (-not $NoServices -and (Test-Path $planFile)) { & (Join-Path $scriptDir 'Register-EdgeServices.ps1') -Action Start -PlanFile $planFile }
if ($code -ne 0) { Write-Host 'RESTORE REFUSED/FAILED (see the message above)' -ForegroundColor Red; exit $code }
& $php $launcher edge:local:health
Write-Host 'RESTORE COMPLETE' -ForegroundColor Green
exit 0
