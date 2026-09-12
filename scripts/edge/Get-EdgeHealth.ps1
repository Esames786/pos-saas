# P4 WINDOWS APPLIANCE - the ONE operator health command (non-secret). Same report as the Status page in the cashier UI.
#   .\Get-EdgeHealth.ps1 -InstallRoot "C:\Program Files\Bingoo Edge" [-Json] [-Services]
param(
    [string]$InstallRoot = 'C:\Program Files\Bingoo Edge',
    [switch]$Json,
    [switch]$Services
)
$ErrorActionPreference = 'Stop'
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$layout = Get-Content -Path (Join-Path $InstallRoot 'appliance.json') -Raw | ConvertFrom-Json
$args = @('edge:local:health', '--no-interaction')
if ($Json) { $args += '--json' }
& ([string]$layout.php) (Join-Path $InstallRoot 'artisan') @args
$code = $LASTEXITCODE
if ($Services) {
    $planFile = Join-Path ([string]$layout.data_root) 'gateway\service-plan.json'
    if (Test-Path $planFile) { & (Join-Path $scriptDir 'Register-EdgeServices.ps1') -Action Status -PlanFile $planFile }
}
exit $code
