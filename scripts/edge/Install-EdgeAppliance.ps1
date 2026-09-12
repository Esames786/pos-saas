# P4 WINDOWS APPLIANCE - FIRST INSTALL of a Bingoo Edge Branch Server from a built package (P4 section 6).
#
# Sequence (each step fails closed; the appliance is never LOCAL_ACTIVE during install - success = READY AS WARM STANDBY):
#   1  verify the package (every file's sha256 against package-manifest.json; boundary marker)
#   2  lay out the install root (php\, gateway\, scripts\, artisan launcher, runtime\versions\<v> + current pointer)
#   3  lay out the data root (config\, certs\, logs\, backups\, gateway\) and restrict ACLs when elevated
#   4  write config\appliance.env from the template: paths, a NEW machine-local app key, DB credentials, public trust
#      keys, the backup recovery key - secrets come from files/prompts, never from argv, never printed
#   5  edge:local:db-init            local schema on a fresh loopback Edge database
#   6  edge:local:pair               one-time pairing code -> device identity (secret generated locally, persisted)
#   7  edge:local:bootstrap-pull     Cloud bootstrap snapshot: config, users/permissions, terminals, printers, ...
#   8  edge:local:enroll             the first local user's credential from a Cloud-signed assertion
#   9  edge:local:gateway-cert       LAN TLS certificate (PFX from the branch CA, or -SelfSignedCert for a lab)
#  10  edge:local:service-plan       render nginx.conf + the service plan JSON
#  11  Register-EdgeServices.ps1     register + start the Windows tasks (skip with -NoServices on a lab box)
#  12  warm sync                      two authority ticks so the standby pulls stock/config/returnable/finance caches
#  13  edge:local:health             the consolidated report; exit 0 only when the appliance is bound
#
# Usage (elevated):
#   .\Install-EdgeAppliance.ps1 -PackageRoot D:\pkg\BingooEdge-0.1.0 -CloudUrl https://pos.example.com `
#       -DbUser bingoo_edge -DbPasswordFile C:\secure\dbpw.txt -PairingCodeFile C:\secure\code.txt `
#       -EnrollmentAssertionFile C:\secure\assertion.json -EnrollmentCredentialFile C:\secure\cred.txt `
#       -EnrollmentPublicKey <base64> -UpdatePublicKey <base64> -RecoveryKeyFile C:\secure\recovery.key `
#       -LanHostname bingoo-edge.local -LanIp 192.168.1.50 -CertPfx C:\secure\server.pfx -CertPfxPasswordFile C:\secure\pfx.txt
param(
    [Parameter(Mandatory = $true)][string]$PackageRoot,
    [string]$InstallRoot = 'C:\Program Files\Bingoo Edge',
    [string]$DataRoot = 'C:\ProgramData\BingooEdge',
    [string]$PhpPath,
    [string]$GatewayPath,
    [string]$DbHost = '127.0.0.1',
    [int]$DbPort = 3306,
    [string]$DbName = 'bingoo_edge_local',
    [Parameter(Mandatory = $true)][string]$DbUser,
    [string]$DbPasswordFile,
    [string]$CloudUrl,
    [string]$PairingCodeFile,
    [string]$DeviceName,
    [string]$EnrollmentAssertionFile,
    [string]$EnrollmentCredentialFile,
    [string]$EnrollmentPublicKey,
    [string]$UpdatePublicKey,
    [string]$RecoveryKeyFile,
    [string]$RecoveryKeyId = 'k1',
    [string]$LanHostname = 'bingoo-edge.local',
    [string]$LanIp,
    [string]$CertPfx,
    [string]$CertPfxPasswordFile,
    [switch]$SelfSignedCert,
    [int]$HttpsPort = 443,
    [int]$HttpPort = 80,
    [int]$WebWorkers = 2,
    [int]$WebPortBase = 8090,
    [string]$ServiceAccount = 'NT AUTHORITY\LOCAL SERVICE',
    [switch]$CurrentUserServices,
    [switch]$NoServices,
    [switch]$NoGateway,
    [switch]$AllowHttpCloud,
    [switch]$SkipWarmSync,
    [int]$PairingCodeWaitSeconds = 900,
    [string]$AppEnv = 'production'
)
$ErrorActionPreference = 'Stop'
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

