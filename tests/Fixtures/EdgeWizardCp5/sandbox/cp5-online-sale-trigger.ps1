# CP6 (stream B): ONE Online POS sale on the sandbox Cloud at each of two moments of a real update, driven by what the process
# watch (cp5-proc-watch.ps1) sees: (1) the old workers are being stopped ('authority-worker --stop' / 'print-worker --stop'),
# (2) the real 'edge:local:update' (plan hash given, not --dry-run) is running. Each attempt runs cp5-cloud.php online-sale
# and saves its JSON as <OutDir>\online-sale-<tag>.json (accepted / blocked + the lease before and after). The Cloud's env
# (CP5_*) is inherited from the driver.
param(
    [Parameter(Mandatory = $true)][string]$ProcFile, [Parameter(Mandatory = $true)][string]$Php, [Parameter(Mandatory = $true)][string]$Ini,
    [Parameter(Mandatory = $true)][string]$CloudScript, [Parameter(Mandatory = $true)][string]$OutDir, [Parameter(Mandatory = $true)][string]$StopFile,
    [int]$MaxSec = 5400
)
$ErrorActionPreference = 'Continue'
function Read-Lines([string]$Path) {
    if (-not (Test-Path $Path)) { return @() }
    try {
        $fs = New-Object IO.FileStream($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, ([IO.FileShare]::ReadWrite -bor [IO.FileShare]::Delete))
        try { return [string[]]((New-Object IO.StreamReader($fs)).ReadToEnd() -split "`r?`n") } finally { $fs.Dispose() }
    } catch { return @() }
}
function Invoke-Sale([string]$Tag, [string]$Seen) {
    $t = (Get-Date).ToString('HH:mm:ss')
    $out = & $Php -c $Ini $CloudScript online-sale $Tag 2>&1 | Out-String
    $i = $out.IndexOf('{')
    $json = $(if ($i -ge 0) { $out.Substring($i) } else { '{"result":"no output"}' })
    try { $doc = $json | ConvertFrom-Json } catch { $doc = [pscustomobject]@{ result = 'unparsed'; raw = $out.Substring(0, [Math]::Min(400, $out.Length)) } }
    $doc | Add-Member -NotePropertyName trigger -NotePropertyValue $Seen -Force
    $doc | Add-Member -NotePropertyName started_local -NotePropertyValue $t -Force
    [IO.File]::WriteAllText((Join-Path $OutDir ('online-sale-' + $Tag + '.json')), ($doc | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false)))
}
$t0 = Get-Date
$done = @{}
while (((Get-Date) - $t0).TotalSeconds -lt $MaxSec -and -not (Test-Path $StopFile) -and $done.Count -lt 2) {
    foreach ($l in (Read-Lines $ProcFile)) {
        if (-not $l) { continue }
        try { $p = $l | ConvertFrom-Json } catch { continue }
        $cmd = [string]$p.cmd
        if (-not $done.ContainsKey('stop') -and $cmd -match '(authority|print)-worker --stop') {
            $done['stop'] = $true
            Start-Sleep -Seconds 3
            Invoke-Sale 'old-workers-stopped' ($p.t + ' ' + $cmd.Substring(0, [Math]::Min(160, $cmd.Length)))
        }
        if (-not $done.ContainsKey('update') -and $cmd -match 'edge:local:update' -and $cmd -notmatch '--dry-run' -and $cmd -match 'expected-plan-hash') {
            $done['update'] = $true
            Invoke-Sale 'update-running' ($p.t + ' ' + $cmd.Substring(0, [Math]::Min(160, $cmd.Length)))
        }
    }
    Start-Sleep -Milliseconds 700
}
