# Copies the three BINGOO shortcuts to the current user's Desktop. No elevation, nothing else touched.
# Run from the folder that holds the .url files:  powershell -NoProfile -ExecutionPolicy Bypass -File .\Install-Shortcuts.ps1
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$desktop = [Environment]::GetFolderPath('Desktop')
$names = @('BINGOO Online.url', 'BINGOO Offline.url', 'Branch Server Status.url')
foreach ($n in $names) {
    $src = Join-Path $here $n
    if (-not (Test-Path $src)) { Write-Host "missing: $n"; continue }
    Copy-Item -Path $src -Destination (Join-Path $desktop $n) -Force
    Write-Host "placed: $n"
}
Write-Host "Desktop: $desktop"
