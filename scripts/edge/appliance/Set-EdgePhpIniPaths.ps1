# Edge 0.9 (stream E) - finish the PORTABLE php.ini of an INSTALLED Bingoo Edge PHP runtime.
#
# A package's php\php.ini carries  curl.cainfo = openssl.cafile = "__BINGOO_PHP_DIR__\cacert.txt"  (written by
# EdgePackageBuilder / PhpIniValidator; extension_dir = "ext", no build-host path). After the installer or updater has
# copied <package>\php to its final place (default <InstallRoot>\php) it runs this script ONCE:
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File Set-EdgePhpIniPaths.ps1 -PhpDir 'C:\Program Files\Bingoo Edge\php'
#
# It replaces the token __BINGOO_PHP_DIR__ with that absolute directory (no trailing backslash), checks that cacert.txt
# and ext\ exist and that no token is left, and (unless -NoProbe) asks the installed php.exe which CA file it now trusts
# and whether curl/openssl load. Idempotent: a php.ini without the token is only checked. The write is atomic (temp file
# + move), UTF-8 without BOM. Exit 0 = ready; exit 1 = refused (the message says why). Never needs a secret.
param(
    [Parameter(Mandatory = $true)][string]$PhpDir,
    [switch]$NoProbe
)
$ErrorActionPreference = 'Stop'
$Token = '__BINGOO_PHP_DIR__'

function Fail([string]$msg) {
    Write-Host "PHP INI REFUSED: $msg"
    exit 1
}

$dir = $PhpDir.TrimEnd('\', '/')
if (-not [System.IO.Path]::IsPathRooted($dir)) { Fail "-PhpDir must be an absolute path (got '$PhpDir')" }
if ($dir.Contains('"')) { Fail '-PhpDir must not contain a double quote' }
$ini = Join-Path $dir 'php.ini'
$ca = Join-Path $dir 'cacert.txt'
if (-not (Test-Path -LiteralPath $ini -PathType Leaf)) { Fail "no php.ini in $dir" }
if (-not (Test-Path -LiteralPath $ca -PathType Leaf)) { Fail "no cacert.txt in $dir (the package ships the CA bundle as php\cacert.txt)" }
if (-not (Test-Path -LiteralPath (Join-Path $dir 'ext') -PathType Container)) { Fail "no ext\ directory in $dir" }

$text = [System.IO.File]::ReadAllText($ini)
if ($text.Contains($Token)) {
    $new = $text.Replace($Token, $dir)
    $tmp = "$ini.tmp-bingoo"
    [System.IO.File]::WriteAllText($tmp, $new, (New-Object System.Text.UTF8Encoding($false)))
    Move-Item -LiteralPath $tmp -Destination $ini -Force
    Write-Host "  php.ini: $Token -> $dir"
    $text = $new
} else {
    Write-Host '  php.ini: no placeholder left (already finished) - checking only'
}

# Every ACTIVE curl.cainfo / openssl.cafile line must name THIS runtime's cacert.txt.
foreach ($key in @('curl.cainfo', 'openssl.cafile')) {
    $pattern = '(?im)^\s*' + [regex]::Escape($key) + '\s*=\s*"?([^";\r\n]+)"?'
    $m = [regex]::Match($text, $pattern)
    if (-not $m.Success) { Fail "$key is not set in $ini" }
    $value = $m.Groups[1].Value.Trim()
    if ($value -ne $ca) { Fail "$key = '$value' (expected '$ca')" }
}
$ext = [regex]::Match($text, '(?im)^\s*extension_dir\s*=\s*"?([^";\r\n]+)"?')
if (-not $ext.Success -or $ext.Groups[1].Value.Trim() -ne 'ext') { Fail 'extension_dir must be "ext" (a packaged runtime is portable)' }

if (-not $NoProbe) {
    $php = Join-Path $dir 'php.exe'
    if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { Fail "no php.exe in $dir" }
    $saved = $env:PHP_INI_SCAN_DIR
    $env:PHP_INI_SCAN_DIR = ''
    try {
        $reported = (& $php -c $ini -r 'echo ini_get(''curl.cainfo'');' 2>&1 | Out-String).Trim()
        $mods = (& $php -c $ini -m 2>&1 | Out-String)
    } finally {
        $env:PHP_INI_SCAN_DIR = $saved
    }
    if ($reported -ne $ca) { Fail "php.exe reports curl.cainfo='$reported' (expected '$ca')" }
    if ($mods -match '(?i)Warning|Unable to load dynamic library') { Fail "php.exe start-up warnings: $($mods.Trim())" }
    foreach ($m in @('curl', 'openssl')) {
        if ($mods -notmatch "(?im)^\s*$m\s*$") { Fail "php.exe does not load the $m extension" }
    }
}
Write-Host "PHP INI READY: $ini (CA bundle $ca)"
exit 0
