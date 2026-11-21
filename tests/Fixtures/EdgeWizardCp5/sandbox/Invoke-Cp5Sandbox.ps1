# CP5 (stream B) - the Windows-native, ELEVATED 0.9 proof. Runs ONLY inside a Windows Sandbox (its own administrator); the
# host is never elevated or changed. Started by Start-Cp5.cmd (the .wsb LogonCommand).
#
#   -Scenario auto      prereqs + Cloud, then a (0.8.5 elevated install: MySQL service, CA, gateway, tasks), b (0.9 wizard
#                       Setup-BingooEdge.cmd UPDATE headless), c (auto-update 0.9.1 with REAL task control + heartbeat-gap /
#                       fencing watch), d (0.9.2 broken health -> pointer rollback with real services), e (counter wizard)
#   -Scenario manual    prereqs + Cloud + a, then the owner clicks through the WPF wizard (update); desktop "CP5 continue" = c..e
#   -Scenario continue  c, d, e after a manual wizard run (same sandbox session)
#   -Scenario fresh     prereqs + Cloud, then f (0.9 wizard FRESH INSTALL headless with a setup code)
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
    # HOST pre-flight only (not elevated): start the disposable Cloud exactly as the sandbox would (package php + package MySQL
    # on 127.0.0.1, read-only code tree), run a few Cloud commands, stop only the processes it started. No appliance step.
    [switch]$CloudSmoke
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
    Add-Content -Path (Join-Path $Ev 'driver.log') -Value $line -Encoding ASCII
}
function Save-Evidence([string]$Name, $Object) {
    $text = $(if ($Object -is [string]) { $Object } else { $Object | ConvertTo-Json -Depth 12 })
    [IO.File]::WriteAllText((Join-Path $Ev $Name), $text, (New-Object Text.UTF8Encoding($false)))
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
    if ($r.Code -ne 0) { throw ('cloud ' + $A[0] + ' exit ' + $r.Code + ': ' + ($r.Err + $r.Out).Trim().Substring(0, [Math]::Min(600, ($r.Err + $r.Out).Trim().Length))) }
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

# ---- scenarios ----
$script:Results = [ordered]@{}
if ($Scenario -eq 'continue' -and (Test-Path (Join-Path $Ev 'scenarios.json'))) {
    (Get-Content -Raw (Join-Path $Ev 'scenarios.json') | ConvertFrom-Json).PSObject.Properties | ForEach-Object { $script:Results[$_.Name] = $_.Value }
}
function Invoke-Scenario([string]$Id, [string]$Title, [string[]]$Needs, [scriptblock]$Body) {
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
    # Visual C++ 2015-2022 x64 runtime (PHP, MySQL, the gateway need it; a clean sandbox has none)
    $vcKey = 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64'
    $how = 'present'
    if (-not (Get-ItemProperty $vcKey -ErrorAction SilentlyContinue)) {
        $how = 'msi'
        foreach ($msi in @('minimum\vc_runtimeMinimum_x64.msi', 'additional\vc_runtimeAdditional_x64.msi')) {
            $f = Join-Path $Kit ('prereqs\' + $msi)
            if (Test-Path $f) {
                $mlog = Join-Path $Ev ('prereq-' + [IO.Path]::GetFileNameWithoutExtension($f) + '.log')
                $r = Start-Process msiexec.exe -ArgumentList ('/i "' + $f + '" /qn /norestart /l*v "' + $mlog + '"') -Wait -PassThru
                Write-Log 'info' "$msi exit $($r.ExitCode)"
            }
        }
        if (-not (Test-Path (Join-Path $env:SystemRoot 'System32\vcruntime140.dll'))) {
            $how = 'dll-copy'
            Copy-Item (Join-Path $Kit 'prereqs\vcrt\*.dll') (Join-Path $env:SystemRoot 'System32') -Force
        }
        if (-not (Get-ItemProperty $vcKey -ErrorAction SilentlyContinue)) {
            # the MSIs alone may not write the bundle's detection key the wizard checks; the runtime itself is in place
            New-Item -Path $vcKey -Force | Out-Null
            New-ItemProperty -Path $vcKey -Name Installed -Value 1 -PropertyType DWord -Force | Out-Null
            New-ItemProperty -Path $vcKey -Name Version -Value 'v14.51.36247.00' -PropertyType String -Force | Out-Null
            $how += '+detection-key'
        }
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
    if (-not (Wait-Port $CloudPort 1)) {
        $log = Join-Path $Work 'cloud\serve.log'
        $p = Start-Process -FilePath $CloudPhp -ArgumentList ('-c "' + $CloudIni + '" -S 127.0.0.1:' + $CloudPort + ' -t "' + (Join-Path $CloudSrc 'public') + '" "' + (Join-Path $Kit 'cp5-router.php') + '"') -RedirectStandardOutput $log -RedirectStandardError ($log + '.err') -WindowStyle Hidden -PassThru
        Save-RunState @{ cloud_php_pid = $p.Id }
        if (-not (Wait-Port $CloudPort 60)) { throw 'the Cloud did not listen on 9831' }
    }
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
    if (-not (Test-Path (Join-Path $InstallRoot 'appliance.json'))) {
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
        if ($r.Out -notmatch 'INSTALL COMPLETE') { throw ('the 0.8.5 install did not complete (exit ' + $r.Code + '), see a-install-085.log') }
        Remove-Item -Force $edgePwFile, $pfxPwFile -ErrorAction SilentlyContinue
    }
    # every Cloud user gets an Edge PIN on 0.8.5 (the documented operator path: Cloud assertion + edge:local:enroll --credential-file)
    $enrolled = [ordered]@{}
    foreach ($u in (Get-Users)) {
        $af = Join-Path $Secrets ("assert-" + $u.id + '.json')
        [void](Invoke-Cloud @('assertion', [string]$u.id, $af))
        $r = Invoke-Edge @('edge:local:enroll', $af, '--type=pin', ('--credential-file=' + (Join-Path $Secrets ('pin-' + $u.id))), '--no-interaction')
        Remove-Item -Force $af -ErrorAction SilentlyContinue
        $enrolled[$u.code] = ($r.Code -eq 0)
    }
    $snap = Get-ApplianceSnapshot 'after-0.8.5-install'
    $logins = Test-PinLogins 'http://127.0.0.1:8090'
    Save-Evidence 'a-state-085.json' ([ordered]@{ mysql = $mysql; certificates = $certs; enrolled = $enrolled; snapshot = $snap; pin_logins_loopback = $logins; cloud_device = (Get-CloudDevice) })
    $ok = $snap.pointer -eq '0.8.5-edge' -and -not ($enrolled.Values -contains $false) -and -not (@($logins.Values) | Where-Object { -not $_.ok })
    return [ordered]@{ verdict_ok = $ok; pointer = $snap.pointer; status = $snap.status; mysql = $mysql.service; tasks = @($snap.tasks | ForEach-Object { $_.name + '=' + $_.state }) }
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
function Invoke-WizardUpdateHeadless {
    $before = Get-ApplianceSnapshot 'before-wizard-update'
    $deviceBefore = Get-CloudDevice
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
    $k5 = [regex]::Match($r2.Out + $r2.Err, 'K5 LAN profile: (\{.*\})').Groups[1].Value
    Start-Sleep -Seconds 20
    $after = Get-ApplianceSnapshot 'after-wizard-update'
    $dev = Get-CloudDevice
    $logins = Test-PinLogins 'http://127.0.0.1:8090'
    $grant = @($after.tasks | Where-Object { $_.name -ne 'BingooEdgeUpdater' } | Where-Object { -not $_.local_service_control_ace } | ForEach-Object { $_.name })
    $doc = [ordered]@{ plan_hash = $plan; wizard_exit_unapproved = $r1.Code; wizard_exit = $r2.Code; wizard_seconds = $wizSec; k5_line = $k5
        before = $before; after = $after; cloud_device_before = $deviceBefore; cloud_device_after = $dev; pin_logins_loopback = $logins; tasks_without_local_service_grant = $grant }
    Save-Evidence 'b-state-090.json' $doc
    $d = $dev.device
    $ok = $r2.Code -eq 0 -and $after.pointer -eq '0.9.0-dev' -and $d.lan_ca_pem_present -and [string]$d.lan_ip -eq $script:LanIp -and [int]$d.lan_https_port -eq $HttpsPort -and
        [string]$d.public_uuid -eq [string]$deviceBefore.device.public_uuid -and [string]$d.installation_uuid -eq [string]$deviceBefore.device.installation_uuid -and
        -not (@($logins.Values) | Where-Object { -not $_.ok }) -and @($after.tasks | Where-Object { $_.name -eq 'BingooEdgeUpdater' }).Count -eq 1
    return [ordered]@{ verdict_ok = $ok; plan = $plan; wizard_seconds = $wizSec; pointer = $after.pointer; k5_ca_on_cloud = [bool]$d.lan_ca_pem_present; lan_ip = $d.lan_ip
        same_device = ([string]$d.public_uuid -eq [string]$deviceBefore.device.public_uuid); status = $after.status; tasks_missing_ls_grant = $grant }
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
    Start-Process -FilePath 'cmd.exe' -ArgumentList ('/c "' + (Join-Path $Pkg090 'Setup-BingooEdge.cmd') + '" -AnswersFile "' + $answers + '"')
    return [ordered]@{ launched = 'Setup-BingooEdge.cmd (WPF) with the rehearsal answers (DEV update key, HTTP Cloud)'; continue_shortcut = $cont }
}

# ------------------------------------------------------------------- c/d) auto-update with REAL task control + lease watch
function Invoke-UpdaterRun([string]$Tag, [string]$PkgDir, [string]$ExpectPointer) {
    $pub = Invoke-Cloud @('artisan', 'edge:release:publish', (ConvertTo-Fwd $PkgDir), '--json') 3600 ($Tag + '-publish.json')
    [void](Invoke-Cloud @('window-now') 300 ($Tag + '-window.json'))
    $before = Get-ApplianceSnapshot ('before-' + $Tag)
    $stop = Join-Path $Work ($Tag + '-watch.stop')
    Remove-Item -Force $stop -ErrorAction SilentlyContinue
    $env:CP5_EDGE_ROOT_PASSFILE = Write-Secret 'watch-root.pass' (Get-Secret 'mysql-root' { throw 'no root password' })
    $watch = Start-Process -FilePath $CloudPhp -ArgumentList ('-c "' + $CloudIni + '" "' + (Join-Path $Kit 'cp5-lease-watch.php') + '" "' + (Join-Path $Ev ($Tag + '-lease')) + '" "' + $stop + '" 5400') -WindowStyle Hidden -PassThru
    Remove-Item env:CP5_EDGE_ROOT_PASSFILE
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
    Remove-Item -Force (Join-Path $Secrets 'watch-root.pass') -ErrorAction SilentlyContinue
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
    Save-Evidence 'f-state-fresh.json' ([ordered]@{ wizard_exit = $r.Code; wizard_seconds = $sec; after = $after; cloud_device = $dev; pin_logins_loopback = $loop; pin_logins_gateway_by_ip = $byIp })
    $ok = $r.Code -eq 0 -and $after.pointer -eq '0.9.0-dev' -and $dev.device.lan_ca_pem_present -and -not (@($loop.Values) | Where-Object { -not $_.ok })
    return [ordered]@{ verdict_ok = $ok; wizard_seconds = $sec; pointer = $after.pointer; status = $after.status; k5_ca_on_cloud = [bool]$dev.device.lan_ca_pem_present; tasks = @($after.tasks | ForEach-Object { $_.name + '=' + $_.state }) }
}

# ---------------------------------------------------------------------------------- evidence hygiene + summary (always)
function Protect-Evidence {
    $hits = 0
    $vals = @($script:SecretValues | Where-Object { $_ -and $_.Length -ge 6 } | Select-Object -Unique)
    foreach ($f in @(Get-ChildItem -Path $Ev -Recurse -File)) {
        $t = Read-Shared $f.FullName
        $n = $t
        foreach ($v in $vals) { if ($n.Contains($v)) { $hits++; $n = $n.Replace($v, '<REDACTED>') } }
        if ($n -ne $t) { [IO.File]::WriteAllText($f.FullName, $n, (New-Object Text.UTF8Encoding($false))) }
    }
    return $hits
}

# ================================================================================================================ main
Write-Log 'step' "Bingoo Edge CP5 sandbox driver - scenario $Scenario - evidence $Ev"
if ($CloudSmoke) {
    try {
        Invoke-Scenario 'cloud' 'HOST pre-flight: the sandbox Cloud (package php + package MySQL, read-only tree)' @() { Start-Cloud }
        Invoke-Scenario 'cloud-commands' 'HOST pre-flight: setup code + public keys + device' @('cloud') {
            $sc = Get-SetupCode
            $sc.Code = $null
            [ordered]@{ setup_mode = $sc.Mode; device = (Get-CloudDevice) }
        }
    } finally {
        if (Test-Path $RunFile) {
            $rs = Get-Content -Raw $RunFile | ConvertFrom-Json
            foreach ($p in @($rs.cloud_php_pid, $rs.cloud_mysqld_pid)) { if ($p) { Stop-OwnTree ([int]$p) } }
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
        $script:Results['b'] = [ordered]@{ title = 'wizard update by hand (WPF)'; ok = ((Get-Pointer) -eq '0.9.0-dev'); pointer = (Get-Pointer) }
    }
    switch ($Scenario) {
        'auto' {
            Invoke-Scenario 'a' '0.8.5 elevated install (MySQL service, branch CA, gateway, tasks) paired to the sandbox Cloud' @('cloud') { Invoke-Install085 }
            Invoke-Scenario 'b' '0.9 wizard Setup-BingooEdge.cmd UPDATE 0.8.5 -> 0.9.0-dev (headless; K5 with the branch CA)' @('a') { Invoke-WizardUpdateHeadless }
        }
        'manual' {
            Invoke-Scenario 'a' '0.8.5 elevated install (MySQL service, branch CA, gateway, tasks) paired to the sandbox Cloud' @('cloud') { Invoke-Install085 }
            Invoke-Scenario 'b-manual' 'owner clicks through the WPF wizard (update)' @('a') { Invoke-WizardUpdateManual }
        }
        'fresh' {
            Invoke-Scenario 'f' '0.9 wizard FRESH INSTALL (headless, setup code, MySQL from the package, branch CA, tasks)' @('cloud') { Invoke-FreshInstall }
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
    }
} finally {
    $hits = Protect-Evidence
    $sum = [ordered]@{ scenario = $Scenario; finished = (Get-Date).ToString('s'); evidence = $Ev; secret_hits_redacted = $hits; results = $script:Results }
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
