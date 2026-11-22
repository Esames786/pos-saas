# CP5 (stream B) - the Windows-native, ELEVATED 0.9 proof. Runs ONLY inside a Windows Sandbox (its own administrator); the
# host is never elevated or changed. Started by Start-Cp5.cmd (the .wsb LogonCommand).
#
#   -Scenario auto      prereqs -> cloud -> a (0.8.5 elevated install: MySQL service, CA, gateway, tasks) -> w-update (the REAL
#                       WPF wizard, Setup-BingooEdge.cmd, driven through UI Automation: UPDATE 0.8.5 -> 0.9.0-dev) -> b (post
#                       checks: K5 with the CA, tasks + LOCAL SERVICE grant, logins; headless update only if w-update could not
#                       start it) -> c (auto-update 0.9.1, REAL task control, heartbeat-gap / fencing watch) -> d (0.9.2 broken
#                       health -> rollback) -> e (counter wizard) -> s (Agent D) -> h (Agent A) -> g1 g3 g4 g2 (automatic
#                       offline takeover: OFF, flapping, clock skew, real takeover + supervised handback)
#   -Scenario manual    prereqs + Cloud + a, then the owner clicks through the WPF wizard (update); desktop "CP5 continue" = c..g
#   -Scenario continue  c .. g after a manual wizard run (same sandbox session)
#   -Scenario fresh     prereqs + Cloud, then w-fresh (WPF wizard FRESH INSTALL through UI Automation, setup code) -> f (post
#                       checks; headless fresh install only if w-fresh could not start it)
#   -Only a,b,c         run only these scenario ids (prereqs and cloud always run); a scenario whose prerequisite was not run
#                       is skipped. Start-Cp5.cmd passes its 2nd argument here.
#
# OTHER AGENTS' SCENARIOS, called with the signatures they delivered:
#   s (Agent D)  a separate STA process: Invoke-Cp5CounterSelfService.ps1 -Evidence -LanIp -Kit -Pkgs -Work [-CloudPhp -CloudIni
#                -LanHost -HttpsPort]; exit 0 = every verdict true; results in <Ev>\s-selfservice.json (verdict.all, web_driver).
#                It stops the in-sandbox Cloud php on purpose and changes the cashier's secrets\pin-<id>.
#   h (Agent A)  in-process: & Invoke-Cp5Continuity.ps1 -Evidence -LanIp -CaPem [-Kit -Work -CloudSrc -CloudPhp -CloudIni -LanHost
#                -HttpsPort -CloudPort -CloudDbPort]; returns an ordered hashtable (verdict_ok, checks, failed_checks, ...). A
#                SUPERVISED takeover: the automatic takeover is switched OFF before it. It restarts the Cloud php itself and
#                writes the new pid into cp5-run.json.
#   The Cloud php is restarted (Confirm-CloudUp) after s, before h and before g.
#
# Mapped folders (see the .wsb): C:\cp5\kit, C:\cp5\pkgs, C:\cp5\rel085, C:\cp5\cloudsrc READ-ONLY; C:\cp5\evidence writable.
# Secrets (MySQL / PFX passwords, PINs, setup + pairing codes) are generated INSIDE the sandbox under C:\cp5w\secrets, never
# printed, never written to the evidence folder (a final scan redacts any accidental hit and records the count).
# DEV-signed packages: the appliance's update key is the DEV key (rehearsal only; a real site needs release-signed packages).
param(
    [ValidateSet('auto', 'manual', 'continue', 'fresh')][string]$Scenario = 'auto',
    [string]$Kit = 'C:\cp5\kit',
    [string]$Pkgs = 'C:\cp5\pkgs',
    [string]$Rel085 = 'C:\cp5\rel085',
    [string]$CloudSrc = 'C:\cp5\cloudsrc',
    [string]$EvidenceRoot = 'C:\cp5\evidence',
    [string]$Work = 'C:\cp5w',
    [string]$Only = '',
    # HOST pre-flight only (not elevated): start the disposable Cloud exactly as the sandbox would (package php + package MySQL
    # on 127.0.0.1, read-only code tree), run a few Cloud commands, stop only the processes it started. No appliance step.
    [switch]$CloudSmoke,
    # host pre-flight only: leave the disposable Cloud running for a UI pre-flight; its pids are in <Work>\cp5-run.json
    [switch]$KeepCloud
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$InstallRoot = 'C:\Program Files\Bingoo Edge'
$DataRoot = 'C:\ProgramData\BingooEdge'
$Base = 'C:\BingooEdge'
$CloudPort = 9831
$CloudDbPort = 3407
$CloudUrl = "http://127.0.0.1:$CloudPort"
$LanHost = 'bingoo-edge.local'
$HttpsPort = 8443
$Pkg090 = Join-Path $Pkgs 'BingooEdge-0.9.0-dev'
$Pkg091 = Join-Path $Pkgs 'BingooEdge-0.9.1-dev'
$Pkg092 = Join-Path $Pkgs 'BingooEdge-0.9.2-dev'
$CloudPhp = Join-Path $Pkg090 'php\php.exe'
$CloudIni = Join-Path $Work 'cloud\php.ini'
$Secrets = Join-Path $Work 'secrets'
$RunFile = Join-Path $Work 'cp5-run.json'
$script:SecretValues = New-Object System.Collections.ArrayList

foreach ($d in @($Work, (Join-Path $Work 'cloud'), $Secrets, $EvidenceRoot)) { New-Item -ItemType Directory -Force -Path $d | Out-Null }
$env:CP5_TREE = $CloudSrc; $env:CP5_WORK = $Work; $env:CP5_KIT = $Kit
$env:CP5_CLOUD_PORT = [string]$CloudPort; $env:CP5_CLOUD_DB_PORT = [string]$CloudDbPort
. (Join-Path $Kit 'Cp5UiAutomation.ps1')   # UI Automation helpers (scenario w)

# ---------------------------------------------------------------------------------------------------------------- basics
$prevRun = $null
if (Test-Path $RunFile) { $prevRun = Get-Content -Raw $RunFile | ConvertFrom-Json }
if ($Scenario -eq 'continue' -and $prevRun) { $Ev = [string]$prevRun.evidence } else { $Ev = Join-Path $EvidenceRoot ($Scenario + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss')) }
New-Item -ItemType Directory -Force -Path $Ev | Out-Null

function Write-Log([string]$Level, [string]$Message) {
    $line = '{0} [{1}] {2}' -f (Get-Date -Format 'HH:mm:ss'), $Level.ToUpper(), $Message
    $color = @{ step = 'Cyan'; ok = 'Green'; warn = 'Yellow'; error = 'Red'; info = 'Gray' }[$Level]
    if (-not $color) { $color = 'Gray' }
    Write-Host $line -ForegroundColor $color
    Write-MappedFile (Join-Path $Ev 'driver.log') ($line + "`r`n") -Append
}
# The evidence folder is a host-mapped folder: a host process reading a file at the same instant can make a guest write fail
# with a sharing violation (run 5 ended right after scenario c that way). Retry briefly; never let evidence writing abort a run.
function Write-MappedFile([string]$Path, [string]$Text, [switch]$Append) {
    for ($i = 1; $i -le 10; $i++) {
        try {
            if ($Append) { [IO.File]::AppendAllText($Path, $Text, (New-Object Text.ASCIIEncoding)) }
            else { [IO.File]::WriteAllText($Path, $Text, (New-Object Text.UTF8Encoding($false))) }
            return
        } catch { Start-Sleep -Milliseconds (200 * $i) }
    }
    Write-Host ("(evidence write failed after retries: " + (Split-Path -Leaf $Path) + ')') -ForegroundColor Yellow
}
# Lines of a log another process is still WRITING (the appliance's edge-<date>.log): [IO.File]::ReadAllLines opens with
# FileShare.Read only and fails against the writer (g2 in run 7); share read/write/delete like Get-Content does. Plain strings.
function Read-SharedLines([string]$Path) {
    $fs = $null
    try {
        $fs = New-Object IO.FileStream($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, ([IO.FileShare]::ReadWrite -bor [IO.FileShare]::Delete))
        $sr = New-Object IO.StreamReader($fs)
        return [string[]]($sr.ReadToEnd() -split "`r?`n")
    } catch { return [string[]]@() } finally { if ($fs) { $fs.Dispose() } }
}
function Save-Evidence([string]$Name, $Object) {
    $text = $(if ($Object -is [string]) { $Object } else { $Object | ConvertTo-Json -Depth 12 })
    Write-MappedFile (Join-Path $Ev $Name) $text
}
function ConvertTo-Fwd([string]$p) { return ($p -replace '\\', '/') }
function Read-Shared([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return '' }
    $fs = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite)
    try { return (New-Object IO.StreamReader($fs)).ReadToEnd() } finally { $fs.Dispose() }
}
function Get-LastJson([string]$Text) {
    if (-not $Text) { return $null }
    $lines = $Text -split "`r?`n"
    for ($i = $lines.Count - 1; $i -ge 0; $i--) {
        if ($lines[$i].StartsWith('{')) {
            try { return (($lines[$i..($lines.Count - 1)]) -join "`n" | ConvertFrom-Json) } catch { }
        }
    }
    return $null
}
function Save-RunState([hashtable]$Patch) {
    $h = @{}
    if (Test-Path $RunFile) { (Get-Content -Raw $RunFile | ConvertFrom-Json).PSObject.Properties | ForEach-Object { $h[$_.Name] = $_.Value } }
    foreach ($k in $Patch.Keys) { $h[$k] = $Patch[$k] }
    [IO.File]::WriteAllText($RunFile, ($h | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false)))
}

# ---- secrets: generated here, files under C:\cp5w\secrets (administrators + SYSTEM only), values never printed ----
$me = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
& icacls $Secrets /inheritance:r /grant:r '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-18:(OI)(CI)F' ('*' + $me + ':(OI)(CI)F') | Out-Null
function New-SecretValue([int]$Length = 24) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
    $b = New-Object byte[] $Length
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
    $v = -join ($b | ForEach-Object { $chars[$_ % $chars.Length] })
    [void]$script:SecretValues.Add($v)
    return $v
}
function New-Pin {
    do {
        $b = New-Object byte[] 6
        [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
        $p = -join ($b | ForEach-Object { [string]($_ % 10) })
    } while ($p -match '^(\d)\1{5}$' -or '0123456789'.Contains($p) -or '9876543210'.Contains($p))
    [void]$script:SecretValues.Add($p)
    return $p
}
function Write-Secret([string]$Name, [string]$Value) {
    $path = Join-Path $Secrets $Name
    [IO.File]::WriteAllText($path, $Value, (New-Object Text.ASCIIEncoding))
    return $path
}
function Get-Secret([string]$Name, [scriptblock]$Create) {
    $path = Join-Path $Secrets ($Name + '.keep')
    if (Test-Path $path) { $v = (Get-Content -Raw $path).Trim(); [void]$script:SecretValues.Add($v); return $v }
    $v = & $Create
    [void](Write-Secret ($Name + '.keep') $v)
    return $v
}

# ---- processes: argument line, logged child processes (stdout/stderr to files), only OUR recorded pids are ever stopped ----
function ConvertTo-ArgLine([string[]]$Arguments) {
    return (($Arguments | ForEach-Object {
                if ($_ -eq '') { '""' }
                elseif ($_ -match '[\s"]') { '"' + (($_ -replace '(\\*)"', '$1$1\"') -replace '(\\+)$', '$1$1') + '"' }
                else { $_ }
            }) -join ' ')
}
function Start-Logged([string]$File, [string[]]$Arguments, [string]$LogFile, [hashtable]$SecretEnv = @{}) {
    foreach ($k in $SecretEnv.Keys) { Set-Item -Path ("env:" + $k) -Value $SecretEnv[$k] }
    try {
        $p = Start-Process -FilePath $File -ArgumentList (ConvertTo-ArgLine $Arguments) -RedirectStandardOutput $LogFile -RedirectStandardError ($LogFile + '.err') -NoNewWindow -PassThru -WorkingDirectory $Work
    } finally {
        foreach ($k in $SecretEnv.Keys) { Remove-Item -Path ("env:" + $k) -ErrorAction SilentlyContinue }
    }
    $null = $p.Handle   # PS 5.1: ExitCode stays $null unless Handle was touched
    return $p
}
function Stop-OwnTree([int]$ProcessId) { & taskkill.exe /PID $ProcessId /T /F 2>$null | Out-Null }
function Invoke-Logged([string]$File, [string[]]$Arguments, [string]$LogFile, [int]$TimeoutSec = 3600, [hashtable]$SecretEnv = @{}, [scriptblock]$OnPoll = $null) {
    $p = Start-Logged $File $Arguments $LogFile $SecretEnv
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while (-not $p.HasExited) {
        if ($OnPoll) { & $OnPoll }
        if ((Get-Date) -gt $deadline) { Stop-OwnTree $p.Id; throw "TIMEOUT after $TimeoutSec s: $File" }
        Start-Sleep -Milliseconds 700
    }
    $p.WaitForExit()
    return @{ Code = [int]$p.ExitCode; Out = (Read-Shared $LogFile); Err = (Read-Shared ($LogFile + '.err')) }
}
function Wait-Port([int]$Port, [int]$TimeoutSec = 120) {
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    do {
        $c = New-Object Net.Sockets.TcpClient
        try { $c.Connect('127.0.0.1', $Port); $c.Close(); return $true } catch { } finally { $c.Dispose() }
        Start-Sleep -Milliseconds 700
    } while ((Get-Date) -lt $deadline)
    return $false
}

# ---- Cloud + appliance command helpers ----
function Invoke-Cloud([string[]]$A, [int]$TimeoutSec = 1800, [string]$EvidenceName = $null) {
    $log = Join-Path $Work ('cloud-' + $A[0] + '-' + (Get-Date -Format 'HHmmssfff') + '.log')
    $r = Invoke-Logged $CloudPhp (@('-c', $CloudIni, (Join-Path $Kit 'cp5-cloud.php')) + $A) $log $TimeoutSec
    if ($EvidenceName) { Save-Evidence $EvidenceName ($r.Out + $(if ($r.Err) { "`n--- stderr ---`n" + $r.Err } else { '' })) }
    # Laravel's exception renderer can leave the plain-php driver with exit 0: an "In <File>.php line N:" block is a failure too
    if ($r.Code -ne 0 -or ($r.Out + $r.Err) -match '(?m)^\s*In [A-Za-z0-9_.]+\.php line \d+:') { throw ('cloud ' + $A[0] + ' exit ' + $r.Code + ': ' + ($r.Err + $r.Out).Trim().Substring(0, [Math]::Min(600, ($r.Err + $r.Out).Trim().Length))) }
    return $r
}
function Invoke-Edge([string[]]$A, [int]$TimeoutSec = 900) {
    $log = Join-Path $Work ('edge-' + ($A[0] -replace '[^a-z-]', '') + '-' + (Get-Date -Format 'HHmmssfff') + '.log')
    return Invoke-Logged (Join-Path $InstallRoot 'php\php.exe') (@((Join-Path $InstallRoot 'artisan')) + $A) $log $TimeoutSec
}
function Get-Health { $r = Invoke-Edge @('edge:local:health', '--json') 300; $j = Get-LastJson $r.Out; if ($j) { return $j }; return @{ error = ('exit ' + $r.Code) } }
function Get-Pointer { $f = Join-Path $InstallRoot 'runtime\current'; if (Test-Path $f) { return (Get-Content -Raw $f).Trim() }; return $null }
function Get-CloudState { return (Get-Content -Raw (Join-Path $Work 'cloud\state.json') | ConvertFrom-Json) }
function Get-CloudDevice { return (Get-LastJson (Invoke-Cloud @('device')).Out) }
function Get-Users {
    $s = Get-CloudState
    $u = @()
    for ($i = 0; $i -lt @($s.users).Count; $i++) { $u += [pscustomobject]@{ id = [int]@($s.users)[$i]; code = [string]@($s.employee_codes)[$i] } }
    return $u
}
function Get-TaskFacts {
    $svc = New-Object -ComObject Schedule.Service
    $svc.Connect()
    $out = @()
    foreach ($t in @(Get-ScheduledTask | Where-Object { $_.TaskName -like 'Bingoo*' })) {
        $i = $t | Get-ScheduledTaskInfo
        $sddl = $null
        try { $path = $t.TaskPath; if ($path.Length -gt 1) { $path = $path.TrimEnd('\') }; $sddl = [string]$svc.GetFolder($path).GetTask($t.TaskName).GetSecurityDescriptor(4) } catch { }
        $out += [ordered]@{ name = $t.TaskName; state = [string]$t.State; user = [string]$t.Principal.UserId; run_level = [string]$t.Principal.RunLevel
            last_run = $(if ($i.LastRunTime) { $i.LastRunTime.ToString('s') } else { $null }); last_result = $i.LastTaskResult
            local_service_control_ace = [bool]($sddl -match ';;;LS\)'); sddl = $sddl }
    }
    return $out
}
function Get-Listeners { return @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { @(80, 3306, 3407, 8090, 8091, 8443, 9831) -contains $_.LocalPort } | ForEach-Object { '{0}:{1} pid {2} ({3})' -f $_.LocalAddress, $_.LocalPort, $_.OwningProcess, (Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue).ProcessName }) }
function Test-PinLogins([string]$BaseUrl, [string]$CaPem = $null, [string]$Resolve = $null) {
    $res = [ordered]@{}
    foreach ($u in (Get-Users)) {
        $a = @('-c', $CloudIni, (Join-Path $Kit 'cp5-pinlogin.php'), $BaseUrl, $u.code, (Join-Path $Secrets ('pin-' + $u.id)))
        if ($CaPem) { $a += $CaPem }
        if ($Resolve) { $env:CP5_RESOLVE = $Resolve }
        try { $r = Invoke-Logged $CloudPhp $a (Join-Path $Work 'pinlogin.log') 120 } finally { Remove-Item env:CP5_RESOLVE -ErrorAction SilentlyContinue }
        $res[$u.code] = Get-LastJson $r.Out
    }
    return $res
}
function Get-ApplianceSnapshot([string]$Label) {
    $h = Get-Health
    return [ordered]@{ label = $Label; at = (Get-Date).ToString('s'); pointer = (Get-Pointer); status = $h.status; problems = $h.problems
        authority = $(if ($h.authority) { $h.authority.state } else { $null }); tasks = (Get-TaskFacts); listeners = (Get-Listeners)
        mysql_service = [string](Get-Service BingooEdgeMySQL -ErrorAction SilentlyContinue).Status }
}

# ---- probes + controls used by g / h / s (read-only SQL; only OUR recorded Cloud php pid is ever stopped) ----
function Invoke-Sql([ValidateSet('edge', 'cloud', 'cloud-master')][string]$Target, [string]$Sql) {
    $r = Invoke-Logged $CloudPhp @('-c', $CloudIni, (Join-Path $Kit 'cp5-sql.php'), $Target, $Sql) (Join-Path $Work 'sql.log') 60
    return (Get-LastJson $r.Out)
}
function Get-EdgeMeta { $j = Invoke-Sql 'edge' 'SELECT * FROM edge_local_meta LIMIT 1'; if ($j -and $j.rows) { return @($j.rows)[0] }; return $null }
function Get-CloudLease { $j = Invoke-Sql 'cloud' 'SELECT * FROM edge_branch_authority_leases ORDER BY id LIMIT 1'; if ($j -and $j.rows) { return @($j.rows)[0] }; return $null }
function Wait-Until([scriptblock]$Condition, [int]$TimeoutSec, [int]$EverySec = 5, [string]$What = 'condition') {
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    do { $v = & $Condition; if ($v) { return $v }; Start-Sleep -Seconds $EverySec } while ((Get-Date) -lt $deadline)
    throw "WAIT_TIMEOUT: $What not reached within $TimeoutSec s"
}
function Stop-CloudPhp {
    $rs = Get-Content -Raw $RunFile | ConvertFrom-Json
    if ($rs.cloud_php_pid) { Stop-OwnTree ([int]$rs.cloud_php_pid) }
    [void](Wait-Until { -not (Wait-Port $CloudPort 1) } 30 1 'the Cloud php to stop')
    Write-Log 'info' "Cloud php stopped (pid $($rs.cloud_php_pid))"
}
function Start-CloudPhp([int]$ClockOffsetSec = 0) {
    $log = Join-Path $Work ('cloud\serve-' + (Get-Date -Format 'HHmmss') + '.log')
    if ($ClockOffsetSec -ne 0) { $env:CP5_CLOUD_CLOCK_OFFSET = [string]$ClockOffsetSec }
    try {
        $p = Start-Process -FilePath $CloudPhp -ArgumentList ('-c "' + $CloudIni + '" -S 127.0.0.1:' + $CloudPort + ' -t "' + (Join-Path $CloudSrc 'public') + '" "' + (Join-Path $Kit 'cp5-router.php') + '"') -RedirectStandardOutput $log -RedirectStandardError ($log + '.err') -WindowStyle Hidden -PassThru
    } finally { Remove-Item env:CP5_CLOUD_CLOCK_OFFSET -ErrorAction SilentlyContinue }
    Save-RunState @{ cloud_php_pid = $p.Id; cloud_clock_offset = $ClockOffsetSec }
    if (-not (Wait-Port $CloudPort 60)) { throw 'the Cloud did not listen on 9831' }
    Write-Log 'info' ("Cloud php started (pid $($p.Id))" + $(if ($ClockOffsetSec) { ", clock offset $ClockOffsetSec s" } else { '' }))
}
function Set-ApplianceEnv([string]$Key, [string]$Value) {
    $f = Join-Path $DataRoot 'config\appliance.env'
    # one definition only (dotenv keeps the FIRST of duplicate keys): replace in place, append when absent
    $out = New-Object System.Collections.ArrayList
    $hit = $false
    foreach ($l in @(Get-Content -Path $f)) {
        if ($l -match ('^' + [regex]::Escape($Key) + '=')) { if (-not $hit) { [void]$out.Add("$Key=$Value") }; $hit = $true } else { [void]$out.Add($l) }
    }
    if (-not $hit) { [void]$out.Add("$Key=$Value") }
    $lines = @($out)
    [IO.File]::WriteAllText($f, (($lines -join "`r`n") + "`r`n"), (New-Object Text.ASCIIEncoding))
}
function Start-LeaseWatch([string]$Tag, [string]$StopFile, [int]$MaxSec = 5400) {
    # 1 s samples of the Cloud lease row + the appliance's authority facts + the runtime pointer (cp5-lease-watch.php)
    if (-not $env:CP5_EDGE_ROOT_PASSFILE) { $env:CP5_EDGE_ROOT_PASSFILE = Join-Path $Secrets 'mysql-root.keep' }
    Remove-Item -Force $StopFile -ErrorAction SilentlyContinue
    return (Start-Process -FilePath $CloudPhp -ArgumentList ('-c "' + $CloudIni + '" "' + (Join-Path $Kit 'cp5-lease-watch.php') + '" "' + (Join-Path $Ev ($Tag + '-lease')) + '" "' + $StopFile + '" ' + $MaxSec) -WindowStyle Hidden -PassThru)
}
function Stop-LeaseWatch($Watch, [string]$StopFile) { Set-Content -Path $StopFile -Value 'stop'; [void]$Watch.WaitForExit(60000) }
function Restart-EdgeTask([string]$Name) {
    $t = Get-ScheduledTask -TaskName $Name
    $t | Stop-ScheduledTask
    Start-Sleep -Seconds 3
    $t | Start-ScheduledTask
    Write-Log 'info' "task $Name restarted"
}

# ---- scenarios ----
$script:Results = [ordered]@{}
# cmd.exe splits arguments at commas: Start-Cp5.cmd passes "e+s+h" (any of + , ; space separate ids here)
$script:OnlyIds = @($Only -split '[+,; ]+' | Where-Object { $_ })
if ($Scenario -eq 'continue' -and (Test-Path (Join-Path $Ev 'scenarios.json'))) {
    (Get-Content -Raw (Join-Path $Ev 'scenarios.json') | ConvertFrom-Json).PSObject.Properties | ForEach-Object { $script:Results[$_.Name] = $_.Value }
}
function Invoke-Scenario([string]$Id, [string]$Title, [string[]]$Needs, [scriptblock]$Body) {
    # the base chain of a fresh sandbox (prereqs, cloud, a, b / f) always runs; -Only selects among the rest
    if ($script:OnlyIds.Count -gt 0 -and @('prereqs', 'cloud', 'a', 'b', 'f') -notcontains $Id -and $script:OnlyIds -notcontains $Id) {
        $script:Results[$Id] = [ordered]@{ title = $Title; ok = $false; skipped = 'not selected (-Only)' }
        Write-Log 'info' "scenario $Id not selected (-Only $($script:OnlyIds -join '+'))"
        Save-Evidence 'scenarios.json' $script:Results
        return
    }
    foreach ($n in $Needs) {
        if (-not ($script:Results.Contains($n) -and $script:Results[$n].ok)) {
            $script:Results[$Id] = [ordered]@{ title = $Title; ok = $false; skipped = "needs $n" }
            Write-Log 'warn' "scenario $Id skipped (needs $n)"
            Save-Evidence 'scenarios.json' $script:Results
            return
        }
    }
    $t0 = Get-Date
    Write-Log 'step' "==== scenario ${Id}: $Title"
    $rec = [ordered]@{ title = $Title; ok = $false; started = $t0.ToString('s') }
    try {
        $detail = & $Body
        if ($detail -is [array]) { $detail = @($detail | Where-Object { $_ -is [Collections.IDictionary] }) | Select-Object -Last 1 }   # stray pipeline output
        $rec.ok = $true
        if ($detail -is [hashtable] -or $detail -is [Collections.Specialized.OrderedDictionary]) {
            foreach ($k in $detail.Keys) { $rec[$k] = $detail[$k] }
            if ($detail.Contains('verdict_ok') -and -not $detail['verdict_ok']) { $rec.ok = $false }
        }
    } catch {
        $rec.error = $_.Exception.Message
        Write-Log 'error' ("scenario ${Id} FAILED: " + $_.Exception.Message)
    }
    $rec.seconds = [int]((Get-Date) - $t0).TotalSeconds
    $script:Results[$Id] = $rec
    Save-Evidence 'scenarios.json' $script:Results
    Write-Log $(if ($rec.ok) { 'ok' } else { 'error' }) ("scenario ${Id}: " + $(if ($rec.ok) { 'PASS' } else { 'FAIL' }) + " in $($rec.seconds) s")
}

# ======================================================================================================================
function Invoke-Prereqs {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    if (-not ((New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator))) { throw 'the driver needs the sandbox administrator (run Start-Cp5.cmd from the .wsb LogonCommand)' }
    foreach ($p in @($Kit, $Pkg090, $Pkg091, $Pkg092, $Rel085, $CloudSrc)) { if (-not (Test-Path $p)) { throw "missing mapped folder: $p" } }
    # Visual C++ 2015-2022 x64 runtime: a clean sandbox has none, and the Cloud php / mysqld here plus the REAL 0.8.5 install of
    # scenario a (no bundled prerequisite) need it BEFORE any 0.9 wizard runs. Installed with stream E's own
    # Install-EdgePrereqs.ps1 + the verified VC_redist.x64.exe that the 0.9.0-dev package carries (Authenticode + Microsoft
    # signer + SHA256SUMS line) - the same script the wizard runs as its first step (which then finds the runtime present).
    $prereq = Join-Path $Pkg090 'scripts\Install-EdgePrereqs.ps1'
    if (-not (Test-Path $prereq)) { throw 'the 0.9.0-dev package carries no scripts\Install-EdgePrereqs.ps1' }
    $chk = Invoke-Logged 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $prereq, '-PackageRoot', $Pkg090, '-CheckOnly') (Join-Path $Ev 'prereq-check-before.log') 120
    $how = 'present (check exit 0)'
    if ($chk.Code -ne 0) {
        $ins = Invoke-Logged 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $prereq, '-PackageRoot', $Pkg090) (Join-Path $Ev 'prereq-install.log') 1800
        if (@(0, 3010) -notcontains $ins.Code) { throw ('Install-EdgePrereqs.ps1 failed (exit ' + $ins.Code + '): ' + ($ins.Out + $ins.Err).Trim()) }
        $how = "check exit $($chk.Code), installed by Install-EdgePrereqs.ps1 (exit $($ins.Code)$(if ($ins.Code -eq 3010) { ', reboot recommended - not needed for this run' }))"
    }
    $lan = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | Sort-Object InterfaceMetric)
    if ($lan.Count -lt 1) { throw 'no IPv4 LAN address in the sandbox (Networking must be Enable in the .wsb)' }
    $script:LanIp = [string]$lan[0].IPAddress
    $os = Get-CimInstance Win32_OperatingSystem
    $facts = [ordered]@{ computer = $env:COMPUTERNAME; user = $env:USERNAME; os = $os.Caption + ' ' + $os.Version; lan_ip = $script:LanIp
        memory_mb = [int]($os.TotalVisibleMemorySize / 1024); free_memory_mb = [int]($os.FreePhysicalMemory / 1024)
        disk_free_gb = [math]::Round((Get-PSDrive C).Free / 1GB, 1); vc_runtime = $how; net_session_ok = $null }
    & net.exe session 2>$null | Out-Null
    $facts.net_session_ok = ($LASTEXITCODE -eq 0)
    Save-Evidence 'sandbox-facts.json' $facts
    Save-RunState @{ evidence = $Ev; lan_ip = $script:LanIp }
    Write-Log 'ok' ("sandbox: " + $facts.os + ", LAN IP " + $script:LanIp + ", VC++ " + $how + ", free disk " + $facts.disk_free_gb + " GB")
    return $facts
}

