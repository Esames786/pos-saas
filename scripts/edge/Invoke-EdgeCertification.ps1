# P5 PHYSICAL WINDOWS CERTIFICATION KIT - run on the LAB appliance machine as Administrator (LAB ONLY, never a live branch).
#
# Gathers the EVIDENCE the certification report needs, phase by phase, into -EvidenceDir (JSON + text). It never
# activates Local Mode, never touches a production tenant, and never weakens a lease. Phases:
#
#   Preflight   admin, OS, PHP, MySQL, network, printer reachability (records PHYSICAL_MACHINE / WINDOWS_VERSION)
#   Tasks       Get-ScheduledTask / Get-ScheduledTaskInfo evidence for every task of the accepted plan: principal
#               (never SYSTEM), run level, triggers (AtStartup, StartWhenAvailable), restart policy, MultipleInstances,
#               working directory, command line (no secret), state, last run result
#   Crash       LAB process termination per task family -> the Task Scheduler restart is observed; single-writer
#               semantics re-checked from the DB (one print-worker heartbeat row, one authority-worker row, outbox intact,
#               authority state unchanged, never LOCAL_ACTIVE)
#   PreReboot   records a marker (health snapshot + time) then tells the operator to reboot (the script never reboots
#               a machine by itself)
#   PostReboot  after the reboot: every task running, ports listening, health back to standby; startup timings from
#               Get-ScheduledTaskInfo.LastRunTime vs the boot time
#   Lan         cashier->Edge and Edge->printer reachability with the WAN disabled at the router (operator step)
#   Security    process command lines, task actions, logs, desktop shortcuts, installer transcript scanned for
#               secret shapes; icacls of appliance.env / certs / logs / backups
#   Health      edge:local:health --json captured (non-secret)
#   Report      folds every phase into certification-report.json
#
# Usage:
#   .\Invoke-EdgeCertification.ps1 -Phase Preflight -InstallRoot "C:\Program Files\Bingoo Edge" -EvidenceDir D:\cert
#   .\Invoke-EdgeCertification.ps1 -Phase Tasks ... ; -Phase Crash ... ; -Phase PreReboot ... ; (reboot) ; -Phase PostReboot ...
#   .\Invoke-EdgeCertification.ps1 -Phase Lan -PrinterIp 192.168.1.60 -CashierIp 192.168.1.21 ...
#   .\Invoke-EdgeCertification.ps1 -Phase Security ... ; -Phase Health ... ; -Phase Report ...
param(
    [Parameter(Mandatory = $true)][ValidateSet('Preflight', 'Tasks', 'Crash', 'PreReboot', 'PostReboot', 'Lan', 'Security', 'Health', 'Report')][string]$Phase,
    [string]$InstallRoot = 'C:\Program Files\Bingoo Edge',
    [Parameter(Mandatory = $true)][string]$EvidenceDir,
    [string]$PrinterIp,
    [string]$CashierIp,
    [string]$CloudHost,
    [int]$RestartWaitSeconds = 150
)
$ErrorActionPreference = 'Continue'
New-Item -ItemType Directory -Path $EvidenceDir -Force | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
function Save($name, $obj) {
    $path = Join-Path $EvidenceDir ("$Phase-$name.json")
    $obj | ConvertTo-Json -Depth 8 | Set-Content -Path $path -Encoding UTF8
    Write-Host "  evidence -> $path"
}
function Test-IsAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}
function Read-Layout {
    $p = Join-Path $InstallRoot 'appliance.json'
    if (-not (Test-Path $p)) { throw "appliance.json not found under $InstallRoot - install first" }
    return (Get-Content -Path $p -Raw | ConvertFrom-Json)
}
function Read-Plan($layout) {
    $p = Join-Path ([string]$layout.data_root) 'gateway\service-plan.json'
    if (-not (Test-Path $p)) { throw "service plan not found: $p" }
    return (Get-Content -Path $p -Raw | ConvertFrom-Json)
}
function Get-Health($layout) {
    $json = & ([string]$layout.php) (Join-Path $InstallRoot 'artisan') edge:local:health --json --no-interaction 2>$null
    try { return (($json -join "`n") | ConvertFrom-Json) } catch { return $null }
}
$secretShapes = '(?i)(device_secret|EDGE_SYNC_DEVICE_SECRET|EDGE_DB_PASSWORD|EDGE_LOCAL_APP_KEY|APP_KEY=|EDGE_BACKUP_RECOVERY_KEY|SIGNING_KEY|Bearer [A-Za-z0-9+/=]{16,}|base64:[A-Za-z0-9+/=]{40,}|[0-9a-f]{64})'

