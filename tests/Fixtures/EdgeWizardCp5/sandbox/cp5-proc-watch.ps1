# CP6 (stream B): which runtime's code executes an update, from the processes themselves. Samples Win32_Process (~0.7 s) for
# php.exe / powershell.exe / nginx.exe whose command line runs an update or service step (the services Stop runs INSIDE
# Update-EdgeAppliance.ps1: its php '--stop' children and nginx '-s quit' are what shows) and appends each new process ONCE
# (time, pid, parent, executable, command line) as JSON lines, and a "gone" line when that process has exited - so a worker's
# whole lifetime is visible (r2: the old authority worker must live until right before step 5's Register). The command lines
# carry paths, a plan hash and a plan file - never a secret (the driver's final evidence scan redacts any known secret anyway).
# Stops at -StopFile or -MaxSec.
param([Parameter(Mandatory = $true)][string]$Out, [Parameter(Mandatory = $true)][string]$StopFile, [int]$MaxSec = 5400)
$ErrorActionPreference = 'Continue'
$seen = @{}
$live = @{}
$t0 = Get-Date
$rx = 'edge:local:(update|service-plan|migrate|auto-update|authority-worker|print-worker|serve|sync-sender|backup)|Update-EdgeAppliance|Register-EdgeServices|Start-BingooEdgeWizard| -s quit'
function Write-Line($o) { try { [IO.File]::AppendAllText($Out, ($o | ConvertTo-Json -Compress) + "`r`n", (New-Object Text.ASCIIEncoding)) } catch { } }
while (((Get-Date) - $t0).TotalSeconds -lt $MaxSec -and -not (Test-Path $StopFile)) {
    $now = @{}
    foreach ($p in @(Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='powershell.exe' OR Name='nginx.exe'" -ErrorAction SilentlyContinue)) {
        $cl = [string]$p.CommandLine
        if ($cl -notmatch $rx) { continue }
        $key = [string]$p.ProcessId + '|' + $cl
        $now[$key] = $true
        if ($seen.ContainsKey($key)) { continue }
        $seen[$key] = $true
        $live[$key] = [int]$p.ProcessId
        Write-Line ([ordered]@{ t = (Get-Date).ToString('HH:mm:ss'); pid = [int]$p.ProcessId; parent = [int]$p.ParentProcessId; exe = [string]$p.ExecutablePath
                cmd = $cl.Substring(0, [Math]::Min(500, $cl.Length)) })
    }
    foreach ($k in @($live.Keys)) {
        if ($now.ContainsKey($k)) { continue }
        $cmd = $k.Substring($k.IndexOf('|') + 1)
        Write-Line ([ordered]@{ t = (Get-Date).ToString('HH:mm:ss'); pid = $live[$k]; gone = $true; cmd = $cmd.Substring(0, [Math]::Min(200, $cmd.Length)) })
        $live.Remove($k)
    }
    Start-Sleep -Milliseconds 700
}