function Start-Cloud {
    # PHP for the Cloud = the 0.9.0-dev package runtime (read-only); its php.ini with absolute paths in the work dir.
    $ini = (Get-Content -Raw (Join-Path $Pkg090 'php\php.ini')).Replace('__BINGOO_PHP_DIR__', (Join-Path $Pkg090 'php'))
    $ini = [regex]::Replace($ini, '(?m)^\s*extension_dir\s*=.*$', ('extension_dir = "' + (Join-Path $Pkg090 'php\ext') + '"'))
    [IO.File]::WriteAllText($CloudIni, $ini, (New-Object Text.ASCIIEncoding))
    $mods = (& $CloudPhp -c $CloudIni -m) -join ','
    foreach ($m in @('pdo_mysql', 'sodium', 'openssl', 'curl', 'mbstring', 'zip')) { if ($mods -notmatch $m) { throw "the package php lacks $m" } }
    # MySQL for the Cloud: the package's MySQL 8.0.30 (read-only basedir), own datadir, 127.0.0.1:3407, console process
    $mysqlSrc = Join-Path $Pkg090 'mysql'   # stream E layout: <package>\mysql\bin\mysqld.exe
    if (-not (Test-Path (Join-Path $mysqlSrc 'bin\mysqld.exe'))) { $mysqlSrc = (Get-ChildItem $mysqlSrc -Directory -Filter 'mysql-*' | Select-Object -First 1).FullName }
    if (-not $mysqlSrc -or -not (Test-Path (Join-Path $mysqlSrc 'bin\mysqld.exe'))) { throw 'the 0.9.0-dev package carries no MySQL runtime' }
    $script:MySqlSrc = $mysqlSrc
    $cm = Join-Path $Work 'cloud-mysql'
    New-Item -ItemType Directory -Force -Path $cm | Out-Null
    $myIni = @"
[mysqld]
basedir=$(ConvertTo-Fwd $mysqlSrc)
datadir=$(ConvertTo-Fwd (Join-Path $cm 'data'))
port=$CloudDbPort
bind-address=127.0.0.1
mysqlx=OFF
skip-log-bin
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
innodb_buffer_pool_size=192M
max_connections=60
max_allowed_packet=64M
log_error=$(ConvertTo-Fwd (Join-Path $cm 'error.log'))
"@
    [IO.File]::WriteAllText((Join-Path $cm 'my.ini'), $myIni, (New-Object Text.ASCIIEncoding))
    if (-not (Test-Path (Join-Path $cm 'data'))) {
        $r = Invoke-Logged (Join-Path $mysqlSrc 'bin\mysqld.exe') @("--defaults-file=$(Join-Path $cm 'my.ini')", '--initialize-insecure') (Join-Path $cm 'init.log') 600
        if ($r.Code -ne 0) { throw ('cloud mysqld --initialize-insecure exit ' + $r.Code) }
    }
    if (-not (Wait-Port $CloudDbPort 2)) {
        $p = Start-Process -FilePath (Join-Path $mysqlSrc 'bin\mysqld.exe') -ArgumentList ('--defaults-file="' + (Join-Path $cm 'my.ini') + '"') -WindowStyle Hidden -PassThru
        Save-RunState @{ cloud_mysqld_pid = $p.Id }
        if (-not (Wait-Port $CloudDbPort 120)) { throw 'the Cloud MySQL did not listen on 3407' }
    }
    if (-not (Test-Path (Join-Path $Work 'cloud\state.json'))) {
        Write-Log 'info' 'Cloud setup: master + tenant DBs, offline_edge plan, users, stock, open shift / table / held bill (several minutes)'
        $r = Invoke-Cloud @('setup') 3600 'cloud-setup.json'
    }
    if (-not (Wait-Port $CloudPort 1)) { Start-CloudPhp }
    try { $code = (Invoke-WebRequest -UseBasicParsing -Uri ($CloudUrl + '/up') -TimeoutSec 60).StatusCode } catch { $code = [int]$_.Exception.Response.StatusCode }
    $keys = Get-LastJson (Invoke-Cloud @('public-keys')).Out
    $script:EnrollmentPublic = [string]$keys.enrollment_public
    $script:DevPublic = [string](Get-Content -Raw (Join-Path $Kit 'dev-public-facts.json') | ConvertFrom-Json).public_key
    $users = Get-Users
    foreach ($u in $users) { $pinFile = Join-Path $Secrets ('pin-' + $u.id); if (-not (Test-Path $pinFile)) { [void](Write-Secret ('pin-' + $u.id) (New-Pin)) } else { [void]$script:SecretValues.Add((Get-Content -Raw $pinFile).Trim()) } }
    Write-Log 'ok' ("Cloud up on $CloudUrl (HTTP $code), tenant cp5edgewiz, users " + (($users | ForEach-Object { $_.code }) -join ', ') + ', update key id ' + $keys.update_key_id)
    return [ordered]@{ http = $code; update_key_id = $keys.update_key_id; users = @($users | ForEach-Object { $_.code }) }
}

