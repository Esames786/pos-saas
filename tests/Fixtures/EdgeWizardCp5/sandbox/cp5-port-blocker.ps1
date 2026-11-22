# CP6 (stream B) - fault injection for the REAL K6 rollback of a release-signed update, without touching the signed package:
# once the real 'edge:local:update' runs (the services are stopped), hold 127.0.0.1:<Port> (a cashier web backend's port)
# with an EXCLUSIVE listener, so the NEW runtime's backend cannot bind and its health shows a new problem -> the wizard's own
# K6 rule rolls back (snapshot restore when the schema changed). Released as soon as the wizard log says the update is
# unhealthy / rolled back (so the restored runtime binds again), at -StopFile, or after -MaxSec. Status -> -StatusFile (JSON).
param(
    [int]$Port = 8091, [Parameter(Mandatory = $true)][string]$ProcFile, [Parameter(Mandatory = $true)][string]$LogDir,
    [Parameter(Mandatory = $true)][string]$StatusFile, [Parameter(Mandatory = $true)][string]$StopFile, [int]$MaxSec = 2400
)
$ErrorActionPreference = 'Continue'
$st = [ordered]@{ port = $Port; armed_at = (Get-Date).ToString('HH:mm:ss'); trigger = $null; bound_at = $null; bind_attempts = 0; released_at = $null; released_because = $null }
function Save-St { try { [IO.File]::WriteAllText($StatusFile, ($st | ConvertTo-Json), (New-Object Text.ASCIIEncoding)) } catch { } }
function Read-Text([string]$Path) {
    try {
        $fs = New-Object IO.FileStream($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, ([IO.FileShare]::ReadWrite -bor [IO.FileShare]::Delete))
        try { return (New-Object IO.StreamReader($fs)).ReadToEnd() } finally { $fs.Dispose() }
    } catch { return '' }
}
Save-St
$t0 = Get-Date
$over = { ((Get-Date) - $t0).TotalSeconds -ge $MaxSec -or (Test-Path $StopFile) }
# 1. wait for the real update (services stopped by then)
while (-not (& $over)) {
    $hit = @((Read-Text $ProcFile) -split "`r?`n" | Where-Object { $_ -match 'edge:local:update' -and $_ -notmatch '--dry-run' -and $_ -match 'expected-plan-hash' }) | Select-Object -First 1
    if ($hit) { $st.trigger = $hit.Substring(0, [Math]::Min(200, $hit.Length)); break }
    Start-Sleep -Milliseconds 500
}
# 2. take the port (the old backend may need a moment to let go)
$l = $null
$deadline = (Get-Date).AddSeconds(240)
while (-not $l -and (Get-Date) -lt $deadline -and -not (& $over)) {
    $st.bind_attempts++
    try {
        $x = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, $Port)
        $x.ExclusiveAddressUse = $true
        $x.Start()
        $l = $x
        $st.bound_at = (Get-Date).ToString('HH:mm:ss')
    } catch { Start-Sleep -Milliseconds 500 }
}
Save-St
# 3. hold until the wizard reports the unhealthy update / rollback
while ($l -and -not (& $over)) {
    $log = (@(Get-ChildItem $LogDir -Filter 'wizard-*.log' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime | Select-Object -Last 1) | ForEach-Object { Read-Text $_.FullName }) -join "`n"
    if ($log -match 'update unhealthy|rolled back to') { $st.released_because = 'wizard: ' + $(if ($log -match 'update unhealthy') { 'update unhealthy' } else { 'rolled back' }); break }
    Start-Sleep -Seconds 1
}
if ($l) { $l.Stop(); $st.released_at = (Get-Date).ToString('HH:mm:ss') }
if (-not $st.released_because) { $st.released_because = $(if (Test-Path $StopFile) { 'stop file' } elseif ($st.bound_at) { 'max time' } else { 'never bound' }) }
Save-St
