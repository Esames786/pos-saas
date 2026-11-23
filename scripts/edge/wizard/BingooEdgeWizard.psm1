# Bingoo Edge 0.9 - SETUP WIZARD logic module (install OR update a Branch Server). PowerShell 5.1, no external modules.
#
# Every page of the wizard is a function here, so the WPF UI (BingooEdgeWizard.Ui.ps1) and the headless rehearsal mode
# (Start-BingooEdgeWizard.ps1 -Headless -AnswersFile) run EXACTLY the same steps:
#
#   Welcome -> Prerequisites (Test-WizPrerequisites / Invoke-WizFix) -> Setup code (Invoke-WizRedeem, contract K2)
#   -> Passwords (typed once, kept as SecureString, written only to user-only temp files that the consumer deletes)
#   -> Install (Install-WizMySql, New-WizCertificates, Invoke-WizApplianceInstall, Add-WizFirewallRule, Add-WizHostsLine)
#      with a done-list for Undo-WizInstall (retry / rollback)
#   -> Users & PINs (Get-WizLocalUsers = edge:local:users --json; K3 assertions; edge:local:enroll per user)
#   -> Finish (K5 LAN profile, K4 ready, desktop shortcuts with the Bingoo icon, health)
#   Update mode (an installed appliance): Invoke-WizUpdate = verify -> standby/outbox gate -> full DB snapshot -> signed
#   update (Update-EdgeAppliance.ps1) -> health -> K6 rollback rule (pointer when no schema change, else snapshot restore).
#
# Secret rules: no secret on a command line, in a log line or in the answers file. Secrets are SecureString in memory; a
# consumer that needs one gets a temp FILE in a directory only the current user can read, and deletes it after reading
# (the existing --password-file / --credential-file / --code-file / -DbPasswordFile contracts). TLS is never disabled; plain
# HTTP to the Cloud only on a flagged LAB rehearsal (answers.allow_http_cloud).
Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

$script:ReleaseUpdatePublicKey = '8BBZRe6+XEhZFVXJPrM4D+kTOi7iv/AV1RztyMZZ9EQ='   # release signing PUBLIC key, key id a2eaf8a3d3e66a48 (public)
$script:CentralCloudUrl = 'https://bingoopos.com'

# ---------------------------------------------------------------------------------------------------------------------
# context, logging, secrets
# ---------------------------------------------------------------------------------------------------------------------

function Get-WizValue($obj, [string]$path, $default = $null) {
    $cur = $obj
    foreach ($part in $path.Split('.')) {
        if ($null -eq $cur) { return $default }
        if ($cur -is [hashtable]) { if ($cur.ContainsKey($part)) { $cur = $cur[$part] } else { return $default } }
        elseif ($cur.PSObject.Properties[$part]) { $cur = $cur.PSObject.Properties[$part].Value }
        else { return $default }
    }
    if ($null -eq $cur) { return $default }
    return $cur
}

function Initialize-WizContext {
    param([object]$Answers = @{}, [switch]$Headless, [string]$LogDir, [string]$PackageRoot)
    if (-not $PackageRoot) { $PackageRoot = Get-WizValue $Answers 'package_root' (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) }
    $dataRoot = Get-WizValue $Answers 'data_root' 'C:\ProgramData\BingooEdge'
    if (-not $LogDir) { $LogDir = Get-WizValue $Answers 'log_dir' (Join-Path $dataRoot 'logs') }
    New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
    $ctx = @{
        Answers = $Answers
        Headless = [bool]$Headless
        PackageRoot = $PackageRoot
        InstallRoot = Get-WizValue $Answers 'install_root' 'C:\Program Files\Bingoo Edge'
        DataRoot = $dataRoot
        CloudUrl = (Get-WizValue $Answers 'cloud_url' $script:CentralCloudUrl).TrimEnd('/')
        AllowHttpCloud = [bool](Get-WizValue $Answers 'allow_http_cloud' $false)
        LogFile = Join-Path $LogDir ('wizard-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
        SecureDir = $null
        Secrets = @{}
        Setup = $null
        Done = New-Object System.Collections.ArrayList
        Progress = $null
        InstallationUuid = [guid]::NewGuid().ToString()
        Db = $null
        Certs = $null
        Lan = $null
        DeviceId = $null
        ApprovedPlanHash = $null
    }
    Write-WizLog $ctx 'info' ("wizard started (headless=" + $ctx.Headless + ", package " + $ctx.PackageRoot + ")")
    return $ctx
}

function Write-WizLog($ctx, [string]$level, [string]$message) {
    $line = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-ddTHH:mm:ss'), $level.ToUpper(), $message
    try { Add-Content -Path $ctx.LogFile -Value $line -Encoding UTF8 } catch { }
    if ($ctx.Progress) { try { & $ctx.Progress $level $message } catch { } }
    if ($ctx.Headless) {
        $color = 'Gray'
        if ($level -eq 'step') { $color = 'Cyan' } elseif ($level -eq 'ok') { $color = 'Green' } elseif ($level -eq 'warn') { $color = 'Yellow' } elseif ($level -eq 'error') { $color = 'Red' }
        Write-Host $line -ForegroundColor $color
    }
}

function Test-WizIsAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

# A directory only the current user can read (inheritance removed). Secret temp files live here and nowhere else.
function New-WizSecureDir($ctx) {
    if ($ctx.SecureDir -and (Test-Path $ctx.SecureDir)) { return $ctx.SecureDir }
    $dir = Join-Path ([IO.Path]::GetTempPath()) ('BingooEdgeWizard-' + [guid]::NewGuid().ToString('N').Substring(0, 12))
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    $me = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    & icacls $dir /inheritance:r /grant:r ($me + ':(OI)(CI)F') | Out-Null
    $ctx.SecureDir = $dir
    return $dir
}

function Remove-WizSecureDir($ctx) {
    if ($ctx.SecureDir -and (Test-Path $ctx.SecureDir)) { Remove-Item -Recurse -Force -Path $ctx.SecureDir -ErrorAction SilentlyContinue }
    $ctx.SecureDir = $null
}

function ConvertTo-WizPlain([Security.SecureString]$secure) {
    if ($null -eq $secure) { return '' }
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

function ConvertTo-WizSecure([string]$plain) {
    $s = New-Object Security.SecureString
    foreach ($ch in $plain.ToCharArray()) { $s.AppendChar($ch) }
    $s.MakeReadOnly()
    return $s
}

# Write ONE secret to a one-time file (no BOM, no trailing newline) in the user-only dir; the consumer deletes it.
function Write-WizSecretFile($ctx, [string]$name, $secret) {
    $dir = New-WizSecureDir $ctx
    $path = Join-Path $dir ($name + '-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
    $plain = $secret
    if ($secret -is [Security.SecureString]) { $plain = ConvertTo-WizPlain $secret }
    [IO.File]::WriteAllText($path, [string]$plain, (New-Object Text.UTF8Encoding($false)))
    $plain = $null
    return $path
}

function New-WizRandomSecret([int]$length = 24) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
    $bytes = New-Object byte[] $length
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $chars = foreach ($b in $bytes) { $alphabet[$b % $alphabet.Length] }
    return ConvertTo-WizSecure (-join $chars)
}

# A secret for $name: already supplied by the UI (ctx.Secrets), TEST-ONLY env injection on a headless LAB rehearsal
# (BINGOO_WIZARD_TEST_SECRETS=1 + BINGOO_WIZARD_SECRET_<NAME>), or a hidden console prompt (headless operator use).
function Get-WizSecret($ctx, [string]$name, [string]$prompt, [switch]$AllowEmpty) {
    if ($ctx.Secrets.ContainsKey($name) -and $null -ne $ctx.Secrets[$name]) { return $ctx.Secrets[$name] }
    $envName = 'BINGOO_WIZARD_SECRET_' + $name.ToUpper()
    if ($ctx.Headless -and $env:BINGOO_WIZARD_TEST_SECRETS -eq '1') {
        $item = Get-Item -Path ('Env:' + $envName) -ErrorAction SilentlyContinue
        if ($item) {
            Write-WizLog $ctx 'warn' "TEST secret injection used for [$name] (LAB rehearsal only)"
            $v = [string]$item.Value
            if ($v -eq '__EMPTY__') { $v = '' }   # Windows cannot hold an empty env var (a LAB MySQL root without password)
            $s = ConvertTo-WizSecure $v
            $ctx.Secrets[$name] = $s
            return $s
        }
    }
    if (-not $ctx.Headless) { throw "WIZARD_SECRET_MISSING: the page did not supply [$name]" }
    for ($i = 0; $i -lt 3; $i++) {
        $s = Read-Host -Prompt $prompt -AsSecureString
        if ($AllowEmpty -or $s.Length -gt 0) { $ctx.Secrets[$name] = $s; return $s }
        Write-Host '  (nothing typed - type it and press Enter)' -ForegroundColor Yellow
    }
    throw "WIZARD_SECRET_MISSING: [$name] was not entered"
}

# ---------------------------------------------------------------------------------------------------------------------
# processes (argument arrays; never a secret in them)
# ---------------------------------------------------------------------------------------------------------------------

function ConvertTo-WizArgString([string[]]$arguments) {
    $parts = foreach ($a in $arguments) {
        if ($null -eq $a) { $a = '' }
        if ($a -eq '' -or $a -match '[\s"]') {
            $escaped = ($a -replace '(\\*)"', '$1$1\"') -replace '(\\+)$', '$1$1'
            '"' + $escaped + '"'
        } else { $a }
    }
    return ($parts -join ' ')
}

function Invoke-WizProcess {
    param([string]$FilePath, [string[]]$ArgumentList = @(), [hashtable]$Environment = @{}, [string]$WorkingDirectory, [int]$TimeoutSec = 900)
    $psi = New-Object Diagnostics.ProcessStartInfo
    $psi.FileName = $FilePath
    $psi.Arguments = ConvertTo-WizArgString $ArgumentList
    $psi.UseShellExecute = $false
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.CreateNoWindow = $true
    if ($WorkingDirectory) { $psi.WorkingDirectory = $WorkingDirectory }
    foreach ($k in $Environment.Keys) { $psi.EnvironmentVariables[$k] = [string]$Environment[$k] }
    $p = [Diagnostics.Process]::Start($psi)
    $out = $p.StandardOutput.ReadToEndAsync()
    $err = $p.StandardError.ReadToEndAsync()
    if (-not $p.WaitForExit($TimeoutSec * 1000)) {
        try { $p.Kill() } catch { }
        return @{ ExitCode = 124; StdOut = ''; StdErr = "timed out after $TimeoutSec s" }
    }
    $p.WaitForExit()
    return @{ ExitCode = $p.ExitCode; StdOut = $out.Result; StdErr = $err.Result }
}

function Get-WizPhp($ctx) {
    $explicit = Get-WizValue $ctx.Answers 'php_path' $null
    if ($explicit) { return $explicit }
    $layout = Join-Path $ctx.InstallRoot 'appliance.json'
    if (Test-Path $layout) { return [string]((Get-Content -Raw -Path $layout | ConvertFrom-Json).php) }
    $pkgPhp = Join-Path $ctx.PackageRoot 'php\php.exe'
    if (Test-Path $pkgPhp) { return $pkgPhp }
    throw 'WIZARD_NO_PHP: no PHP runtime (package php\php.exe or answers.php_path)'
}

# An appliance command: through the INSTALLED launcher (current runtime), or the PACKAGE's own app (in place, same
# appliance.env) when -Package is given (update mode: snapshot/restore/report with the new code).
function Invoke-WizArtisan($ctx, [string[]]$Arguments, [switch]$Package, [int]$TimeoutSec = 900) {
    $php = Get-WizPhp $ctx
    $envVars = @{}
    if ($Package) {
        $artisan = Join-Path $ctx.PackageRoot 'app\artisan'
        $envVars['BINGOO_EDGE_ENV_DIR'] = Join-Path $ctx.DataRoot 'config'
        $cwd = Join-Path $ctx.PackageRoot 'app'
    } else {
        $artisan = Join-Path $ctx.InstallRoot 'artisan'
        $cwd = $ctx.InstallRoot
    }
    $r = Invoke-WizProcess -FilePath $php -ArgumentList (@($artisan) + $Arguments + @('--no-interaction')) -Environment $envVars -WorkingDirectory $cwd -TimeoutSec $TimeoutSec
    return $r
}

function ConvertFrom-WizJsonOutput([string]$text) {
    $t = $text.Trim()
    try { return ($t | ConvertFrom-Json) } catch { }
    $lines = $t -split "`r?`n"
    for ($i = $lines.Count - 1; $i -ge 0; $i--) {
        $l = $lines[$i].Trim()
        if ($l.StartsWith('{')) { try { return ($l | ConvertFrom-Json) } catch { } }
    }
    return $null
}

# ---------------------------------------------------------------------------------------------------------------------
# Cloud calls (TLS verified; HTTP only on a flagged LAB)
# ---------------------------------------------------------------------------------------------------------------------

function Assert-WizCloudUrl($ctx, [string]$url) {
    $u = [Uri]$url
    if ($u.Scheme -eq 'https') { return }
    if ($u.Scheme -eq 'http' -and $ctx.AllowHttpCloud) { return }
    throw "WIZARD_INSECURE_URL: only https Cloud URLs are accepted ($url)"
}

function Invoke-WizCloudJson($ctx, [string]$Method, [string]$Path, $Body = $null, [hashtable]$Headers = @{}) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    $url = $ctx.CloudUrl + $Path
    Assert-WizCloudUrl $ctx $url
    $params = @{ Method = $Method; Uri = $url; Headers = $Headers; UseBasicParsing = $true; TimeoutSec = 30; ContentType = 'application/json' }
    if ($null -ne $Body) { $params['Body'] = ($Body | ConvertTo-Json -Depth 6 -Compress) }
    $params.Headers['Accept'] = 'application/json'
    try {
        $resp = Invoke-WebRequest @params
        return ($resp.Content | ConvertFrom-Json)
    } catch [Net.WebException] {
        $status = 0; $detail = ''
        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode
            try { $detail = (New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())).ReadToEnd() } catch { }
        }
        $code = ''
        try { $j = $detail | ConvertFrom-Json; $code = [string](Get-WizValue $j 'code' (Get-WizValue $j 'message' '')) } catch { }
        throw ("WIZARD_CLOUD_REFUSED: HTTP $status $code").Trim()
    }
}