# ---------------------------------------------------------------------------------- a) 0.8.5 elevated install (Khatri way)
function Install-ApplianceMySql {
    $dir = Join-Path $Base 'mysql'
    $data = Join-Path $Base 'mysql-data'
    if (-not (Test-Path (Join-Path $dir 'bin\mysqld.exe'))) { & robocopy.exe $script:MySqlSrc $dir /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 | Out-Null }
    $iniPath = Join-Path $dir 'my.ini'
    # the Khatri kit template (my.ini.template) - buffer pool 256M / redo 64M instead of 512M / 256M (sandbox memory + disk)
    $ini = @"
[mysqld]
basedir=$(ConvertTo-Fwd $dir)
datadir=$(ConvertTo-Fwd $data)
port=3306
bind-address=127.0.0.1
mysqlx=OFF
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
default-time-zone=+05:00
innodb_buffer_pool_size=256M
innodb_log_file_size=64M
innodb_flush_log_at_trx_commit=1
sync_binlog=1
max_connections=80
max_allowed_packet=64M
log_error=$(ConvertTo-Fwd $data)/mysql-error.log
skip-log-bin
skip-name-resolve
sql_mode=ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION

[client]
port=3306
default-character-set=utf8mb4
"@
    [IO.File]::WriteAllText($iniPath, $ini, (New-Object Text.ASCIIEncoding))
    if (-not (Test-Path $data)) {
        $r = Invoke-Logged (Join-Path $dir 'bin\mysqld.exe') @("--defaults-file=$iniPath", '--initialize-insecure', '--console') (Join-Path $Ev 'a-mysql-initialize.log') 600
        if ($r.Code -ne 0) { throw ('mysqld --initialize-insecure exit ' + $r.Code) }
    }
    $root = Get-Secret 'mysql-root' { New-SecretValue }
    $edge = Get-Secret 'mysql-edge' { New-SecretValue }
    $env:CP5_EDGE_ROOT_PASSFILE = Join-Path $Secrets 'mysql-root.keep'   # read-only probes (cp5-sql.php, lease watch) read it; never printed
    if (-not (Get-Service BingooEdgeMySQL -ErrorAction SilentlyContinue)) {
        # README-MYSQL step 4 + the on-site init-file fix (root@127.0.0.1 for skip-name-resolve), as ONE first-start init file
        $sql = "CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '$root';`nGRANT ALL ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;`nALTER USER 'root'@'localhost' IDENTIFIED BY '$root';`n" +
            "CREATE DATABASE IF NOT EXISTS bingoo_edge_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`nCREATE USER IF NOT EXISTS 'bingoo_edge'@'127.0.0.1' IDENTIFIED BY '$edge';`n" +
            "CREATE USER IF NOT EXISTS 'bingoo_edge'@'localhost' IDENTIFIED BY '$edge';`nGRANT ALL PRIVILEGES ON bingoo_edge_local.* TO 'bingoo_edge'@'127.0.0.1', 'bingoo_edge'@'localhost';`nFLUSH PRIVILEGES;`n"
        $initFile = Write-Secret 'mysql-init.sql' $sql
        Remove-Variable sql
        [IO.File]::AppendAllText($iniPath, "`r`n[mysqld]`r`ninit-file=$(ConvertTo-Fwd $initFile)`r`n")
        $r = Invoke-Logged (Join-Path $dir 'bin\mysqld.exe') @('--install', 'BingooEdgeMySQL', "--defaults-file=$iniPath") (Join-Path $Ev 'a-mysql-install.log') 120
        Set-Service BingooEdgeMySQL -StartupType Automatic
        Start-Service BingooEdgeMySQL
        if (-not (Wait-Port 3306 120)) { throw 'BingooEdgeMySQL did not listen on 3306' }
        Start-Sleep -Seconds 3
        [IO.File]::WriteAllText($iniPath, $ini, (New-Object Text.ASCIIEncoding))   # init-file line removed again
        Remove-Item -Force $initFile
        Restart-Service BingooEdgeMySQL
        if (-not (Wait-Port 3306 120)) { throw 'BingooEdgeMySQL did not come back on 3306' }
    }
    $l = @(Get-NetTCPConnection -State Listen -LocalPort 3306 -ErrorAction SilentlyContinue | ForEach-Object { $_.LocalAddress })
    return [ordered]@{ service = [string](Get-Service BingooEdgeMySQL).Status; start_type = [string](Get-Service BingooEdgeMySQL).StartType; listen = $l; loopback_only = -not ($l | Where-Object { $_ -ne '127.0.0.1' }) }
}

function New-BranchCertificates {
    $caCrt = Join-Path $Base 'ca\bingoo-edge-branch-ca.crt'
    if (-not (Test-Path $caCrt)) {
        $r = Invoke-Logged 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $Rel085 'scripts\New-EdgeBranchCA.ps1'), '-BranchName', 'CP5-Sandbox', '-OutDir', (Join-Path $Base 'ca')) (Join-Path $Ev 'a-branch-ca.log') 300
        if ($r.Code -ne 0) { throw 'New-EdgeBranchCA failed' }
    }
    $ca = New-Object Security.Cryptography.X509Certificates.X509Certificate2($caCrt)
    $script:CaThumbprint = $ca.Thumbprint
    $pem = "-----BEGIN CERTIFICATE-----`n" + ([Convert]::ToBase64String($ca.RawData, 'InsertLineBreaks') -replace "`r", '') + "`n-----END CERTIFICATE-----`n"
    $script:CaPem = Join-Path $Work 'branch-ca.pem'
    [IO.File]::WriteAllText($script:CaPem, $pem, (New-Object Text.ASCIIEncoding))
    $pfx = Join-Path $Base 'certs\bingoo-edge-server.pfx'
    $pfxPw = Get-Secret 'pfx' { New-SecretValue }
    if (-not (Test-Path $pfx)) {
        $pwFile = Write-Secret 'pfx.pass' $pfxPw
        $r = Invoke-Logged 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $Rel085 'scripts\New-EdgeServerCertificate.ps1'), '-Hostname', $LanHost, '-ReservedIp', $script:LanIp, '-CaThumbprint', $ca.Thumbprint, '-OutDir', (Join-Path $Base 'certs'), '-ExportPfx', '-PfxPasswordFile', $pwFile) (Join-Path $Ev 'a-server-cert.log') 300
        if ($r.Code -ne 0 -or -not (Test-Path $pfx)) { throw 'New-EdgeServerCertificate failed' }
    }
    $sha256 = ([BitConverter]::ToString((New-Object Security.Cryptography.SHA256Managed).ComputeHash($ca.RawData)) -replace '-', '').ToLower()
    return [ordered]@{ ca_subject = $ca.Subject; ca_thumbprint = $ca.Thumbprint; ca_sha256 = $sha256; ca_not_after = $ca.NotAfter.ToString('s')
        ca_in_local_machine_root = [bool](Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $ca.Thumbprint }); server_pfx = $pfx }
}

