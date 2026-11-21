# P4 WINDOWS APPLIANCE - register / start / stop / unregister / inspect the Branch Server services.
#
# ONE source of truth: the plan JSON produced by `php <InstallRoot>\artisan edge:local:service-plan --json`
# (EdgeSupervisionPlan). This script never invents a task, a principal or an argument: it renders exactly what the
# plan says - N loopback web backends (edge:local:serve --worker=N), the print worker, the sync sender (periodic),
# the authority/heartbeat worker, the hourly backup, and the ONE non-artisan process, the TLS gateway (nginx).
#
# Policy (locked): Windows Scheduled Tasks, boot start, StartWhenAvailable, restart 999x every minute, a restricted
# service account (default NT AUTHORITY\LOCAL SERVICE), NON-elevated (RunLevel Limited), NEVER SYSTEM, no secret on
# any command line. Stop is COOPERATIVE first (worker --stop flags, nginx -s quit), then the task is stopped.
#
# Usage (elevated for the service account; -CurrentUser registers under the interactive user for a lab box):
#   .\Register-EdgeServices.ps1 -Action Register  -PlanFile "C:\ProgramData\BingooEdge\gateway\service-plan.json"
#   .\Register-EdgeServices.ps1 -Action Stop      -PlanFile ...   # cooperative stop + disable
#   .\Register-EdgeServices.ps1 -Action Start     -PlanFile ...
#   .\Register-EdgeServices.ps1 -Action Status    -PlanFile ...
#   .\Register-EdgeServices.ps1 -Action Unregister -PlanFile ...  # removes the tasks only; DB/outbox/backups untouched
#
# EDGE 0.9 (auto-update): -Exclude <task names> leaves those tasks alone (the updater never stops itself); -KeepEnabled makes
# Stop/Start only stop/run the tasks (no Disable/Enable - that needs write rights the service account does not hold). Register
# grants the SERVICE ACCOUNT read+run rights on each Bingoo task's security descriptor (never another principal, never SYSTEM),
# so the BingooEdgeUpdater task - running as that same account - can stop / start the others around a runtime switch.
param(
    [Parameter(Mandatory = $true)][ValidateSet('Register', 'Start', 'Stop', 'Unregister', 'Status')][string]$Action,
    [Parameter(Mandatory = $true)][string]$PlanFile,
    [string]$ServiceAccount = 'NT AUTHORITY\LOCAL SERVICE',
    [switch]$CurrentUser,
    [switch]$NoGateway,
    [int]$StopWaitSeconds = 150,
    [string[]]$Exclude = @(),
    [switch]$KeepEnabled,
    [switch]$NoTaskControlGrant
)
$ErrorActionPreference = 'Stop'

if (-not (Test-Path $PlanFile)) { throw "Service plan not found: $PlanFile (run edge:local:service-plan --json first)" }
$plan = Get-Content -Path $PlanFile -Raw | ConvertFrom-Json
if ($ServiceAccount -match '(?i)^(NT AUTHORITY\\SYSTEM|SYSTEM|LocalSystem)$') {
    throw 'Refusing to register Edge services as SYSTEM - use a restricted service account.'
}
$php = [string]$plan.php
$launcher = Join-Path ([string]$plan.app_root) 'artisan'

function New-EdgePrincipal {
    if ($CurrentUser) {
        $me = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
        return New-ScheduledTaskPrincipal -UserId $me -LogonType Interactive -RunLevel Limited
    }
    return New-ScheduledTaskPrincipal -UserId $ServiceAccount -LogonType ServiceAccount -RunLevel Limited
}