function Get-WizKeyId([string]$base64) {
    $raw = [Convert]::FromBase64String($base64)
    $sha = [Security.Cryptography.SHA256]::Create().ComputeHash($raw)
    return ((($sha | ForEach-Object { $_.ToString('x2') }) -join '').Substring(0, 16))
}

# K2 - redeem the owner's setup code: tenant / branch / central Cloud URL / a fresh pairing code / the enrollment public key.
function Invoke-WizRedeem($ctx, [Security.SecureString]$SetupCode) {
    $deviceName = Get-WizValue $ctx.Answers 'device_name' ($env:COMPUTERNAME + ' Branch Server')
    $code = (ConvertTo-WizPlain $SetupCode).Trim().ToUpper() -replace '[\s-]', ''
    if ($code.Length -lt 6) { throw 'WIZARD_SETUP_CODE_INVALID: the setup code is too short' }
    # K2 (stream C): the installation id redeemed here MUST be the one the device pairs with; an installed appliance keeps its own.
    $installed = Test-WizInstalled $ctx
    if ($installed) {
        $existing = Get-WizApplianceEnvValue $ctx 'EDGE_INSTALLATION_UUID'
        if ($existing) { $ctx.InstallationUuid = $existing }
    }
    try {
        $doc = Invoke-WizCloudJson $ctx 'POST' '/api/edge/setup/redeem' @{ setup_code = $code; installation_uuid = $ctx.InstallationUuid; device_name = $deviceName }
    } catch {
        $m = $_.Exception.Message
        if ($m -match 'EDGE_SETUP_PAIRING_CODE_ACTIVE') { throw 'WIZARD_PAIRING_CODE_ACTIVE: a pairing code was already generated by hand for this branch - cancel it on the Cloud Offline Edge page (or use it with the manual installer) and try again.' }
        throw
    } finally { $code = $null }
    $mode = [string](Get-WizValue $doc 'mode' 'install')
    if ($mode -eq 'update' -and -not $installed) { throw 'WIZARD_SETUP_CODE_FOR_UPDATE: this setup code updates an EXISTING Branch Server; on a new PC create a new "Set up a Branch Server" code on the Cloud page.' }
    $required = @('setup_token', 'tenant_code', 'branch_id', 'enrollment_public_key')
    if ($mode -ne 'update') { $required += 'pairing_code' }
    foreach ($k in $required) {
        if (-not (Get-WizValue $doc $k $null)) { throw "WIZARD_REDEEM_INCOMPLETE: the Cloud answer has no $k" }
    }
    $cloud = [string](Get-WizValue $doc 'cloud_url' $ctx.CloudUrl)
    # The Cloud names its canonical URL as scheme://<central domain>, which drops a non-default port. When that is the same
    # scheme + host the wizard has just reached it through, keep the port it used (CP5: http://127.0.0.1 instead of
    # http://127.0.0.1:9831 would have paired the fresh install against port 80). Production (https://bingoopos.com) unchanged.
    try {
        $mine = [Uri]$ctx.CloudUrl
        $theirs = [Uri]$cloud
        if ($theirs.Host -eq $mine.Host -and $theirs.Scheme -eq $mine.Scheme -and $theirs.IsDefaultPort -and -not $mine.IsDefaultPort) { $cloud = $ctx.CloudUrl }
    } catch { }
    $ctx.Setup = @{
        TenantCode = [string]$doc.tenant_code
        BranchId = [int]$doc.branch_id
        BranchName = [string](Get-WizValue $doc 'branch_name' ('#' + $doc.branch_id))
        TenantName = [string](Get-WizValue $doc 'tenant_name' $doc.tenant_code)
        CloudUrl = $cloud.TrimEnd('/')
        OnlinePosUrl = [string](Get-WizValue $doc 'online_pos_url' '')
        EnrollmentPublicKey = [string]$doc.enrollment_public_key
        UpdateKeyId = [string](Get-WizValue $doc 'update_public_key_fingerprint' '')
        MaintenanceWindow = Get-WizValue $doc 'maintenance_window' $null
        SetupToken = ConvertTo-WizSecure ([string]$doc.setup_token)
        PairingCode = $(if (Get-WizValue $doc 'pairing_code' $null) { ConvertTo-WizSecure ([string]$doc.pairing_code) } else { $null })
        PairingIssuedAt = Get-Date
        PairingExpiresAt = $(if (Get-WizValue $doc 'pairing_code_expires_at' $null) { [DateTime]::Parse([string]$doc.pairing_code_expires_at).ToUniversalTime() } else { (Get-Date).ToUniversalTime().AddMinutes(15) })
        SetupTokenExpiresAt = [string](Get-WizValue $doc 'setup_token_expires_at' '')
        SetupSessionId = [string](Get-WizValue $doc 'setup_session_id' '')
        Mode = $mode
        DeviceId = [string](Get-WizValue $doc 'device_id' '')
        DeviceName = $deviceName
    }
    $doc = $null
    $ctx.CloudUrl = $ctx.Setup.CloudUrl
    Write-WizLog $ctx 'ok' ("setup code accepted: tenant " + $ctx.Setup.TenantCode + ", branch " + $ctx.Setup.BranchName + " (#" + $ctx.Setup.BranchId + ")")
    return $ctx.Setup
}

# K2b (optional Cloud endpoint): a fresh pairing code for the same setup session when the first one is about to expire.
function Update-WizPairingCode($ctx) {
    try {
        $tok = ConvertTo-WizPlain $ctx.Setup.SetupToken
        $doc = Invoke-WizCloudJson $ctx 'POST' '/api/edge/setup/pairing-code' @{ installation_uuid = $ctx.InstallationUuid } @{ 'X-Edge-Setup-Token' = $tok }
        $tok = $null
        if (Get-WizValue $doc 'pairing_code' $null) {
            $ctx.Setup.PairingCode = ConvertTo-WizSecure ([string]$doc.pairing_code)
            $ctx.Setup.PairingIssuedAt = Get-Date
            $ctx.Setup.PairingExpiresAt = (Get-Date).ToUniversalTime().AddMinutes(15)
            Write-WizLog $ctx 'info' 'pairing code refreshed for this setup session'
            return $true
        }
    } catch { Write-WizLog $ctx 'warn' ('pairing code refresh not available: ' + $_.Exception.Message) }
    return $false
}

# The update verification key: the pinned release key (or answers.update_public_key for a DEV-signed rehearsal), which must
# match the fingerprint the Cloud states (K2) AND the signing key id in the package manifest.
function Resolve-WizUpdatePublicKey($ctx) {
    $key = Get-WizValue $ctx.Answers 'update_public_key' $script:ReleaseUpdatePublicKey
    $id = Get-WizKeyId $key
    if ($ctx.Setup -and $ctx.Setup.UpdateKeyId -and $ctx.Setup.UpdateKeyId -ne $id) {
        throw "WIZARD_UPDATE_KEY_MISMATCH: the Cloud names update key $($ctx.Setup.UpdateKeyId), this wizard trusts $id"
    }
    $manifest = Get-WizPackageManifest $ctx
    $pkgId = [string](Get-WizValue $manifest 'components.update.signing_key_id' '')
    if ($pkgId -and $pkgId -ne $id) { throw "WIZARD_UPDATE_KEY_MISMATCH: the package is signed with key $pkgId, this wizard trusts $id" }
    return $key
}

function Get-WizPackageManifest($ctx) {
    $p = Join-Path $ctx.PackageRoot 'package-manifest.json'
    if (-not (Test-Path $p)) { throw "WIZARD_NOT_A_PACKAGE: $p not found" }
    return (Get-Content -Raw -Path $p | ConvertFrom-Json)
}

# ---------------------------------------------------------------------------------------------------------------------
# Prerequisites (read-only checks; a fix runs only when the operator presses it / answers ask for it)
# ---------------------------------------------------------------------------------------------------------------------

function New-WizCheck([string]$id, [string]$name, [string]$status, [string]$detail, [string]$fix = '') {
    return [pscustomobject]@{ Id = $id; Name = $name; Status = $status; Detail = $detail; Fix = $fix }
}

function Get-WizLanInfo {
    $cfg = Get-NetIPConfiguration -ErrorAction SilentlyContinue | Where-Object { $_.IPv4DefaultGateway -and $_.IPv4Address } | Select-Object -First 1
    if (-not $cfg) { return $null }
    $ip = [string]($cfg.IPv4Address | Select-Object -First 1).IPAddress
    $dhcp = $null
    try { $dhcp = [string](Get-NetIPInterface -InterfaceIndex $cfg.InterfaceIndex -AddressFamily IPv4).Dhcp } catch { }
    $mac = ''
    try { $mac = [string](Get-NetAdapter -InterfaceIndex $cfg.InterfaceIndex).MacAddress } catch { }
    return [pscustomobject]@{ Ip = $ip; Gateway = [string]($cfg.IPv4DefaultGateway | Select-Object -First 1).NextHop; Interface = [string]$cfg.InterfaceAlias; InterfaceIndex = $cfg.InterfaceIndex; Mac = $mac; Dhcp = $dhcp }
}

