# EDGE-LOCAL-PRINT-1 Slice 2 — the SAFE stop for maintenance/update (§8): request a COOPERATIVE stop
# through the worker's own DB flag first (the loop finishes its in-flight job, records a graceful
# stop, exits), and only then stop the Scheduled Task so the supervisor does not relaunch it.
# If the process is already dead, job-lease expiry recovers any in-flight delivery — leases are never
# rewritten here. This is the process-quiescing step the locked §AE update sequence needs around its
# maintenance-mode step: Stop-EdgePrintWorkerTask → backup/update/migrate → Install/Start task again.

param(
    [Parameter(Mandatory = $true)][string]$PhpPath,
    [Parameter(Mandatory = $true)][string]$AppRoot,
    [string]$TaskName = 'BingooEdgePrintWorker',
    [int]$WaitSeconds = 150   # > one job lease (120s) so an in-flight delivery can finish
)

$ErrorActionPreference = 'Stop'

& $PhpPath (Join-Path $AppRoot 'artisan') edge:local:print-worker --stop --stop-wait=$WaitSeconds
if ($LASTEXITCODE -ne 0) {
    Write-Warning 'Worker did not confirm a graceful stop (it may be dead) — lease expiry recovers any in-flight job.'
}

Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
Disable-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue | Out-Null
Write-Host "Scheduled task [$TaskName] stopped and disabled (re-enable with Install-EdgePrintWorkerTask.ps1 or Enable-ScheduledTask)."