function Invoke-Install085 {
    $mysql = Install-ApplianceMySql
    $certs = New-BranchCertificates
    $script:InstalledNow = -not (Test-Path (Join-Path $InstallRoot 'appliance.json'))
    if ($script:InstalledNow) {
        $codeFile = Join-Path $Secrets 'pairing.code'
        Remove-Item -Force $codeFile -ErrorAction SilentlyContinue
        $edgePwFile = Write-Secret 'edge.pass' (Get-Secret 'mysql-edge' { throw 'no edge password' })
        $pfxPwFile = Write-Secret 'pfx-install.pass' (Get-Secret 'pfx' { throw 'no pfx password' })
        $args085 = @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $Rel085 'scripts\Install-EdgeAppliance.ps1'),
            '-PackageRoot', $Rel085, '-DbHost', '127.0.0.1', '-DbPort', '3306', '-DbName', 'bingoo_edge_local', '-DbUser', 'bingoo_edge', '-DbPasswordFile', $edgePwFile,
            '-CloudUrl', $CloudUrl, '-AllowHttpCloud', '-PairingCodeFile', $codeFile, '-DeviceName', 'CP5-SANDBOX-SERVER-1',
            '-EnrollmentPublicKey', $script:EnrollmentPublic, '-UpdatePublicKey', $script:DevPublic,
            '-LanHostname', $LanHost, '-LanIp', $script:LanIp, '-CertPfx', (Join-Path $Base 'certs\bingoo-edge-server.pfx'), '-CertPfxPasswordFile', $pfxPwFile,
            '-HttpsPort', [string]$HttpsPort, '-HttpPort', '80')
        $log = Join-Path $Ev 'a-install-085.log'
        $script:Handed = $false
        Write-Log 'info' 'Install-EdgeAppliance.ps1 (0.8.5, real signed package, read-only) with services, gateway and the branch certificate'
        $r = Invoke-Logged 'powershell.exe' $args085 $log 3600 @{} {
            if (-not $script:Handed -and (Read-Shared $log) -match 'waiting up to') {
                [void](Invoke-Cloud @('pairing-code', $codeFile))
                $script:Handed = $true
                Write-Log 'info' 'pairing code issued by the Cloud owner path and handed to the installer (one-time file)'
            }
        }
        if ($r.Out -notmatch 'INSTALL COMPLETE') {
            if (($r.Out + $r.Err) -match 'P99999999DT23H59M59S' -and $r.Out -match '\[11\] Register-EdgeServices') {
                # The REAL 0.8.5 kit bug met at Khatri (21 Nov, docs/status/khatri-onsite-install-steps-084 pitfall (b)): its
                # Register-EdgeServices.ps1 uses [TimeSpan]::MaxValue as a repetition duration, which the Task Scheduler rejects
                # (Khatri: Windows 10 19045; here also Windows 11 26100). Apply the documented on-site workaround verbatim: a
                # copy with (New-TimeSpan -Days 3650), -Action Register, -Action Start, then the installer's steps 12 + 13.
                Write-Log 'warn' '0.8.5 step [11] hit the known [TimeSpan]::MaxValue task bug - applying the documented Khatri workaround'
                $src = Join-Path $InstallRoot 'scripts\Register-EdgeServices.ps1'
                $fix = Join-Path $InstallRoot 'scripts\Register-EdgeServices.khatri-fix.ps1'
                [IO.File]::WriteAllText($fix, (Get-Content -Raw $src).Replace('([TimeSpan]::MaxValue)', '(New-TimeSpan -Days 3650)'), (New-Object Text.ASCIIEncoding))
                $plan = Join-Path $DataRoot 'gateway\service-plan.json'
                $w1 = Invoke-Logged 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $fix, '-Action', 'Register', '-PlanFile', $plan) (Join-Path $Ev 'a-khatri-fix-register.log') 600
                $w2 = Invoke-Logged 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $fix, '-Action', 'Start', '-PlanFile', $plan) (Join-Path $Ev 'a-khatri-fix-start.log') 600
                $w3 = Invoke-Edge @('edge:local:authority-worker', '--max-ticks=2', '--interval=5', '--no-interaction') 300
                Save-Evidence 'a-khatri-workaround.json' ([ordered]@{ why = 'real 0.8.5 kit: Register-EdgeServices.ps1 [TimeSpan]::MaxValue repetition duration rejected (HRESULT 0x80041318)'
                        applied = 'documented Khatri on-site workaround (copy with New-TimeSpan -Days 3650, Register, Start, warm sync)'; register_exit = $w1.Code; start_exit = $w2.Code; warm_sync_exit = $w3.Code })
                if ($w1.Code -ne 0 -or $w2.Code -ne 0) { throw ('the documented 0.8.5 task workaround failed (register ' + $w1.Code + ', start ' + $w2.Code + ')') }
                $script:Khatri085Workaround = $true
            } else {
                throw ('the 0.8.5 install did not complete (exit ' + $r.Code + '), see a-install-085.log')
            }
        }
        Remove-Item -Force $edgePwFile, $pfxPwFile -ErrorAction SilentlyContinue
    }
    # every Cloud user gets an Edge PIN on 0.8.5 (the documented operator path: Cloud assertion + edge:local:enroll --credential-file)
    $enrolled = [ordered]@{}
    foreach ($u in (Get-Users)) {
        $af = Join-Path $Secrets ("assert-" + $u.id + '.json')
        [void](Invoke-Cloud @('assertion', [string]$u.id, $af))
        # edge:local:enroll READS AND DELETES its --credential-file (secret hygiene): hand it a one-time copy, keep pin-<id>
        $pinFile = Join-Path $Secrets ('pin-' + $u.id)
        if (-not (Test-Path $pinFile)) { [void](Write-Secret ('pin-' + $u.id) (New-Pin)) }
        $once = Write-Secret ('pin-' + $u.id + '.enroll') ((Get-Content -Raw $pinFile).Trim())
        $r = Invoke-Edge @('edge:local:enroll', $af, '--type=pin', ('--credential-file=' + $once), '--no-interaction')
        Remove-Item -Force $af, $once -ErrorAction SilentlyContinue
        $enrolled[$u.code] = ($r.Code -eq 0)
    }
    $snap = Get-ApplianceSnapshot 'after-0.8.5-install'
    $logins = Test-PinLogins 'http://127.0.0.1:8090'
    Save-Evidence 'a-state-085.json' ([ordered]@{ mysql = $mysql; certificates = $certs; enrolled = $enrolled; snapshot = $snap; pin_logins_loopback = $logins; cloud_device = (Get-CloudDevice) })
    # installed now -> must be the 0.8.5 runtime; a resumed sandbox session (installed + maybe updated by an earlier driver run)
    # only re-enrolls the PINs and proves the logins
    $ok = ((-not $script:InstalledNow) -or $snap.pointer -eq '0.8.5-edge') -and $snap.pointer -and -not ($enrolled.Values -contains $false) -and -not (@($logins.Values) | Where-Object { -not $_.ok })
    return [ordered]@{ verdict_ok = $ok; pointer = $snap.pointer; status = $snap.status; mysql = $mysql.service; tasks = @($snap.tasks | ForEach-Object { $_.name + '=' + $_.state })
        khatri_085_task_workaround = [bool]$script:Khatri085Workaround }
}

# ------------------------------------------------------------------------------ b) 0.9 wizard UPDATE (Setup-BingooEdge.cmd)
function New-UpdateAnswers([string]$ApproveHash, [string]$ResultName) {
    $a = [ordered]@{ cloud_url = $CloudUrl; allow_http_cloud = $true; log_dir = (Join-Path $Ev 'wizard-logs'); update_public_key = $script:DevPublic
        mysql = [ordered]@{ base_dir = $Base }; services = [ordered]@{ register = $true; gateway = $true }
        update = [ordered]@{ use_setup_code = $true; health_wait_seconds = 150 }; result_file = (Join-Path $Ev $ResultName) }
    if ($ApproveHash) { $a.update['approve_plan_hash'] = $ApproveHash }
    $f = Join-Path $Work ('answers-' + [IO.Path]::GetFileNameWithoutExtension($ResultName) + '.json')
    [IO.File]::WriteAllText($f, ($a | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false)))
    return $f
}
function Get-SetupCode {
    $f = Join-Path $Secrets 'setup.code'
    $r = Invoke-Cloud @('setup-code', $f)
    $code = (Get-Content -Raw $f).Trim()
    Remove-Item -Force $f
    [void]$script:SecretValues.Add($code)
    return @{ Code = $code; Mode = [string](Get-LastJson $r.Out).mode }
}
function Invoke-SetupCmd([string]$AnswersFile, [string]$LogName, [hashtable]$SecretEnv) {
    $cmd = Join-Path $Pkg090 'Setup-BingooEdge.cmd'
    & net.exe session 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) {
        return Invoke-Logged 'cmd.exe' @('/c', $cmd, '-Headless', '-AnswersFile', $AnswersFile) (Join-Path $Ev $LogName) 5400 $SecretEnv
    }
    # 'net session' (Setup-BingooEdge.cmd's admin probe) fails without the Server service: recorded, wizard started directly
    Write-Log 'warn' 'Setup-BingooEdge.cmd admin probe (net session) fails in this sandbox - starting the same wizard script directly'
    return Invoke-Logged 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-STA', '-File', (Join-Path $Pkg090 'app\scripts\edge\wizard\Start-BingooEdgeWizard.ps1'), '-Headless', '-AnswersFile', $AnswersFile) (Join-Path $Ev $LogName) 5400 $SecretEnv
}
function Invoke-UpdateChecks([string]$Via, [hashtable]$Extra = @{}) {
    # the same proof for the UI-driven and the headless update: same device, 0.9 runtime, K5 with the CA on the Cloud, tasks
    # re-registered with BingooEdgeUpdater + the LOCAL SERVICE control grant, every user's PIN still logs in
    Save-BeforeUpdate   # loads the pre-update snapshot of this sandbox session when the update ran in another driver process
    Start-Sleep -Seconds 20
    $after = Get-ApplianceSnapshot 'after-wizard-update'
    $dev = Get-CloudDevice
    $logins = Test-PinLogins 'http://127.0.0.1:8090'
    $grant = @($after.tasks | Where-Object { $_.name -ne 'BingooEdgeUpdater' } | Where-Object { -not $_.local_service_control_ace } | ForEach-Object { $_.name })
    # [string] strips Get-Content's PSDrive / PSProvider note properties: ConvertTo-Json -Depth 12 would expand them without end
    # (run 3: 1.9 GB and minutes of CPU inside Save-Evidence)
    $k5 = [string](@(Get-ChildItem (Join-Path $Ev 'wizard-logs') -Filter '*.log' -ErrorAction SilentlyContinue | ForEach-Object { Read-SharedLines $_.FullName } | Where-Object { $_ -match 'K5 LAN profile' }) | Select-Object -Last 1)
    $d = $dev.device
    $b = $script:DeviceBeforeUpdate.device
    $doc = [ordered]@{ via = $Via; k5_line = $k5; before = $script:BeforeUpdate; after = $after; cloud_device_before = $script:DeviceBeforeUpdate; cloud_device_after = $dev
        pin_logins_loopback = $logins; tasks_without_local_service_grant = $grant }
    foreach ($k in $Extra.Keys) { $doc[$k] = $Extra[$k] }
    Save-Evidence 'b-state-090.json' $doc
    $ok = $after.pointer -eq '0.9.0-dev' -and $d.lan_ca_pem_present -and [string]$d.lan_ip -eq $script:LanIp -and [int]$d.lan_https_port -eq $HttpsPort -and
        [string]$d.public_uuid -eq [string]$b.public_uuid -and [string]$d.installation_uuid -eq [string]$b.installation_uuid -and
        -not (@($logins.Values) | Where-Object { -not $_.ok }) -and @($after.tasks | Where-Object { $_.name -eq 'BingooEdgeUpdater' }).Count -eq 1 -and $grant.Count -eq 0
    return [ordered]@{ verdict_ok = $ok; via = $Via; pointer = $after.pointer; k5_ca_on_cloud = [bool]$d.lan_ca_pem_present; lan_ip = $d.lan_ip
        same_device = ([string]$d.public_uuid -eq [string]$b.public_uuid); status = $after.status; tasks_missing_ls_grant = $grant; k5 = $k5 }
}
function Save-BeforeUpdate {
    $f = Join-Path $Work 'before-update.json'
    if (-not $script:BeforeUpdate -and (Test-Path $f)) {   # a 'continue' run after the owner's WPF update
        $j = Get-Content -Raw $f | ConvertFrom-Json
        $script:BeforeUpdate = $j.snapshot; $script:DeviceBeforeUpdate = $j.device
    }
    if (-not $script:BeforeUpdate) {
        $script:BeforeUpdate = Get-ApplianceSnapshot 'before-wizard-update'; $script:DeviceBeforeUpdate = Get-CloudDevice
        [IO.File]::WriteAllText($f, ([ordered]@{ snapshot = $script:BeforeUpdate; device = $script:DeviceBeforeUpdate } | ConvertTo-Json -Depth 12), (New-Object Text.UTF8Encoding($false)))
    }
}
function Invoke-WizardUpdateHeadless {
    Save-BeforeUpdate
    $sc = Get-SetupCode
    $r1 = Invoke-SetupCmd (New-UpdateAnswers $null 'b-wizard-result-unapproved.json') 'b-wizard-unapproved.out.txt' @{ BINGOO_WIZARD_TEST_SECRETS = '1'; BINGOO_WIZARD_SECRET_SETUP_CODE = $sc.Code }
    $m = [regex]::Match($r1.Out + $r1.Err, 'approve plan (sha256:[0-9a-f]{64})')
    if (-not $m.Success) { throw ('the unapproved wizard run did not report a plan hash (exit ' + $r1.Code + ')') }
    $plan = $m.Groups[1].Value
    Write-Log 'info' "plan approval gate refused as designed; plan $plan - approving it (owner step)"
    $sc = Get-SetupCode
    $t0 = Get-Date
    $r2 = Invoke-SetupCmd (New-UpdateAnswers $plan 'b-wizard-result-approved.json') 'b-wizard-approved.out.txt' @{ BINGOO_WIZARD_TEST_SECRETS = '1'; BINGOO_WIZARD_SECRET_SETUP_CODE = $sc.Code }
    $wizSec = [int]((Get-Date) - $t0).TotalSeconds
    $r = Invoke-UpdateChecks 'headless Setup-BingooEdge.cmd -Headless' @{ plan_hash = $plan; wizard_exit_unapproved = $r1.Code; wizard_exit = $r2.Code; wizard_seconds = $wizSec }
    $r['verdict_ok'] = $r.verdict_ok -and $r2.Code -eq 0
    $r['plan'] = $plan
    $r['wizard_seconds'] = $wizSec
    return $r
}