function Get-WizCloudClockSkew($ctx) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    $url = $ctx.CloudUrl + '/up'
    Assert-WizCloudUrl $ctx $url
    $r = Invoke-WebRequest -Uri $url -Method Get -UseBasicParsing -TimeoutSec 15
    $date = [string]$r.Headers['Date']
    if (-not $date) { return $null }
    $cloud = [DateTime]::Parse($date).ToUniversalTime()
    return [int][Math]::Round(($cloud - (Get-Date).ToUniversalTime()).TotalSeconds)
}

function Test-WizPrerequisites($ctx) {
    $checks = New-Object System.Collections.ArrayList
    $mode = Get-WizValue $ctx.Answers 'mysql.mode' 'install'
    $needsAdmin = ((Get-WizValue $ctx.Answers 'services.register' $true) -or $mode -eq 'install' -or (Get-WizValue $ctx.Answers 'services.firewall' $true))
    if (Test-WizIsAdmin) { [void]$checks.Add((New-WizCheck 'admin' 'Administrator' 'ok' 'running elevated')) }
    elseif ($needsAdmin) { [void]$checks.Add((New-WizCheck 'admin' 'Administrator' 'fail' 'run Setup-BingooEdge.cmd and answer Yes on the Windows prompt')) }
    else { [void]$checks.Add((New-WizCheck 'admin' 'Administrator' 'warn' 'not elevated (LAB rehearsal without services)')) }

    $os = Get-CimInstance Win32_OperatingSystem
    $osOk = ($os.Caption -match 'Windows (10|11)|Windows Server') -and ($os.OSArchitecture -match '64')
    [void]$checks.Add((New-WizCheck 'os' 'Windows version' $(if ($osOk) { 'ok' } else { 'fail' }) ($os.Caption + ' ' + $os.OSArchitecture + ' build ' + $os.BuildNumber)))

    $drive = (Split-Path -Qualifier $ctx.InstallRoot).TrimEnd(':')
    $free = [math]::Round((Get-PSDrive -Name $drive).Free / 1GB, 1)
    [void]$checks.Add((New-WizCheck 'disk' 'Free disk space' $(if ($free -ge 10) { 'ok' } elseif ($free -ge 5) { 'warn' } else { 'fail' }) ("$free GB free on $drive" + ':')))

    try {
        $skew = Get-WizCloudClockSkew $ctx
        if ($null -eq $skew) { [void]$checks.Add((New-WizCheck 'clock' 'Clock' 'warn' 'the Cloud sent no Date header' 'clock')) }
        elseif ([math]::Abs($skew) -le 30) { [void]$checks.Add((New-WizCheck 'clock' 'Clock' 'ok' "within $([math]::Abs($skew)) s of the Cloud")) }
        else { [void]$checks.Add((New-WizCheck 'clock' 'Clock' 'fail' "this PC is $skew s off the Cloud clock (Khatri was 12 h off) - press Fix to sync with Windows time" 'clock')) }
        [void]$checks.Add((New-WizCheck 'cloud' 'Internet / Cloud' 'ok' ($ctx.CloudUrl + ' reachable')))
    } catch {
        [void]$checks.Add((New-WizCheck 'cloud' 'Internet / Cloud' 'fail' ('cannot reach ' + $ctx.CloudUrl + ': ' + $_.Exception.Message)))
    }

    try {
        $q = (& powercfg /query SCHEME_CURRENT SUB_SLEEP STANDBYIDLE) -join "`n"
        $m = [regex]::Match($q, 'Current AC Power Setting Index:\s*0x([0-9a-fA-F]+)')
        if ($m.Success -and [Convert]::ToInt32($m.Groups[1].Value, 16) -eq 0) { [void]$checks.Add((New-WizCheck 'sleep' 'Sleep on mains power' 'ok' 'never sleeps')) }
        else { [void]$checks.Add((New-WizCheck 'sleep' 'Sleep on mains power' 'warn' 'this PC goes to sleep - a sleeping Branch Server cannot take over; press Fix' 'sleep')) }
    } catch { [void]$checks.Add((New-WizCheck 'sleep' 'Sleep on mains power' 'warn' 'could not read the power plan')) }

    $lan = Get-WizLanInfo
    if ($lan) {
        $hint = "LAN IP $($lan.Ip) via $($lan.Gateway) ($($lan.Interface))"
        if ($lan.Dhcp -eq 'Enabled') { $hint += " - reserve this IP for MAC $($lan.Mac) on the router (DHCP reservation) so counters always find the server" }
        [void]$checks.Add((New-WizCheck 'lan' 'LAN address' $(if ($lan.Dhcp -eq 'Enabled') { 'warn' } else { 'ok' }) $hint))
        try {
            $prof = Get-NetConnectionProfile -InterfaceIndex $lan.InterfaceIndex -ErrorAction Stop
            if ([string]$prof.NetworkCategory -eq 'Public') { [void]$checks.Add((New-WizCheck 'network' 'Network profile' 'warn' 'Public network - counters may not reach the server; press Fix to mark it Private' 'network_private')) }
            else { [void]$checks.Add((New-WizCheck 'network' 'Network profile' 'ok' ([string]$prof.NetworkCategory))) }
        } catch { }
    } else { [void]$checks.Add((New-WizCheck 'lan' 'LAN address' 'fail' 'no network adapter with a default gateway')) }

    $httpsPort = [int](Get-WizValue $ctx.Answers 'lan.https_port' 8443)
    $httpPort = [int](Get-WizValue $ctx.Answers 'lan.http_port' 80)
    $webBase = [int](Get-WizValue $ctx.Answers 'services.web_port_base' 8090)
    $workers = [int](Get-WizValue $ctx.Answers 'services.web_workers' 2)
    $ports = @($httpsPort, $httpPort) + @(0..($workers - 1) | ForEach-Object { $webBase + $_ })
    if ($mode -eq 'install') { $ports += [int](Get-WizValue $ctx.Answers 'mysql.port' 3306) }
    $busy = @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $ports -contains $_.LocalPort })
    if (Test-WizInstalled $ctx) { [void]$checks.Add((New-WizCheck 'ports' 'Ports' 'ok' 'used by the installed Branch Server (update mode)')) }
    elseif ($busy.Count -eq 0) { [void]$checks.Add((New-WizCheck 'ports' 'Ports free' 'ok' ('free: ' + ($ports -join ', ')))) }
    else {
        $list = ($busy | ForEach-Object { "$($_.LocalPort) (pid $($_.OwningProcess) $((Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue).ProcessName))" } | Select-Object -Unique) -join ', '
        [void]$checks.Add((New-WizCheck 'ports' 'Ports free' $(if ((Get-WizValue $ctx.Answers 'services.register' $true)) { 'fail' } else { 'warn' }) ('in use: ' + $list + ' - never stop the Online Print Agent; ask before changing anything')))
    }

    $mysqlSvc = @(Get-Service -ErrorAction SilentlyContinue | Where-Object { $_.Name -match 'mysql|maria' })
    if ($mode -eq 'install' -and ($mysqlSvc | Where-Object { $_.Name -eq (Get-WizValue $ctx.Answers 'mysql.service_name' 'BingooEdgeMySQL') })) {
        [void]$checks.Add((New-WizCheck 'mysql' 'MySQL' 'warn' 'BingooEdgeMySQL already exists - it will be reused (its root password is needed)'))
    } elseif ($mysqlSvc.Count -gt 0) {
        [void]$checks.Add((New-WizCheck 'mysql' 'MySQL' 'warn' ('other MySQL services found: ' + (($mysqlSvc | ForEach-Object { $_.Name }) -join ', ') + ' - Bingoo Edge uses its own')))
    } else { [void]$checks.Add((New-WizCheck 'mysql' 'MySQL' 'ok' $(if ($mode -eq 'install') { 'will be installed from the package' } else { 'existing server per answers' }))) }

    $vc = Test-WizPrereqs $ctx
    if ($vc.Code -eq 0) { [void]$checks.Add((New-WizCheck 'vcredist' 'Visual C++ runtime' 'ok' $vc.Message)) }
    elseif ($vc.Code -eq 2) { [void]$checks.Add((New-WizCheck 'vcredist' 'Visual C++ runtime' 'warn' ($vc.Message + ' - it is installed from the package as the first setup step'))) }
    else { [void]$checks.Add((New-WizCheck 'vcredist' 'Visual C++ runtime' 'fail' $vc.Message)) }

    $pkg = Test-Path (Join-Path $ctx.PackageRoot 'package-manifest.json')
    [void]$checks.Add((New-WizCheck 'package' 'Setup package' $(if ($pkg) { 'ok' } else { 'fail' }) $ctx.PackageRoot))
    foreach ($c in $checks) { Write-WizLog $ctx $(if ($c.Status -eq 'fail') { 'error' } elseif ($c.Status -eq 'warn') { 'warn' } else { 'ok' }) ("check " + $c.Name + ': ' + $c.Detail) }
    return , $checks.ToArray()
}

# The Microsoft Visual C++ 2015-2022 x64 runtime (php.exe, mysqld.exe and the gateway need it) through stream E's
# <package>\scripts\Install-EdgePrereqs.ps1 ONLY: it checks the version against the bundled installer, verifies the installer
# (Authenticode + Microsoft signer + its SHA256SUMS line) and installs it quietly. Exit 0 present, 3010 installed (reboot
# later), 2 (-CheckOnly) missing / older, 1 refused / failed.
function Get-WizPrereqScript($ctx) {
    foreach ($p in @('scripts\Install-EdgePrereqs.ps1', 'app\scripts\edge\Install-EdgePrereqs.ps1')) {
        $full = Join-Path $ctx.PackageRoot $p
        if (Test-Path $full) { return $full }
    }
    return $null
}
function Test-WizPrereqs($ctx) {
    $script = Get-WizPrereqScript $ctx
    if (-not $script) { return @{ Code = 1; Message = 'the package carries no scripts\Install-EdgePrereqs.ps1' } }
    $r = Invoke-WizProcess -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script, '-PackageRoot', $ctx.PackageRoot, '-CheckOnly') -TimeoutSec 120
    return @{ Code = [int]$r.ExitCode; Message = (($r.StdOut + ' ' + $r.StdErr).Trim() -replace '\s+', ' ') }
}
# The FIRST setup step in install AND update mode (elevated). 3010 = continue, the operator reboots later.
function Invoke-WizPrereqs($ctx) {
    $script = Get-WizPrereqScript $ctx
    if (-not $script) { throw 'WIZARD_NO_PREREQS: the package carries no scripts\Install-EdgePrereqs.ps1' }
    Write-WizLog $ctx 'step' 'Visual C++ runtime (Install-EdgePrereqs.ps1)'
    $r = Invoke-WizProcess -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script, '-PackageRoot', $ctx.PackageRoot) -TimeoutSec 1800
    $msg = (($r.StdOut + ' ' + $r.StdErr).Trim() -replace '\s+', ' ')
    switch ([int]$r.ExitCode) {
        0 { Write-WizLog $ctx 'ok' $msg }
        3010 { $ctx.RebootRecommended = $true; Write-WizLog $ctx 'warn' ($msg + ' - setup continues; restart Windows later (outside shop hours)') }
        default { throw ('WIZARD_PREREQS_FAILED (exit ' + $r.ExitCode + '): ' + $msg) }
    }
    return [int]$r.ExitCode
}

