# CP5 scenario s (stream D) - CASHIER SELF-SERVICE "Install Offline Counter", Windows-native, INSIDE the Windows Sandbox
# (its administrator WDAGUtilityAccount; refused anywhere else). Called by stream B's Invoke-Cp5Sandbox.ps1 after scenario e:
#
#   powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -File C:\cp5\kit\Invoke-Cp5CounterSelfService.ps1 `
#       -Evidence <evidence dir> -LanIp <sandbox LAN IP> [-Kit C:\cp5\kit] [-Pkgs C:\cp5\pkgs] [-Work C:\cp5w]
#
# 1  a cashier with tenant.offline-edge.counter-install logs in to the in-sandbox Online POS in HEADLESS MICROSOFT EDGE driven
#    over the DevTools protocol (fallback: a cookie session on the same routes - the evidence names the driver used); the Online
#    POS runs behind a TLS front with a sandbox-only test CA (full verification, no bypass) because the counter setup accepts an
#    https Online POS URL only
# 2  POS -> "Install Offline Counter" -> "Download with my Edge PIN setup"; the signed payload (sodium + the wizard's own
#    PowerShell Ed25519) and the key id + certificate SHA-256 shown on the page are checked against the file and the Cloud
# 3  the download carries a self-enrolment assertion for THAT cashier only (user = issuer = the cashier)
# 4  the REAL counter wizard window: CA only after explicit confirmation, ONE hosts line, Online / Offline / Status shortcuts with
#    the Bingoo icon, HTTPS checks by name and IP; then Repair (idempotent)
# 5  the cashier's own Edge PIN through POST /edge/local/enroll (the optional endpoint is switched on for the run, then back off)
# 6  PIN login through the gateway with full TLS (branch CA only), by name and by IP; the previous PIN no longer works
# 8  negatives: no permission -> 403, other branch -> refused, other tenant -> refused (Cloud host + Branch Server binding),
#    an assertion for another user -> refused, throttles -> 429 (Cloud and Branch Server)
# 7  ONLY the in-sandbox Cloud php processes are stopped (recorded pids): the Offline shortcut still works over the LAN
#
# Evidence: <Evidence>\s-*.json + s-*.png; secrets (passwords, PINs, assertions, keys) live in <Work>\secrets only and every
# evidence file is scanned and redacted before the scenario ends. Exit 0 = every verdict true. Last stdout line = verdict JSON.
param(
    [Parameter(Mandatory = $true)][string]$Evidence,
    [Parameter(Mandatory = $true)][string]$LanIp,
    [string]$Kit = 'C:\cp5\kit',
    [string]$Pkgs = 'C:\cp5\pkgs',
    [string]$Work = 'C:\cp5w',
    [string]$CloudPhp = '',
    [string]$CloudIni = '',
    [string]$LanHost = 'bingoo-edge.local',
    [int]$HttpsPort = 8443,
    [int]$WebPort = 9841,
    [int]$TlsPort = 9443,
    [int]$CdpPort = 9842,
    [switch]$KeepCloudRunning
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not ($isAdmin -and $env:USERNAME -eq 'WDAGUtilityAccount')) {
    throw 'refusing: scenario s changes the certificate store, the hosts file, the Public Desktop and the appliance - it runs only inside the Windows Sandbox (administrator WDAGUtilityAccount)'
}

$Pkg090 = Join-Path $Pkgs 'BingooEdge-0.9.0-dev'
if (-not $CloudPhp) { $CloudPhp = Join-Path $Pkg090 'php\php.exe' }
if (-not $CloudIni) { $CloudIni = Join-Path $Work 'cloud\php.ini' }
if (-not $env:CP5_TREE) { $env:CP5_TREE = 'C:\cp5\cloudsrc' }
if (-not $env:CP5_WORK) { $env:CP5_WORK = $Work }
if (-not $env:CP5_KIT) { $env:CP5_KIT = $Kit }
if (-not $env:CP5_CLOUD_PORT) { $env:CP5_CLOUD_PORT = '9831' }
if (-not $env:CP5_CLOUD_DB_PORT) { $env:CP5_CLOUD_DB_PORT = '3407' }
$env:CP5_SS_TLS_PORT = [string]$TlsPort
$InstallRoot = 'C:\Program Files\Bingoo Edge'
$ApplianceEnv = 'C:\ProgramData\BingooEdge\config\appliance.env'
$Secrets = Join-Path $Work 'secrets'
$S = Join-Path $Work 'selfservice'
$RunFile = Join-Path $Work 'cp5-run.json'
$TenantHost = 'cp5edgewiz.cp5.test'
$OnlineBase = "https://${TenantHost}:$TlsPort"
$EdgeByName = "https://${LanHost}:$HttpsPort"
$EdgeByIp = "https://${LanIp}:$HttpsPort"
foreach ($d in @($Evidence, $S, (Join-Path $S 'tls'), (Join-Path $S 'download'), (Join-Path $S 'jars'), $Secrets)) { New-Item -ItemType Directory -Force -Path $d | Out-Null }
Get-ChildItem -LiteralPath (Join-Path $S 'download') -File -ErrorAction SilentlyContinue | Remove-Item -Force

$script:SecretValues = New-Object System.Collections.ArrayList
$script:Pids = [ordered]@{}
$script:Cleanup = [ordered]@{}
$doc = [ordered]@{ scenario = 's'; title = 'cashier self-service: Online POS -> Install Offline Counter -> wizard -> own Edge PIN -> TLS login; Cloud stopped -> Offline still works; negatives'
    started = (Get-Date).ToString('s'); web_driver = $null; steps = [ordered]@{} }

