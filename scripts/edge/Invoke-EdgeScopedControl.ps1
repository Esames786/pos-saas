# AGENT F (30 Oct 2026) - SCOPED start / stop / status of ONE Bingoo Edge install root's supervised processes, by RECORDED PID
# and install-root scope only. Companion of Get-EdgeProcessTree.ps1 (read-only listing). Rule set (Agent C section 3):
#   1. scope by directory, never by name  - a pid is acted on only when its command line names THIS install root (or data root);
#   2. record before you start, stop only what you recorded - every Start appends to -PidRecord {name,pid,executable,started_utc,
#      start_token}; Stop names the recorded pid and verifies pid + command line + creation time before any termination;
#   3. one supervisor per singleton - Start REFUSES when the task's listen port is held by any process, when the DB slot is HELD by a
#      LIVE process (edge:local:worker-status --probe), or when the pid record already has a live entry for the task;
#   4. cooperative before forced - Stop runs the plan's cooperative stop (--stop flags / nginx -s quit), waits, and only then
#      `taskkill /PID <pid> /T /F` the recorded pid (never by name, never a wildcard).
# Nothing here registers a Scheduled Task, elevates, or touches anything outside -InstallRoot/-DataRoot. Pure ASCII (PS 5.1).
#
#   .\Invoke-EdgeScopedControl.ps1 -InstallRoot <root> -PidRecord <pids.json> -Action Status
#   .\Invoke-EdgeScopedControl.ps1 -InstallRoot <root> -PidRecord <pids.json> -Action Start -Task BingooEdgePrintWorker [-ExtraArguments '--idle-sleep=2'] [-LogDir <dir>]
#   .\Invoke-EdgeScopedControl.ps1 -InstallRoot <root> -PidRecord <pids.json> -Action Stop  -Task BingooEdgePrintWorker [-StopWaitSeconds 150]
param(
    [Parameter(Mandatory = $true)][string]$InstallRoot,
    [Parameter(Mandatory = $true)][string]$PidRecord,
    [Parameter(Mandatory = $true)][ValidateSet('Status', 'Start', 'Stop')][string]$Action,
    [string]$Task,
    [string]$DataRoot,
    [string]$PlanFile,
    [string]$ExtraArguments = '',
    [string]$LogDir,
    [int]$StopWaitSeconds = 60,
    [switch]$Json
)
$ErrorActionPreference = 'Stop'
$root = $InstallRoot.TrimEnd('\', '/')
if (-not (Test-Path (Join-Path $root 'artisan'))) { throw "not an Edge install root (no artisan launcher): $root" }
$layoutFile = Join-Path $root 'appliance.json'
$layout = $null
if (Test-Path $layoutFile) { try { $layout = Get-Content -Path $layoutFile -Raw | ConvertFrom-Json } catch {} }
if (-not $DataRoot -and $layout) { $DataRoot = [string]$layout.data_root }
if (-not $DataRoot) { throw 'data root unknown: pass -DataRoot or provide appliance.json' }
$DataRoot = $DataRoot.TrimEnd('\', '/')
$php = $(if ($layout -and $layout.php) { [string]$layout.php } else { 'php.exe' })
$launcher = Join-Path $root 'artisan'
if (-not $PlanFile) { $PlanFile = Join-Path $DataRoot 'gateway\service-plan.json' }
if (-not (Test-Path $PlanFile)) { throw "service plan not found: $PlanFile (render it with edge:local:service-plan --write-gateway-config)" }
$plan = Get-Content -Path $PlanFile -Raw | ConvertFrom-Json
if (-not $LogDir) { $LogDir = Join-Path $DataRoot 'task-logs' }
$needles = @($root, ($root -replace '\\', '/'), $DataRoot, ($DataRoot -replace '\\', '/')) | Select-Object -Unique
$secretShapes = '(?i)(device_secret|EDGE_SYNC_DEVICE_SECRET|EDGE_DB_PASSWORD|EDGE_LOCAL_APP_KEY|APP_KEY=|EDGE_BACKUP_RECOVERY_KEY|SIGNING_KEY|Bearer [A-Za-z0-9+/=]{16,}|base64:[A-Za-z0-9+/=]{40,}|[0-9a-f]{64})'

function Read-Record { if (Test-Path $PidRecord) { try { return @((Get-Content -Path $PidRecord -Raw | ConvertFrom-Json) | ForEach-Object { $_ }) } catch { return @() } }; return @() }
function Write-Record($entries) { ConvertTo-Json -InputObject @($entries) -Depth 5 | Set-Content -Path $PidRecord -Encoding UTF8 }
function Get-Proc($id) { return Get-CimInstance Win32_Process -Filter "ProcessId=$id" -ErrorAction SilentlyContinue }
function Test-InScope($proc) {
    if (-not $proc) { return $false }
    $cl = [string]$proc.CommandLine
    foreach ($n in $needles) { if ($n -and $cl.IndexOf($n, [System.StringComparison]::OrdinalIgnoreCase) -ge 0) { return $true } }
    return $false
}
function Get-StartToken($proc) { if ($proc -and $proc.CreationDate) { return [int64]([DateTimeOffset]$proc.CreationDate).ToUnixTimeSeconds() }; return $null }
function Get-PlanTask($name) {
    if ($name -eq $plan.gateway.name) { return $plan.gateway }
    foreach ($t in $plan.tasks) { if ($t.name -eq $name) { return $t } }
    throw "task [$name] is not in the plan ($PlanFile)"
}
function Get-ListenOwner($listen) {
    if (-not $listen) { return $null }
    $parts = $listen.Split(':'); $port = [int]$parts[$parts.Length - 1]
    $c = Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($c) { return [pscustomobject]@{ port = $port; pid = [int]$c.OwningProcess; in_scope = (Test-InScope (Get-Proc $c.OwningProcess)) } }
    return $null
}
function Get-SlotState($taskName) {
    # DB singleton slots (print worker / authority worker): ask the runtime, with the OS liveness probe.
    $slotName = $null
    if ($taskName -eq 'BingooEdgePrintWorker') { $slotName = 'print_worker' }
    if ($taskName -eq 'BingooEdgeAuthorityWorker') { $slotName = 'authority_worker' }
    if (-not $slotName) { return $null }
    try {
        $raw = & $php $launcher edge:local:worker-status --probe --json 2>$null
        $ws = ($raw | Out-String) | ConvertFrom-Json
        return $ws.slots.$slotName
    } catch { return [pscustomobject]@{ state = 'unknown'; slot_verdict = "worker-status failed: $($_.Exception.Message)"; liveness = $null } }
}
function Resolve-Recorded($taskName) {
    # live recorded entries for this task whose pid still names this root AND whose creation time matches the record
    $live = @()
    foreach ($r in Read-Record) {
        if ($r.name -ne $taskName -or $r.stopped_utc) { continue }
        $p = Get-Proc $r.pid
        if (-not $p) { continue }
        if (-not (Test-InScope $p)) { continue }
        $tok = Get-StartToken $p
        if ($r.start_token -and $tok -and [math]::Abs([int64]$r.start_token - [int64]$tok) -gt 2) { continue }  # pid reused by a stranger
        $live += [pscustomobject]@{ pid = [int]$r.pid; started_utc = $r.started_utc; start_token = $tok; cmd = $(if (([string]$p.CommandLine) -match $secretShapes) { '<REDACTED>' } else { [string]$p.CommandLine }) }
    }
    return $live
}

switch ($Action) {
    'Status' {
        $rows = @()
        $names = @($plan.tasks | ForEach-Object { $_.name }) + @($plan.gateway.name)
        foreach ($n in $names) {
            $t = Get-PlanTask $n
            $owner = Get-ListenOwner $t.listen
            $slot = Get-SlotState $n
            $rec = Resolve-Recorded $n
            $rows += [pscustomobject]@{
                task = $n; kind = $t.kind; listen = $t.listen
                listen_owner = $(if ($owner) { "pid $($owner.pid)" + $(if ($owner.in_scope) { ' (this root)' } else { ' (NOT this root)' }) } else { '' })
                slot = $(if ($slot) { "$($slot.state)" + $(if ($slot.liveness) { "/$($slot.liveness)" } else { '' }) } else { '' })
                slot_verdict = $(if ($slot) { $slot.slot_verdict } else { '' })
                recorded_live = (@($rec) | ForEach-Object { $_.pid }) -join ','
            }
        }
        $rows | Format-Table -AutoSize -Wrap | Out-String -Width 220 | Write-Host
        if ($Json) { $rows | ConvertTo-Json -Depth 4 }
    }
    'Start' {
        if (-not $Task) { throw '-Task is required for Start' }
        $t = Get-PlanTask $Task
        $refusals = @()
        $owner = Get-ListenOwner $t.listen
        if ($owner) { $refusals += "listen port $($owner.port) is held by pid $($owner.pid)" + $(if ($owner.in_scope) { ' (a process of THIS root)' } else { ' (a process of ANOTHER environment - never touch it)' }) }
        $rec = Resolve-Recorded $Task
        if (@($rec).Count -gt 0) { $refusals += "pid record already has a LIVE $Task under this root: pid(s) " + ((@($rec) | ForEach-Object { $_.pid }) -join ',') }
        $slot = Get-SlotState $Task
        if ($slot -and $slot.state -eq 'running' -and $slot.liveness -eq 'alive') { $refusals += "DB slot is HELD by a live process (pid $($slot.pid)): $($slot.slot_verdict)" }
        if ($slot -and $slot.state -eq 'running' -and $slot.liveness -ne 'alive' -and $slot.liveness -ne 'dead') { Write-Host ("  note: DB slot shows a fresh heartbeat without a verifiable process ({0}); the worker itself decides (pre-0.8.3 rule: it exits as duplicate)" -f $slot.slot_verdict) }
        if ($refusals.Count -gt 0) {
            Write-Host "START REFUSED for $Task :" -ForegroundColor Yellow
            foreach ($r in $refusals) { Write-Host ("  - {0}" -f $r) }
            exit 3
        }
        if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
        $stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
        $out = Join-Path $LogDir "$Task.$stamp.out.log"; $err = Join-Path $LogDir "$Task.$stamp.err.log"
        $arguments = [string]$t.arguments
        if ($ExtraArguments) { $arguments = $arguments + ' ' + $ExtraArguments }
        if ($arguments -match $secretShapes) { throw 'refusing to start: the command line matches a secret shape' }
        $exe = [string]$t.executable
        if ($t.kind_of_process -eq 'artisan' -and $layout -and $layout.php) { $exe = [string]$layout.php }
        $pr = Start-Process -FilePath $exe -ArgumentList $arguments -WorkingDirectory ([string]$t.working_directory) -WindowStyle Hidden -PassThru -RedirectStandardOutput $out -RedirectStandardError $err
        Start-Sleep -Milliseconds 800
        $p = Get-Proc $pr.Id
        $entry = [ordered]@{
            name = $Task; pid = [int]$pr.Id; executable = $exe; arguments = $arguments; working_directory = [string]$t.working_directory
            started_utc = (Get-Date).ToUniversalTime().ToString('o'); start_token = (Get-StartToken $p); install_root = $root; stdout = $out; stderr = $err
        }
        $entries = @(Read-Record) + @([pscustomobject]$entry)
        Write-Record $entries
        Write-Host ("started {0} pid={1} start_token={2} (recorded in {3})" -f $Task, $pr.Id, $entry.start_token, $PidRecord)
        if (-not (Test-InScope $p)) { Write-Warning 'the started process does not (yet) name the install root in its command line - verify with Get-EdgeProcessTree.ps1' }
    }
    'Stop' {
        if (-not $Task) { throw '-Task is required for Stop' }
        $t = Get-PlanTask $Task
        $targets = @(Resolve-Recorded $Task)
        if ($targets.Count -eq 0) {
            Write-Host "nothing to stop: no LIVE recorded $Task under $root (a stale record entry, if any, is left as is)"
            exit 0
        }
        # 1. cooperative first (the plan's own stop contract)
        $coop = $null
        switch ($Task) {
            'BingooEdgePrintWorker'     { $coop = @('edge:local:print-worker', '--stop', "--stop-wait=$StopWaitSeconds") }
            'BingooEdgeAuthorityWorker' { $coop = @('edge:local:authority-worker', '--stop', "--stop-wait=$StopWaitSeconds") }
        }
        if ($coop) {
            Write-Host ("cooperative stop: {0}" -f ($coop -join ' '))
            try { & $php $launcher @coop | ForEach-Object { Write-Host ("  " + $_) } } catch { Write-Warning "cooperative stop failed: $($_.Exception.Message)" }
        } elseif ($Task -eq $plan.gateway.name) {
            Write-Host 'cooperative stop: nginx -s quit'
            try { & $plan.gateway.executable -p $plan.gateway.working_directory -c $plan.gateway.config_path -s quit 2>$null } catch {}
        }
        # 2. wait for the recorded pids to leave by themselves
        $deadline = (Get-Date).AddSeconds([math]::Max(5, [math]::Min($StopWaitSeconds, 30)))
        while ((Get-Date) -lt $deadline) {
            $alive = @($targets | Where-Object { Get-Proc $_.pid })
            if ($alive.Count -eq 0) { break }
            Start-Sleep -Milliseconds 500
        }
        # 3. forced, ONLY on a recorded pid that still names this root with the recorded creation time
        $stopped = @()
        foreach ($tg in $targets) {
            $p = Get-Proc $tg.pid
            if (-not $p) { Write-Host ("pid {0} exited by itself" -f $tg.pid); $stopped += [pscustomobject]@{ pid = $tg.pid; method = 'cooperative (exited by itself)' }; continue }
            if (-not (Test-InScope $p)) { Write-Warning ("pid {0} no longer names this root - NOT terminating" -f $tg.pid); continue }
            $tok = Get-StartToken $p
            if ($tg.start_token -and $tok -and [math]::Abs([int64]$tg.start_token - [int64]$tok) -gt 2) { Write-Warning ("pid {0} has a different creation time (pid reuse) - NOT terminating" -f $tg.pid); continue }
            Write-Host ("forced stop of recorded pid {0} (tree) after the cooperative wait" -f $tg.pid)
            & taskkill /PID $tg.pid /T /F 2>&1 | ForEach-Object { Write-Host ("  " + $_) }
            $stopped += [pscustomobject]@{ pid = $tg.pid; method = 'taskkill /PID /T /F (recorded pid, scope + creation time verified)' }
        }
        # 4. record the stop
        $entries = @(Read-Record)
        $now = (Get-Date).ToUniversalTime().ToString('o')
        foreach ($e in $entries) { foreach ($s in $stopped) { if ([int]$e.pid -eq [int]$s.pid -and -not $e.stopped_utc) { $e | Add-Member -NotePropertyName stopped_utc -NotePropertyValue $now -Force; $e | Add-Member -NotePropertyName stop_method -NotePropertyValue $s.method -Force } } }
        Write-Record $entries
        Write-Host ("stopped {0} pid(s); record updated: {1}" -f $stopped.Count, $PidRecord)
    }
}