function Invoke-WizFix($ctx, [string]$fix) {
    if (-not (Test-WizIsAdmin)) { throw 'WIZARD_NOT_ADMIN: fixes need the elevated wizard' }
    switch ($fix) {
        'clock' {
            Set-Service -Name w32time -StartupType Automatic
            Start-Service -Name w32time -ErrorAction SilentlyContinue
            & w32tm /config /update /syncfromflags:manual /manualpeerlist:"time.windows.com,0x9 pool.ntp.org,0x9" | Out-Null
            & w32tm /resync /force | Out-Null
        }
        'sleep' {
            & powercfg /change standby-timeout-ac 0 | Out-Null
            & powercfg /change hibernate-timeout-ac 0 | Out-Null
        }

        'network_private' {
            $lan = Get-WizLanInfo
            if ($lan) { Set-NetConnectionProfile -InterfaceIndex $lan.InterfaceIndex -NetworkCategory Private }
        }
        default { throw "unknown fix $fix" }
    }
    Write-WizLog $ctx 'ok' "fix applied: $fix"
}

# ---------------------------------------------------------------------------------------------------------------------
# Install steps
# ---------------------------------------------------------------------------------------------------------------------

function Add-WizDone($ctx, [string]$step, [hashtable]$data = @{}) { [void]$ctx.Done.Add(@{ Step = $step; Data = $data; At = Get-Date }) }

# MySQL 8 from the package's mysql\ dir as the BingooEdgeMySQL service. Khatri lessons: skip-log-bin (log_bin=OFF ENABLES a
# binlog named OFF), and with skip-name-resolve root@localhost is unreachable over TCP -> root@127.0.0.1 is created by the
# SAME one-time --init-file that sets every password (written to the user-only dir, deleted right after initialisation).
# The package's MySQL runtime: stream E's builder ships it AT <package>\mysql (bin\, share\); an older hand-made layout had a
# versioned sub-folder <package>\mysql\mysql-8.0.x-winx64. (CP5 prep: only the second was looked at, so a fresh install
# from a real 0.9 package would have stopped with WIZARD_NO_MYSQL.)
function Get-WizPackageMySqlDir($ctx) {
    $root = Join-Path $ctx.PackageRoot 'mysql'
    if (Test-Path (Join-Path $root 'bin\mysqld.exe')) { return $root }
    $sub = Get-ChildItem -Path $root -Directory -Filter 'mysql-*' -ErrorAction SilentlyContinue | Where-Object { Test-Path (Join-Path $_.FullName 'bin\mysqld.exe') } | Select-Object -First 1
    if ($sub) { return $sub.FullName }
    return $null
}

