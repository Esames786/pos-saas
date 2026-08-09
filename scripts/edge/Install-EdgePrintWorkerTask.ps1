# EDGE-LOCAL-PRINT-1 Slice 2 — register the Branch Server local print worker as a boot-start
# Scheduled Task. The SUPERVISION policy mirrors the proven print-agent pattern (Register-ScheduledTask,
# -AtStartup, unregister-first idempotent reinstall, aggressive restart) — but the EXECUTION IDENTITY
# deliberately does NOT: the Edge PHP worker touches Edge DB credentials, the application key and
# business data, and the grounded appliance contract is "a restricted local service account, not the
# logged-in user, not admin at runtime". Default principal = NT AUTHORITY\LOCAL SERVICE (ServiceAccount
# logon, NON-elevated Limited run level). The INSTALLER runs elevated to REGISTER the task; the worker
# itself never runs elevated and never runs as SYSTEM.
#
# ACL prerequisite (installer's responsibility, before running this): the chosen principal needs
# read/execute on the immutable app + PHP runtime, write ONLY on the app's storage/ and
# bootstrap/cache/ (and ProgramData log/config paths), and nothing else. If LOCAL SERVICE cannot be
# granted those ACLs in a given install, pass -ServiceAccount 'BingooEdgeSvc' (an installer-managed
# dedicated local account: no interactive login, no admin membership; register with -Password there —
# never store it in the artifact or repo).
#
# Usage (elevated):
#   .\Install-EdgePrintWorkerTask.ps1 -PhpPath "C:\Program Files\Bingoo Edge\php\php.exe" `
#                                     -AppRoot "C:\Program Files\Bingoo Edge\app"
#
# Stop/maintenance contract: use Stop-EdgePrintWorkerTask.ps1 (cooperative stop FIRST, then the task
# stop) — never Stop-ScheduledTask alone, which is a hard kill recovered only by job-lease expiry.

param(
    [Parameter(Mandatory = $true)][string]$PhpPath,
    [Parameter(Mandatory = $true)][string]$AppRoot,
    [string]$TaskName = 'BingooEdgePrintWorker',
    [string]$ServiceAccount = 'NT AUTHORITY\LOCAL SERVICE'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $PhpPath)) { throw "PHP not found at [$PhpPath]" }
if (-not (Test-Path (Join-Path $AppRoot 'artisan'))) { throw "artisan not found under [$AppRoot]" }
if ($ServiceAccount -match '(?i)^(NT AUTHORITY\\SYSTEM|SYSTEM)$') {
    throw 'Refusing to register the Edge print worker as SYSTEM - use a restricted service account.'
}

$action = New-ScheduledTaskAction -Execute $PhpPath `
    -Argument ('"' + (Join-Path $AppRoot 'artisan') + '" edge:local:print-worker') `
    -WorkingDirectory $AppRoot
$trigger = New-ScheduledTaskTrigger -AtStartup
$settings = New-ScheduledTaskSettingsSet `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -StartWhenAvailable -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Days 3650)
# Least privilege: ServiceAccount logon, explicit NON-elevated run level. Never SYSTEM, never Highest.
$principal = New-ScheduledTaskPrincipal -UserId $ServiceAccount -LogonType ServiceAccount -RunLevel Limited

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
Start-ScheduledTask -TaskName $TaskName

Write-Host "Scheduled task [$TaskName] registered (boot start, 1-min restart, principal=$ServiceAccount, non-elevated) and started."
