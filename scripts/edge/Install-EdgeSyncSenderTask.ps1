# OFFLINE EDGE PRODUCTIZATION (J) — register the Branch Server SYNC SENDER as a boot-start, periodically
# repeating Scheduled Task. Mirrors the hardened print-worker supervision exactly (least-privilege identity,
# boot start, aggressive restart, unregister-first idempotent reinstall) — see Install-EdgePrintWorkerTask.ps1
# for the full identity/ACL rationale. The sender drains the immutable outbox to the Cloud; a duplicate run
# is safe because delivery is guarded by the outbox SKIP-LOCKED lease, so no singleton row is needed here.
#
# Identity: NT AUTHORITY\LOCAL SERVICE (ServiceAccount logon, NON-elevated). SYSTEM is refused. The installer
# runs elevated only to REGISTER; the worker never runs elevated. No secret is ever placed on the command
# line — the appliance's device secret is provisioned via env, never as a task argument.
#
# Usage (elevated):
#   .\Install-EdgeSyncSenderTask.ps1 -PhpPath "C:\Program Files\Bingoo Edge\php\php.exe" `
#                                    -AppRoot "C:\Program Files\Bingoo Edge\app"

param(
    [Parameter(Mandatory = $true)][string]$PhpPath,
    [Parameter(Mandatory = $true)][string]$AppRoot,
    [string]$TaskName = 'BingooEdgeSyncSender',
    [int]$IntervalMinutes = 2,
    [string]$ServiceAccount = 'NT AUTHORITY\LOCAL SERVICE'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $PhpPath)) { throw "PHP not found at [$PhpPath]" }
if (-not (Test-Path (Join-Path $AppRoot 'artisan'))) { throw "artisan not found under [$AppRoot]" }
if ($ServiceAccount -match '(?i)^(NT AUTHORITY\\SYSTEM|SYSTEM)$') {
    throw 'Refusing to register the Edge sync sender as SYSTEM - use a restricted service account.'
}

$action = New-ScheduledTaskAction -Execute $PhpPath `
    -Argument ('"' + (Join-Path $AppRoot 'artisan') + '" edge:local:sync-send') `
    -WorkingDirectory $AppRoot

# Boot start, then repeat every $IntervalMinutes for the life of the session.
$trigger = New-ScheduledTaskTrigger -AtStartup
$repeat = (New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
    -RepetitionDuration ([TimeSpan]::MaxValue)).Repetition
$trigger.Repetition = $repeat

$settings = New-ScheduledTaskSettingsSet `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -StartWhenAvailable -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
$principal = New-ScheduledTaskPrincipal -UserId $ServiceAccount -LogonType ServiceAccount -RunLevel Limited

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
Start-ScheduledTask -TaskName $TaskName

Write-Host "Scheduled task [$TaskName] registered (boot start, repeat ${IntervalMinutes}m, IgnoreNew, principal=$ServiceAccount, non-elevated) and started."