function Write-SLog([string]$Level, [string]$Message) {
    $line = '{0} [{1}] s: {2}' -f (Get-Date -Format 'HH:mm:ss'), $Level.ToUpper(), $Message
    Write-Host $line -ForegroundColor $(switch ($Level) { 'ok' { 'Green' } 'warn' { 'Yellow' } 'error' { 'Red' } default { 'Cyan' } })
    Add-Content -LiteralPath (Join-Path $Evidence 's-selfservice.log') -Value $line -Encoding ASCII
}
function Save-SEvidence([string]$Name, $Object) {
    $text = $(if ($Object -is [string]) { $Object } else { $Object | ConvertTo-Json -Depth 12 })
    [IO.File]::WriteAllText((Join-Path $Evidence $Name), $text, (New-Object Text.UTF8Encoding($false)))
}
function Get-LastJson([string]$Text) {
    if (-not $Text) { return $null }
    $lines = $Text -split "`r?`n"
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i].StartsWith('{') -or $lines[$i].StartsWith('[')) {
            try { return (($lines[$i..($lines.Count - 1)]) -join "`n" | ConvertFrom-Json) } catch { }
        }
    }
    return $null
}
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
    } while ($p -match '^(\d)\1{5}$' -or '0123456789'.Contains($p) -or '9876543210'.Contains($p) -or @('123123', '000000') -contains $p)
    [void]$script:SecretValues.Add($p)
    return $p
}
function Write-SecretFile([string]$Name, [string]$Value) {
    $path = Join-Path $Secrets $Name
    [IO.File]::WriteAllText($path, $Value, (New-Object Text.ASCIIEncoding))
    return $path
}
function Invoke-SPhp([string]$Script, [string[]]$Arguments, [int]$TimeoutSec = 600) {
    $log = Join-Path $S ('php-' + [IO.Path]::GetFileNameWithoutExtension($Script) + '-' + (Get-Date -Format 'HHmmssfff') + '.log')
    $line = (@('-c', $CloudIni, (Join-Path $Kit $Script)) + $Arguments | ForEach-Object { if ($_ -match '[\s"]') { '"' + ($_ -replace '"', '\"') + '"' } else { $_ } }) -join ' '
    $p = Start-Process -FilePath $CloudPhp -ArgumentList $line -RedirectStandardOutput $log -RedirectStandardError ($log + '.err') -NoNewWindow -PassThru -WorkingDirectory $S
    $null = $p.Handle
    if (-not $p.WaitForExit($TimeoutSec * 1000)) { & taskkill.exe /PID $p.Id /T /F 2>$null | Out-Null; throw "TIMEOUT: $Script" }
    $out = [IO.File]::ReadAllText($log)
    $err = ''
    if (Test-Path -LiteralPath ($log + '.err')) { $err = [IO.File]::ReadAllText($log + '.err') }
    return @{ Code = [int]$p.ExitCode; Out = $out; Err = $err; Json = (Get-LastJson $out) }
}
function Invoke-SWeb([string]$Mode, [hashtable]$Config, [int]$TimeoutSec = 600) {
    $cfg = Join-Path $Secrets ('s-web-' + $Mode + '.json')
    [IO.File]::WriteAllText($cfg, ($Config | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false)))
    try { return Invoke-SPhp 'cp5-selfservice-web.php' @($Mode, $cfg) $TimeoutSec } finally { Remove-Item -Force -LiteralPath $cfg -ErrorAction SilentlyContinue }
}
function Test-Port([int]$Port) {
    $c = New-Object Net.Sockets.TcpClient
    try { $c.Connect('127.0.0.1', $Port); return $true } catch { return $false } finally { $c.Dispose() }
}
function Wait-Port([int]$Port, [int]$TimeoutSec = 60) {
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    do { if (Test-Port $Port) { return $true }; Start-Sleep -Milliseconds 500 } while ((Get-Date) -lt $deadline)
    return $false
}
function Stop-RecordedTree([string]$Name) {
    if ($script:Pids.Contains($Name) -and $script:Pids[$Name]) {
        & taskkill.exe /PID ([int]$script:Pids[$Name]) /T /F 2>$null | Out-Null
        Write-SLog 'info' ("stopped $Name (recorded pid " + $script:Pids[$Name] + ')')
        $script:Pids[$Name] = $null
    }
}
function Invoke-Step([string]$Id, [string]$Title, [scriptblock]$Body) {
    Write-SLog 'step' "---- $Id $Title"
    $t0 = Get-Date
    $rec = [ordered]@{ title = $Title; ok = $false }
    try {
        $r = & $Body
        if ($r -is [array]) { $r = @($r | Where-Object { $_ -is [Collections.IDictionary] }) | Select-Object -Last 1 }
        if ($r -is [Collections.IDictionary]) { foreach ($k in $r.Keys) { $rec[$k] = $r[$k] } }
        $rec.ok = $true
        if ($rec.Contains('verdict_ok')) { $rec.ok = [bool]$rec['verdict_ok'] }
    } catch {
        $rec.error = $_.Exception.Message
        Write-SLog 'error' ("$Id failed: " + $_.Exception.Message)
    }
    $rec.seconds = [int]((Get-Date) - $t0).TotalSeconds
    $doc.steps[$Id] = $rec
    Save-SEvidence 's-selfservice.json' $doc
    Write-SLog $(if ($rec.ok) { 'ok' } else { 'error' }) ("$Id " + $(if ($rec.ok) { 'PASS' } else { 'FAIL' }))
    return $rec
}
function Get-WizardVar([string]$Text, [string]$Name) {
    if ($Text -match ('(?m)^\$' + $Name + "\s*=\s*'((?:[^']|'')*)'")) { return $Matches[1].Replace("''", "'") }
    return $null
}

. (Join-Path $Kit 'Cp5Cdp.ps1')

