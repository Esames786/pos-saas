# CP6 (stream B): which runtime's code executes an update, from the processes themselves. Samples Win32_Process (~0.7 s) for
# php.exe / powershell.exe / nginx.exe whose command line runs an update or service step (the services Stop runs INSIDE
# Update-EdgeAppliance.ps1: its php '--stop' children and nginx '-s quit' are what shows) and appends each new process ONCE (time, pid, parent,
# executable, command line) as JSON lines. The command lines carry paths, a plan hash and a plan file - never a secret
# (the driver's final evidence scan redacts any known secret anyway). Stops at -StopFile or -MaxSec.
param([Parameter(Mandatory = $true)][string]$Out, [Parameter(Mandatory = $true)][string]$StopFile, [int]$MaxSec = 5400)
$ErrorActionPreference = 'Continue'
$seen = @{}
$t0 = Get-Date
$rx = 'edge:local:(update|service-plan|migrate|auto-update|authority-worker|print-worker|serve|sync-sender|backup)|Update-EdgeAppliance|Register-EdgeServices|Start-BingooEdgeWizard| -s quit'
while (((Get-Date) - $t0).TotalSeconds -lt $MaxSec -and -not (Test-Path $StopFile)) {
    foreach ($p in @(Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='powershell.exe' OR Name='nginx.exe'" -ErrorAction SilentlyContinue)) {
        $cl = [string]$p.CommandLine
        if ($cl -notmatch $rx) { continue }
        $key = [string]$p.ProcessId + '|' + $cl
        if ($seen.ContainsKey($key)) { continue }
        $seen[$key] = $true
        $line = [ordered]@{ t = (Get-Date).ToString('HH:mm:ss'); pid = [int]$p.ProcessId; parent = [int]$p.ParentProcessId; exe = [string]$p.ExecutablePath
            cmd = $cl.Substring(0, [Math]::Min(500, $cl.Length)) } | ConvertTo-Json -Compress
        try { [IO.File]::AppendAllText($Out, $line + "`r`n", (New-Object Text.ASCIIEncoding)) } catch { }
    }
    Start-Sleep -Milliseconds 700
}