# ---------------------------------------------------------- w) the REAL WPF wizard driven through UI Automation (update/fresh)
function Start-WizardWindow([string]$AnswersFile) {
    # Setup-BingooEdge.cmd exactly as the owner double-clicks it (elevation probe -> the WPF window); no -Headless
    # cmd /c strips the first and the LAST quote of a line with more than two quotes: both paths are space-free, pass them bare
    $cmd = Join-Path $Pkg090 'Setup-BingooEdge.cmd'
    if ("$cmd$AnswersFile" -match '\s') { throw "paths with spaces are not supported here: $cmd / $AnswersFile" }
    $p = Start-Process -FilePath 'cmd.exe' -ArgumentList ('/c ' + $cmd + ' -AnswersFile ' + $AnswersFile) -PassThru
    $script:WizardLauncherPid = $p.Id
    return (Find-UiWindow 240)
}
function Save-Shot($Window, [string]$Name, [string[]]$Mask = @()) {
    $path = Join-Path $Ev ('ui-' + $Name + '.png')
    try { [void](Save-UiShot $Window $path $Mask); [void]$script:Shots.Add($path) } catch { Write-Log 'warn' ("screenshot $Name failed: " + $_.Exception.Message) }
}
function Wait-UiChecksDone($Window) {
    $deadline = (Get-Date).AddSeconds(300)
    do { Start-Sleep -Seconds 2; $s = Get-UiStatus $Window } while ($s -ne 'Checks done.' -and (Get-Date) -lt $deadline)
    $checks = Get-UiChecks $Window
    $fails = @($checks.Keys | Where-Object { $checks[$_].status -eq 'FAIL' })
    return @{ Status = $s; Checks = $checks; Fails = $fails }
}
function Wait-UiProgress($Window, [string]$UntilPage, [int]$TimeoutSec, [string]$ShotPrefix) {
    # the Install / Update page streams the step log; returns when $UntilPage shows or the failure panel appears
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    $mid = $false
    $t0 = Get-Date
    do {
        Start-Sleep -Seconds 10
        $page = Get-UiPage $Window
        if ($page -eq $UntilPage) { return @{ Ok = $true; Page = $page; Log = (Get-UiListItems $Window 'LogList') } }
        $fail = Get-UiText $Window 'FailText'
        if ($fail) { Save-Shot $Window ($ShotPrefix + '-failed'); return @{ Ok = $false; Page = $page; Fail = $fail; Log = (Get-UiListItems $Window 'LogList') } }
        if (-not $mid -and ((Get-Date) - $t0).TotalSeconds -gt 120) { Save-Shot $Window ($ShotPrefix + '-progress'); $mid = $true }
    } while ((Get-Date) -lt $deadline)
    Save-Shot $Window ($ShotPrefix + '-timeout')
    return @{ Ok = $false; Page = (Get-UiPage $Window); Fail = "timeout after $TimeoutSec s"; Log = (Get-UiListItems $Window 'LogList') }
}
function Invoke-WizardUiUpdate {
    Save-BeforeUpdate
    $script:Shots = New-Object System.Collections.ArrayList
    $a = [ordered]@{ cloud_url = $CloudUrl; allow_http_cloud = $true; log_dir = (Join-Path $Ev 'wizard-logs'); update_public_key = $script:DevPublic
        mysql = [ordered]@{ base_dir = $Base }; services = [ordered]@{ register = $true; gateway = $true }; update = [ordered]@{ health_wait_seconds = 150 } }
    $af = Join-Path $Work 'answers-ui-update.json'
    [IO.File]::WriteAllText($af, ($a | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false)))
    $doc = [ordered]@{ pages = New-Object System.Collections.ArrayList }
    $w = Start-WizardWindow $af
    $t0 = Get-Date
    try {
        [void]$doc.pages.Add(@{ page = (Get-UiPage $w); text = (Get-UiText $w 'WelcomeText') }); Save-Shot $w 'update-01-welcome'
        Invoke-UiButton $w 'BtnNext'
        Wait-UiPage $w 'Check this PC' 60 | Out-Null
        $c = Wait-UiChecksDone $w
        [void]$doc.pages.Add(@{ page = 'Check this PC'; status = $c.Status; checks = $c.Checks }); Save-Shot $w 'update-02-checks'
        if ($c.Fails.Count -gt 0) { throw ('UI prerequisite check FAILED: ' + ($c.Fails -join ', ') + ' - Next stays disabled') }
        Invoke-UiButton $w 'BtnNext'
        Wait-UiPage $w 'Update plan' 60 | Out-Null
        $plan = Wait-Until { $t = Get-UiText $w 'UpdateText'; if ($t -and $t -ne 'Reading the update plan...') { $t } } 900 5 'the update plan text'
        [void]$doc.pages.Add(@{ page = 'Update plan'; text = $plan }); Save-Shot $w 'update-03-plan'
        if ($plan -match 'cannot be installed') { throw "UI update plan refused: $plan" }
        Set-UiChecked $w 'ChkApprove' $true
        Save-Shot $w 'update-04-approved'
        Invoke-UiButton $w 'BtnNext'
        Wait-UiPage $w 'Update' 60 | Out-Null
        Save-Shot $w 'update-05-updating'
        $p = Wait-UiProgress $w 'Finish' 3600 'update-06'
        $doc.progress_log = $p.Log
        if (-not $p.Ok) { throw ('UI update stopped: ' + $p.Fail) }
        $finish = Wait-Until { $t = Get-UiText $w 'FinishText'; if ($t) { $t } } 120 3 'the finish text'
        [void]$doc.pages.Add(@{ page = 'Finish'; text = $finish }); Save-Shot $w 'update-07-finish'
        $doc.finish = $finish
        Invoke-UiButton $w 'BtnNext'   # Close
        [void](Wait-Until { -not ($UiA::RootElement.FindFirst($UiTree::Children, (New-Object System.Windows.Automation.PropertyCondition($UiA::NameProperty, $script:UiWindowTitle)))) } 60 2 'the wizard window to close')
    } catch {
        $doc.error = $_.Exception.Message
        Save-Shot $w 'update-99-error'
        Close-UiWindow $w
    } finally {
        $doc.seconds = [int]((Get-Date) - $t0).TotalSeconds
        $doc.screenshots = @($script:Shots | ForEach-Object { Split-Path -Leaf $_ })
        Save-Evidence 'w-update-ui.json' $doc
    }
    $ok = -not $doc.Contains('error') -and [string]$doc.finish -like 'Updated*'
    return [ordered]@{ verdict_ok = $ok; seconds = $doc.seconds; finish = $doc.finish; error = $doc.error; screenshots = $doc.screenshots.Count; pointer = (Get-Pointer) }
}
function Invoke-WizardUiFresh {
    if (Test-Path (Join-Path $InstallRoot 'appliance.json')) { throw 'an appliance is already installed in this sandbox - use a NEW sandbox (BingooEdge-CP5-fresh .wsb)' }
    $script:Shots = New-Object System.Collections.ArrayList
    $users = Get-Users
    $a = [ordered]@{ cloud_url = $CloudUrl; allow_http_cloud = $true; device_name = 'CP5-SANDBOX-FRESH-UI'; log_dir = (Join-Path $Ev 'wizard-logs'); update_public_key = $script:DevPublic
        mysql = [ordered]@{ mode = 'install'; base_dir = $Base; port = 3306 }; certificates = [ordered]@{ mode = 'branch_ca' }
        lan = [ordered]@{ hostname = $LanHost; ip = $script:LanIp; https_port = $HttpsPort; http_port = 80 }
        services = [ordered]@{ register = $true; gateway = $true; firewall = $true; hosts_line = $true }; shortcuts = [ordered]@{ create = $true } }
    $af = Join-Path $Work 'answers-ui-fresh.json'
    [IO.File]::WriteAllText($af, ($a | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false)))
    $doc = [ordered]@{ pages = New-Object System.Collections.ArrayList; secret_entry = [ordered]@{} }
    $w = Start-WizardWindow $af
    $t0 = Get-Date
    try {
        Save-Shot $w 'fresh-01-welcome'
        Invoke-UiButton $w 'BtnNext'
        Wait-UiPage $w 'Check this PC' 60 | Out-Null
        $c = Wait-UiChecksDone $w
        [void]$doc.pages.Add(@{ page = 'Check this PC'; status = $c.Status; checks = $c.Checks }); Save-Shot $w 'fresh-02-checks'
        if ($c.Fails.Count -gt 0) { throw ('UI prerequisite check FAILED: ' + ($c.Fails -join ', ') + ' - Next stays disabled') }
        Invoke-UiButton $w 'BtnNext'
        Wait-UiPage $w 'Setup code' 60 | Out-Null
        Save-Shot $w 'fresh-03-code-empty'
        $sc = Get-SetupCode
        if ($sc.Mode -ne 'install') { throw "the Cloud answered setup mode [$($sc.Mode)], expected install" }
        Set-UiText $w 'TxtDevice' 'CP5-SANDBOX-FRESH-UI'
        Set-UiText $w 'TxtCode' $sc.Code
        $sc = $null
        Invoke-UiButton $w 'BtnRedeem'          # the wizard clears the code field on click; no screenshot while it is filled
        $red = Wait-Until { $t = Get-UiText $w 'RedeemText'; if ($t) { $t } } 120 2 'the redeemed business / branch'
        [void]$doc.pages.Add(@{ page = 'Setup code'; redeemed = $red; status = (Get-UiStatus $w) }); Save-Shot $w 'fresh-04-code-accepted' @('TxtCode')
        Set-UiChecked $w 'ChkRedeemOk' $true
        Invoke-UiButton $w 'BtnNext'
        Wait-UiPage $w 'Passwords' 60 | Out-Null
        $root = Get-Secret 'mysql-root' { New-SecretValue }
        $doc.secret_entry.root = Set-UiSecret $w 'PwRoot' $root
        [void](Set-UiSecret $w 'PwRoot2' $root)
        $root = $null
        Set-UiText $w 'TxtIp' $script:LanIp; Set-UiText $w 'TxtHost' $LanHost; Set-UiText $w 'TxtPort' ([string]$HttpsPort)
        Save-Shot $w 'fresh-05-passwords' @('PwRoot', 'PwRoot2')
        Invoke-UiButton $w 'BtnNext'   # Install
        Wait-UiPage $w 'Install' 60 | Out-Null
        Save-Shot $w 'fresh-06-installing'
        $p = Wait-UiProgress $w 'Users and PINs' 3600 'fresh-07'
        $doc.progress_log = $p.Log
        if (-not $p.Ok) { throw ('UI install stopped: ' + $p.Fail) }
        Save-Shot $w 'fresh-08-users'
        foreach ($u in $users) {
            if (-not (Find-Ui $w ('UserPin_' + $u.id) 5 -Optional)) { $doc.secret_entry[('user_' + $u.id)] = 'no row (no employee code?)'; continue }
            $pin = (Get-Content -Raw (Join-Path $Secrets ('pin-' + $u.id))).Trim()
            $doc.secret_entry[('user_' + $u.id)] = Set-UiSecret $w ('UserPin_' + $u.id) $pin
            [void](Set-UiSecret $w ('UserPin2_' + $u.id) $pin)
            Set-UiChecked $w ('UserChk_' + $u.id) $true
        }
        $pin = $null
        Invoke-UiButton $w 'BtnEnroll'
        [void](Wait-Until { $s = Get-UiStatus $w; if ($s -like 'PINs saved*' -or $s -like 'PINs not set*') { $s } } 600 3 'the PIN result')
        $res = [ordered]@{}
        foreach ($u in $users) { $res[$u.code] = Get-UiText $w ('UserResult_' + $u.id) }
        [void]$doc.pages.Add(@{ page = 'Users and PINs'; status = (Get-UiStatus $w); results = $res }); Save-Shot $w 'fresh-09-pins-set'
        Invoke-UiButton $w 'BtnNext'
        Wait-UiPage $w 'Finish' 60 | Out-Null
        $finish = Wait-Until { $t = Get-UiText $w 'FinishText'; if ($t -and $t -notlike 'Finishing*') { $t } } 600 3 'the finish text'
        [void]$doc.pages.Add(@{ page = 'Finish'; text = $finish }); Save-Shot $w 'fresh-10-finish'
        $doc.finish = $finish
        $doc.pin_results = $res
        Invoke-UiButton $w 'BtnNext'
    } catch {
        $doc.error = $_.Exception.Message
        Save-Shot $w 'fresh-99-error'
        Close-UiWindow $w
    } finally {
        $doc.seconds = [int]((Get-Date) - $t0).TotalSeconds
        $doc.screenshots = @($script:Shots | ForEach-Object { Split-Path -Leaf $_ })
        Save-Evidence 'w-fresh-ui.json' $doc
    }
    $pinsOk = $doc.Contains('pin_results') -and -not (@($doc.pin_results.Values) | Where-Object { $_ -ne 'PIN set' })
    $ok = -not $doc.Contains('error') -and [string]$doc.finish -like '*WARM STANDBY*' -and $pinsOk
    return [ordered]@{ verdict_ok = $ok; seconds = $doc.seconds; finish = $doc.finish; pins = $doc.pin_results; error = $doc.error; screenshots = $doc.screenshots.Count; pointer = (Get-Pointer) }
}

