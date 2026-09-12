# P4 WINDOWS APPLIANCE - take an encrypted appliance backup now (the hourly BingooEdgeBackup task does the same).
# The backup is sealed with a per-backup key wrapped by the branch recovery key (EDGE_BACKUP_RECOVERY_KEY) - keep
# that key OUTSIDE the appliance (Cloud recovery authority / sealed envelope) so a fresh machine can restore.
#   .\Backup-EdgeAppliance.ps1 -InstallRoot "C:\Program Files\Bingoo Edge" [-Json]
param(
    [string]$InstallRoot = 'C:\Program Files\Bingoo Edge',
    [switch]$Json
)
$ErrorActionPreference = 'Stop'
$layout = Get-Content -Path (Join-Path $InstallRoot 'appliance.json') -Raw | ConvertFrom-Json
$args = @('edge:local:backup', '--no-interaction')
if ($Json) { $args += '--json' }
& ([string]$layout.php) (Join-Path $InstallRoot 'artisan') @args
exit $LASTEXITCODE
