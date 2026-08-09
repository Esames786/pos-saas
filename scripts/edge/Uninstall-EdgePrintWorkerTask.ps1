# EDGE-LOCAL-PRINT-1 Slice 2 — remove the print-worker Scheduled Task (mirrors the print-agent
# uninstall contract). Business data / delivery metadata in the Edge DB is NEVER touched here.

param(
    [string]$TaskName = 'BingooEdgePrintWorker'
)

Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Write-Host "Scheduled task [$TaskName] removed (Edge DB untouched)."