function Invoke-WizardUpdateManual {
    $sc = Get-SetupCode
    $answers = New-UpdateAnswers $null 'b-wizard-result-manual.json'
    Write-Host ''
    Write-Host '==================== OWNER: click through the Bingoo Edge setup wizard (UPDATE) ====================' -ForegroundColor Yellow
    Write-Host ('  Setup code (type it when the wizard asks; valid about 60 minutes):   ' + $sc.Code) -ForegroundColor Yellow
    Write-Host '  Approve the migration plan the wizard shows, keep "register services" on, finish, close the wizard.' -ForegroundColor Yellow
    Write-Host '  Then double-click "CP5 continue (c-e)" on the sandbox desktop.' -ForegroundColor Yellow
    Write-Host '=====================================================================================================' -ForegroundColor Yellow
    $sc = $null
    $cont = Join-Path ([Environment]::GetFolderPath('Desktop')) 'CP5 continue (c-e).cmd'
    [IO.File]::WriteAllText($cont, "@echo off`r`ncall C:\cp5\kit\Start-Cp5.cmd continue`r`n", (New-Object Text.ASCIIEncoding))
    Start-Process -FilePath 'cmd.exe' -ArgumentList ('/c ' + (Join-Path $Pkg090 'Setup-BingooEdge.cmd') + ' -AnswersFile ' + $answers)   # space-free paths, see Start-WizardWindow
    return [ordered]@{ launched = 'Setup-BingooEdge.cmd (WPF) with the rehearsal answers (DEV update key, HTTP Cloud)'; continue_shortcut = $cont }
}

# ------------------------------------------------------------------- c/d) auto-update with REAL task control + lease watch
function Invoke-UpdaterRun([string]$Tag, [string]$PkgDir, [string]$ExpectPointer) {
    $pub = Invoke-Cloud @('artisan', 'edge:release:publish', (ConvertTo-Fwd $PkgDir), '--json') 3600 ($Tag + '-publish.json')
    [void](Invoke-Cloud @('window-now') 300 ($Tag + '-window.json'))
    $before = Get-ApplianceSnapshot ('before-' + $Tag)
    $stop = Join-Path $Work ($Tag + '-watch.stop')
    Remove-Item -Force $stop -ErrorAction SilentlyContinue
    $watch = Start-LeaseWatch $Tag $stop
    Start-Sleep -Seconds 8
    $t0 = Get-Date
    Write-Log 'info' "$Tag : Start-ScheduledTask BingooEdgeUpdater (runs as its own principal, LOCAL SERVICE)"
    Start-ScheduledTask -TaskName 'BingooEdgeUpdater'
    $deadline = $t0.AddMinutes(60)
    $seenRunning = $false
    $lastPtr = Get-Pointer
    do {
        Start-Sleep -Seconds 5
        $st = [string](Get-ScheduledTask -TaskName 'BingooEdgeUpdater').State
        if ($st -eq 'Running') { $seenRunning = $true }
        $p = Get-Pointer
        if ($p -ne $lastPtr) { Write-Log 'info' "$Tag : pointer $lastPtr -> $p"; $lastPtr = $p }
        $info = Get-ScheduledTaskInfo -TaskName 'BingooEdgeUpdater'
        $done = ($seenRunning -or ($info.LastRunTime -ge $t0.AddSeconds(-2))) -and $st -ne 'Running'
    } while (-not $done -and (Get-Date) -lt $deadline)
    $updaterSec = [int]((Get-Date) - $t0).TotalSeconds
    Write-Log 'info' "$Tag : updater finished after $updaterSec s (last result $($info.LastTaskResult)); watching 90 s more"
    Start-Sleep -Seconds 90
    Set-Content -Path $stop -Value 'stop'
    $watch.WaitForExit(60000) | Out-Null
    $lease = Get-Content -Raw (Join-Path $Ev ($Tag + '-lease.summary.json')) | ConvertFrom-Json
    $status = Get-LastJson (Invoke-Edge @('edge:local:auto-update', '--status', '--json')).Out
    $after = Get-ApplianceSnapshot ('after-' + $Tag)
    $logins = Test-PinLogins 'http://127.0.0.1:8090'
    $restarted = @($after.tasks | Where-Object { $_.name -ne 'BingooEdgeUpdater' -and $_.last_run -and ([datetime]$_.last_run) -ge $t0 } | ForEach-Object { $_.name })
    Save-Evidence ($Tag + '-state.json') ([ordered]@{ publish = (Get-LastJson $pub.Out); updater_seconds = $updaterSec; updater_last_result = $info.LastTaskResult
        auto_update_status = $status; lease = $lease; before = $before; after = $after; tasks_restarted_by_updater = $restarted; pin_logins_loopback = $logins; cloud_device = (Get-CloudDevice) })
    $running = @($after.tasks | Where-Object { $_.state -eq 'Running' } | ForEach-Object { $_.name })
    return [ordered]@{ pointer = $after.pointer; expected_pointer = $ExpectPointer; updater_seconds = $updaterSec; lease_verdict = $lease.verdict
        max_heartbeat_gap_s = $lease.cloud_heartbeats.max_gap_s; lease_ttl_s = $lease.lease_ttl_s; min_lease_remaining_s = $lease.min_lease_remaining_s; fenced = $lease.fenced_at_seen
        tasks_restarted = $restarted; tasks_running = $running; health = $after.status; logins_ok = -not (@($logins.Values) | Where-Object { -not $_.ok })
        last_result = $(if ($status) { $status.last_result } else { $null }) }
}