$state = Get-Content -Raw (Join-Path $Work 'cloud\state.json') | ConvertFrom-Json
$cashierCode = 'CP5C1'
$branchCaPem = $null
$wizardFile = $null
$caThumb = $null
$prep = $null
try {
    # ================================================================= 0  preflight
    $pre = Invoke-Step 's0' 'preflight: Edge browser, nginx, the in-sandbox Cloud, the 0.9 appliance' {
        $nginx = @("$Pkg090\gateway\nginx.exe", "$InstallRoot\gateway\nginx.exe") | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
        if (-not $nginx) { $nginx = (Get-ChildItem -LiteralPath $Pkgs -Recurse -Filter 'nginx.exe' -ErrorAction SilentlyContinue | Select-Object -First 1).FullName }
        $script:Nginx = $nginx
        $run = $null
        if (Test-Path -LiteralPath $RunFile) { $run = Get-Content -Raw $RunFile | ConvertFrom-Json }
        $script:CloudPid = $(if ($run -and $run.cloud_php_pid) { [int]$run.cloud_php_pid } else { $null })
        foreach ($p in @("$Work\branch-ca.pem", "$Work\branch-ca-fresh.pem")) { if (-not $script:BranchCaFile -and (Test-Path -LiteralPath $p)) { $script:BranchCaFile = $p } }
        $r = [ordered]@{ msedge = (Find-EdgeBrowser); nginx = $nginx; cloud_9831_listening = (Test-Port ([int]$env:CP5_CLOUD_PORT)); cloud_php_pid_recorded = $script:CloudPid
            appliance_installed = (Test-Path -LiteralPath (Join-Path $InstallRoot 'appliance.json')); branch_ca_file = $script:BranchCaFile; lan_ip = $LanIp }
        $r.verdict_ok = [bool]($r.msedge -and $r.nginx -and $r.cloud_9831_listening -and $r.appliance_installed -and $r.branch_ca_file)
        $r
    }
    if (-not $pre.ok) { throw 'preflight failed' }

    # ================================================================= 1  the Online POS over HTTPS + the scenario users
    Invoke-Step 's1' 'Online POS over HTTPS (sandbox test CA, full verification) + the scenario users' {
        $keyFile = Join-Path $Secrets 's-cloud-tls.key'
        $tls = (Invoke-SPhp 'cp5-selfservice.php' @('tls', (Join-Path $S 'tls'), $keyFile)).Json
        $caFile = Join-Path $S 'tls\cloud-test-ca.crt'
        $ca = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2 -ArgumentList $caFile
        $store = New-Object System.Security.Cryptography.X509Certificates.X509Store -ArgumentList ([System.Security.Cryptography.X509Certificates.StoreName]::Root), ([System.Security.Cryptography.X509Certificates.StoreLocation]::LocalMachine)
        $store.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadWrite)
        try { $store.Add($ca) } finally { $store.Close() }
        $script:TestCaThumb = $ca.Thumbprint
        Copy-Item -LiteralPath $caFile -Destination (Join-Path $Evidence 's-cloud-test-ca.crt') -Force   # public

        $log = Join-Path $S 'web-serve.log'
        $p = Start-Process -FilePath $CloudPhp -ArgumentList ('-c "' + $CloudIni + '" -S 127.0.0.1:' + $WebPort + ' -t "' + (Join-Path $env:CP5_TREE 'public') + '" "' + (Join-Path $Kit 'cp5-selfservice-router.php') + '"') -RedirectStandardOutput $log -RedirectStandardError ($log + '.err') -WindowStyle Hidden -PassThru
        $script:Pids['web_php'] = $p.Id
        if (-not (Wait-Port $WebPort 60)) { throw "the scenario web php did not listen on $WebPort" }

        $prefix = Join-Path $S 'nginx'
        foreach ($d in @('conf', 'logs', 'temp')) { New-Item -ItemType Directory -Force -Path (Join-Path $prefix $d) | Out-Null }
        $fwd = { param($x) $x -replace '\\', '/' }
        $conf = @'
worker_processes 1;
error_log logs/error.log warn;
pid logs/nginx.pid;
events { worker_connections 64; }
http {
    access_log off;
    client_body_temp_path temp/client;
    proxy_temp_path temp/proxy;
    fastcgi_temp_path temp/fastcgi;
    uwsgi_temp_path temp/uwsgi;
    scgi_temp_path temp/scgi;
    client_max_body_size 16m;
    server {
        listen 127.0.0.1:__TLS_PORT__ ssl;
        server_name __HOST__ *.cp5.test;
        ssl_certificate __CERT__;
        ssl_certificate_key __KEY__;
        ssl_protocols TLSv1.2 TLSv1.3;
        location / {
            proxy_pass http://127.0.0.1:__WEB_PORT__;
            proxy_http_version 1.1;
            proxy_set_header Host $http_host;
            proxy_set_header X-CP5-TLS 1;
            proxy_set_header X-Forwarded-For $remote_addr;
            proxy_read_timeout 300s;
            proxy_buffering off;
        }
    }
}
'@
        $conf = $conf.Replace('__TLS_PORT__', [string]$TlsPort).Replace('__WEB_PORT__', [string]$WebPort).Replace('__HOST__', $TenantHost).Replace('__CERT__', (& $fwd (Join-Path $S 'tls\cloud-tls.crt'))).Replace('__KEY__', (& $fwd $keyFile))
        [IO.File]::WriteAllText((Join-Path $prefix 'conf\nginx.conf'), $conf, (New-Object Text.ASCIIEncoding))
        $n = Start-Process -FilePath $script:Nginx -ArgumentList ('-p "' + ((& $fwd $prefix) + '/') + '" -c conf/nginx.conf') -WorkingDirectory $prefix -WindowStyle Hidden -PassThru
        $script:Pids['web_nginx'] = $n.Id
        if (-not (Wait-Port $TlsPort 30)) { throw ("the scenario TLS front did not listen on ${TlsPort}: " + (Get-Content -Raw -LiteralPath (Join-Path $prefix 'logs\error.log') -ErrorAction SilentlyContinue)) }

        foreach ($code in @('CP5C1', 'CP5C2', 'CP5NP', 'CP5B2')) { [void](Write-SecretFile ('s-web-' + $code + '.keep') (New-SecretValue 20)) }
        $script:Prep = (Invoke-SPhp 'cp5-selfservice.php' @('prepare', $Secrets)).Json
        if (-not $script:Prep -or -not $script:Prep.users.CP5C1.can_counter_install) { throw 'the cashier CP5C1 does not hold tenant.offline-edge.counter-install' }
        [ordered]@{ test_ca = $tls; test_ca_trusted_in_sandbox_root = $script:TestCaThumb; web_php_pid = $script:Pids['web_php']; nginx_pid = $script:Pids['web_nginx']
            online_base = $OnlineBase; users = $script:Prep.users; branch2_id = $script:Prep.branch2_id; enrollment_key_id = $script:Prep.enrollment_key_id; device = $script:Prep.device }
    } | Out-Null
    if (-not $doc.steps.s1.ok) { throw 'the Online POS could not be started' }
    $prep = $script:Prep
    $cashierId = [int]$prep.users.CP5C1.id
    $webCfg = @{ base = $OnlineBase; cafile = (Join-Path $S 'tls\cloud-test-ca.crt'); jar_dir = (Join-Path $S 'jars')
        resolve = @("${TenantHost}:${TlsPort}:127.0.0.1", "othertenant.cp5.test:${TlsPort}:127.0.0.1"); other_tenant_base = "https://othertenant.cp5.test:$TlsPort"
        cashier_code = $cashierCode; cashier_id = $cashierId; owner_id = [int]$prep.owner_id
        users = @{ } }
    foreach ($code in @('CP5C1', 'CP5C2', 'CP5NP', 'CP5B2')) { $webCfg.users[$code] = @{ email = [string]$prep.users.$code.email; pass_file = (Join-Path $Secrets ('s-web-' + $code + '.keep')) } }

    # ================================================================= 1b  the Branch Server's optional self-enrolment endpoint ON
    Invoke-Step 's1b' 'switch on the optional POST /edge/local/enroll on the in-sandbox Branch Server (EDGE_LOCAL_ENROLL_ENDPOINT_ENABLED)' {
        $envText = [IO.File]::ReadAllText($ApplianceEnv)
        $script:EnvLineAdded = $false
        if ($envText -notmatch '(?m)^EDGE_LOCAL_ENROLL_ENDPOINT_ENABLED=true\s*$') {
            [IO.File]::AppendAllText($ApplianceEnv, "`r`nEDGE_LOCAL_ENROLL_ENDPOINT_ENABLED=true`r`n")
            $script:EnvLineAdded = $true
        }
        # few probes on purpose: every POST counts against the endpoint's 5/min/IP throttle (404s and 429s included)
        $edgeCfg = @{ edge_base = $EdgeByIp; cafile = $script:BranchCaFile }
        $probes = @()
        $restarted = $false
        foreach ($attempt in 1..4) {
            $probe = (Invoke-SWeb 'edge-probe' $edgeCfg).Json
            $script:LastEnrollPost = Get-Date
            $probes += [int]$probe.status
            if ($probe.enabled) { break }
            if ([int]$probe.status -eq 404 -and -not $restarted) {
                Stop-ScheduledTask -TaskName 'BingooEdgeWeb' -ErrorAction SilentlyContinue
                Start-Sleep -Seconds 3
                Start-ScheduledTask -TaskName 'BingooEdgeWeb'
                $restarted = $true
                Start-Sleep -Seconds 15
            } else {
                Start-Sleep -Seconds 65
            }
        }
        [ordered]@{ env_line_added = $script:EnvLineAdded; web_task_restarted = $restarted; probe_statuses = $probes; tls_verified = $probe.tls_verified; verdict_ok = [bool]$probe.enabled }
    } | Out-Null

    # ================================================================= 2+3  the cashier in a real browser: POS -> Install Offline Counter -> download
    Invoke-Step 's2' 'cashier logs in to the Online POS (headless Microsoft Edge over CDP) -> Install Offline Counter -> Download with my Edge PIN setup' {
        $dl = Join-Path $S 'download'
        $r = [ordered]@{ driver = 'cdp-headless-edge' }
        try {
            $r.browser = Start-CdpBrowser -ProfileDir (Join-Path $S 'edge-profile-online') -Port $CdpPort -ResolverRules ("MAP $TenantHost 127.0.0.1") -LogFile (Join-Path $Evidence 's-cdp.log')
            [void](Send-Cdp 'Browser.setDownloadBehavior' @{ behavior = 'allow'; downloadPath = $dl; eventsEnabled = $true } -Browser)
            $r.login_url = Open-CdpPage "$OnlineBase/login" '/login'
            $r.shot_login = Save-CdpScreenshot (Join-Path $Evidence 's-web-01-login.png')
            $pass = (Get-Content -Raw -LiteralPath (Join-Path $Secrets 's-web-CP5C1.keep')).Trim()
            [void](Invoke-CdpJs ("(() => { const e = document.querySelector('input[name=email]'); const f = e.form; e.value = " + (ConvertTo-JsString ([string]$prep.users.CP5C1.email)) + "; f.querySelector('input[name=password]').value = " + (ConvertTo-JsString $pass) + "; f.submit(); return true; })()"))
            $pass = $null
            $r.after_login = Wait-CdpPage '^(?!.*/login).*$' 60
            try { $r.pos_url = Open-CdpPage "$OnlineBase/pos" '/pos' 90 } catch { $r.pos_note = $_.Exception.Message }
            $r.shot_pos = Save-CdpScreenshot (Join-Path $Evidence 's-web-02-pos.png')
            $r.entry_button_href = $null
            if ($r.pos_url) { $r.entry_button_href = Invoke-CdpJs "(() => { const b = document.getElementById('pos-offline-counter-btn'); if (!b) return null; const h = b.getAttribute('href'); b.click(); return h; })()" }
            if (-not $r.entry_button_href) { $r.entry_note = 'the POS page showed no Install Offline Counter button - opened the page directly'; [void](Send-Cdp 'Page.navigate' @{ url = "$OnlineBase/pos/offline-counter" }) }
            $r.install_url = Wait-CdpPage '/pos/offline-counter$' 60
            $r.page = Invoke-CdpJs "JSON.stringify({key_id: (document.querySelector('.edge-enrollment-key-id')||{}).innerText || null, ca_sha256: (document.querySelector('.edge-counter-ca')||{}).innerText || null, problem: (document.querySelector('.edge-counter-problem')||{}).innerText || null, https: location.protocol === 'https:'})" | ConvertFrom-Json
            $r.shot_install = Save-CdpScreenshot (Join-Path $Evidence 's-web-03-install-offline-counter.png')
            [void](Invoke-CdpJs "(() => { document.querySelector('form[action`$=""/pos/offline-counter/my-enrollment""] button').click(); return true; })()")
            $file = Wait-CdpDownload $dl 45
            $r.download_method = 'browser-download'
            if (-not $file) {
                $r.download_method = 'in-page-fetch (the browser did not finish the download)'
                $resp = Invoke-CdpJs "(async () => { const f = document.querySelector('form[action`$=""/pos/offline-counter/my-enrollment""]'); const x = await fetch(f.action, {method: 'POST', body: new FormData(f), credentials: 'same-origin'}); return JSON.stringify({status: x.status, cd: x.headers.get('content-disposition'), text: await x.text()}); })()" -Await -TimeoutSec 120 | ConvertFrom-Json
                $name = 'Install-Offline-Counter.ps1'
                if ($resp.cd -match 'filename="([^"]+)"') { $name = $Matches[1] }
                $file = Join-Path $dl $name
                if ([int]$resp.status -eq 200) { [IO.File]::WriteAllText($file, [string]$resp.text, (New-Object Text.ASCIIEncoding)) }
                $resp = $null
            }
            $r.shot_after = Save-CdpScreenshot (Join-Path $Evidence 's-web-04-after-download.png')
        } catch {
            $r.cdp_error = $_.Exception.Message
            $r.driver = 'cookie-session (CDP failed: ' + $_.Exception.Message + ')'
            Write-SLog 'warn' ('CDP could not drive the page - cookie session on the same routes: ' + $_.Exception.Message)
            $cfg = $webCfg.Clone()
            $cfg.out_file = Join-Path $dl 'Install-Offline-Counter-cookie-session.ps1'
            $fb = (Invoke-SWeb 'download' $cfg).Json
            $r.cookie_session = $fb
            $r.page = $fb.page
            if ($fb.saved) { $file = $cfg.out_file; if ($fb.file_name) { $named = Join-Path $dl $fb.file_name; Move-Item -Force -LiteralPath $file -Destination $named; $file = $named } }
        } finally { Stop-CdpBrowser }
        $doc.web_driver = $r.driver
        if (-not $file -or -not (Test-Path -LiteralPath $file)) { throw 'no counter setup was downloaded' }
        $script:WizardFile = Join-Path $S (Split-Path -Leaf $file)
        Move-Item -Force -LiteralPath $file -Destination $script:WizardFile
        $r.file_name = Split-Path -Leaf $script:WizardFile
        $r.file_bytes = (Get-Item -LiteralPath $script:WizardFile).Length
        $r.verdict_ok = [bool]($r.file_name -like 'Install-Offline-Counter-*.ps1' -and $r.page.key_id -and $r.page.ca_sha256)
        $r
    } | Out-Null
    $wizardFile = $script:WizardFile
    if (-not $wizardFile) { throw 'no counter setup to continue with' }
    $wizardText = [IO.File]::ReadAllText($wizardFile)
    $caThumb = Get-WizardVar $wizardText 'CaThumbprint'
    if ($wizardText -match '(?s)-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----') {
        # from here on every Branch Server TLS check trusts ONLY the CA the Cloud delivered in the download
        $script:BranchCaFile = Join-Path $S 'branch-ca-from-download.pem'
        [IO.File]::WriteAllText($script:BranchCaFile, ($Matches[0] -replace "`r", '') + "`n", (New-Object Text.ASCIIEncoding))
        Copy-Item -LiteralPath $script:BranchCaFile -Destination (Join-Path $Evidence 's-branch-ca-from-download.pem') -Force   # public
    }
    $assertionB64 = Get-WizardVar $wizardText 'EnrollAssertionB64'
    if ($assertionB64) { [void]$script:SecretValues.Add($assertionB64); try { $a = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($assertionB64)) | ConvertFrom-Json; [void]$script:SecretValues.Add([string]$a.signature); [void]$script:SecretValues.Add([string]$a.payload.jti) } catch { } }
    # a public copy for the evidence: the single-use assertion is cut out
    Save-SEvidence ('s-' + (Split-Path -Leaf $wizardFile) + '.redacted.txt') ([regex]::Replace($wizardText, "(?m)^(\`$EnrollAssertionB64\s*=\s*')[^']*(')", '${1}<REDACTED single-use self-enrolment assertion>${2}'))

    Invoke-Step 's3' 'the download: signed payload (sodium) + page key id / certificate SHA-256 = file = Cloud; assertion for THIS cashier only' {
        $v = (Invoke-SPhp 'cp5-selfservice.php' @('verify-download', $wizardFile, [string]$cashierId)).Json
        $page = $doc.steps.s2.page
        $colon = { param($h) if (-not $h) { return '' }; return (($h.ToUpper() -split '(..)' | Where-Object { $_ }) -join ':') }
        $r = [ordered]@{ verify = $v; page_key_id = $page.key_id; page_ca_sha256 = $page.ca_sha256
            page_key_id_matches = ([string]$page.key_id).Trim() -eq [string]$v.key_id_cloud -and ([string]$page.key_id).Trim() -eq [string]$v.key_id_in_file
            page_ca_matches = (([string]$page.ca_sha256) -replace '\s', '') -eq [string]$v.ca_sha256_display_in_file -and [string]$v.ca_sha256_display_in_file -eq (& $colon $v.ca_sha256_device) }
        $r.verdict_ok = [bool]($v.verdict -and $r.page_key_id_matches -and $r.page_ca_matches)
        $r
    } | Out-Null

    # ================================================================= 4+5  the counter wizard window (real primitives) + own Edge PIN
    Invoke-Step 's4' 'counter wizard (REAL window): CA only after confirmation, one hosts line, shortcuts + icon, TLS checks, own Edge PIN via /edge/local/enroll, Repair' {
        $pre = [ordered]@{ branch_ca_in_root_before = [bool](Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $caThumb }) }
        if ($pre.branch_ca_in_root_before) {
            # left by an earlier scenario: removed (exact thumbprint) so the confirmation gate is exercised from a clean counter
            Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $caThumb } | Remove-Item
        }
        $script:NewPin = New-Pin
        $script:NewPinFile = Write-SecretFile ('s-pin-' + $cashierId + '.keep') $script:NewPin
        # the wizard's own POST /edge/local/enroll must not land in the minute the probes used (5/min/IP)
        if ($script:LastEnrollPost) {
            $wait = 61 - [int]((Get-Date) - $script:LastEnrollPost).TotalSeconds
            if ($wait -gt 0) { Write-SLog 'info' "waiting $wait s for a fresh /edge/local/enroll throttle window"; Start-Sleep -Seconds $wait }
        }
        $log = Join-Path $S 'wizard-ui.log'
        $p = Start-Process -FilePath 'powershell.exe' -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -STA -File "' + (Join-Path $Kit 'Invoke-Cp5CounterWizardUi.ps1') + '" -WizardFile "' + $wizardFile + '" -Evidence "' + $Evidence + '" -PinFile "' + $script:NewPinFile + '" -InSandbox') -RedirectStandardOutput $log -RedirectStandardError ($log + '.err') -PassThru -NoNewWindow
        $null = $p.Handle
        if (-not $p.WaitForExit(900000)) { & taskkill.exe /PID $p.Id /T /F 2>$null | Out-Null; throw 'the wizard UI run timed out' }
        $ui = Get-Content -Raw -LiteralPath (Join-Path $Evidence 's-wizard-ui.json') | ConvertFrom-Json
        $wlog = Get-ChildItem -LiteralPath 'C:\ProgramData\BingooPOS\logs' -Filter 'counter-wizard-*.log' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime | Select-Object -Last 2
        foreach ($f in $wlog) { Copy-Item -LiteralPath $f.FullName -Destination (Join-Path $Evidence ('s-' + $f.Name)) -Force }
        [ordered]@{ pre = $pre; harness_exit = $p.ExitCode; verdict = $ui.verdict; pin_status = $ui.install.pin.status; finish = $ui.install.finish.title; verdict_ok = [bool]$ui.verdict.all }
    } | Out-Null

    # ================================================================= 6  PIN login through the gateway, full TLS, by name + by IP
    Invoke-Step 's6' 'PIN login through the gateway with full TLS (branch CA only), by name (hosts line) and by IP; the previous PIN is refused' {
        $login = { param([string]$Base, [string]$PinFile) return (Invoke-SPhp 'cp5-pinlogin.php' @($Base, $cashierCode, $PinFile, $script:BranchCaFile) 180).Json }
        $byName = & $login $EdgeByName $script:NewPinFile
        $byIp = & $login $EdgeByIp $script:NewPinFile
        $oldPinFile = Join-Path $Secrets ('pin-' + $cashierId)
        $old = $null
        if (Test-Path -LiteralPath $oldPinFile) {
            [void]$script:SecretValues.Add((Get-Content -Raw -LiteralPath $oldPinFile).Trim())
            $old = & $login $EdgeByIp $oldPinFile
        }
        $ok = [bool]($byName.ok -and $byName.tls.verified -and $byIp.ok -and $byIp.tls.verified -and (-not $old -or -not $old.ok))
        if ($ok -and (Test-Path -LiteralPath $oldPinFile)) {
            # the cashier's CURRENT PIN is now the self-service one: keep stream B's later PIN logins valid
            [IO.File]::WriteAllText($oldPinFile, $script:NewPin, (New-Object Text.ASCIIEncoding))
            $script:Cleanup['pin_file_updated_for_stream_b'] = 'pin-' + $cashierId
        }
        [ordered]@{ by_name = $byName; by_ip = $byIp; previous_pin = $old; verdict_ok = $ok }
    } | Out-Null

    # ================================================================= 8  negatives (Cloud + Branch Server), audit
    Invoke-Step 's8' 'negatives: no permission 403, other branch refused, other tenant refused, assertion for another user refused, throttles 429' {
        $cloud = Invoke-SWeb 'negatives' $webCfg 900
        $files = @{ replay = (Write-SecretFile 's-assert-replay.json' ([Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($assertionB64)))) }
        foreach ($k in @('tenant', 'branch', 'badsig')) {
            $f = Join-Path $Secrets ('s-assert-' + $k + '.json')
            [void](Invoke-SPhp 'cp5-selfservice.php' @('assertion-variant', $wizardFile, $k, $f))
            $files[$k] = $f
        }
        $dummy = Write-SecretFile 's-dummy-pin.keep' (New-Pin)
        try {
            $edge = Invoke-SWeb 'edge' @{ edge_base = $EdgeByIp; cafile = $script:BranchCaFile; assertions = $files; dummy_pin_file = $dummy }
        } finally { foreach ($f in @($files.Values) + $dummy) { Remove-Item -Force -LiteralPath $f -ErrorAction SilentlyContinue } }
        $audit = (Invoke-SPhp 'cp5-selfservice.php' @('audit', [string]$cashierId)).Json
        [ordered]@{ cloud = $cloud.Json; edge = $edge.Json; audit = $audit; verdict_ok = [bool]($cloud.Json.ok -and $edge.Json.ok -and [int]$audit.self_enrollment_assertions_issued -ge 1) }
    } | Out-Null

    # ================================================================= 7  Cloud stopped: the Offline shortcut still works over the LAN
    Invoke-Step 's7' 'stop ONLY the in-sandbox Cloud php (recorded pids) -> Online POS unreachable, BINGOO Offline still works over the LAN' {
        $r = [ordered]@{}
        if ($KeepCloudRunning) { throw '-KeepCloudRunning: step 7 skipped' }
        Stop-RecordedTree 'web_nginx'
        Stop-RecordedTree 'web_php'
        if ($script:CloudPid) {
            & taskkill.exe /PID $script:CloudPid /T /F 2>$null | Out-Null
            Write-SLog 'info' ("stopped stream B's Cloud php (recorded pid " + $script:CloudPid + ')')
        }
        Start-Sleep -Seconds 2
        $r.cloud_ports_listening_after = [ordered]@{ cloud_9831 = (Test-Port ([int]$env:CP5_CLOUD_PORT)); web = (Test-Port $WebPort); tls_front = (Test-Port $TlsPort) }
        $r.online_pos = (Invoke-SWeb 'online-down' $webCfg).Json
        $shortcut = Join-Path ([Environment]::GetFolderPath('CommonDesktopDirectory')) 'BINGOO Offline.url'
        $url = $null
        if ((Test-Path -LiteralPath $shortcut) -and ([IO.File]::ReadAllText($shortcut) -match '(?m)^URL=(.+)$')) { $url = $Matches[1].Trim() }
        $r.offline_shortcut_url = $url
        try {
            $r.browser = Start-CdpBrowser -ProfileDir (Join-Path $S 'edge-profile-offline') -Port ($CdpPort + 1) -ResolverRules '' -LogFile (Join-Path $Evidence 's-cdp.log')
            $r.login_url = Open-CdpPage $url '/edge/local/login' 60
            $r.login_form = Invoke-CdpJs "JSON.stringify({https: location.protocol === 'https:', host: location.host, form: !!document.querySelector('input[name=employee_code]')})" | ConvertFrom-Json
            $r.shot_login = Save-CdpScreenshot (Join-Path $Evidence 's-offline-01-login.png')
            $pin = (Get-Content -Raw -LiteralPath $script:NewPinFile).Trim()
            [void](Invoke-CdpJs ("(() => { const e = document.querySelector('input[name=employee_code]'); const f = e.form; e.value = " + (ConvertTo-JsString $cashierCode) + "; f.querySelector('input[name=credential]').value = " + (ConvertTo-JsString $pin) + "; f.submit(); return true; })()"))
            $pin = $null
            $r.after_login = Wait-CdpPage '^(?!.*/edge/local/login).*$' 60
            $r.shot_after = Save-CdpScreenshot (Join-Path $Evidence 's-offline-02-after-login.png')
        } catch { $r.browser_error = $_.Exception.Message } finally { Stop-CdpBrowser }
        $byName = (Invoke-SPhp 'cp5-pinlogin.php' @($EdgeByName, $cashierCode, $script:NewPinFile, $script:BranchCaFile) 180).Json
        $byIp = (Invoke-SPhp 'cp5-pinlogin.php' @($EdgeByIp, $cashierCode, $script:NewPinFile, $script:BranchCaFile) 180).Json
        $r.pin_login_by_name = $byName
        $r.pin_login_by_ip = $byIp
        $r.verdict_ok = [bool](-not $r.cloud_ports_listening_after.cloud_9831 -and -not $r.cloud_ports_listening_after.tls_front -and $r.online_pos.unreachable -and $url -eq ($EdgeByName + '/edge/local/login') -and
            $r.login_form.https -and $r.login_form.form -and $r.after_login -and $byName.ok -and $byName.tls.verified -and $byIp.ok -and $byIp.tls.verified)
        $r
    } | Out-Null
} catch {
    $doc.error = $_.Exception.Message
    Write-SLog 'error' $_.Exception.Message
} finally {
    # ---- cleanup: only what this scenario created or switched on ----
    try { Stop-CdpBrowser } catch { }
    foreach ($n in @('web_nginx', 'web_php')) { try { Stop-RecordedTree $n } catch { } }
    if ($script:TestCaThumb) {
        try {
            $store = New-Object System.Security.Cryptography.X509Certificates.X509Store -ArgumentList ([System.Security.Cryptography.X509Certificates.StoreName]::Root), ([System.Security.Cryptography.X509Certificates.StoreLocation]::LocalMachine)
            $store.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadWrite)
            try { foreach ($c in @($store.Certificates | Where-Object { $_.Thumbprint -eq $script:TestCaThumb })) { $store.Remove($c) } } finally { $store.Close() }
            $script:Cleanup['sandbox_test_ca_removed'] = $script:TestCaThumb
        } catch { $script:Cleanup['sandbox_test_ca_removed'] = 'FAILED: ' + $_.Exception.Message }
    }
    if ($script:EnvLineAdded) {
        try {
            $lines = @([IO.File]::ReadAllLines($ApplianceEnv) | Where-Object { $_ -ne 'EDGE_LOCAL_ENROLL_ENDPOINT_ENABLED=true' })
            [IO.File]::WriteAllLines($ApplianceEnv, [string[]]$lines, (New-Object Text.UTF8Encoding($false)))
            $script:Cleanup['enroll_endpoint'] = 'switched off again (appliance.env line removed; takes effect on the next request / web task restart)'
        } catch { $script:Cleanup['enroll_endpoint'] = 'FAILED: ' + $_.Exception.Message }
    }
    foreach ($f in @(Get-ChildItem -LiteralPath $Secrets -Filter 's-*' -File -ErrorAction SilentlyContinue)) {
        if ($f.Name -notlike 's-pin-*') { Remove-Item -Force -LiteralPath $f.FullName -ErrorAction SilentlyContinue }
    }
    if ($wizardFile -and (Test-Path -LiteralPath $wizardFile)) { Remove-Item -Force -LiteralPath $wizardFile -ErrorAction SilentlyContinue; $script:Cleanup['downloaded_setup_deleted'] = $true }
    Remove-Item -Recurse -Force -LiteralPath (Join-Path $S 'jars') -ErrorAction SilentlyContinue
    $script:Cleanup['cloud_left_stopped'] = 'stream B''s Start-Cloud starts it again when needed'
    $doc.cleanup = $script:Cleanup

    $steps = $doc.steps
    $get = { param($id) if ($steps.Contains($id)) { return [bool]$steps[$id].ok } else { return $false } }
    $s4 = $(if ($steps.Contains('s4')) { $steps.s4.verdict } else { $null })
    $neg = $(if ($steps.Contains('s8')) { $steps.s8 } else { $null })
    $doc.verdict = [ordered]@{
        web_driver                       = $doc.web_driver
        cashier_downloaded_from_pos      = (& $get 's2')
        signed_payload_and_page_verified = (& $get 's3')
        self_enrolment_for_cashier_only  = [bool]($steps.Contains('s3') -and $steps.s3.verify.assertion_self_only)
        ca_only_after_confirmation       = [bool]($s4 -and $s4.ca_only_after_confirmation)
        one_hosts_line                   = [bool]($s4 -and $s4.one_hosts_line)
        shortcuts_online_offline_icon    = [bool]($s4 -and $s4.shortcuts_with_icon)
        tls_checks_by_name_and_ip        = [bool]($s4 -and $s4.checks_by_name_and_ip)
        own_pin_via_edge_enroll          = [bool]($s4 -and $s4.own_pin_set -and (& $get 's1b'))
        repair_idempotent                = [bool]($s4 -and $s4.repair_idempotent)
        non_bingoo_roots_untouched       = [bool]($s4 -and $s4.non_bingoo_roots_untouched)
        pin_login_gateway_full_tls       = (& $get 's6')
        negatives                        = (& $get 's8')
        no_permission_403                = [bool]($neg -and $neg.cloud.negatives.no_permission.ok)
        other_branch_refused             = [bool]($neg -and $neg.cloud.negatives.other_branch.ok)
        other_tenant_refused             = [bool]($neg -and $neg.cloud.negatives.other_tenant_host.ok -and $neg.edge.edge.other_tenant.ok)
        assertion_for_other_user_refused = [bool]($neg -and $neg.cloud.negatives.assertion_for_other_user.ok)
        throttle_429                     = [bool]($neg -and $neg.cloud.negatives.throttle.ok -and $neg.edge.edge.throttle.ok)
        cloud_stopped_offline_works      = (& $get 's7')
    }
    # ---- evidence hygiene: every value this run generated or read is cut out of every s-* evidence file ----
    $hits = 0
    $values = @($script:SecretValues | Where-Object { $_ -and $_.Length -ge 6 } | Select-Object -Unique)
    foreach ($f in @(Get-ChildItem -LiteralPath $Evidence -File -Filter 's-*' -ErrorAction SilentlyContinue | Where-Object { $_.Extension -ne '.png' })) {
        $t = [IO.File]::ReadAllText($f.FullName)
        $n = $t
        foreach ($v in $values) { if ($n.Contains($v)) { $hits++; $n = $n.Replace($v, '<REDACTED>') } }
        if ($n -ne $t) { [IO.File]::WriteAllText($f.FullName, $n, (New-Object Text.UTF8Encoding($false))) }
    }
    $doc.secret_hits_redacted = $hits
    $doc.verdict.no_secrets_in_evidence = ($hits -eq 0)
    $doc.verdict.all = -not (@($doc.verdict.Keys | Where-Object { $_ -ne 'web_driver' } | ForEach-Object { $doc.verdict[$_] }) -contains $false)
    $doc.finished = (Get-Date).ToString('s')
    Save-SEvidence 's-selfservice.json' $doc
    Write-SLog $(if ($doc.verdict.all) { 'ok' } else { 'error' }) ('scenario s: ' + $(if ($doc.verdict.all) { 'PASS' } else { 'FAIL' }) + ' (driver: ' + $doc.web_driver + ', secret hits redacted: ' + $hits + ')')
}
$doc.verdict | ConvertTo-Json -Compress
if ($doc.verdict.all) { exit 0 } else { exit 1 }