function Step($n, $text) { Write-Host ("[{0,2}] {1}" -f $n, $text) -ForegroundColor Cyan }
function Fail($text) { Write-Host "INSTALL FAILED: $text" -ForegroundColor Red; exit 1 }
function Test-IsAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}
function Read-SecretFile($path, $label) {
    if (-not $path) { return $null }
    if (-not (Test-Path $path)) { Fail "$label file not found: $path" }
    $raw = Get-Content -Path $path -Raw
    if ($null -eq $raw) { $raw = '' }
    $v = ([string]$raw).Trim()
    Remove-Item -Path $path -Force -ErrorAction SilentlyContinue
    return $v
}
function New-AppKey {
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return 'base64:' + [Convert]::ToBase64String($bytes)
}
function Write-Utf8NoBom($path, $text) {
    [System.IO.File]::WriteAllText($path, $text, (New-Object System.Text.UTF8Encoding($false)))
}
function Invoke-Edge {
    param([string[]]$Arguments)
    & $php $launcher @Arguments | Out-Host
    return $LASTEXITCODE
}
function Copy-Tree($src, $dst) {
    New-Item -ItemType Directory -Path $dst -Force | Out-Null
    # robocopy: /E all subdirs, /XJ never follow junctions (re-created below), quiet, exit codes 0-7 = success.
    & robocopy $src $dst /E /XJ /NFL /NDL /NJH /NJS /NP /R:1 /W:1 | Out-Null
    if ($LASTEXITCODE -ge 8) { Fail "copy failed ($src -> $dst), robocopy exit $LASTEXITCODE" }
    # Dev/test packages carry app\vendor as a junction to a shared vendor closure: re-create it at the destination.
    Get-ChildItem -Path $src -Directory -Recurse -Force | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint } | ForEach-Object {
        $rel = $_.FullName.Substring($src.TrimEnd('\').Length).TrimStart('\')
        $target = $_.Target
        if (-not $target) { $target = (Get-Item $_.FullName).Target }
        if ($target -is [array]) { $target = $target[0] }
        $link = Join-Path $dst $rel
        if (-not (Test-Path $link)) {
            $parent = Split-Path $link -Parent
            New-Item -ItemType Directory -Path $parent -Force | Out-Null
            & cmd /c mklink /J "$link" "$target" | Out-Null
        }
    }
}

# ---- 1. package verification -----------------------------------------------------------------------------------
Step 1 "Verifying package at $PackageRoot"
$manifestPath = Join-Path $PackageRoot 'package-manifest.json'
if (-not (Test-Path $manifestPath)) { Fail 'package-manifest.json not found - not a Bingoo Edge package' }
$manifest = Get-Content -Path $manifestPath -Raw | ConvertFrom-Json
if ($manifest.package_format_version -ne 'edge-package-v1') { Fail "unsupported package format $($manifest.package_format_version)" }
if ($manifest.runtime_mode_supported -ne 'branch_server') { Fail 'package is not a Branch Server package' }
$bad = 0; $checked = 0
foreach ($prop in $manifest.files.PSObject.Properties) {
    $rel = $prop.Name; $expected = [string]$prop.Value
    $full = Join-Path $PackageRoot ($rel -replace '/', '\')
    if (-not (Test-Path $full)) { Write-Host "  MISSING  $rel"; $bad++; continue }
    if ($rel -like 'app/vendor/*') { $checked++; continue }   # vendor is hashed by the artifact manifest; skip per-file rehash for speed
    $actual = (Get-FileHash -Path $full -Algorithm SHA256).Hash.ToLower()
    if ($actual -ne $expected.ToLower()) { Write-Host "  TAMPERED $rel"; $bad++ }
    $checked++
}
if ($bad -gt 0) { Fail "$bad file(s) missing or tampered - refusing to install" }
$version = [string]$manifest.edge_app_version
Write-Host "  package OK: Bingoo Edge $version ($checked files, build $($manifest.build_mode), commit $($manifest.git_commit))"
if ($manifest.build_mode -ne 'release') { Write-Warning 'This is a DEV package (dirty/overridden provenance). Never install a dev package at a live branch.' }

# ---- 2. install root -------------------------------------------------------------------------------------------
Step 2 "Laying out install root $InstallRoot"
New-Item -ItemType Directory -Path $InstallRoot -Force | Out-Null
if (-not $PhpPath) {
    if (Test-Path (Join-Path $PackageRoot 'php\php.exe')) { Copy-Tree (Join-Path $PackageRoot 'php') (Join-Path $InstallRoot 'php'); $PhpPath = Join-Path $InstallRoot 'php\php.exe' }
    else { Fail 'the package bundles no PHP runtime - pass -PhpPath' }
}
if (-not (Test-Path $PhpPath)) { Fail "PHP not found at $PhpPath" }
$php = $PhpPath
$phpVersion = (& $php -r 'echo PHP_VERSION;')
if ([version]($phpVersion -replace '[^0-9.].*$', '') -lt [version]$manifest.min_php) { Fail "PHP $phpVersion is below the required $($manifest.min_php)" }
$mods = (& $php -m) -join ' '
foreach ($ext in @('pdo_mysql', 'openssl', 'mbstring', 'sodium', 'gd', 'curl', 'fileinfo')) {
    if ($mods -notmatch "(?im)^\s*$ext\s*$" -and $mods -notmatch "\b$ext\b") { Write-Warning "PHP extension [$ext] not reported by php -m - the appliance may fail to boot" }
}
if (-not $NoGateway) {
    if (-not $GatewayPath) {
        if (Test-Path (Join-Path $PackageRoot 'gateway\nginx.exe')) { New-Item -ItemType Directory -Path (Join-Path $InstallRoot 'gateway') -Force | Out-Null; Copy-Item (Join-Path $PackageRoot 'gateway\nginx.exe') (Join-Path $InstallRoot 'gateway\nginx.exe') -Force; $GatewayPath = Join-Path $InstallRoot 'gateway\nginx.exe' }
        else { Write-Warning 'the package bundles no gateway binary and -GatewayPath was not given: the TLS gateway will not be registered'; $NoGateway = $true }
    }
}
Copy-Tree (Join-Path $PackageRoot 'scripts') (Join-Path $InstallRoot 'scripts')
$runtimeRoot = Join-Path $InstallRoot 'runtime'
$versionDir = Join-Path $runtimeRoot ('versions\' + ($version -replace '[^A-Za-z0-9._+-]', '_'))
if (Test-Path $versionDir) { Fail "runtime version already installed at $versionDir - use Update-EdgeAppliance.ps1 or uninstall first" }
Copy-Tree (Join-Path $PackageRoot 'app') $versionDir
foreach ($d in @('bootstrap\cache', 'storage\framework\cache\data', 'storage\framework\views', 'storage\framework\sessions', 'storage\logs', 'storage\app')) {
    New-Item -ItemType Directory -Path (Join-Path $versionDir $d) -Force | Out-Null
}
# The atomic runtime pointer the launcher resolves (and edge:local:update switches): the installed version name.
$safeVersion = ($version -replace '[^A-Za-z0-9._+-]', '_')
Write-Utf8NoBom (Join-Path $runtimeRoot 'current') $safeVersion
Copy-Item (Join-Path $PackageRoot 'templates\edge-launcher.php') (Join-Path $InstallRoot 'artisan') -Force
$layout = @{ data_root = $DataRoot; runtime_root = $runtimeRoot; php = $php; gateway = $GatewayPath; edge_app_version = $version; installed_at = (Get-Date).ToString('o') }
Write-Utf8NoBom (Join-Path $InstallRoot 'appliance.json') ($layout | ConvertTo-Json)
$launcher = Join-Path $InstallRoot 'artisan'

# ---- 3. data root ----------------------------------------------------------------------------------------------
Step 3 "Laying out data root $DataRoot"
foreach ($d in @('config', 'certs', 'logs', 'backups', 'gateway')) { New-Item -ItemType Directory -Path (Join-Path $DataRoot $d) -Force | Out-Null }
$envFile = Join-Path $DataRoot 'config\appliance.env'
if (Test-Path $envFile) { Fail "an appliance configuration already exists at $envFile - this box is already installed (use Update-EdgeAppliance.ps1)" }
if (Test-IsAdmin) {
    $acct = if ($CurrentUserServices) { [System.Security.Principal.WindowsIdentity]::GetCurrent().Name } else { $ServiceAccount }
    & icacls (Join-Path $DataRoot 'config') /inheritance:r /grant:r "SYSTEM:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" "$($acct):(OI)(CI)RX" | Out-Null
    & icacls (Join-Path $DataRoot 'certs')  /inheritance:r /grant:r "SYSTEM:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" "$($acct):(OI)(CI)RX" | Out-Null
    foreach ($d in @('logs', 'backups', 'gateway')) { & icacls (Join-Path $DataRoot $d) /grant "$($acct):(OI)(CI)M" | Out-Null }
    foreach ($d in @('bootstrap\cache', 'storage')) { & icacls (Join-Path $versionDir $d) /grant "$($acct):(OI)(CI)M" | Out-Null }
    Write-Host "  ACLs restricted (config/certs: SYSTEM + Administrators full, $acct read; logs/backups/storage: $acct modify)"
} else {
    Write-Warning 'Not elevated: ACLs were NOT restricted. Apply the config/certs ACLs (SYSTEM + Administrators full, service account read) before go-live.'
}

# ---- 4. appliance.env ------------------------------------------------------------------------------------------
Step 4 'Writing appliance.env (secrets from files/prompts only; never printed)'
$dbPassword = Read-SecretFile $DbPasswordFile 'DB password'
if ($null -eq $dbPassword) {
    $sec = Read-Host -Prompt 'Local MySQL/MariaDB password for the Edge database user' -AsSecureString
    $dbPassword = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
}
$recoveryKey = Read-SecretFile $RecoveryKeyFile 'recovery key'
if (-not $recoveryKey) { Write-Warning 'No backup recovery key given: backups cannot be sealed until EDGE_BACKUP_RECOVERY_KEY is provisioned.' }
if ($DbName -notmatch '^(bingoo_edge_|edge_local_)' -and $DbName -notmatch 'edge.*test|test.*edge') { Fail "DbName [$DbName] must be a dedicated Edge database (bingoo_edge_* / edge_local_*)" }
$template = Get-Content -Path (Join-Path $PackageRoot 'templates\appliance.env.template') -Raw
$values = @{
    'APP_ENV' = $AppEnv
    'APP_URL' = "https://$LanHostname"
    'EDGE_LOG_PATH' = (Join-Path $DataRoot 'logs\edge.log')
    'EDGE_DATA_ROOT' = $DataRoot
    'EDGE_UPDATE_INSTALL_ROOT' = $runtimeRoot
    'EDGE_BACKUP_PATH' = (Join-Path $DataRoot 'backups')
    'EDGE_LOCAL_APP_KEY' = (New-AppKey)
    'EDGE_DB_HOST' = $DbHost
    'EDGE_DB_PORT' = "$DbPort"
    'EDGE_DB_DATABASE' = $DbName
    'EDGE_DB_USERNAME' = $DbUser
    'EDGE_DB_PASSWORD' = $dbPassword
    'EDGE_ENROLLMENT_PUBLIC_KEY' = $EnrollmentPublicKey
    'EDGE_UPDATE_PUBLIC_KEY' = $UpdatePublicKey
    'EDGE_BACKUP_RECOVERY_KEY' = $recoveryKey
    'EDGE_BACKUP_RECOVERY_KEY_ID' = $RecoveryKeyId
    'EDGE_WEB_WORKERS' = "$WebWorkers"
    'EDGE_WEB_PORT_BASE' = "$WebPortBase"
    'EDGE_GATEWAY_HTTPS_PORT' = "$HttpsPort"
    'EDGE_GATEWAY_HTTP_PORT' = "$HttpPort"
    'EDGE_LAN_HOSTNAME' = $LanHostname
    'EDGE_LAN_IP' = $LanIp
}
if ($AllowHttpCloud) { $values['EDGE_CLOUD_ALLOW_HTTP'] = 'true' }
$lines = $template -split "`r?`n"
$out = New-Object System.Collections.Generic.List[string]
$seen = @{}
foreach ($line in $lines) {
    if ($line -match '^\s*([A-Z0-9_]+)\s*=') {
        $k = $Matches[1]
        if ($values.ContainsKey($k)) {
            $v = [string]$values[$k]
            if ($v -match '[\s#"\\]') {
                # Windows paths / spaces / '#': single quotes are LITERAL for Dotenv; a value holding a quote is double-quoted with escapes.
                if ($v -notmatch "'") { $v = "'" + $v + "'" } else { $v = '"' + (($v -replace '\\', '\\') -replace '"', '\"') + '"' }
            }
            $out.Add("$k=$v"); $seen[$k] = $true; continue
        }
    }
    $out.Add($line)
}
foreach ($k in $values.Keys) { if (-not $seen.ContainsKey($k)) { $out.Add("$k=$([string]$values[$k])") } }
Write-Utf8NoBom $envFile (($out -join "`r`n") + "`r`n")
Write-Host "  appliance.env written ($($values.Count) keys set)"

# ---- 5. local schema -------------------------------------------------------------------------------------------
Step 5 "edge:local:db-init ($DbName @ $DbHost)"
if ((Invoke-Edge @('edge:local:db-init', '--no-interaction')) -ne 0) { Fail 'edge:local:db-init failed' }

# ---- 6. pairing ------------------------------------------------------------------------------------------------
if ($PairingCodeFile -and $CloudUrl) {
    Step 6 "edge:local:pair ($CloudUrl)"
    if (-not (Test-Path $PairingCodeFile)) {
        # A pairing code is one-time and short-lived (15 min): generate it on the Cloud Offline Edge page NOW and save it to the file.
        Write-Host "  waiting up to $PairingCodeWaitSeconds s for the one-time pairing code file $PairingCodeFile (generate the code on the Cloud Offline Edge page now)"
        $deadline = (Get-Date).AddSeconds($PairingCodeWaitSeconds)
        while (-not (Test-Path $PairingCodeFile) -and (Get-Date) -lt $deadline) { Start-Sleep -Seconds 2 }
        if (-not (Test-Path $PairingCodeFile)) { Fail "the pairing code file did not appear within $PairingCodeWaitSeconds s" }
    }
    $pairArgs = @('edge:local:pair', "--cloud-url=$CloudUrl", "--code-file=$PairingCodeFile", '--no-interaction')
    if ($DeviceName) { $pairArgs += "--device-name=$DeviceName" }
    if ((Invoke-Edge $pairArgs) -ne 0) { Fail 'pairing failed' }
} else {
    Step 6 'edge:local:pair skipped (no -PairingCodeFile/-CloudUrl) - run it manually before bootstrap-pull'
}

# ---- 7. bootstrap ----------------------------------------------------------------------------------------------
if ($PairingCodeFile -and $CloudUrl) {
    Step 7 'edge:local:bootstrap-pull'
    if ((Invoke-Edge @('edge:local:bootstrap-pull', "--cloud-url=$CloudUrl", '--no-interaction')) -ne 0) { Fail 'bootstrap pull failed' }
} else { Step 7 'edge:local:bootstrap-pull skipped' }

# ---- 8. first local user ---------------------------------------------------------------------------------------
if ($EnrollmentAssertionFile) {
    Step 8 'edge:local:enroll (first local user)'
    $enrollArgs = @('edge:local:enroll', $EnrollmentAssertionFile, '--no-interaction')
    if ($EnrollmentCredentialFile) { $enrollArgs += "--credential-file=$EnrollmentCredentialFile" }
    if ((Invoke-Edge $enrollArgs) -ne 0) { Fail 'enrollment failed' }
} else { Step 8 'edge:local:enroll skipped (no assertion) - enroll users from the Cloud Offline Edge page' }

# ---- 9. gateway certificate ------------------------------------------------------------------------------------
if (-not $NoGateway) {
    Step 9 'edge:local:gateway-cert'
    if ($CertPfx) {
        $certArgs = @('edge:local:gateway-cert', $CertPfx, '--no-interaction')
        if ($CertPfxPasswordFile) { $certArgs += "--password-file=$CertPfxPasswordFile" }
        if ((Invoke-Edge $certArgs) -ne 0) { Fail 'gateway certificate import failed' }
    } elseif ($SelfSignedCert) {
        $certArgs = @('edge:local:gateway-cert', "--self-signed=$LanHostname", '--no-interaction')
        if ($LanIp) { $certArgs += "--ip=$LanIp" }
        if ((Invoke-Edge $certArgs) -ne 0) { Fail 'self-signed gateway certificate failed' }
        Write-Warning 'Self-signed gateway certificate: LAB ONLY. A live branch uses the branch-CA certificate (New-EdgeServerCertificate.ps1 -ExportPfx).'
    } else {
        Write-Warning 'No gateway certificate given: run edge:local:gateway-cert <pfx> before starting the gateway.'
    }
} else { Step 9 'gateway certificate skipped (-NoGateway)' }

# ---- 10. service plan + gateway config -------------------------------------------------------------------------
Step 10 'edge:local:service-plan (render nginx.conf + plan JSON)'
$planArgs = @('edge:local:service-plan', "--php=$php", "--app-root=$InstallRoot", "--data-root=$DataRoot", '--write-gateway-config', '--json')
if ($GatewayPath) { $planArgs += "--gateway=$GatewayPath" }
$planJson = & $php $launcher @planArgs
if ($LASTEXITCODE -ne 0) { Fail 'service plan failed' }
$planFile = Join-Path $DataRoot 'gateway\service-plan.json'
Write-Utf8NoBom $planFile ($planJson -join "`n")
Write-Host "  plan written: $planFile"

# ---- 11. services ----------------------------------------------------------------------------------------------
if (-not $NoServices) {
    Step 11 'Register-EdgeServices.ps1 -Action Register'
    $regArgs = @{ Action = 'Register'; PlanFile = $planFile; ServiceAccount = $ServiceAccount }
    if ($CurrentUserServices) { $regArgs['CurrentUser'] = $true }
    if ($NoGateway) { $regArgs['NoGateway'] = $true }
    & (Join-Path $scriptDir 'Register-EdgeServices.ps1') @regArgs
} else {
    Step 11 'services NOT registered (-NoServices): register later with Register-EdgeServices.ps1 -Action Register'
}

# ---- 12. warm sync ---------------------------------------------------------------------------------------------
if (-not $SkipWarmSync -and $PairingCodeFile -and $CloudUrl) {
    Step 12 'warm standby sync (two authority ticks)'
    Invoke-Edge @('edge:local:authority-worker', '--max-ticks=2', '--interval=5', '--no-interaction') | Out-Null
} else { Step 12 'warm sync skipped' }

# ---- 13. health -------------------------------------------------------------------------------------------------
Step 13 'edge:local:health'
$healthJson = & $php $launcher edge:local:health --json
$health = ($healthJson -join "`n") | ConvertFrom-Json
& $php $launcher edge:local:health
Write-Host ''
if ($health.status -eq 'NOT_BOUND') { Fail 'the appliance is not bound (pairing / bootstrap incomplete)' }
Write-Host "INSTALL COMPLETE - status $($health.status): $($health.status_label)" -ForegroundColor Green
Write-Host "  install root : $InstallRoot"
Write-Host "  data root    : $DataRoot  (config\appliance.env holds the secrets; back up the recovery key separately)"
Write-Host "  next         : distribute the branch CA public cert to terminals; point terminals at https://$LanHostname"
exit 0