# --------------------------------------------------------------------------------------------- e) counter wizard (stream D)
function Invoke-CounterScenario {
    $dir = Join-Path $Work 'counter'
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    $file = Join-Path $dir 'Install-Offline-Counter-CP5.ps1'
    $r = Invoke-Cloud @('counter-wizard', $file) 300 'e-counter-wizard-render.json'
    Copy-Item $file (Join-Path $Ev 'e-Install-Offline-Counter-CP5.ps1')   # public data only (no self-enrollment requested)
    $pre = [bool](Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $script:CaThumbprint })
    if ($pre) {
        Write-Log 'warn' 'the branch CA was already in LocalMachine\Root before the counter wizard - removed first so the confirmation gate is exercised'
        Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $script:CaThumbprint } | Remove-Item
    }
    $h = Invoke-Logged 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-STA', '-File', (Join-Path $Kit 'Test-Cp5CounterWizard.ps1'), '-WizardFile', $file, '-Evidence', $Ev) (Join-Path $Ev 'e-counter-harness.log') 900
    $steps = Get-Content -Raw (Join-Path $Ev 'counter-steps.json') | ConvertFrom-Json
    # PIN logins THROUGH THE GATEWAY with full TLS verification against the branch CA only: by name (SNI + name check) and by IP
    $byName = Test-PinLogins ("https://${LanHost}:$HttpsPort") $script:CaPem ("${LanHost}:${HttpsPort}:" + $script:LanIp)
    $byIp = Test-PinLogins ("https://" + $script:LanIp + ":$HttpsPort") $script:CaPem
    Save-Evidence 'e-gateway-pin-logins.json' ([ordered]@{ ca_was_in_root_before = $pre; by_name = $byName; by_ip = $byIp })
    $desk = [Environment]::GetFolderPath('Desktop')
    [IO.File]::WriteAllText((Join-Path $desk 'Counter wizard (by hand).cmd'), ("@echo off`r`npowershell.exe -NoProfile -ExecutionPolicy Bypass -STA -File `"" + $file + "`"`r`n"), (New-Object Text.ASCIIEncoding))
    $loginsOk = -not (@($byName.Values) + @($byIp.Values) | Where-Object { -not ($_.ok -and $_.tls.verified) })
    return [ordered]@{ verdict_ok = ([bool]$steps.verdict.all -and $loginsOk); harness_exit = $h.Code; verdict = $steps.verdict; gateway_pin_logins_full_tls = $loginsOk; ca_was_in_root_before = $pre
        by_hand = 'desktop: Counter wizard (by hand).cmd (Install / Repair / Remove in the WPF window)' }
}

# ------------------------------------------------------------------------------------- f) 0.9 wizard FRESH INSTALL headless
function Invoke-FreshInstall {
    if (Test-Path (Join-Path $InstallRoot 'appliance.json')) { throw 'an appliance is already installed in this sandbox - use a NEW sandbox (BingooEdge-CP5-fresh.wsb)' }
    $sc = Get-SetupCode
    if ($sc.Mode -ne 'install') { throw "the Cloud answered setup mode [$($sc.Mode)], expected install" }
    $users = Get-Users
    $a = [ordered]@{ cloud_url = $CloudUrl; allow_http_cloud = $true; device_name = 'CP5-SANDBOX-FRESH-1'; log_dir = (Join-Path $Ev 'wizard-logs'); update_public_key = $script:DevPublic
        mysql = [ordered]@{ mode = 'install'; base_dir = $Base; port = 3306 }; certificates = [ordered]@{ mode = 'branch_ca' }
        lan = [ordered]@{ hostname = $LanHost; ip = $script:LanIp; https_port = $HttpsPort; http_port = 80 }
        services = [ordered]@{ register = $true; gateway = $true; firewall = $true; hosts_line = $true }
        prerequisites = [ordered]@{ fix_clock = $false; fix_sleep = $false; fix_network_private = $false }
        users = [ordered]@{ enroll = 'all' }; shortcuts = [ordered]@{ create = $true }; rollback_on_failure = $true; result_file = (Join-Path $Ev 'f-wizard-result.json') }
    $af = Join-Path $Work 'answers-fresh.json'
    [IO.File]::WriteAllText($af, ($a | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false)))
    $envs = @{ BINGOO_WIZARD_TEST_SECRETS = '1'; BINGOO_WIZARD_SECRET_SETUP_CODE = $sc.Code
        BINGOO_WIZARD_SECRET_MYSQL_ROOT = (Get-Secret 'mysql-root' { New-SecretValue }); BINGOO_WIZARD_SECRET_DB_PASSWORD = (Get-Secret 'mysql-edge' { New-SecretValue }) }
    foreach ($u in $users) { $envs[('BINGOO_WIZARD_SECRET_PIN_' + $u.id)] = (Get-Content -Raw (Join-Path $Secrets ('pin-' + $u.id))).Trim() }
    $t0 = Get-Date
    $r = Invoke-SetupCmd $af 'f-wizard.out.txt' $envs
    $sec = [int]((Get-Date) - $t0).TotalSeconds
    $res = Invoke-FreshChecks 'headless Setup-BingooEdge.cmd -Headless' $sec
    $res['verdict_ok'] = $res.verdict_ok -and $r.Code -eq 0
    $res['wizard_exit'] = $r.Code
    return $res
}
function Invoke-FreshChecks([string]$Via, [int]$Sec) {
    Start-Sleep -Seconds 20
    $after = Get-ApplianceSnapshot 'after-fresh-install'
    $dev = Get-CloudDevice
    $caFile = Join-Path $DataRoot 'ca\bingoo-edge-branch-ca.crt'
    $byIp = $null
    if (Test-Path $caFile) {
        $ca = New-Object Security.Cryptography.X509Certificates.X509Certificate2($caFile)
        $script:CaPem = Join-Path $Work 'branch-ca-fresh.pem'
        [IO.File]::WriteAllText($script:CaPem, ("-----BEGIN CERTIFICATE-----`n" + ([Convert]::ToBase64String($ca.RawData, 'InsertLineBreaks') -replace "`r", '') + "`n-----END CERTIFICATE-----`n"), (New-Object Text.ASCIIEncoding))
        $byIp = Test-PinLogins ("https://" + $script:LanIp + ":$HttpsPort") $script:CaPem
    }
    $loop = Test-PinLogins 'http://127.0.0.1:8090'
    $updater = @($after.tasks | Where-Object { $_.name -eq 'BingooEdgeUpdater' }).Count -eq 1
    Save-Evidence 'f-state-fresh.json' ([ordered]@{ via = $Via; wizard_seconds = $Sec; after = $after; cloud_device = $dev; pin_logins_loopback = $loop; pin_logins_gateway_by_ip = $byIp })
    $gwOk = $byIp -and -not (@($byIp.Values) | Where-Object { -not ($_.ok -and $_.tls.verified) })
    $ok = $after.pointer -eq '0.9.0-dev' -and $dev.device.lan_ca_pem_present -and $updater -and -not (@($loop.Values) | Where-Object { -not $_.ok }) -and $gwOk
    return [ordered]@{ verdict_ok = $ok; via = $Via; wizard_seconds = $Sec; pointer = $after.pointer; status = $after.status; k5_ca_on_cloud = [bool]$dev.device.lan_ca_pem_present
        gateway_pin_logins_full_tls = [bool]$gwOk; tasks = @($after.tasks | ForEach-Object { $_.name + '=' + $_.state }) }
}

# ------------------------------------------------------------------------- g) optional Automatic Offline Takeover (sandbox)
function Get-TakeoverFacts {
    $m = Get-EdgeMeta
    $h = Get-Health
    $at = $h.auto_takeover
    return [ordered]@{ at = (Get-Date).ToUniversalTime().ToString('s'); state = [string]$m.authority_state; last_ack = $m.authority_last_ack_at; failures = $m.heartbeat_consecutive_failures
        takeover_at = $m.authority_takeover_at; skew = $m.authority_cloud_skew_seconds; decision = $m.auto_takeover_last_decision; reasons = $m.auto_takeover_last_reasons
        health_auto_takeover = $at }
}
function Set-AutoTakeover([bool]$CloudOn, [bool]$ApplianceAllowed, [int]$After = 180) {
    [void](Invoke-Cloud @('auto-takeover', $(if ($CloudOn) { 'on' } else { 'off' }), [string]$After))
    Set-ApplianceEnv 'EDGE_AUTO_TAKEOVER_ALLOWED' $(if ($ApplianceAllowed) { 'true' } else { 'false' })
    Restart-EdgeTask 'BingooEdgeAuthorityWorker'
    # the Cloud switch reaches the appliance with the next config revision (heartbeat ack -> config refresh)
    [void](Wait-Until {
            $h = Get-Health
            $s = $h.auto_takeover   # flat: cloud_enabled, allowed, enabled, after_seconds, last_decision, last_reasons, cloud_skew_seconds
            if ($s -and [bool]$s.cloud_enabled -eq $CloudOn -and [bool]$s.allowed -eq $ApplianceAllowed) { $s }
        } 300 10 "auto-takeover settings cloud=$CloudOn appliance=$ApplianceAllowed on the appliance")
    Write-Log 'info' "auto-takeover: Cloud setting $CloudOn (after $After s), appliance switch $ApplianceAllowed"
}
function Wait-CloudAck([int]$TimeoutSec = 180) {
    $t = (Get-Date).ToUniversalTime()
    [void](Wait-Until { $m = Get-EdgeMeta; if ($m.authority_last_ack_at -and ([datetime]::SpecifyKind([datetime]$m.authority_last_ack_at, 'Utc')) -gt $t -and [int]$m.heartbeat_consecutive_failures -eq 0) { $m } } $TimeoutSec 5 'a fresh Cloud acknowledgement')
}
function Wait-HandbackQuiet {
    # the evaluator refuses an automatic takeover within 15 min of a handback (RECENT_HANDBACK); wait it out when needed
    $j = Invoke-Sql 'edge' "SELECT MAX(occurred_at) AS last_handback FROM edge_local_connection_transitions WHERE from_state = 'handing_back'"
    $last = $(if ($j -and $j.rows) { @($j.rows)[0].last_handback } else { $null })
    if (-not $last) { return 0 }
    $left = 900 - [int]((Get-Date).ToUniversalTime() - [datetime]::SpecifyKind([datetime]$last, 'Utc')).TotalSeconds + 20
    if ($left -gt 0) { Write-Log 'info' "last handback at $last UTC - waiting $left s (RECENT_HANDBACK guard)"; Start-Sleep -Seconds $left; return $left }
    return 0
}
function Invoke-G1 {
    Confirm-CloudUp
    Set-AutoTakeover $false $false 180
    $stop = Join-Path $Work 'g1-watch.stop'; $watch = Start-LeaseWatch 'g1' $stop 1800
    $samples = New-Object System.Collections.ArrayList
    Stop-CloudPhp
    $t0 = Get-Date
    try {
        while (((Get-Date) - $t0).TotalSeconds -lt 300) { Start-Sleep -Seconds 30; [void]$samples.Add((Get-TakeoverFacts)) }
    } finally { Start-CloudPhp }
    Wait-CloudAck
    Stop-LeaseWatch $watch $stop
    Save-Evidence 'g1-off.json' ([ordered]@{ cloud_down_seconds = 300; samples = $samples })
    $neverTook = -not ($samples | Where-Object { $_.state -ne 'standby' })
    $last = $samples[$samples.Count - 1]
    $shownOff = [string]$last.health_auto_takeover.last_decision -eq 'disabled' -and -not [bool]$last.health_auto_takeover.enabled
    return [ordered]@{ verdict_ok = ($neverTook -and $shownOff); stayed_standby = $neverTook; health_shows_off = $shownOff; last_decision = $last.decision; last_reasons = $last.reasons }
}
function Invoke-G3 {
    Set-AutoTakeover $true $true 180
    $stop = Join-Path $Work 'g3-watch.stop'; $watch = Start-LeaseWatch 'g3' $stop 3600
    $samples = New-Object System.Collections.ArrayList
    for ($i = 1; $i -le 5; $i++) {
        Stop-CloudPhp
        $t = Get-Date
        while (((Get-Date) - $t).TotalSeconds -lt 60) { Start-Sleep -Seconds 15; [void]$samples.Add((Get-TakeoverFacts)) }
        Start-CloudPhp
        Start-Sleep -Seconds 45
        [void]$samples.Add((Get-TakeoverFacts))
    }
    Wait-CloudAck
    Stop-LeaseWatch $watch $stop
    Save-Evidence 'g3-flapping.json' ([ordered]@{ cycles = 5; down_s = 60; up_s = 45; samples = $samples })
    $took = @($samples | Where-Object { $_.state -ne 'standby' }).Count
    $decisions = @($samples | ForEach-Object { [string]$_.decision } | Select-Object -Unique)
    return [ordered]@{ verdict_ok = ($took -eq 0 -and $decisions -notcontains 'take_over'); stayed_standby = ($took -eq 0); decisions_seen = $decisions
        reasons_seen = @($samples | ForEach-Object { [string]$_.reasons } | Where-Object { $_ } | Select-Object -Unique) }
}
function Invoke-G4 {
    # a REAL measured skew: only the Cloud process runs 45 s ahead; the appliance measures it from the heartbeat ack server_time
    Stop-CloudPhp
    Start-CloudPhp -ClockOffsetSec 45
    $skewed = Wait-Until { $m = Get-EdgeMeta; if ($m.authority_cloud_skew_seconds -ne $null -and [math]::Abs([int]$m.authority_cloud_skew_seconds) -gt 10) { $m } } 180 5 'a measured Cloud clock skew above 10 s'
    $stop = Join-Path $Work 'g4-watch.stop'; $watch = Start-LeaseWatch 'g4' $stop 1800
    Stop-CloudPhp
    $samples = New-Object System.Collections.ArrayList
    $t0 = Get-Date
    try {
        while (((Get-Date) - $t0).TotalSeconds -lt 300) { Start-Sleep -Seconds 20; [void]$samples.Add((Get-TakeoverFacts)) }
    } finally { Start-CloudPhp }
    Wait-CloudAck
    $after = Get-EdgeMeta
    Stop-LeaseWatch $watch $stop
    Save-Evidence 'g4-skew.json' ([ordered]@{ measured_skew_s = $skewed.authority_cloud_skew_seconds; how = 'Cloud php started with CP5_CLOUD_CLOCK_OFFSET=45 (Cloud clock only)'; samples = $samples; skew_after_restore = $after.authority_cloud_skew_seconds })
    $took = @($samples | Where-Object { $_.state -ne 'standby' }).Count
    $refusedSkew = [bool](@($samples | Where-Object { [string]$_.decision -eq 'refused' -and [string]$_.reasons -match 'CLOCK_SKEW_TOO_LARGE' }).Count)
    return [ordered]@{ verdict_ok = ($took -eq 0 -and $refusedSkew); measured_skew_s = $skewed.authority_cloud_skew_seconds; refused_for_skew = $refusedSkew; stayed_standby = ($took -eq 0)
        skew_after_restore = $after.authority_cloud_skew_seconds }
}
function Invoke-G2 {
    $waited = Wait-HandbackQuiet
    Set-AutoTakeover $true $true 180
    Wait-CloudAck
    $before = Get-TakeoverFacts
    if ($before.state -ne 'standby') { throw "not in standby before g2 ($($before.state))" }
    $stop = Join-Path $Work 'g2-watch.stop'; $watch = Start-LeaseWatch 'g2' $stop 3600
    Stop-CloudPhp
    $tStop = (Get-Date).ToUniversalTime()
    $samples = New-Object System.Collections.ArrayList
    $took = $null
    try {
        $took = Wait-Until { $f = Get-TakeoverFacts; [void]$samples.Add($f); if ($f.state -eq 'local_active') { $f } } 600 10 'the automatic takeover (local_active)'
    } catch { Start-CloudPhp; Stop-LeaseWatch $watch $stop; Save-Evidence 'g2-takeover.json' ([ordered]@{ before = $before; samples = $samples; error = $_.Exception.Message }); throw }
    $lease = $meta = $afterReconnect = $leaseAfter = $final = $leaseFinal = $null; $audit = @()
    $hb = New-Object System.Collections.ArrayList
    try {   # a fault after the takeover must still bring the Cloud back and stop the watch (run 7 left both behind)
        $lease = Get-CloudLease   # read from the Cloud DB while the Cloud php is still down
        $meta = Get-EdgeMeta
        $audit = @(@(Get-ChildItem (Join-Path $DataRoot 'logs') -Filter 'edge-*.log' -ErrorAction SilentlyContinue | ForEach-Object { Read-SharedLines $_.FullName } | Where-Object { $_ -match 'edge-auto-takeover' }) | Select-Object -Last 12 | ForEach-Object { [string]$_ })
        Start-CloudPhp
        Start-Sleep -Seconds 120
        $afterReconnect = Get-TakeoverFacts
        $leaseAfter = Get-CloudLease
        # supervised handback (an operator decision; never automatic)
        for ($i = 0; $i -lt 8; $i++) {
            $r = Invoke-Edge @('edge:local:authority-handback', '--by=cp5-supervisor') 300
            $st = [string](Get-EdgeMeta).authority_state
            [void]$hb.Add([ordered]@{ attempt = $i + 1; exit = $r.Code; output = ($r.Out + $r.Err).Trim(); state = $st })
            if ($st -eq 'standby') { break }
            Start-Sleep -Seconds 30
        }
        Start-Sleep -Seconds 30
        $final = Get-TakeoverFacts
        $leaseFinal = Get-CloudLease
    } catch {
        $err = $_.Exception.Message
        if (-not (Wait-Port $CloudPort 1)) { Start-CloudPhp }
        Stop-LeaseWatch $watch $stop
        Save-Evidence 'g2-takeover.json' ([ordered]@{ before = $before; cloud_stopped_at = $tStop.ToString('s'); takeover = $took; meta_at_takeover = $meta; cloud_lease_at_takeover = $lease
                audit_lines = $audit; after_reconnect = $afterReconnect; cloud_lease_after_reconnect = $leaseAfter; handback = $hb; error = $err })
        throw
    }
    Stop-LeaseWatch $watch $stop
    Set-AutoTakeover $false $false 180
    $ttl = [int]$meta.authority_lease_ttl_seconds
    $lastAck = [datetime]::SpecifyKind([datetime]$meta.authority_last_ack_at, 'Utc')
    $takeAt = [datetime]::SpecifyKind([datetime]$meta.authority_takeover_at, 'Utc')
    $expires = [datetime]::SpecifyKind([datetime]$lease.expires_at, 'Utc')
    $gap = [int]($takeAt - $lastAck).TotalSeconds
    $doc = [ordered]@{ handback_quiet_wait_s = $waited; before = $before; cloud_stopped_at = $tStop.ToString('s'); takeover = $took; meta_at_takeover = $meta; cloud_lease_at_takeover = $lease
        audit_lines = $audit; after_reconnect = $afterReconnect; cloud_lease_after_reconnect = $leaseAfter; handback = $hb; final = $final; cloud_lease_final = $leaseFinal
        checks = [ordered]@{ seconds_last_ack_to_takeover = $gap; lease_ttl_s = $ttl; required_min_s = [math]::Max($ttl + 30, 180)
            lease_expired_before_takeover = ($expires -le $takeAt); cloud_lease_expires_at = $lease.expires_at; takeover_at = $meta.authority_takeover_at } }
    Save-Evidence 'g2-takeover.json' $doc
    $ok = $gap -ge [math]::Max($ttl + 30, 180) -and ($expires -le $takeAt) -and [string]$meta.authority_state_reason -match 'auto-takeover' -and $audit.Count -gt 0 -and
        $afterReconnect.state -eq 'local_active' -and [string]$leaseAfter.holder -eq 'edge' -and $final.state -eq 'standby' -and [string]$leaseFinal.holder -eq 'cloud'
    return [ordered]@{ verdict_ok = $ok; seconds_last_ack_to_takeover = $gap; lease_expired_before_takeover = ($expires -le $takeAt); reason = $meta.authority_state_reason
        holder_after_reconnect = $leaseAfter.holder; state_after_reconnect = $afterReconnect.state; handback_attempts = $hb.Count; final_state = $final.state; final_holder = $leaseFinal.holder }
}

# ------------------------------------------------------------------------------- s / h) the scenarios of agents D and A
function Confirm-CloudUp {
    # s stops the in-sandbox Cloud php on purpose (its test 7); h restarts it itself and records the new pid in cp5-run.json
    if (-not (Wait-Port $CloudPort 1)) {
        Write-Log 'info' 'the Cloud php is down (stopped by a previous scenario) - restarting it'
        Start-CloudPhp
        Wait-CloudAck 240
    }
}
function Invoke-ScenarioS {
    # Agent D's own STA process (headless Edge / cookie driver + the REAL counter wizard window); exit 0 = every verdict true
    $p = Join-Path $Kit 'Invoke-Cp5CounterSelfService.ps1'
    if (-not (Test-Path $p)) { throw 'NOT_DELIVERED: Invoke-Cp5CounterSelfService.ps1 is not in the kit' }
    $r = Invoke-Logged 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-STA', '-File', $p, '-Evidence', $Ev, '-LanIp', $script:LanIp,
        '-Kit', $Kit, '-Pkgs', $Pkgs, '-Work', $Work, '-CloudPhp', $CloudPhp, '-CloudIni', $CloudIni, '-LanHost', $LanHost, '-HttpsPort', [string]$HttpsPort) (Join-Path $Ev 's-driver.log') 3600
    # s changed the cashier's PIN (secrets\pin-<id>) and keeps its own s-*.keep secrets: every short secret file joins the
    # redaction list of the final evidence scan
    foreach ($f in @(Get-ChildItem $Secrets -File | Where-Object { $_.Length -gt 0 -and $_.Length -lt 256 -and ($_.Name -like 'pin-*' -or $_.Name -like '*.keep') })) {
        [void]$script:SecretValues.Add((Get-Content -Raw $f.FullName).Trim())
    }
    $sf = Join-Path $Ev 's-selfservice.json'
    $j = $(if (Test-Path $sf) { Get-Content -Raw $sf | ConvertFrom-Json } else { $null })
    Confirm-CloudUp
    return [ordered]@{ verdict_ok = ($r.Code -eq 0 -and $j -and [bool]$j.verdict.all); exit = $r.Code; web_driver = $(if ($j) { $j.web_driver } else { $null })
        verdict = $(if ($j) { $j.verdict } else { 's-selfservice.json missing' }) }
}
function Invoke-ScenarioH {
    $p = Join-Path $Kit 'Invoke-Cp5Continuity.ps1'
    if (-not (Test-Path $p)) { throw 'NOT_DELIVERED: Invoke-Cp5Continuity.ps1 is not in the kit' }
    Confirm-CloudUp
    # h is a SUPERVISED takeover: the optional automatic takeover must be OFF (if it fired first, h's
    # adoption_audited_supervised check fails by design)
    $at = (Get-Health).auto_takeover
    if ([bool]$at.enabled -or [bool]$at.cloud_enabled -or [bool]$at.allowed) { Set-AutoTakeover $false $false 180 }
    Write-Log 'info' ('automatic takeover before h: cloud=' + [bool]$at.cloud_enabled + ' appliance=' + [bool]$at.allowed + ' (OFF required)')
    $res = & $p -Evidence $Ev -LanIp $script:LanIp -CaPem $script:CaPem -Kit $Kit -Work $Work -CloudSrc $CloudSrc -CloudPhp $CloudPhp -CloudIni $CloudIni `
        -LanHost $LanHost -HttpsPort $HttpsPort -CloudPort $CloudPort -CloudDbPort $CloudDbPort
    if ($res -is [array]) { $res = @($res | Where-Object { $_ -is [Collections.IDictionary] }) | Select-Object -Last 1 }
    Confirm-CloudUp
    if (-not ($res -is [Collections.IDictionary])) { throw 'h returned no result dictionary' }
    return $res
}

# ---------------------------------------------------------------------------------- evidence hygiene + summary (always)
function Protect-Evidence {
    $hits = 0
    $vals = @($script:SecretValues | Where-Object { $_ -and $_.Length -ge 6 } | Select-Object -Unique)
    foreach ($f in @(Get-ChildItem -Path $Ev -Recurse -File | Where-Object { @('.png', '.zip', '.snap', '.enc') -notcontains $_.Extension })) {
        $t = Read-Shared $f.FullName
        $n = $t
        foreach ($v in $vals) { if ($n.Contains($v)) { $hits++; $n = $n.Replace($v, '<REDACTED>') } }
        # signed download URLs (the update status carries one, valid ~15 min): the capability part goes, the URL stays readable
        $rx = '(?i)((?:[?&]|\\u0026)(?:signature|token)=)[0-9A-Za-z%._~-]{16,}'
        $m = [regex]::Matches($n, $rx).Count
        if ($m) { $script:SignedUrlRedactions += $m; $n = [regex]::Replace($n, $rx, '$1<REDACTED>') }
        if ($n -ne $t) { Write-MappedFile $f.FullName $n }
    }
    return $hits
}
$script:SignedUrlRedactions = 0

# ================================================================================================================ main
Write-Log 'step' "Bingoo Edge CP5 sandbox driver - scenario $Scenario - evidence $Ev"
if ($CloudSmoke) {
    try {
        Invoke-Scenario 'cloud' 'HOST pre-flight: the sandbox Cloud (package php + package MySQL, read-only tree)' @() { Start-Cloud }
        Invoke-Scenario 'cloud-commands' 'HOST pre-flight: setup code + public keys + device + auto-takeover switch + SQL probe + clock offset' @('cloud') {
            $sc = Get-SetupCode
            $sc.Code = $null
            $on = Get-LastJson (Invoke-Cloud @('auto-takeover', 'on', '180')).Out
            $off = Get-LastJson (Invoke-Cloud @('auto-takeover', 'off', '180')).Out
            $sql = Invoke-Sql 'cloud' 'SELECT id, name, edge_auto_takeover_enabled, edge_auto_takeover_after_seconds FROM branches ORDER BY id LIMIT 1'
            Stop-CloudPhp
            Start-CloudPhp -ClockOffsetSec 45
            $hdr = (Invoke-WebRequest -UseBasicParsing -Uri ($CloudUrl + '/up') -TimeoutSec 60).Headers['Date']
            $skew = [int]([datetime]::Parse($hdr).ToUniversalTime() - (Get-Date).ToUniversalTime()).TotalSeconds
            Stop-CloudPhp
            Start-CloudPhp
            [ordered]@{ setup_mode = $sc.Mode; device = (Get-CloudDevice); auto_takeover_on = $on.after; auto_takeover_off = $off.after; branch_row = $sql.rows; offset_date_header_skew_s = $skew }
        }
    } finally {
        if (Test-Path $RunFile) {
            $rs = Get-Content -Raw $RunFile | ConvertFrom-Json
            if ($KeepCloud) { Write-Log 'info' "Cloud kept running: php pid $($rs.cloud_php_pid), mysqld pid $($rs.cloud_mysqld_pid)" }
            else { foreach ($p in @($rs.cloud_php_pid, $rs.cloud_mysqld_pid)) { if ($p) { Stop-OwnTree ([int]$p) } } }
        }
        [void](Protect-Evidence)
        Save-Evidence 'summary.json' ([ordered]@{ scenario = 'cloud-smoke'; results = $script:Results })
    }
    return
}
try {
    if ($Scenario -ne 'continue') {
        Invoke-Scenario 'prereqs' 'sandbox prerequisites (admin, VC++ runtime, LAN IP)' @() { Invoke-Prereqs }
        Invoke-Scenario 'cloud' 'disposable Cloud inside the sandbox (MySQL 3407 + php -S 9831)' @('prereqs') { Start-Cloud }
    } else {
        $script:LanIp = [string]$prevRun.lan_ip
        $script:EnrollmentPublic = [string](Get-LastJson (Invoke-Cloud @('public-keys')).Out).enrollment_public
        $script:DevPublic = [string](Get-Content -Raw (Join-Path $Kit 'dev-public-facts.json') | ConvertFrom-Json).public_key
        foreach ($u in (Get-Users)) { [void]$script:SecretValues.Add((Get-Content -Raw (Join-Path $Secrets ('pin-' + $u.id))).Trim()) }
        $caCrt = Join-Path $Base 'ca\bingoo-edge-branch-ca.crt'
        $ca = New-Object Security.Cryptography.X509Certificates.X509Certificate2($caCrt)
        $script:CaThumbprint = $ca.Thumbprint
        $script:CaPem = Join-Path $Work 'branch-ca.pem'
        $env:CP5_EDGE_ROOT_PASSFILE = Join-Path $Secrets 'mysql-root.keep'
        $script:Results['b'] = [ordered]@{ title = 'wizard update by hand (WPF)'; ok = $false; pointer = (Get-Pointer) }
        Invoke-Scenario 'b' 'post-checks after the owner''s WPF update (K5 with the CA, tasks + grant, logins)' @() { Invoke-UpdateChecks 'owner WPF click-through' }
    }
    switch ($Scenario) {
        'auto' {
            Invoke-Scenario 'a' '0.8.5 elevated install (MySQL service, branch CA, gateway, tasks) paired to the sandbox Cloud' @('cloud') { Invoke-Install085 }
            Invoke-Scenario 'w-update' 'the REAL WPF wizard (Setup-BingooEdge.cmd) driven by UI Automation: UPDATE 0.8.5 -> 0.9.0-dev' @('a') { Invoke-WizardUiUpdate }
            Invoke-Scenario 'b' 'update post-checks: same device, K5 with the branch CA, tasks + LOCAL SERVICE grant, PIN logins' @('a') {
                if ((Get-Pointer) -eq '0.9.0-dev') { Invoke-UpdateChecks 'WPF wizard via UI Automation' }
                else { Write-Log 'warn' 'the UI-driven update did not reach 0.9.0-dev - headless Setup-BingooEdge.cmd so the chain can continue'; Invoke-WizardUpdateHeadless }
            }
        }
        'manual' {
            Invoke-Scenario 'a' '0.8.5 elevated install (MySQL service, branch CA, gateway, tasks) paired to the sandbox Cloud' @('cloud') { Invoke-Install085 }
            Invoke-Scenario 'b-manual' 'owner clicks through the WPF wizard (update)' @('a') { Save-BeforeUpdate; Save-RunState @{ before_update_saved = $true }; Invoke-WizardUpdateManual }
        }
        'fresh' {
            Invoke-Scenario 'w-fresh' 'the REAL WPF wizard driven by UI Automation: FRESH INSTALL with a setup code' @('cloud') { Invoke-WizardUiFresh }
            Invoke-Scenario 'f' 'fresh-install post-checks: MySQL service, CA, tasks, K5, PIN logins (loopback + gateway full TLS)' @('cloud') {
                if (Test-Path (Join-Path $InstallRoot 'appliance.json')) { Invoke-FreshChecks 'WPF wizard via UI Automation' 0 }
                else { Write-Log 'warn' 'the UI-driven install did not install - headless fresh install instead'; Invoke-FreshInstall }
            }
        }
    }
    if (@('auto', 'continue') -contains $Scenario) {
        Invoke-Scenario 'c' 'auto-update 0.9.0 -> 0.9.1-dev by the BingooEdgeUpdater task (LOCAL SERVICE), heartbeat gap vs lease TTL' @('b') {
            $r = Invoke-UpdaterRun 'c' $Pkg091 '0.9.1-dev'
            $r['verdict_ok'] = $r.pointer -eq '0.9.1-dev' -and $r.lease_verdict -eq 'PASS' -and $r.logins_ok
            $r
        }
        Invoke-Scenario 'd' '0.9.2-dev broken health -> pointer rollback with real services' @('c') {
            $r = Invoke-UpdaterRun 'd' $Pkg092 '0.9.1-dev'
            $r['verdict_ok'] = $r.pointer -eq '0.9.1-dev' -and $r.lease_verdict -eq 'PASS' -and $r.logins_ok -and $r.health -ne 'NOT_BOUND'
            $r
        }
        Invoke-Scenario 'e' 'counter setup wizard: CA only after confirmation, hosts, shortcuts, TLS by name and IP, Repair, Remove' @('b') { Invoke-CounterScenario }
        Invoke-Scenario 's' 'cashier self-service "Install Offline Counter" (Agent D: headless Edge + the real counter wizard)' @('b') { Invoke-ScenarioS }
        Invoke-Scenario 'h' 'shift / order continuity across a supervised takeover + handback (Agent A)' @('b') { Invoke-ScenarioH }
        Invoke-Scenario 'g1' 'auto takeover default OFF: Cloud down > after_seconds -> NO takeover, health shows OFF' @('b') { Invoke-G1 }
        Invoke-Scenario 'g3' 'auto takeover ON, flapping Cloud (60 s down / 45 s up x5) -> NO takeover' @('b') { Invoke-G3 }
        Invoke-Scenario 'g4' 'auto takeover ON, Cloud clock skew 45 s -> refused CLOCK_SKEW_TOO_LARGE' @('b') { Invoke-G4 }
        Invoke-Scenario 'g2' 'auto takeover ON (after 180 s, TTL 120): lease expires -> takeover -> Cloud back stays EDGE -> supervised handback' @('b') { Invoke-G2 }
    }
} finally {
    $hits = Protect-Evidence
    $sum = [ordered]@{ scenario = $Scenario; finished = (Get-Date).ToString('s'); evidence = $Ev; secret_hits_redacted = $hits; signed_url_params_redacted = $script:SignedUrlRedactions; results = $script:Results }
    Save-Evidence 'summary.json' $sum
    Write-Host ''
    Write-Host '================================ CP5 summary ================================' -ForegroundColor Cyan
    foreach ($k in $script:Results.Keys) {
        $r = $script:Results[$k]
        $state = $(if ($r.skipped) { 'SKIP' } elseif ($r.ok) { 'PASS' } else { 'FAIL' })
        Write-Host ('{0,-9} {1,-5} {2,5} s  {3}' -f $k, $state, $r.seconds, $r.title) -ForegroundColor $(if ($state -eq 'PASS') { 'Green' } elseif ($state -eq 'SKIP') { 'Yellow' } else { 'Red' })
    }
    Write-Host ("evidence: $Ev (secret hits redacted: $hits)") -ForegroundColor Cyan
}
