# Bingoo Print Agent — Windows uninstaller
# Run as Administrator. Removes the auto-start task. Config/logs are kept unless -Purge.

param([switch]$Purge)

$ErrorActionPreference = 'Stop'
$taskName = 'BingooPrintAgent'
$dataDir  = Join-Path $env:ProgramData 'BingooPrintAgent'

try { Stop-ScheduledTask -TaskName $taskName -ErrorAction Stop } catch {}
try { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction Stop; Write-Host "Removed task '$taskName'." } catch { Write-Host "Task '$taskName' was not installed." }

if ($Purge -and (Test-Path $dataDir)) {
    Remove-Item -Recurse -Force $dataDir
    Write-Host "Removed $dataDir (config + logs)."
} else {
    Write-Host "Config/logs kept at $dataDir (use -Purge to remove)."
}
