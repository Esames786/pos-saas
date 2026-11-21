# Edge 0.9 (stream E) - make sure the Microsoft Visual C++ 2015-2022 x64 runtime is present on the Branch Server.
#
# php.exe (PHP 8.3 vs16) and mysqld.exe (MySQL 8.0) do not start without it, and a clean Windows does not have it.
# The package carries the official installer as prereqs\VC_redist.x64.exe (EdgePackageBuilder: Authenticode-valid
# Microsoft bundle, complete payload, listed in SHA256SUMS). The setup wizard runs this script FIRST, in install AND update
# mode, elevated:
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File "<PackageRoot>\scripts\Install-EdgePrereqs.ps1" -PackageRoot "<PackageRoot>"
#
# 1. detect: HKLM\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64 (64-bit registry view) Installed=1 and
#    Major.Minor.Bld.Rbld >= the bundled installer's FileVersion  -> nothing to do;
# 2. otherwise verify the bundled installer (Authenticode Valid + Microsoft Corporation signer + its SHA256SUMS line),
#    require elevation, run  VC_redist.x64.exe /install /quiet /norestart  and detect again.
#
# Exit codes:  0    runtime present (already, or installed now)
#              3010 installed now, Windows wants a REBOOT to finish (setup may continue; tell the operator to reboot later)
#              2    -CheckOnly: runtime missing or older than the bundled one (nothing was changed)
#              1    refused / failed (the message says why: not elevated, installer missing / unsigned / tampered, installer error)
# -CheckOnly never changes anything. No secret is involved.
param(
    [string]$PackageRoot,
    [string]$InstallerPath,
    [switch]$CheckOnly,
    [string]$MinimumVersion
)
$ErrorActionPreference = 'Stop'

function Out-Result([int]$code, [string]$msg) {
    Write-Host $msg
    exit $code
}

function Get-VcRuntimeVersion {
    # Read the 64-bit view explicitly (a 32-bit PowerShell would otherwise be redirected to WOW6432Node).
    try {
        $base = [Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::LocalMachine, [Microsoft.Win32.RegistryView]::Registry64)
        $key = $base.OpenSubKey('SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64')
        if ($null -eq $key) { return $null }
        if ([int]$key.GetValue('Installed', 0) -ne 1) { return $null }
        $v = [version]('{0}.{1}.{2}.{3}' -f [int]$key.GetValue('Major', 0), [int]$key.GetValue('Minor', 0), [int]$key.GetValue('Bld', 0), [int]$key.GetValue('Rbld', 0))
        $key.Close()
        return $v
    } catch {
        return $null
    }
}

# ---- locate the package and the installer ------------------------------------------------------------------------
if (-not $PackageRoot) {
    foreach ($candidate in @((Split-Path -Parent $PSScriptRoot), (Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))))) {
        if ($candidate -and (Test-Path -LiteralPath (Join-Path $candidate 'package-manifest.json'))) { $PackageRoot = $candidate; break }
    }
}
if (-not $InstallerPath -and $PackageRoot) { $InstallerPath = Join-Path $PackageRoot 'prereqs\VC_redist.x64.exe' }
$haveInstaller = $InstallerPath -and (Test-Path -LiteralPath $InstallerPath -PathType Leaf)

# Required version: -MinimumVersion, else the bundled installer's FileVersion, else the 2015-2022 family floor 14.0.
$required = [version]'14.0.0.0'
if ($MinimumVersion) {
    $required = [version]$MinimumVersion
} elseif ($haveInstaller) {
    $vi = (Get-Item -LiteralPath $InstallerPath).VersionInfo
    if ($vi.FileMajorPart -gt 0) { $required = [version]('{0}.{1}.{2}.{3}' -f $vi.FileMajorPart, $vi.FileMinorPart, $vi.FileBuildPart, $vi.FilePrivatePart) }
}

# ---- 1. detect -----------------------------------------------------------------------------------------------------
$installed = Get-VcRuntimeVersion
if ($installed -and $installed -ge $required) {
    Out-Result 0 "PREREQS OK: Microsoft Visual C++ 2015-2022 x64 runtime $installed is installed (required $required)."
}
$state = $(if ($installed) { "installed $installed is older than the required $required" } else { 'not installed' })
if ($CheckOnly) {
    Out-Result 2 "PREREQS MISSING: Microsoft Visual C++ 2015-2022 x64 runtime $state (check only - nothing changed)."
}

# ---- 2. verify the bundled installer, then install -------------------------------------------------------------------
if (-not $haveInstaller) {
    Out-Result 1 "PREREQS FAILED: the VC++ runtime is $state and the package carries no prereqs\VC_redist.x64.exe. Install VC_redist.x64.exe (x64) from microsoft.com, then run setup again."
}
$sig = Get-AuthenticodeSignature -LiteralPath $InstallerPath
$subject = $(if ($sig.SignerCertificate) { $sig.SignerCertificate.Subject } else { '' })
if ($sig.Status -ne 'Valid' -or $subject -notmatch '(^|,\s*)CN=Microsoft Corporation(,|$)' -or $subject -notmatch '(^|,\s*)O=Microsoft Corporation(,|$)') {
    Out-Result 1 "PREREQS FAILED: $InstallerPath is not a valid Microsoft-signed installer (Authenticode $($sig.Status), signer '$subject') - refusing to run it."
}
if ($PackageRoot -and (Test-Path -LiteralPath (Join-Path $PackageRoot 'SHA256SUMS'))) {
    $rel = 'prereqs/VC_redist.x64.exe'
    $line = Get-Content -LiteralPath (Join-Path $PackageRoot 'SHA256SUMS') | Where-Object { $_ -match ('^[0-9a-f]{64} [ *]' + [regex]::Escape($rel) + '$') } | Select-Object -First 1
    $actual = (Get-FileHash -LiteralPath $InstallerPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if (-not $line -or $line.Substring(0, 64) -ne $actual) {
        Out-Result 1 "PREREQS FAILED: $rel does not match the package SHA256SUMS - refusing to run it."
    }
}
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) {
    Out-Result 1 "PREREQS FAILED: the VC++ runtime is $state and installing it needs an elevated (Run as administrator) setup."
}

Write-Host "  installing Microsoft Visual C++ 2015-2022 x64 runtime $required (silent) ..."
$p = Start-Process -FilePath $InstallerPath -ArgumentList @('/install', '/quiet', '/norestart') -Wait -PassThru
$code = [int]$p.ExitCode
# 0 = done, 3010 = done + reboot required, 1638 = a newer version is already installed (Microsoft's documented codes).
if (@(0, 3010, 1638) -notcontains $code) {
    Out-Result 1 "PREREQS FAILED: VC_redist.x64.exe exited $code (see %TEMP%\dd_vcredist_amd64_*.log)."
}
$after = Get-VcRuntimeVersion
if (-not $after -or $after -lt $required) {
    Out-Result 1 "PREREQS FAILED: VC_redist.x64.exe exited $code but the runtime is still not detected (found '$after', required $required)."
}
if ($code -eq 3010) {
    Out-Result 3010 "PREREQS OK, REBOOT REQUIRED: Microsoft Visual C++ 2015-2022 x64 runtime $after installed; restart Windows after setup."
}
Out-Result 0 "PREREQS OK: Microsoft Visual C++ 2015-2022 x64 runtime $after installed (installer exit $code)."
