# EDGE-LOCAL-PRINT-1 Slice 2 — register the Branch Server local print worker as a boot-start
# Scheduled Task. DELIBERATELY mirrors the proven print-agent supervision policy
# (tools/print-agent/installer/windows/install-service.ps1): Register-ScheduledTask, -AtStartup,
# SYSTEM/Highest, aggressive restart policy, unregister-first idempotent reinstall. No NSSM/WinSW —
# Task Scheduler is the repository's one native supervision mechanism.
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
    [string]$TaskName = 'BingooEdgePrintWorker'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $PhpPath)) { throw "PHP not found at [$PhpPath]" }
if (-not (Test-Path (Join-Path $AppRoot 'artisan'))) { throw "artisan not found under [$AppRoot]" }

$action = New-ScheduledTaskAction -Execute $PhpPath `
    -Argument ('"' + (Join-Path $AppRoot 'artisan') + '" edge:local:print-worker') `
    -WorkingDirectory $AppRoot
$trigger = New-ScheduledTaskTrigger -AtStartup
$settings = New-ScheduledTaskSettingsSet `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -StartWhenAvailable -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -RunLevel Highest

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
Start-ScheduledTask -TaskName $TaskName

Write-Host "Scheduled task [$TaskName] registered (boot start, 1-min restart policy) and started."