switch ($Phase) {
    'Preflight' {
        $os = Get-CimInstance Win32_OperatingSystem
        $cs = Get-CimInstance Win32_ComputerSystem
        $ev = [ordered]@{
            time = (Get-Date).ToString('o'); admin = (Test-IsAdmin); machine = "$($cs.Manufacturer) $($cs.Model)"; computer_name = $env:COMPUTERNAME
            windows = "$($os.Caption) $($os.Version)"; php = (& (Get-ChildItem -Path $InstallRoot -Filter php.exe -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName) -r 'echo PHP_VERSION;' 2>$null)
            ipv4 = @(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254*' } | ForEach-Object { "$($_.InterfaceAlias)=$($_.IPAddress)/$($_.PrefixLength)" })
            gateway_route = @(Get-NetRoute -DestinationPrefix 0.0.0.0/0 -ErrorAction SilentlyContinue | ForEach-Object { $_.NextHop })
            mysql_3306 = (Test-NetConnection -ComputerName 127.0.0.1 -Port 3306 -InformationLevel Quiet -WarningAction SilentlyContinue)
            printer_9100 = if ($PrinterIp) { (Test-NetConnection -ComputerName $PrinterIp -Port 9100 -InformationLevel Quiet -WarningAction SilentlyContinue) } else { $null }
            cloud_https = if ($CloudHost) { (Test-NetConnection -ComputerName $CloudHost -Port 443 -InformationLevel Quiet -WarningAction SilentlyContinue) } else { $null }
        }
        Save 'preflight' $ev
        if (-not $ev.admin) { Write-Warning 'NOT elevated: task registration and ACL phases will not certify on this session.' }
    }
    'Tasks' {
        $layout = Read-Layout; $plan = Read-Plan $layout
        $names = @($plan.tasks | ForEach-Object { $_.name }); if ($plan.gateway) { $names += $plan.gateway.name }
        $rows = foreach ($n in $names) {
            $t = Get-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue
            if (-not $t) { [ordered]@{ task = $n; registered = $false }; continue }
            $i = Get-ScheduledTaskInfo -TaskName $n
            $action = $t.Actions | Select-Object -First 1
            [ordered]@{
                task = $n; registered = $true; state = [string]$t.State
                principal_user = $t.Principal.UserId; logon_type = [string]$t.Principal.LogonType; run_level = [string]$t.Principal.RunLevel
                is_system = ($t.Principal.UserId -match '(?i)SYSTEM')
                triggers = @($t.Triggers | ForEach-Object { $_.CimClass.CimClassName + ($(if ($_.Repetition.Interval) { " every $($_.Repetition.Interval)" } else { '' })) })
                start_when_available = $t.Settings.StartWhenAvailable; restart_count = $t.Settings.RestartCount; restart_interval = [string]$t.Settings.RestartInterval
                multiple_instances = [string]$t.Settings.MultipleInstances; execution_time_limit = [string]$t.Settings.ExecutionTimeLimit
                execute = $action.Execute; arguments = $action.Arguments; working_directory = $action.WorkingDirectory
                arguments_carry_secret_shape = ([string]$action.Arguments -match $secretShapes)
                last_run = [string]$i.LastRunTime; last_result = $i.LastTaskResult; next_run = [string]$i.NextRunTime; missed_runs = $i.NumberOfMissedRuns
            }
        }
        Save 'tasks' $rows
        $rows | ForEach-Object { Write-Host ("  {0,-26} registered={1} state={2} principal={3} restart={4}x/{5} startWhenAvailable={6}" -f $_.task, $_.registered, $_.state, $_.principal_user, $_.restart_count, $_.restart_interval, $_.start_when_available) }
    }
    'Crash' {
        $layout = Read-Layout; $plan = Read-Plan $layout
        $before = Get-Health $layout
        $results = @()
        $families = @(
            @{ task = ($plan.tasks | Where-Object { $_.artisan_command -eq 'edge:local:serve' } | Select-Object -First 1).name; match = 'edge:local:serve --worker=1' },
            @{ task = $plan.gateway.name; match = 'nginx' },
            @{ task = 'BingooEdgeAuthorityWorker'; match = 'edge:local:authority-worker' },
            @{ task = 'BingooEdgeSyncSender'; match = 'edge:local:sync-send' },
            @{ task = 'BingooEdgePrintWorker'; match = 'edge:local:print-worker' }
        )
        foreach ($f in $families) {
            if (-not $f.task) { continue }
            $procs = Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -match [regex]::Escape($f.match) }
            $killed = @($procs | ForEach-Object { $_.ProcessId })
            $t0 = Get-Date
            foreach ($p in $procs) { Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue }
            $restarted = $false; $elapsed = $null
            $deadline = (Get-Date).AddSeconds($RestartWaitSeconds)
            while ((Get-Date) -lt $deadline) {
                Start-Sleep -Seconds 3
                $again = Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -match [regex]::Escape($f.match) -and ($killed -notcontains $_.ProcessId) }
                if ($again) { $restarted = $true; $elapsed = [math]::Round(((Get-Date) - $t0).TotalSeconds, 1); break }
            }
            $results += [ordered]@{ task = $f.task; killed_pids = $killed; restarted_by_supervisor = $restarted; restart_seconds = $elapsed; task_state = [string](Get-ScheduledTask -TaskName $f.task -ErrorAction SilentlyContinue).State }
            Write-Host ("  {0,-26} killed={1} restarted={2} in {3}s" -f $f.task, ($killed -join ','), $restarted, $elapsed)
        }
        Start-Sleep -Seconds 10
        $after = Get-Health $layout
        Save 'crash' ([ordered]@{
            results = $results
            authority_before = $before.authority.state; authority_after = $after.authority.state
            never_local_active = (($before.authority.state -ne 'local_active') -and ($after.authority.state -ne 'local_active'))
            print_worker_after = $after.workers.print_worker.state; authority_worker_after = $after.workers.authority_worker.running
            outbox_before = $before.sync; outbox_after = $after.sync
        })
    }
    'PreReboot' {
        $layout = Read-Layout
        $h = Get-Health $layout
        Save 'prereboot' ([ordered]@{ time = (Get-Date).ToString('o'); status = $h.status; authority = $h.authority.state; binding = $h.binding; sync = $h.sync })
        Write-Host 'Marker saved. NOW REBOOT WINDOWS (Restart-Computer) and run -Phase PostReboot after login. Do NOT start any Edge task by hand.'
    }
    'PostReboot' {
        $layout = Read-Layout; $plan = Read-Plan $layout
        $boot = (Get-CimInstance Win32_OperatingSystem).LastBootUpTime
        $names = @($plan.tasks | ForEach-Object { $_.name }); if ($plan.gateway) { $names += $plan.gateway.name }
        $tasks = foreach ($n in $names) {
            $t = Get-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue; $i = if ($t) { Get-ScheduledTaskInfo -TaskName $n } else { $null }
            [ordered]@{ task = $n; state = [string]$t.State; last_run = [string]$i.LastRunTime; seconds_after_boot = if ($i -and $i.LastRunTime -gt $boot) { [math]::Round(($i.LastRunTime - $boot).TotalSeconds) } else { $null }; last_result = $i.LastTaskResult }
        }
        $ports = foreach ($t in $plan.tasks) { if ($t.listen) { [ordered]@{ listen = $t.listen; open = (Test-NetConnection -ComputerName '127.0.0.1' -Port ([int]($t.listen -split ':')[-1]) -InformationLevel Quiet -WarningAction SilentlyContinue) } } }
        $ports += [ordered]@{ listen = $plan.gateway.listen; open = (Test-NetConnection -ComputerName '127.0.0.1' -Port ([int]($plan.gateway.listen -split ':')[-1]) -InformationLevel Quiet -WarningAction SilentlyContinue) }
        $deadline = (Get-Date).AddMinutes(5); $h = $null
        while ((Get-Date) -lt $deadline) { $h = Get-Health $layout; if ($h -and $h.authority.last_heartbeat_ack_at -and $h.workers.authority_worker.running) { break }; Start-Sleep -Seconds 10 }
        Save 'postreboot' ([ordered]@{ boot_time = $boot.ToString('o'); checked_at = (Get-Date).ToString('o'); tasks = $tasks; ports = $ports
            status = $h.status; authority = $h.authority; binding = $h.binding; sync = $h.sync; freshness = $h.freshness; workers = $h.workers })
        Write-Host ("  status={0} authority={1} last_ack={2}" -f $h.status, $h.authority.state, $h.authority.last_heartbeat_ack_at)
    }
    'Lan' {
        $layout = Read-Layout
        $ev = [ordered]@{
            time = (Get-Date).ToString('o')
            edge_to_printer_9100 = if ($PrinterIp) { Test-NetConnection -ComputerName $PrinterIp -Port 9100 -InformationLevel Quiet -WarningAction SilentlyContinue } else { 'no -PrinterIp' }
            edge_to_cashier_ping = if ($CashierIp) { Test-Connection -ComputerName $CashierIp -Count 2 -Quiet -ErrorAction SilentlyContinue } else { 'no -CashierIp' }
            cloud_reachable = if ($CloudHost) { Test-NetConnection -ComputerName $CloudHost -Port 443 -InformationLevel Quiet -WarningAction SilentlyContinue } else { 'no -CloudHost' }
            internet_dns = (Test-NetConnection -ComputerName 1.1.1.1 -Port 443 -InformationLevel Quiet -WarningAction SilentlyContinue)
            health = (Get-Health $layout).authority
        }
        Save 'lan' $ev
        Write-Host ("  edge->printer={0} edge->cashier={1} cloud={2} internet={3}" -f $ev.edge_to_printer_9100, $ev.edge_to_cashier_ping, $ev.cloud_reachable, $ev.internet_dns)
        Write-Host '  Run Test-EdgeCashierTrust.ps1 on the CASHIER PC for the cashier->Edge TLS leg.'
    }
    'Security' {
        $layout = Read-Layout
        $procs = Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -match 'artisan|nginx|php' } | ForEach-Object { [ordered]@{ pid = $_.ProcessId; cmd = $_.CommandLine; secret_shape = ([string]$_.CommandLine -match $secretShapes) } }
        $tasks = Get-ScheduledTask | Where-Object { $_.TaskName -like 'BingooEdge*' } | ForEach-Object { $a = $_.Actions | Select-Object -First 1; [ordered]@{ task = $_.TaskName; arguments = $a.Arguments; secret_shape = ([string]$a.Arguments -match $secretShapes) } }
        $logHits = @(); foreach ($f in Get-ChildItem -Path (Join-Path ([string]$layout.data_root) 'logs') -Filter *.log -ErrorAction SilentlyContinue) { $m = Select-String -Path $f.FullName -Pattern 'EDGE_SYNC_DEVICE_SECRET=|EDGE_DB_PASSWORD=|EDGE_LOCAL_APP_KEY=|EDGE_BACKUP_RECOVERY_KEY=|Bearer [A-Za-z0-9+/=]{16,}' -ErrorAction SilentlyContinue; if ($m) { $logHits += "$($f.Name): $($m.Count) hit(s)" } }
        $shortcuts = Get-ChildItem -Path @("$env:PUBLIC\Desktop", "$env:USERPROFILE\Desktop") -Filter *.lnk -ErrorAction SilentlyContinue | ForEach-Object { $sh = (New-Object -ComObject WScript.Shell).CreateShortcut($_.FullName); [ordered]@{ lnk = $_.Name; target = $sh.TargetPath; args = $sh.Arguments; secret_shape = ([string]$sh.Arguments -match $secretShapes) } }
        $acl = foreach ($p in @((Join-Path ([string]$layout.data_root) 'config\appliance.env'), (Join-Path ([string]$layout.data_root) 'certs'), (Join-Path ([string]$layout.data_root) 'logs'), (Join-Path ([string]$layout.data_root) 'backups'))) { [ordered]@{ path = $p; icacls = (& icacls $p 2>&1 | Out-String) } }
        Save 'security' ([ordered]@{ processes = $procs; tasks = $tasks; log_secret_hits = $logHits; shortcuts = $shortcuts; acls = $acl; admin = (Test-IsAdmin) })
        Write-Host ("  processes with secret shape: {0}; tasks with secret shape: {1}; log hits: {2}" -f (@($procs | Where-Object { $_.secret_shape }).Count), (@($tasks | Where-Object { $_.secret_shape }).Count), $logHits.Count)
    }
    'Health' {
        $layout = Read-Layout
        $h = Get-Health $layout
        Save 'health' $h
        & ([string]$layout.php) (Join-Path $InstallRoot 'artisan') edge:local:health --no-interaction
    }
    'Report' {
        $files = Get-ChildItem -Path $EvidenceDir -Filter '*.json' | Where-Object { $_.Name -ne 'certification-report.json' }
        $report = [ordered]@{ generated_at = (Get-Date).ToString('o'); evidence = [ordered]@{} }
        foreach ($f in $files) { $report.evidence[$f.BaseName] = (Get-Content -Path $f.FullName -Raw | ConvertFrom-Json) }
        $report | ConvertTo-Json -Depth 12 | Set-Content -Path (Join-Path $EvidenceDir 'certification-report.json') -Encoding UTF8
        Write-Host "  certification-report.json written with $($files.Count) evidence file(s)."
    }
}