function Install-WizMySql($ctx) {
    $mode = Get-WizValue $ctx.Answers 'mysql.mode' 'install'
    $dbName = Get-WizValue $ctx.Answers 'mysql.db_name' 'bingoo_edge_local'
    $dbUser = Get-WizValue $ctx.Answers 'mysql.db_user' 'bingoo_edge'
    if ($dbName -notmatch '^(bingoo_edge_|edge_local_)' -and $dbName -notmatch 'edge.*test|test.*edge') { throw "WIZARD_DB_NAME: [$dbName] must be a dedicated Edge database (bingoo_edge_* / edge_local_*)" }
    if (-not $ctx.Secrets.ContainsKey('db_password')) {
        if ($mode -eq 'existing') { [void](Get-WizSecret $ctx 'db_password' 'Password of the existing MySQL user for the Edge database' -AllowEmpty) }
        else { $ctx.Secrets['db_password'] = New-WizRandomSecret 28 }   # never shown: lives in appliance.env only
    }
    $ctx.Db = @{ Host = Get-WizValue $ctx.Answers 'mysql.host' '127.0.0.1'; Port = [int](Get-WizValue $ctx.Answers 'mysql.port' 3306); Name = $dbName; User = $dbUser }
    if ($mode -eq 'existing') { Write-WizLog $ctx 'ok' "MySQL: using the existing server $($ctx.Db.Host):$($ctx.Db.Port) (database $dbName)"; return }

    $base = Get-WizValue $ctx.Answers 'mysql.base_dir' 'C:\BingooEdge'
    $svcName = Get-WizValue $ctx.Answers 'mysql.service_name' 'BingooEdgeMySQL'
    $mysqlDir = Join-Path $base 'mysql'
    $dataDir = Join-Path $base 'mysql-data'
    $rootPw = Get-WizSecret $ctx 'mysql_root' 'MySQL root password for this Branch Server (keep it in your password manager)'
    $existing = Get-Service -Name $svcName -ErrorAction SilentlyContinue
    if (-not $existing) {
        if (-not (Test-Path (Join-Path $mysqlDir 'bin\mysqld.exe'))) {
            $src = Get-WizPackageMySqlDir $ctx
            if (-not $src) { throw 'WIZARD_NO_MYSQL: the package has no MySQL runtime (mysql\bin\mysqld.exe)' }
            New-Item -ItemType Directory -Force -Path $mysqlDir | Out-Null
            & robocopy $src $mysqlDir /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 | Out-Null
            if ($LASTEXITCODE -ge 8) { throw "WIZARD_MYSQL_COPY: robocopy exit $LASTEXITCODE" }
            Add-WizDone $ctx 'mysql_files' @{ Dir = $mysqlDir }
        }
        $ini = @(
            '[mysqld]',
            "basedir=$($mysqlDir -replace '\\', '/')",
            "datadir=$($dataDir -replace '\\', '/')",
            "port=$($ctx.Db.Port)",
            'bind-address=127.0.0.1',
            'mysqlx=OFF',
            'skip-log-bin',
            'skip-name-resolve',
            'default_authentication_plugin=mysql_native_password',
            'character-set-server=utf8mb4',
            'collation-server=utf8mb4_unicode_ci',
            'max_allowed_packet=256M',
            'innodb_buffer_pool_size=256M',
            "log-error=$(($base -replace '\\', '/'))/mysql-error.log",
            '[client]',
            "port=$($ctx.Db.Port)"
        ) -join "`r`n"
        $iniPath = Join-Path $mysqlDir 'my.ini'
        [IO.File]::WriteAllText($iniPath, $ini + "`r`n", (New-Object Text.ASCIIEncoding))
        if (-not (Test-Path $dataDir)) {
            $edgePw = ConvertTo-WizPlain $ctx.Secrets['db_password']
            $rp = ConvertTo-WizPlain $rootPw
            $q = { param($s) $s.Replace('\', '\\').Replace("'", "''") }
            $sql = @(
                "ALTER USER 'root'@'localhost' IDENTIFIED BY '$(& $q $rp)';",
                "CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '$(& $q $rp)';",
                "GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;",
                "CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
                "CREATE USER IF NOT EXISTS '$dbUser'@'127.0.0.1' IDENTIFIED BY '$(& $q $edgePw)';",
                "CREATE USER IF NOT EXISTS '$dbUser'@'localhost' IDENTIFIED BY '$(& $q $edgePw)';",
                "GRANT ALL PRIVILEGES ON ``$dbName``.* TO '$dbUser'@'127.0.0.1', '$dbUser'@'localhost';",
                'FLUSH PRIVILEGES;'
            ) -join "`n"
            $edgePw = $null; $rp = $null
            $initFile = Write-WizSecretFile $ctx 'mysql-init' $sql
            $sql = $null
            try {
                $r = Invoke-WizProcess -FilePath (Join-Path $mysqlDir 'bin\mysqld.exe') -ArgumentList @("--defaults-file=$iniPath", '--initialize-insecure', "--init-file=$initFile", '--console') -TimeoutSec 600
            } finally { Remove-Item -Force -Path $initFile -ErrorAction SilentlyContinue }
            Add-WizDone $ctx 'mysql_data' @{ Dir = $dataDir }
            if ($r.ExitCode -ne 0 -or ($r.StdErr + $r.StdOut) -match '\[ERROR\]') { throw ('WIZARD_MYSQL_INIT: mysqld --initialize failed: ' + (($r.StdErr + $r.StdOut) -split "`n" | Where-Object { $_ -match 'ERROR' } | Select-Object -First 3) -join ' | ') }
        }
        $r = Invoke-WizProcess -FilePath (Join-Path $mysqlDir 'bin\mysqld.exe') -ArgumentList @('--install', $svcName, "--defaults-file=$iniPath") -TimeoutSec 60
        if ($r.ExitCode -ne 0) { throw ('WIZARD_MYSQL_SERVICE: ' + $r.StdOut + $r.StdErr) }
        Add-WizDone $ctx 'mysql_service' @{ Name = $svcName; Exe = (Join-Path $mysqlDir 'bin\mysqld.exe') }
        Set-Service -Name $svcName -StartupType Automatic
    }
    Start-Service -Name $svcName
    $deadline = (Get-Date).AddSeconds(90)
    while ((Get-Date) -lt $deadline -and -not (Get-NetTCPConnection -State Listen -LocalPort $ctx.Db.Port -ErrorAction SilentlyContinue)) { Start-Sleep -Seconds 2 }
    # Prove the Edge account works over TCP without putting its password on a command line (client option file, deleted).
    $cnf = Write-WizSecretFile $ctx 'mysql-client' ("[client]`r`nuser=$dbUser`r`npassword=" + (ConvertTo-WizPlain $ctx.Secrets['db_password']) + "`r`nhost=127.0.0.1`r`nport=$($ctx.Db.Port)`r`n")
    try {
        $r = Invoke-WizProcess -FilePath (Join-Path $mysqlDir 'bin\mysql.exe') -ArgumentList @("--defaults-extra-file=$cnf", '-N', '-e', "SELECT 1 FROM DUAL; USE ``$dbName``;") -TimeoutSec 60
    } finally { Remove-Item -Force -Path $cnf -ErrorAction SilentlyContinue }
    if ($r.ExitCode -ne 0) { throw ('WIZARD_MYSQL_CHECK: the Edge database account does not work: ' + $r.StdErr.Trim()) }
    Write-WizLog $ctx 'ok' "MySQL: service $svcName running on 127.0.0.1:$($ctx.Db.Port), database $dbName ready"
}

# Branch CA (private key non-exportable, LocalMachine\My) + server certificate with DNS + IP SAN, exported as a PFX whose
# password is random, never shown, and deleted by the consumer (New-EdgeServerCertificate, then edge:local:gateway-cert).
function New-WizCertificates($ctx) {
    $mode = Get-WizValue $ctx.Answers 'certificates.mode' 'branch_ca'
    $ctx.Lan = Resolve-WizLan $ctx
    if ($mode -eq 'self_signed_lab') { Write-WizLog $ctx 'warn' 'certificates: LAB self-signed gateway certificate (never at a live branch)'; return }
    $scripts = Join-Path $ctx.PackageRoot 'scripts'
    $caDir = Join-Path $ctx.DataRoot 'ca'
    $certDir = Join-Path $ctx.DataRoot 'certs-issue'
    $label = Get-WizValue $ctx.Answers 'certificates.branch_label' ($ctx.Setup.TenantCode + '-' + $ctx.Setup.BranchId)
    $before = @(Get-ChildItem Cert:\LocalMachine\My | ForEach-Object { $_.Thumbprint })
    $out = & (Join-Path $scripts 'New-EdgeBranchCA.ps1') -BranchName $label -OutDir $caDir 6>&1 | Out-String
    $ca = Get-ChildItem Cert:\LocalMachine\My | Where-Object { $before -notcontains $_.Thumbprint -and $_.Subject -like '*Bingoo Edge Branch CA*' } | Select-Object -First 1
    if (-not $ca) { throw 'WIZARD_CA_FAILED: the branch CA was not created' }
    Add-WizDone $ctx 'cert' @{ Thumbprint = $ca.Thumbprint }
    if (-not $ctx.Secrets.ContainsKey('pfx')) { $ctx.Secrets['pfx'] = New-WizRandomSecret 32 }
    $pfxPwFile = Write-WizSecretFile $ctx 'pfx' $ctx.Secrets['pfx']
    $before = @(Get-ChildItem Cert:\LocalMachine\My | ForEach-Object { $_.Thumbprint })
    & (Join-Path $scripts 'New-EdgeServerCertificate.ps1') -Hostname $ctx.Lan.Hostname -ReservedIp $ctx.Lan.Ip -CaThumbprint $ca.Thumbprint -OutDir $certDir -ExportPfx -PfxPasswordFile $pfxPwFile 6>&1 | Out-Null
    Remove-Item -Force -Path $pfxPwFile -ErrorAction SilentlyContinue
    $srv = Get-ChildItem Cert:\LocalMachine\My | Where-Object { $before -notcontains $_.Thumbprint } | Select-Object -First 1
    if ($srv) { Add-WizDone $ctx 'cert' @{ Thumbprint = $srv.Thumbprint } }
    $ctx.Certs = @{ CaFile = Join-Path $caDir 'bingoo-edge-branch-ca.crt'; Pfx = Join-Path $certDir 'bingoo-edge-server.pfx'; CaThumbprint = $ca.Thumbprint }
    if (-not (Test-Path $ctx.Certs.Pfx)) { throw 'WIZARD_CERT_FAILED: the server certificate PFX was not written' }
    Write-WizLog $ctx 'ok' ("certificates: branch CA " + $ca.Thumbprint + ", server certificate for " + $ctx.Lan.Hostname + ' / ' + $ctx.Lan.Ip)
}

function Resolve-WizLan($ctx) {
    $ip = Get-WizValue $ctx.Answers 'lan.ip' 'auto'
    if (-not $ip -or $ip -eq 'auto') { $lan = Get-WizLanInfo; if ($lan) { $ip = $lan.Ip } else { throw 'WIZARD_NO_LAN_IP' } }
    return @{ Ip = $ip; Hostname = Get-WizValue $ctx.Answers 'lan.hostname' 'bingoo-edge.local'; HttpsPort = [int](Get-WizValue $ctx.Answers 'lan.https_port' 8443); HttpPort = [int](Get-WizValue $ctx.Answers 'lan.http_port' 80) }
}

# Install-EdgeAppliance.ps1 as a child process; its [NN] step lines become progress; the pairing code is written to the
# one-time file only when the installer asks for it (so a slow db-init never burns the 15-minute code).
function Invoke-WizApplianceInstall($ctx) {
    if (-not $ctx.Lan) { $ctx.Lan = Resolve-WizLan $ctx }
    $updateKey = Resolve-WizUpdatePublicKey $ctx
    $script = Join-Path $ctx.PackageRoot 'scripts\Install-EdgeAppliance.ps1'
    $dbPwFile = Write-WizSecretFile $ctx 'db' $ctx.Secrets['db_password']
    $codeFile = Join-Path (New-WizSecureDir $ctx) ('pairing-' + [guid]::NewGuid().ToString('N').Substring(0, 8) + '.code')
    $a = @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script,
        '-PackageRoot', $ctx.PackageRoot, '-InstallRoot', $ctx.InstallRoot, '-DataRoot', $ctx.DataRoot,
        '-DbHost', $ctx.Db.Host, '-DbPort', [string]$ctx.Db.Port, '-DbName', $ctx.Db.Name, '-DbUser', $ctx.Db.User, '-DbPasswordFile', $dbPwFile,
        '-CloudUrl', $ctx.Setup.CloudUrl, '-PairingCodeFile', $codeFile, '-DeviceName', $ctx.Setup.DeviceName, '-InstallationUuid', $ctx.InstallationUuid,
        '-EnrollmentPublicKey', $ctx.Setup.EnrollmentPublicKey, '-UpdatePublicKey', $updateKey,
        '-LanHostname', $ctx.Lan.Hostname, '-LanIp', $ctx.Lan.Ip, '-HttpsPort', [string]$ctx.Lan.HttpsPort, '-HttpPort', [string]$ctx.Lan.HttpPort,
        '-WebWorkers', [string](Get-WizValue $ctx.Answers 'services.web_workers' 2), '-WebPortBase', [string](Get-WizValue $ctx.Answers 'services.web_port_base' 8090),
        '-ServiceAccount', (Get-WizValue $ctx.Answers 'services.service_account' 'NT AUTHORITY\LOCAL SERVICE'))
    $php = Get-WizValue $ctx.Answers 'php_path' $null
    if ($php) { $a += @('-PhpPath', $php) }
    $gw = Get-WizValue $ctx.Answers 'services.gateway_path' $null
    if ($gw) { $a += @('-GatewayPath', $gw) }
    if ($ctx.Certs -and $ctx.Certs.Pfx) { $a += @('-CertPfx', $ctx.Certs.Pfx, '-CertPfxPasswordFile', (Write-WizSecretFile $ctx 'pfx2' $ctx.Secrets['pfx'])) }
    elseif ((Get-WizValue $ctx.Answers 'certificates.mode' 'branch_ca') -eq 'self_signed_lab') { $a += '-SelfSignedCert' }
    if (-not (Get-WizValue $ctx.Answers 'services.register' $true)) { $a += '-NoServices' }
    if (-not (Get-WizValue $ctx.Answers 'services.gateway' $true)) { $a += '-NoGateway' }
    if ($ctx.AllowHttpCloud) { $a += '-AllowHttpCloud' }
    $w = $ctx.Setup.MaintenanceWindow
    if ($w) {
        if (Get-WizValue $w 'start' $null) { $a += @('-AutoUpdateWindowStart', [string]$w.start) }
        if (Get-WizValue $w 'end' $null) { $a += @('-AutoUpdateWindowEnd', [string]$w.end) }
        if (Get-WizValue $w 'tz' $null) { $a += @('-AutoUpdateWindowTz', [string]$w.tz) }
    }
    $logOut = Join-Path (Split-Path -Parent $ctx.LogFile) ('install-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
    $logErr = $logOut + '.err'
    Write-WizLog $ctx 'step' 'installing the Branch Server (Install-EdgeAppliance.ps1)'
    $p = Start-Process -FilePath 'powershell.exe' -ArgumentList (ConvertTo-WizArgString $a) -RedirectStandardOutput $logOut -RedirectStandardError $logErr -NoNewWindow -PassThru
    $null = $p.Handle   # PS 5.1: without touching Handle first, ExitCode stays $null after a -PassThru process exits
    Add-WizDone $ctx 'appliance' @{ InstallRoot = $ctx.InstallRoot; DataRoot = $ctx.DataRoot }
    $seen = 0
    $codeWritten = $false
    try {
    while (-not $p.HasExited) {
        Start-Sleep -Milliseconds 700
        $lines = @(Read-WizSharedLines $logOut)
        for ($i = $seen; $i -lt $lines.Count; $i++) {
            $l = $lines[$i]
            if ($l -match '^\[\s*(\d+)\]\s*(.+)$') { Write-WizLog $ctx 'step' ("install step " + $Matches[1] + ': ' + $Matches[2]) }
            elseif ($l -match 'package OK|Paired\.|Bootstrap applied|INSTALL COMPLETE|device_id|tenant\s+:|branch_id\s+:') { Write-WizLog $ctx 'ok' $l.Trim() }
            elseif ($l -match 'INSTALL FAILED|Pairing failed|failed') { Write-WizLog $ctx 'error' $l.Trim() }
            if (-not $codeWritten -and $l -match 'waiting up to') {
                if (($ctx.Setup.PairingExpiresAt - (Get-Date).ToUniversalTime()).TotalMinutes -lt 3) { [void](Update-WizPairingCode $ctx) }
                $tmp = Write-WizSecretFile $ctx 'pairing-tmp' $ctx.Setup.PairingCode
                Move-Item -Force -Path $tmp -Destination $codeFile
                $codeWritten = $true
                Write-WizLog $ctx 'info' 'pairing code handed to the installer (one-time file)'
            }
        }
        $seen = $lines.Count
    }
    } catch {
        # Never leave the installer running behind a failed wizard step (it would wait for a pairing code / keep writing).
        if (-not $p.HasExited) { & taskkill.exe /PID $p.Id /T /F | Out-Null }
        foreach ($f in @($dbPwFile, $codeFile)) { Remove-Item -Force -Path $f -ErrorAction SilentlyContinue }
        throw
    }
    $p.WaitForExit()
    $lines = @(Read-WizSharedLines $logOut)
    for ($i = $seen; $i -lt $lines.Count; $i++) { if ($lines[$i] -match '^\[\s*(\d+)\]|INSTALL') { Write-WizLog $ctx 'info' $lines[$i].Trim() } }
    foreach ($f in @($dbPwFile, $codeFile)) { Remove-Item -Force -Path $f -ErrorAction SilentlyContinue }
    $all = ($lines -join "`n")
    $exit = $p.ExitCode
    if (($null -ne $exit -and $exit -ne 0) -or $all -notmatch 'INSTALL COMPLETE' -or $all -match 'INSTALL FAILED') {
        $why = ($lines | Where-Object { $_ -match 'INSTALL FAILED|failed|error' } | Select-Object -Last 3) -join ' | '
        throw ('WIZARD_INSTALL_FAILED: ' + $why + " (full log: $logOut)")
    }
    $dev = [regex]::Match($all, 'device_id\s*:\s*(\S+)')
    if ($dev.Success) { $ctx.DeviceId = $dev.Groups[1].Value }
    Write-WizLog $ctx 'ok' ('Branch Server installed, paired and bootstrapped' + $(if ($ctx.DeviceId) { ' (device ' + $ctx.DeviceId + ')' } else { '' }))
}

function Read-WizSharedLines([string]$path) {
    if (-not (Test-Path $path)) { return @() }
    $fs = [IO.File]::Open($path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite)
    try { $text = (New-Object IO.StreamReader($fs)).ReadToEnd() } finally { $fs.Close() }
    return @($text -split "`r?`n" | Where-Object { $_ -ne '' })
}

function Add-WizFirewallRule($ctx) {
    if (-not (Get-WizValue $ctx.Answers 'services.firewall' $true)) { return }
    $name = 'Bingoo Edge gateway'
    if (-not (Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue)) {
        $nginx = Join-Path $ctx.InstallRoot 'gateway\nginx.exe'
        New-NetFirewallRule -DisplayName $name -Direction Inbound -Action Allow -Protocol TCP -LocalPort @($ctx.Lan.HttpsPort, $ctx.Lan.HttpPort) -Program $nginx -Profile Private, Public | Out-Null
        Add-WizDone $ctx 'firewall' @{ Name = $name }
    }
    Write-WizLog $ctx 'ok' "firewall: inbound TCP $($ctx.Lan.HttpsPort),$($ctx.Lan.HttpPort) to the gateway only (MySQL and the PHP backends stay loopback)"
}

function Add-WizHostsLine($ctx) {
    if (-not (Get-WizValue $ctx.Answers 'services.hosts_line' $true)) { return }
    $hosts = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
    $lines = @(Get-Content -Path $hosts -ErrorAction SilentlyContinue)
    $re = '\s' + [regex]::Escape($ctx.Lan.Hostname) + '\s*$'
    $keep = @($lines | Where-Object { $_ -notmatch $re })
    $new = $keep + ("$($ctx.Lan.Ip)  $($ctx.Lan.Hostname)")
    if (($new -join "`n") -ne ($lines -join "`n")) {
        Set-Content -Path $hosts -Value $new -Encoding ASCII
        Add-WizDone $ctx 'hosts' @{ Hostname = $ctx.Lan.Hostname; Previous = ($lines | Where-Object { $_ -match $re }) }
    }
    Write-WizLog $ctx 'ok' "hosts: $($ctx.Lan.Ip) $($ctx.Lan.Hostname)"
}

# ---------------------------------------------------------------------------------------------------------------------
# Users & PINs
# ---------------------------------------------------------------------------------------------------------------------

function Get-WizLocalUsers($ctx) {
    $r = Invoke-WizArtisan $ctx @('edge:local:users', '--json') -TimeoutSec 120
    $doc = ConvertFrom-WizJsonOutput $r.StdOut
    if ($r.ExitCode -ne 0 -or -not $doc) { throw ('WIZARD_USERS_FAILED: ' + ($r.StdErr + $r.StdOut).Trim()) }
    return @($doc.users)
}

# The same rules edge:local:enroll enforces, checked BEFORE anything is sent (>= 6 digits, not one repeated digit, no default).
function Test-WizPin([Security.SecureString]$pin) {
    $p = ConvertTo-WizPlain $pin
    try {
        if ($p -notmatch '^\d{6,12}$') { return 'a PIN is 6 to 12 digits' }
        if ($p -match '^(\d)\1+$') { return 'a PIN must not be one repeated digit' }
        if (@('123456', '1234567', '12345678', '654321', '000000', '111111') -contains $p) { return 'that PIN is too easy to guess' }
        return $null
    } finally { $p = $null }
}

# $Pins: hashtable userId -> SecureString. Each user is enrolled with a Cloud-signed 15-minute assertion (K3) and a PIN file
# (read then deleted by edge:local:enroll). Returns one result row per user. Assertion files are deleted in every case.
function Invoke-WizEnrollUsers($ctx, [hashtable]$Pins) {
    $ids = @($Pins.Keys | ForEach-Object { [int]$_ } | Sort-Object)
    if ($ids.Count -eq 0) { return @() }
    foreach ($id in $ids) { $why = Test-WizPin $Pins[$id]; if ($why) { throw "WIZARD_PIN_INVALID: user #$id - $why" } }
    $dir = Join-Path (New-WizSecureDir $ctx) ('assertions-' + [guid]::NewGuid().ToString('N').Substring(0, 6))
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    $tokFile = Write-WizSecretFile $ctx 'setup-token' $ctx.Setup.SetupToken
    $results = New-Object System.Collections.ArrayList
    try {
        $all = @()
        $skippedAll = @()
        # K3 (stream C): at most 300 users per call; assertions live 15 minutes, so each chunk is enrolled right away.
        for ($i = 0; $i -lt $ids.Count; $i += 300) {
        $chunk = @($ids[$i..([Math]::Min($i + 299, $ids.Count - 1))])
        if ($i -gt 0) { $tokFile = Write-WizSecretFile $ctx 'setup-token' $ctx.Setup.SetupToken }
        $r = Invoke-WizArtisan $ctx @('edge:local:users', "--assertions-dir=$dir", "--setup-token-file=$tokFile", ('--ids=' + ($chunk -join ',')), '--json') -TimeoutSec 120
        $doc = ConvertFrom-WizJsonOutput $r.StdOut
        if ($r.ExitCode -ne 0 -or -not $doc -or $doc.result -ne 'ok') {
            $why = $(if ($doc -and $doc.PSObject.Properties['error']) { [string]$doc.error } else { ($r.StdErr + $r.StdOut).Trim() })
            if ($why -match 'EDGE_SETUP_ENROLLMENT_UNAVAILABLE') { $why = 'the Cloud cannot issue PINs before the Branch Server finished its first sync (bootstrap) - ' + $why }
            if ($why -match 'EDGE_SETUP_TOKEN_SCOPE|SETUP_TOKEN') { $why = 'the setup session expired or belongs to another installation - create a new setup code on the Cloud page - ' + $why }
            throw ('WIZARD_ASSERTIONS_FAILED: ' + $why)
        }
        foreach ($s in @($doc.skipped)) { [void]$results.Add([pscustomobject]@{ UserId = [int]$s.user_id; Ok = $false; Detail = 'skipped by the Cloud: ' + $s.reason }) }
        foreach ($as in @($doc.assertions)) {
            $uid = [int]$as.user_id
            $pinFile = Write-WizSecretFile $ctx 'pin' $Pins[$uid]
            try {
                $e = Invoke-WizArtisan $ctx @('edge:local:enroll', [string]$as.file, '--type=pin', "--credential-file=$pinFile") -TimeoutSec 120
            } finally {
                Remove-Item -Force -Path $pinFile -ErrorAction SilentlyContinue
                Remove-Item -Force -Path ([string]$as.file) -ErrorAction SilentlyContinue
            }
            $ok = $e.ExitCode -eq 0
            $detail = $(if ($ok) { 'PIN set' } else { (($e.StdErr + $e.StdOut).Trim() -split "`n" | Select-Object -Last 1) })
            [void]$results.Add([pscustomobject]@{ UserId = $uid; Ok = $ok; Detail = $detail; EmployeeCode = [string]$as.employee_code; Name = [string]$as.name })
            Write-WizLog $ctx $(if ($ok) { 'ok' } else { 'error' }) ("user #$uid " + [string]$as.employee_code + ': ' + $detail)
        }
        }
    } finally {
        Remove-Item -Force -Path $tokFile -ErrorAction SilentlyContinue
        Remove-Item -Recurse -Force -Path $dir -ErrorAction SilentlyContinue
    }
    return , $results.ToArray()
}

# ---------------------------------------------------------------------------------------------------------------------
# Finish
# ---------------------------------------------------------------------------------------------------------------------

function Send-WizReports($ctx, [string]$phase = 'ready', [string]$message = '') {
    if ($ctx.Certs -and $ctx.Certs.CaFile -and (Test-Path $ctx.Certs.CaFile)) {
        $r = Invoke-WizArtisan $ctx @('edge:local:report-lan-profile', "--ca-file=$($ctx.Certs.CaFile)", "--hostname=$($ctx.Lan.Hostname)", "--lan-ip=$($ctx.Lan.Ip)", "--https-port=$($ctx.Lan.HttpsPort)", '--json') -TimeoutSec 60
    } else {
        $r = Invoke-WizArtisan $ctx @('edge:local:report-lan-profile', "--hostname=$($ctx.Lan.Hostname)", "--lan-ip=$($ctx.Lan.Ip)", "--https-port=$($ctx.Lan.HttpsPort)", '--json') -TimeoutSec 60
    }
    Write-WizLog $ctx 'info' ('K5 LAN profile: ' + ($r.StdOut.Trim()))
    $s = Invoke-WizArtisan $ctx @('edge:local:report-status', $phase, "--message=$message", '--json') -TimeoutSec 60
    Write-WizLog $ctx 'info' ("K4 $phase" + ': ' + $s.StdOut.Trim())
}

function Get-WizOnlinePosUrl($ctx) {
    if ($ctx.Setup -and $ctx.Setup.OnlinePosUrl) { return $ctx.Setup.OnlinePosUrl }
    $u = [Uri]$ctx.CloudUrl
    $cloudHost = $u.Host
    if ($cloudHost -match '^\d+\.\d+\.\d+\.\d+$' -or $cloudHost -eq 'localhost') { return $ctx.CloudUrl + '/pos' }
    return $u.Scheme + '://' + $ctx.Setup.TenantCode + '.' + $cloudHost + '/pos'
}

# Three desktop links for ALL users (Public Desktop) with the Bingoo icon; answers.shortcuts.dir redirects them (rehearsal).
function New-WizShortcuts($ctx) {
    if (-not (Get-WizValue $ctx.Answers 'shortcuts.create' $true)) { return @() }
    $dir = Get-WizValue $ctx.Answers 'shortcuts.dir' (Join-Path $env:PUBLIC 'Desktop')
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    $iconDir = Get-WizValue $ctx.Answers 'shortcuts.icon_dir' (Join-Path $env:ProgramData 'BingooPOS')
    New-Item -ItemType Directory -Force -Path $iconDir | Out-Null
    $icon = Join-Path $iconDir 'bingoo.ico'
    Copy-Item -Force -Path (Join-Path $PSScriptRoot 'assets\bingoo.ico') -Destination $icon
    $base = 'https://' + $ctx.Lan.Hostname + $(if ($ctx.Lan.HttpsPort -ne 443) { ':' + $ctx.Lan.HttpsPort } else { '' })
    $links = [ordered]@{
        'BINGOO Online' = Get-WizOnlinePosUrl $ctx
        'BINGOO Offline' = $base + '/edge/local/login'
        'Branch Server Status' = $base + '/edge/local/pos/health'
    }
    $made = @()
    foreach ($name in $links.Keys) {
        $p = Join-Path $dir ($name + '.url')
        Set-Content -Path $p -Value @('[InternetShortcut]', ('URL=' + $links[$name]), ('IconFile=' + $icon), 'IconIndex=0') -Encoding ASCII
        $made += $p
    }
    try { & ie4uinit.exe -show } catch { }
    Write-WizLog $ctx 'ok' ('shortcuts: ' + ($made -join ', '))
    return $made
}

function Get-WizHealth($ctx, [switch]$Package) {
    $r = Invoke-WizArtisan $ctx @('edge:local:health', '--json') -Package:$Package -TimeoutSec 180
    $doc = ConvertFrom-WizJsonOutput $r.StdOut
    if (-not $doc) { return [pscustomobject]@{ error = ('health unavailable (exit ' + $r.ExitCode + '): ' + ($r.StdErr.Trim() -split "`n" | Select-Object -Last 1)) } }
    return $doc
}

# The same verdict as the PHP auto-updater: target version, no boot problem, DB reachable, bound, no NEW non-printer problem.
function Test-WizHealthAfter($before, $after, [string]$expectedVersion) {
    $reasons = @()
    if ($after.PSObject.Properties['error']) { return @{ Ok = $false; Reasons = @([string]$after.error) } }
    $v = [string](Get-WizValue $after 'runtime.edge_app_version' '')
    if ($expectedVersion -and $v -ne $expectedVersion) { $reasons += "runtime reports $v, expected $expectedVersion" }
    foreach ($b in @(Get-WizValue $after 'runtime.boot_problems' @())) { if ($b) { $reasons += "boot: $b" } }
    if (-not (Get-WizValue $after 'database.reachable' $false)) { $reasons += 'local database unreachable' }
    if ([string]$after.status -eq 'NOT_BOUND') { $reasons += 'NOT_BOUND' }
    $old = @()
    if ($before -and -not $before.PSObject.Properties['error']) { $old = @($before.problems) }
    foreach ($p in @($after.problems)) { if ($p -and $old -notcontains $p -and $p -notmatch '(?i)printer|print job') { $reasons += "new problem: $p" } }
    return @{ Ok = ($reasons.Count -eq 0); Reasons = $reasons }
}

# ---------------------------------------------------------------------------------------------------------------------
# Rollback of a failed install (the steps THIS run did, newest first)
# ---------------------------------------------------------------------------------------------------------------------

function Undo-WizInstall($ctx) {
    $items = @($ctx.Done.ToArray())
    [array]::Reverse($items)
    foreach ($d in $items) {
        try {
            switch ($d.Step) {
                'hosts' {
                    $hosts = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
                    $re = '\s' + [regex]::Escape($d.Data.Hostname) + '\s*$'
                    $keep = @(Get-Content -Path $hosts | Where-Object { $_ -notmatch $re }) + @($d.Data.Previous | Where-Object { $_ })
                    Set-Content -Path $hosts -Value $keep -Encoding ASCII
                }
                'firewall' { Remove-NetFirewallRule -DisplayName $d.Data.Name -ErrorAction SilentlyContinue }
                'appliance' {
                    $uninstall = Join-Path $ctx.PackageRoot 'scripts\Uninstall-EdgeAppliance.ps1'
                    if (Test-Path (Join-Path $d.Data.InstallRoot 'appliance.json')) {
                        $ua = @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $uninstall, '-InstallRoot', $d.Data.InstallRoot)
                        if (-not (Get-WizValue $ctx.Answers 'services.register' $true)) { $ua += '-NoServices' }
                        $r = Invoke-WizProcess -FilePath 'powershell.exe' -ArgumentList $ua -TimeoutSec 600
                        Write-WizLog $ctx 'info' ('uninstall runtime: exit ' + $r.ExitCode)
                    } elseif (Test-Path $d.Data.InstallRoot) { Remove-Item -Recurse -Force -Path (Join-Path $d.Data.InstallRoot 'runtime') -ErrorAction SilentlyContinue }
                    $envFile = Join-Path $d.Data.DataRoot 'config\appliance.env'
                    if (Test-Path $envFile) {
                        $paired = (Get-Content -Raw -Path $envFile) -match '(?m)^EDGE_SYNC_DEVICE_ID=\S'
                        Remove-Item -Force -Path $envFile
                        if ($paired) { Write-WizLog $ctx 'warn' 'the device had already paired: Revoke it on the Cloud Offline Edge page before the next attempt' }
                    }
                }
                'cert' { Get-ChildItem Cert:\LocalMachine\My | Where-Object { $_.Thumbprint -eq $d.Data.Thumbprint } | Remove-Item -Force -ErrorAction SilentlyContinue }
                'mysql_service' {
                    Stop-Service -Name $d.Data.Name -ErrorAction SilentlyContinue
                    Invoke-WizProcess -FilePath $d.Data.Exe -ArgumentList @('--remove', $d.Data.Name) -TimeoutSec 60 | Out-Null
                }
                'mysql_data' { Remove-Item -Recurse -Force -Path $d.Data.Dir -ErrorAction SilentlyContinue }
                'mysql_files' { Remove-Item -Recurse -Force -Path $d.Data.Dir -ErrorAction SilentlyContinue }
            }
            Write-WizLog $ctx 'ok' ('rolled back: ' + $d.Step)
        } catch { Write-WizLog $ctx 'error' ('rollback of ' + $d.Step + ' failed: ' + $_.Exception.Message) }
    }
    $ctx.Done.Clear()
}

# ---------------------------------------------------------------------------------------------------------------------
# Orchestrators (headless; the UI calls the same step functions page by page)
# ---------------------------------------------------------------------------------------------------------------------

# ONE non-secret key of the installed appliance.env (e.g. EDGE_INSTALLATION_UUID); the secrets in that file are never read here.
function Get-WizApplianceEnvValue($ctx, [string]$key) {
    if ($key -match '(?i)SECRET|PASSWORD|KEY$|TOKEN') { throw "WIZARD_REFUSED: $key is not read by the wizard" }
    $file = Join-Path $ctx.DataRoot 'config\appliance.env'
    if (-not (Test-Path $file)) { return $null }
    foreach ($line in (Get-Content -Path $file)) {
        if ($line -match ('^' + [regex]::Escape($key) + '=(.*)$')) { return $Matches[1].Trim().Trim("'").Trim('"') }
    }
    return $null
}

function Test-WizInstalled($ctx) { return (Test-Path (Join-Path $ctx.DataRoot 'config\appliance.env')) -and (Test-Path (Join-Path $ctx.InstallRoot 'appliance.json')) }

# Users to enrol + their PINs (headless): answers.users.enroll = "all" | "none" | [ids]; a PIN per user from the secret source
# (pin_<id>), or ONE PIN for answers.users.same_pin_for only when answers.users.same_pin_acknowledged is true.
function Get-WizHeadlessPins($ctx, $users) {
    $sel = Get-WizValue $ctx.Answers 'users.enroll' 'all'
    $ids = @()
    if ($sel -is [string]) { if ($sel -eq 'all') { $ids = @($users | Where-Object { $_.employee_code -and -not $_.enrolled -and $_.status -ne 'inactive' } | ForEach-Object { [int]$_.id }) } }
    else { $ids = @($sel | ForEach-Object { [int]$_ }) }
    $same = @(Get-WizValue $ctx.Answers 'users.same_pin_for' @() | ForEach-Object { [int]$_ })
    $ack = [bool](Get-WizValue $ctx.Answers 'users.same_pin_acknowledged' $false)
    if ($same.Count -gt 0 -and -not $ack) { throw 'WIZARD_SAME_PIN_NOT_ACKNOWLEDGED: one PIN for several users needs users.same_pin_acknowledged = true' }
    $pins = @{}
    $shared = $null
    foreach ($id in $ids) {
        if ($same -contains $id) {
            if (-not $shared) { $shared = Get-WizSecret $ctx 'pin_shared' 'ONE PIN for the selected users (they will share it)' }
            $pins[$id] = $shared
        } else {
            $u = $users | Where-Object { [int]$_.id -eq $id } | Select-Object -First 1
            $pins[$id] = Get-WizSecret $ctx ('pin_' + $id) ("Edge PIN for " + $u.name + " (" + $u.employee_code + "), 6+ digits")
        }
    }
    return $pins
}

function Invoke-WizInstall($ctx) {
    [void](Invoke-WizPrereqs $ctx)
    $checks = Test-WizPrerequisites $ctx
    foreach ($fix in @('clock', 'sleep', 'network_private')) {
        $want = Get-WizValue $ctx.Answers ('prerequisites.fix_' + $fix) $false
        if ($want -and ($checks | Where-Object { $_.Fix -eq $fix -and $_.Status -ne 'ok' })) { Invoke-WizFix $ctx $fix }
    }
    $fatal = @($checks | Where-Object { $_.Status -eq 'fail' -and $_.Id -ne 'clock' })
    if ($fatal.Count -gt 0) { throw ('WIZARD_PREREQUISITES: ' + (($fatal | ForEach-Object { $_.Name + ' - ' + $_.Detail }) -join '; ')) }
    $code = Get-WizSecret $ctx 'setup_code' 'Setup code from Cloud > Settings > Offline Edge > Set up a Branch Server'
    $setup = Invoke-WizRedeem $ctx $code
    Write-WizLog $ctx 'step' ("installing for " + $setup.TenantName + ' / ' + $setup.BranchName)
    try {
        Install-WizMySql $ctx
        New-WizCertificates $ctx
        Invoke-WizApplianceInstall $ctx
        if (Get-WizValue $ctx.Answers 'services.register' $true) { Add-WizFirewallRule $ctx; Add-WizHostsLine $ctx }
    } catch {
        Write-WizLog $ctx 'error' $_.Exception.Message
        if (Get-WizValue $ctx.Answers 'rollback_on_failure' $true) { Write-WizLog $ctx 'step' 'rolling back the steps already done'; Undo-WizInstall $ctx }
        throw
    }
    Invoke-WizArtisan $ctx @('edge:local:report-status', 'bootstrapped', '--message=installed by the setup wizard') | Out-Null
    $users = Get-WizLocalUsers $ctx
    $pins = Get-WizHeadlessPins $ctx $users
    $enrolled = Invoke-WizEnrollUsers $ctx $pins
    $okCount = @($enrolled | Where-Object { $_.Ok }).Count
    Invoke-WizArtisan $ctx @('edge:local:report-status', 'enrolled', "--message=$okCount user(s) enrolled") | Out-Null
    $shortcuts = New-WizShortcuts $ctx
    $health = Get-WizHealth $ctx
    Send-WizReports $ctx 'ready' ("setup wizard finished: $okCount user(s), health " + [string](Get-WizValue $health 'status' '?'))
    Remove-WizSecureDir $ctx
    return [pscustomobject]@{ Mode = 'install'; Tenant = $setup.TenantCode; Branch = $setup.BranchId; DeviceId = $ctx.DeviceId; Users = $enrolled; Shortcuts = $shortcuts; Health = [string](Get-WizValue $health 'status' '?'); HealthReport = $health }
}

# What the update page shows BEFORE anything changes: installed vs package version, the branch's authority / outbox, and the
# exact migration plan the NEW runtime would run (the owner approves its hash; Invoke-WizUpdate refuses another plan).
function Get-WizUpdatePlan($ctx) {
    $manifest = Get-WizPackageManifest $ctx
    $layout = Get-Content -Raw -Path (Join-Path $ctx.InstallRoot 'appliance.json') | ConvertFrom-Json
    $previous = (Get-Content -Raw -Path (Join-Path ([string]$layout.runtime_root) 'current')).Trim()
    $health = Get-WizHealth $ctx
    $upd = @(Get-ChildItem -Path (Join-Path $ctx.PackageRoot 'update') -Filter 'edge-update-*.json')[0].FullName
    $dry = Invoke-WizArtisan $ctx @('edge:local:update', $upd, (Join-Path $ctx.PackageRoot 'app'), '--dry-run', '--json') -TimeoutSec 900
    $verdict = ConvertFrom-WizJsonOutput $dry.StdOut
    $ok = ($dry.ExitCode -eq 0 -and $verdict -and [string]$verdict.verdict -eq 'OK')
    return [pscustomobject]@{
        From = $previous; To = [string]$manifest.edge_app_version; Ok = $ok
        Error = $(if ($ok) { $null } else { ($dry.StdErr + $dry.StdOut).Trim() })
        PlanHash = $(if ($ok) { [string]$verdict.plan_hash } else { $null })
        Migrations = $(if ($ok) { @($verdict.plan.entries | ForEach-Object { [string]$_.migration }) } else { @() })
        SchemaChange = $(if ($ok) { (@($verdict.plan.run).Count + @(Get-WizValue $verdict 'plan.substitute' @()).Count) -gt 0 } else { $false })
        Authority = [string](Get-WizValue $health 'authority.state' 'unknown')
        Pending = [int](Get-WizValue $health 'sync.outbox_pending' 0) + [int](Get-WizValue $health 'sync.outbox_leased' 0)
    }
}

# UPDATE MODE (0.8.5 -> 0.9 at Khatri, no re-pairing): verify -> gate (standby, outbox empty) -> full DB snapshot -> signed
# update through Update-EdgeAppliance.ps1 (-ReRegisterServices adds BingooEdgeUpdater) -> health -> K6 rollback rule.
# The PUBLIC branch CA certificate for K5. A wizard install writes it to <DataRoot>\ca; a 0.8.x site set up by hand (the Khatri
# kit: New-EdgeBranchCA.ps1 -OutDir C:\BingooEdge\ca) keeps it next to the MySQL base dir - both are looked at, an explicit
# answers lan.ca_file first. Public material only.
function Find-WizBranchCaFile($ctx) {
    $candidates = @()
    $explicit = Get-WizValue $ctx.Answers 'lan.ca_file' $null
    if ($explicit) { $candidates += [string]$explicit }
    $candidates += (Join-Path $ctx.DataRoot 'ca\bingoo-edge-branch-ca.crt')
    $candidates += (Join-Path ([string](Get-WizValue $ctx.Answers 'mysql.base_dir' 'C:\BingooEdge')) 'ca\bingoo-edge-branch-ca.crt')
    foreach ($c in $candidates) { if ($c -and (Test-Path -LiteralPath $c)) { return $c } }
    return $null
}
function Invoke-WizUpdate($ctx) {
    $manifest = Get-WizPackageManifest $ctx
    $to = [string]$manifest.edge_app_version
    $layout = Get-Content -Raw -Path (Join-Path $ctx.InstallRoot 'appliance.json') | ConvertFrom-Json
    $runtimeRoot = [string]$layout.runtime_root
    $previous = (Get-Content -Raw -Path (Join-Path $runtimeRoot 'current')).Trim()
    Write-WizLog $ctx 'step' "update mode: installed $previous -> package $to"
    [void](Invoke-WizPrereqs $ctx)
    [void](Resolve-WizUpdatePublicKey $ctx)
    $before = Get-WizHealth $ctx
    if ($before.PSObject.Properties['error']) { throw ('WIZARD_UPDATE_HEALTH: cannot read the current health: ' + $before.error) }
    $auth = [string](Get-WizValue $before 'authority.state' '')
    $pending = [int](Get-WizValue $before 'sync.outbox_pending' 0) + [int](Get-WizValue $before 'sync.outbox_leased' 0)
    if ($auth -ne 'standby') { throw "WIZARD_UPDATE_REFUSED: the Branch Server is $auth - press Return to Online first (updates run only while the Cloud sells)" }
    if ($pending -gt 0) { throw "WIZARD_UPDATE_REFUSED: $pending event(s) still waiting to sync - wait until the outbox is empty" }
    # K2 in UPDATE mode (optional, owner's setup code from the Offline Edge page): proves this is still the Cloud's active Branch
    # Server for this tenant/branch (same installation id, same device id) before anything changes; nothing is re-paired.
    if (Get-WizValue $ctx.Answers 'update.use_setup_code' $false) {
        $code = Get-WizSecret $ctx 'setup_code' 'Setup code from the Cloud Offline Edge page (update)'
        $setup = Invoke-WizRedeem $ctx $code
        $bTenant = [string](Get-WizValue $before 'binding.tenant_code' '')
        $bBranch = [int](Get-WizValue $before 'binding.branch_id' 0)
        $bDevice = Get-WizApplianceEnvValue $ctx 'EDGE_SYNC_DEVICE_ID'
        if ($setup.Mode -ne 'update') { throw 'WIZARD_SETUP_NOT_UPDATE: the Cloud has no active Branch Server for this branch any more (revoked?) - the setup code is an install code; nothing was changed.' }
        if ($setup.TenantCode -ne $bTenant -or $setup.BranchId -ne $bBranch) { throw "WIZARD_SETUP_WRONG_BRANCH: the setup code is for $($setup.TenantCode) / branch $($setup.BranchId), this Branch Server serves $bTenant / branch $bBranch - nothing was changed." }
        if ($setup.DeviceId -and $bDevice -and $setup.DeviceId -ne $bDevice) { throw "WIZARD_SETUP_WRONG_DEVICE: the Cloud's active device is $($setup.DeviceId), this Branch Server is $bDevice - nothing was changed." }
        Write-WizLog $ctx 'ok' ("setup code (update) confirmed: tenant $bTenant, branch $bBranch, device $bDevice, installation " + $ctx.InstallationUuid)
    }

    # the exact migration plan of the NEW runtime, for the owner to approve (UI shows it; headless needs the hash).
    $upd = @(Get-ChildItem -Path (Join-Path $ctx.PackageRoot 'update') -Filter 'edge-update-*.json')[0].FullName
    $dry = Invoke-WizArtisan $ctx @('edge:local:update', $upd, (Join-Path $ctx.PackageRoot 'app'), '--dry-run', '--json') -TimeoutSec 900
    $verdict = ConvertFrom-WizJsonOutput $dry.StdOut
    if ($dry.ExitCode -ne 0 -or -not $verdict -or [string]$verdict.verdict -ne 'OK') { throw ('WIZARD_UPDATE_PLAN_REFUSED: ' + ($dry.StdErr + $dry.StdOut).Trim()) }
    $planHash = [string]$verdict.plan_hash
    $schemaChange = (@($verdict.plan.run).Count + @(Get-WizValue $verdict 'plan.substitute' @()).Count) -gt 0
    Write-WizLog $ctx 'info' ("migration plan " + $planHash + ': run ' + @($verdict.plan.run).Count + ', skip ' + @($verdict.plan.skip).Count + $(if ($schemaChange) { ' (schema changes)' } else { ' (no schema change)' }))
    $approved = Get-WizValue $ctx.Answers 'update.approve_plan_hash' $null
    if ($ctx.ApprovedPlanHash) { $approved = $ctx.ApprovedPlanHash }
    if (-not $approved -and (Get-WizValue $ctx.Answers 'update.approve_any_plan_for_lab' $false)) { $approved = $planHash; Write-WizLog $ctx 'warn' 'plan self-approved (LAB rehearsal answer)' }
    if (@($verdict.plan.entries).Count -gt 0 -and $approved -ne $planHash) { throw "WIZARD_UPDATE_PLAN_NOT_APPROVED: approve plan $planHash first" }

    # backup before update: a FULL encrypted snapshot, taken with the NEW code (the old runtime has no snapshot command).
    $snap = Join-Path $ctx.DataRoot ('updates\snapshots\wizard-pre-' + ($to -replace '[^A-Za-z0-9._+-]', '_') + '-' + (Get-Date -Format 'yyyyMMddHHmmss') + '.snap')
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $snap) | Out-Null
    $s = Invoke-WizArtisan $ctx @('edge:local:auto-update', "--snapshot-to=$snap", '--json') -Package -TimeoutSec 1800
    if ($s.ExitCode -ne 0) { throw ('WIZARD_UPDATE_SNAPSHOT_FAILED (nothing was changed): ' + ($s.StdErr + $s.StdOut).Trim()) }
    Write-WizLog $ctx 'ok' ('pre-update snapshot: ' + $snap)

    $updScript = Join-Path $ctx.PackageRoot 'app\scripts\edge\Update-EdgeAppliance.ps1'
    if (-not (Test-Path $updScript)) { $updScript = Join-Path $ctx.PackageRoot 'scripts\Update-EdgeAppliance.ps1' }
    $ua = @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $updScript,
        '-InstallRoot', $ctx.InstallRoot, '-PackageRoot', $ctx.PackageRoot, '-UpdateFile', $upd, '-ExpectedPlanHash', $planHash,
        '-ServiceAccount', (Get-WizValue $ctx.Answers 'services.service_account' 'NT AUTHORITY\LOCAL SERVICE'))
    $services = Get-WizValue $ctx.Answers 'services.register' $true
    if ($services) { $ua += '-ReRegisterServices' } else { $ua += '-NoServices' }
    Invoke-WizArtisan $ctx @('edge:local:report-status', 'updating', "--report-version=$previous", "--message=setup wizard: $previous -> $to") -Package | Out-Null
    $u = Invoke-WizProcess -FilePath 'powershell.exe' -ArgumentList $ua -TimeoutSec 3600
    Write-WizLog $ctx 'info' (($u.StdOut -split "`n" | Where-Object { $_ -match 'UPDATE|PLAN_HASH|Package OK|refreshed' }) -join ' | ')
    $current = (Get-Content -Raw -Path (Join-Path $runtimeRoot 'current')).Trim()
    $after = $null
    $verdictH = @{ Ok = $false; Reasons = @('the update did not complete: ' + (($u.StdOut + $u.StdErr) -split "`n" | Where-Object { $_ -match 'FAILED|refused' } | Select-Object -Last 1)) }
    if ($u.ExitCode -eq 0 -and $current -ne $previous) {
        $deadline = (Get-Date).AddSeconds([int](Get-WizValue $ctx.Answers 'update.health_wait_seconds' 150))
        do {
            $after = Get-WizHealth $ctx
            $verdictH = Test-WizHealthAfter $before $after $to
            if ($verdictH.Ok) { break }
            Start-Sleep -Seconds 10
        } while ((Get-Date) -lt $deadline)
    }
    if ($verdictH.Ok) {
        Remove-Item -Force -Path $snap -ErrorAction SilentlyContinue
        Invoke-WizArtisan $ctx @('edge:local:report-status', 'updated', "--message=setup wizard: $previous -> $to") | Out-Null
        # K5 from the updated runtime (an appliance installed before 0.9 never reported its LAN profile).
        $caFile = Find-WizBranchCaFile $ctx
        $k5 = @('edge:local:report-lan-profile', '--json')
        if ($caFile) { $k5 += "--ca-file=$caFile" } else { Write-WizLog $ctx 'warn' 'K5: no branch CA certificate found (answers lan.ca_file, <DataRoot>\ca, <mysql.base_dir>\ca) - the Cloud refuses a LAN profile without it' }
        $k5r = Invoke-WizArtisan $ctx $k5 -TimeoutSec 60
        Write-WizLog $ctx 'info' ('K5 LAN profile: ' + $k5r.StdOut.Trim())
        Write-WizLog $ctx 'ok' ("updated $previous -> $to, health OK (no new problem; status " + [string]$after.status + ')')
        Remove-WizSecureDir $ctx
        return [pscustomobject]@{ Mode = 'update'; From = $previous; To = $to; Result = 'updated'; Health = [string]$after.status }
    }

    # K6 rollback rule.
    Write-WizLog $ctx 'error' ('update unhealthy: ' + ($verdictH.Reasons -join '; '))
    $planFile = Join-Path $ctx.DataRoot 'gateway\service-plan.json'
    $reg = Join-Path $ctx.InstallRoot 'scripts\Register-EdgeServices.ps1'
    if ($services -and (Test-Path $planFile)) { Invoke-WizProcess -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $reg, '-Action', 'Stop', '-PlanFile', $planFile) -TimeoutSec 600 | Out-Null }
    $rollback = 'reverted_runtime'
    if ($schemaChange -and $current -ne $previous) {
        $rs = Invoke-WizArtisan $ctx @('edge:local:auto-update', "--restore-from=$snap", '--confirm-restore=RESTORE', '--json') -Package -TimeoutSec 1800
        if ($rs.ExitCode -eq 0) { $rollback = 'restored_snapshot' } else { $rollback = 'restore_required'; Write-WizLog $ctx 'error' ('snapshot restore failed - keep ' + $snap + ': ' + ($rs.StdErr + $rs.StdOut).Trim()) }
    }
    [IO.File]::WriteAllText((Join-Path $runtimeRoot 'current'), $previous, (New-Object Text.UTF8Encoding($false)))
    if ($services -and (Test-Path $planFile)) {
        $pr = Invoke-WizArtisan $ctx @('edge:local:service-plan', "--php=$([string]$layout.php)", "--app-root=$($ctx.InstallRoot)", "--data-root=$($ctx.DataRoot)", '--write-gateway-config', '--json')
        if ($pr.ExitCode -eq 0) { [IO.File]::WriteAllText($planFile, $pr.StdOut, (New-Object Text.UTF8Encoding($false))) }
        Invoke-WizProcess -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $reg, '-Action', 'Start', '-PlanFile', $planFile) -TimeoutSec 600 | Out-Null
    }
    $back = Get-WizHealth $ctx
    Invoke-WizArtisan $ctx @('edge:local:report-status', $(if ($rollback -eq 'restore_required') { 'update_failed' } else { 'rolled_back' }), '--failed', "--report-version=$previous", ("--message=setup wizard: $to unhealthy (" + ($verdictH.Reasons -join '; ') + "), rollback $rollback")) -Package | Out-Null
    Write-WizLog $ctx 'warn' ("rolled back to $previous ($rollback); health now " + [string](Get-WizValue $back 'status' '?'))
    return [pscustomobject]@{ Mode = 'update'; From = $previous; To = $to; Result = 'rolled_back'; Rollback = $rollback; Reasons = $verdictH.Reasons; Health = [string](Get-WizValue $back 'status' '?') }
}

Export-ModuleMember -Function *-Wiz*