function New-EdgeSettings {
    return New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
        -StartWhenAvailable -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries `
        -ExecutionTimeLimit (New-TimeSpan -Days 3650) -MultipleInstances IgnoreNew
}

function New-EdgeTrigger($task) {
    $trigger = New-ScheduledTaskTrigger -AtStartup
    if ($task.kind -eq 'periodic' -and $task.repeat_minutes) {
        # [TimeSpan]::MaxValue serialises to P99999999DT23H59M59S, rejected by Windows 10 Task Scheduler (found at Khatri 21 Nov 2026)
        $repeat = (New-ScheduledTaskTrigger -Once -At (Get-Date) `
            -RepetitionInterval (New-TimeSpan -Minutes ([int]$task.repeat_minutes)) `
            -RepetitionDuration (New-TimeSpan -Days 3650)).Repetition
        $trigger.Repetition = $repeat
    }
    return $trigger
}

function Register-EdgeTask($name, $exe, $arguments, $workdir, $task) {
    if ($arguments -match '(?i)(secret|password|recovery_key|Bearer )') {
        throw "Refusing to register [$name]: its command line looks like it carries a secret."
    }
    $action = New-ScheduledTaskAction -Execute $exe -Argument $arguments -WorkingDirectory $workdir
    Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
    Register-ScheduledTask -TaskName $name -Action $action -Trigger (New-EdgeTrigger $task) -Settings (New-EdgeSettings) -Principal (New-EdgePrincipal) | Out-Null
    Grant-EdgeTaskControl $name
    Write-Host ("  registered {0,-28} {1} {2}" -f $name, (Split-Path $exe -Leaf), $arguments)
}

$tasks = @($plan.tasks | Where-Object { $Exclude -notcontains [string]$_.name })
$gateway = $plan.gateway
$gatewayEnabled = (-not $NoGateway) -and $gateway -and (Test-Path ([string]$gateway.executable)) -and ($Exclude -notcontains [string]$gateway.name)

# EDGE 0.9 - let the service account (and only it) read + run/stop the Bingoo tasks, so the auto-updater (same account) can
# restart them after a verified runtime switch. GRGX on the task's own DACL; the task principal is never changed.
function Grant-EdgeTaskControl($name) {
    if ($NoTaskControlGrant -or $CurrentUser) { return }
    try {
        $sid = (New-Object System.Security.Principal.NTAccount($ServiceAccount)).Translate([System.Security.Principal.SecurityIdentifier]).Value
        $svc = New-Object -ComObject 'Schedule.Service'
        $svc.Connect()
        $task = $svc.GetFolder('\').GetTask($name)
        $sddl = [string]$task.GetSecurityDescriptor(4)   # DACL_SECURITY_INFORMATION
        $ace = "(A;;GRGX;;;$sid)"
        if ($sddl.IndexOf($ace) -lt 0) { $task.SetSecurityDescriptor($sddl + $ace, 0) }
    } catch {
        Write-Warning "Could not grant $ServiceAccount run/stop rights on [$name] (auto-update restarts need it): $($_.Exception.Message)"
    }
}

switch ($Action) {
    'Register' {
        Write-Host "Registering Bingoo Edge services from $PlanFile (principal: $(if ($CurrentUser) { 'current user' } else { $ServiceAccount }), non-elevated)"
        foreach ($t in $tasks) { Register-EdgeTask $t.name $t.executable $t.arguments $t.working_directory $t }
        if ($gatewayEnabled) {
            if (-not (Test-Path ([string]$gateway.working_directory))) { New-Item -ItemType Directory -Path $gateway.working_directory -Force | Out-Null }
            Register-EdgeTask $gateway.name $gateway.executable $gateway.arguments $gateway.working_directory $gateway
        } else {
            Write-Warning 'TLS gateway task not registered (no gateway binary or -NoGateway). The LAN listener is missing until it is.'
        }
        foreach ($t in $tasks) { Start-ScheduledTask -TaskName $t.name }
        if ($gatewayEnabled) { Start-ScheduledTask -TaskName $gateway.name }
        Write-Host 'Services registered and started.'
    }
    'Start' {
        foreach ($t in $tasks) { if (-not $KeepEnabled) { Enable-ScheduledTask -TaskName $t.name -ErrorAction SilentlyContinue | Out-Null }; Start-ScheduledTask -TaskName $t.name }
        if ($gatewayEnabled) { if (-not $KeepEnabled) { Enable-ScheduledTask -TaskName $gateway.name -ErrorAction SilentlyContinue | Out-Null }; Start-ScheduledTask -TaskName $gateway.name }
        Write-Host 'Services started.'
    }
    'Stop' {
        # Cooperative first: the workers finish their in-flight job / tick and record a graceful stop.
        & $php $launcher edge:local:print-worker --stop --stop-wait=$StopWaitSeconds
        if ($LASTEXITCODE -ne 0) { Write-Warning 'Print worker did not confirm a graceful stop (lease expiry recovers any in-flight job).' }
        & $php $launcher edge:local:authority-worker --stop
        if ($LASTEXITCODE -ne 0) { Write-Warning 'Authority worker did not confirm a graceful stop.' }
        if ($gatewayEnabled) {
            & $gateway.executable -p $gateway.working_directory -c $gateway.config_path -s quit 2>$null
        }
        foreach ($t in $tasks) {
            Stop-ScheduledTask -TaskName $t.name -ErrorAction SilentlyContinue
            if (-not $KeepEnabled) { Disable-ScheduledTask -TaskName $t.name -ErrorAction SilentlyContinue | Out-Null }
        }
        if ($gatewayEnabled) {
            Stop-ScheduledTask -TaskName $gateway.name -ErrorAction SilentlyContinue
            if (-not $KeepEnabled) { Disable-ScheduledTask -TaskName $gateway.name -ErrorAction SilentlyContinue | Out-Null }
        }
        Write-Host $(if ($KeepEnabled) { 'Services stopped (left enabled).' } else { 'Services stopped and disabled (Start re-enables them).' })
    }
    'Unregister' {
        foreach ($t in $tasks) {
            Stop-ScheduledTask -TaskName $t.name -ErrorAction SilentlyContinue
            Unregister-ScheduledTask -TaskName $t.name -Confirm:$false -ErrorAction SilentlyContinue
        }
        if ($gateway) {
            Stop-ScheduledTask -TaskName $gateway.name -ErrorAction SilentlyContinue
            Unregister-ScheduledTask -TaskName $gateway.name -Confirm:$false -ErrorAction SilentlyContinue
        }
        Write-Host 'Service tasks removed (local database, outbox, backups and configuration untouched).'
    }
    'Status' {
        $names = @($tasks | ForEach-Object { $_.name })
        if ($gateway) { $names += $gateway.name }
        foreach ($n in $names) {
            $st = Get-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue
            if ($st) {
                $info = Get-ScheduledTaskInfo -TaskName $n
                Write-Host ("  {0,-28} {1,-10} last run {2}  result {3}" -f $n, $st.State, $info.LastRunTime, $info.LastTaskResult)
            } else {
                Write-Host ("  {0,-28} NOT REGISTERED" -f $n)
            }
        }
    }
}
