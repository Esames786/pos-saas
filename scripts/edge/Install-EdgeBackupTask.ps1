# OFFLINE EDGE PRODUCTIZATION (J) — register the Branch Server encrypted BACKUP as a boot-start, hourly
# Scheduled Task. Same hardened supervision as the print worker / sync sender (least-privilege LOCAL SERVICE,
# non-elevated, SYSTEM refused, unregister-first idempotent reinstall). A duplicate run is safe because the
# backup service takes a single-writer file lock and exits cleanly if another backup is in progress. No secret
# is ever placed on the command line; the recovery key is provisioned via env.
#
# Usage (elevated):
#   .\Install-EdgeBackupTask.ps1 -PhpPath "C:\Program Files\Bingoo Edge\php\php.exe" `
#                                -AppRoot "C:\Program Files\Bingoo Edge\app"

param(
    [Parameter(Mandatory = $true)][string]$PhpPath,
    [Parameter(Mandatory = $true)][string]$AppRoot,
    [string]$TaskName = 'BingooEdgeBackup',
    [int]$IntervalMinutes = 60,
    [string]$ServiceAccount = 'NT AUTHORITY\LOCAL SERVICE'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $PhpPath)) { throw "PHP not found at [$PhpPath]" }
if (-not (Test-Path (Join-Path $AppRoot 'artisan'))) { throw "artisan not found under [$AppRoot]" }
if ($ServiceAccount -match '(?i)^(NT AUTHORITY\\SYSTEM|SYSTEM)$') {
    throw 'Refusing to register the Edge backup task as SYSTEM - use a restricted service account.'
}

$action = New-ScheduledTaskAction -Execute $PhpPath `
    -Argument ('"' + (Join-Path $AppRoot 'artisan') + '" edge:local:backup') `
    -WorkingDirectory $AppRoot

$trigger = New-ScheduledTaskTrigger -AtStartup
$repeat = (New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
    -RepetitionDuration ([TimeSpan]::MaxValue)).Repetition
$trigger.Repetition = $repeat

$settings = New-ScheduledTaskSettingsSet `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 5) `
    -StartWhenAvailable -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
$principal = New-ScheduledTaskPrincipal -UserId $ServiceAccount -LogonType ServiceAccount -RunLevel Limited

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
Start-ScheduledTask -TaskName $TaskName

Write-Host "Scheduled task [$TaskName] registered (boot start, hourly, IgnoreNew, principal=$ServiceAccount, non-elevated) and started."
