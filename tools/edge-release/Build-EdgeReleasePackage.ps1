# Edge 0.9 (stream E) - RELEASE BUILD RECIPE for a Bingoo Edge appliance package (build host only, never an appliance).
#
# Builds <Version> (default 0.9.0-edge) from ONE exact commit, never from a working tree:
#   1  clean source  : `git clone --shared --no-checkout` of the repository + `checkout --detach <commit>` into a fresh
#                      build folder (byte-identical to `git archive <commit>`, proven in step 7), plus the git archive itself;
#   2  vendor closure: a `composer install --no-dev` closure is COPIED into the build tree (robocopy, junctions excluded,
#                      no reparse point allowed) - never linked: a junctioned closure makes PHP resolve App\ classes from the
#                      closure's own tree (stream B's CP4 trap: the "new" build ran baseline App code). composer.lock must be
#                      byte-identical and installed.json must say dev=false; the autoloader is regenerated for THIS commit;
#   3  provenance    : the build tree's autoloader must load App\Services\Edge\EdgePackageBuilder from the build tree;
#   4  build         : `edge:build-package` in RELEASE mode (clean tree, --vendor-from, custody keystore) with --strict-layout,
#                      the portable PHP runtime, nginx, the slim MySQL server bundle, the VC++ prerequisite, signed SHA256SUMS;
#   5  audit         : `edge:audit-package --strict-layout --require-signature --public-key=<the keystore's public key> --json`;
#   6  zip           : BingooEdge-<Version>.zip + its SHA-256 (what the Cloud download page publishes);
#   7  source proof  : every app/ file that is not vendor equals the git archive byte for byte;
#   8  summary       : release-build-summary.json beside the package.
#
# SIGNING: the package is signed INSIDE the build (update manifest + SHA256SUMS) with the keystore given here. The LEAD runs
# this recipe with the P5B custody keystore + passphrase file; anyone else uses a DEV keystore for a dry proof - the output is
# then named "...-DRYRUN-DEVSIGNED-<key id>" and the audit reports signing_key_role=dev. The passphrase is read from its file
# by the builder (never argv, never printed). Nothing is installed; no service, task, firewall or certificate store is touched.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File tools\edge-release\Build-EdgeReleasePackage.ps1 `
#       -Commit <sha> -VendorClosure D:\build\closure-<sha>  -VcRedist D:\prereqs\VC_redist.x64.exe `
#       -SigningKeystore <keystore.json> -SigningPassphraseFile <passphrase file> [-OutRoot D:\BingooEdgeLab-e09\e\release-build]
#
# -VendorClosure is a folder holding vendor\ and the composer.lock it was installed from (`composer install --no-dev`).
# Exit 0 = package built, audited (ok), source-proven; any other exit = refused (the message says where).
param(
    [Parameter(Mandatory = $true)][string]$Commit,
    [Parameter(Mandatory = $true)][string]$VendorClosure,
    [Parameter(Mandatory = $true)][string]$VcRedist,
    [Parameter(Mandatory = $true)][string]$SigningKeystore,
    [Parameter(Mandatory = $true)][string]$SigningPassphraseFile,
    [string]$Version = '0.9.0-edge',
    [string]$OutRoot = 'D:\BingooEdgeLab-e09\e\release-build',
    [string]$RepoPath,
    [string]$PhpExe = 'D:\laragon2\bin\php\php-8.3.16-Win32-vs16-x64\php.exe',
    [string]$PhpRuntime = 'C:\Users\Dell\BingooEdgeLab\php-runtime-8.3.16-portable',
    [string]$CaBundle,
    [string]$Gateway = 'D:\laragon2\bin\nginx\nginx-1.22.0\nginx.exe',
    [string]$MysqlRuntime = 'D:\laragon2\bin\mysql\mysql-8.0.30-winx64',
    [string]$ComposerPhar = 'D:\laragon2\bin\composer\composer.phar',
    [switch]$KeepBuildTree
)
$ErrorActionPreference = 'Stop'
$ReleaseKeyId = 'a2eaf8a3d3e66a48'   # P5B custody release key v1 (public fingerprint)
$WinTar = Join-Path $env:SystemRoot 'System32\tar.exe'   # Windows bsdtar (zip + drive-letter paths); never Git's GNU tar from PATH
$t0 = Get-Date
$timings = [ordered]@{}

function Fail([string]$msg) { Write-Host "RELEASE BUILD REFUSED: $msg"; exit 1 }
function Step([string]$name) { Write-Host ""; Write-Host "== $name"; $script:stepStart = Get-Date }
function Done([string]$name) { $timings[$name] = [math]::Round(((Get-Date) - $script:stepStart).TotalSeconds, 1) }
function Invoke-Native([string]$exe, [string[]]$argList, [string]$cwd, [string]$log) {
    $p = Start-Process -FilePath $exe -ArgumentList $argList -WorkingDirectory $cwd -NoNewWindow -Wait -PassThru `
        -RedirectStandardOutput $log -RedirectStandardError "$log.err"
    return [int]$p.ExitCode
}
function Get-Sha256([string]$path) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $fs = [System.IO.File]::OpenRead($path)
    try { return ([System.BitConverter]::ToString($sha.ComputeHash($fs)) -replace '-', '').ToLowerInvariant() } finally { $fs.Dispose(); $sha.Dispose() }
}
function Quote([string]$s) { return '"' + $s + '"' }

# ---- 0. preconditions --------------------------------------------------------------------------------------------
if (-not $RepoPath) { $RepoPath = Split-Path -Parent (Split-Path -Parent $PSScriptRoot) }
foreach ($f in @($PhpExe, $Gateway, $VcRedist, $SigningKeystore, $SigningPassphraseFile, $ComposerPhar)) {
    if (-not (Test-Path -LiteralPath $f -PathType Leaf)) { Fail "missing file: $f" }
}
foreach ($d in @($RepoPath, $PhpRuntime, $MysqlRuntime, (Join-Path $VendorClosure 'vendor'))) {
    if (-not (Test-Path -LiteralPath $d -PathType Container)) { Fail "missing folder: $d" }
}
$sha = (& git -C $RepoPath rev-parse --verify "$Commit^{commit}" 2>$null)
if ($LASTEXITCODE -ne 0 -or -not $sha) { Fail "commit $Commit is not in $RepoPath" }
$sha = $sha.Trim()
$short = $sha.Substring(0, 8)
$ks = Get-Content -LiteralPath $SigningKeystore -Raw | ConvertFrom-Json
if (-not $ks.key_id -or -not $ks.public_key) { Fail 'the signing keystore has no key_id / public_key (not an edge:update:keygen keystore)' }
$keyRole = $(if ($ks.key_id -eq $ReleaseKeyId) { 'release' } else { 'dev' })
$name = "BingooEdge-$Version" + $(if ($keyRole -eq 'dev') { "-DRYRUN-DEVSIGNED-$($ks.key_id)" } else { '' })
$work = Join-Path $OutRoot "work-$short"
$out = Join-Path $OutRoot $name
foreach ($p in @($out, "$out.zip", $work)) { if (Test-Path -LiteralPath $p) { Fail "$p already exists - move it aside first (a release build never overwrites)" } }
New-Item -ItemType Directory -Force -Path $work | Out-Null
$logs = Join-Path $OutRoot "logs-$short"
New-Item -ItemType Directory -Force -Path $logs | Out-Null
Write-Host "Bingoo Edge release build: $Version from $sha (key $($ks.key_id), $keyRole) -> $out"

# ---- 1. clean source -----------------------------------------------------------------------------------------------
Step '1 clean source'
$build = Join-Path $work 'build'
$src = Join-Path $work 'archive'
& git clone --quiet --shared --no-checkout $RepoPath $build
if ($LASTEXITCODE -ne 0) { Fail 'git clone --shared failed' }
& git -C $build -c advice.detachedHead=false checkout --quiet --detach $sha
if ($LASTEXITCODE -ne 0) { Fail "checkout of $sha failed" }
if ((& git -C $build rev-parse HEAD).Trim() -ne $sha) { Fail 'the build tree HEAD is not the requested commit' }
if (& git -C $build status --porcelain) { Fail 'the build tree is not clean right after checkout' }
New-Item -ItemType Directory -Force -Path $src | Out-Null
& git -C $build archive --format=tar -o (Join-Path $work 'archive.tar') $sha
& $WinTar -x -f (Join-Path $work 'archive.tar') -C $src
if ($LASTEXITCODE -ne 0) { Fail 'git archive extraction failed' }
Remove-Item -LiteralPath (Join-Path $work 'archive.tar') -Force
Done '1_clean_source'

# ---- 2. vendor closure (COPIED, never linked) ----------------------------------------------------------------------
Step '2 vendor closure (copy)'
$closureLock = Join-Path $VendorClosure 'composer.lock'
if (-not (Test-Path -LiteralPath $closureLock)) { Fail "no composer.lock beside the closure ($closureLock)" }
if ((Get-Sha256 $closureLock) -ne (Get-Sha256 (Join-Path $build 'composer.lock'))) { Fail "the closure's composer.lock differs from $short's composer.lock - rebuild the closure from this commit" }
$installed = Get-Content -LiteralPath (Join-Path $VendorClosure 'vendor\composer\installed.json') -Raw | ConvertFrom-Json
if ($installed.dev -ne $false) { Fail 'the closure was not installed with --no-dev (installed.json dev != false)' }
& robocopy (Join-Path $VendorClosure 'vendor') (Join-Path $build 'vendor') /E /XJ /NFL /NDL /NJH /NJS /NP /R:1 /W:1 | Out-Null
if ($LASTEXITCODE -ge 8) { Fail "robocopy of the vendor closure failed ($LASTEXITCODE)" }
$reparse = @(Get-ChildItem -LiteralPath (Join-Path $build 'vendor') -Recurse -Force -Attributes ReparsePoint -ErrorAction SilentlyContinue)
if ((Get-Item -LiteralPath (Join-Path $build 'vendor') -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { $reparse += 'vendor' }
if ($reparse.Count -gt 0) { Fail "the copied vendor contains a junction/symlink: $($reparse | Select-Object -First 3)" }
# Regenerate the class map for THIS commit (the closure's map was made for the tree it was installed in).
$rc = Invoke-Native $PhpExe @((Quote $ComposerPhar), 'dump-autoload', '--no-dev', '--optimize', '--no-scripts', '--no-interaction') $build (Join-Path $logs '2-dump-autoload.log')
if ($rc -ne 0) { Fail "composer dump-autoload failed ($rc) - see $logs" }
$installedAfter = Get-Content -LiteralPath (Join-Path $build 'vendor\composer\installed.json') -Raw | ConvertFrom-Json
if ($installedAfter.dev -ne $false) { Fail 'installed.json reports dev packages after dump-autoload' }
Done '2_vendor_copy'

# ---- 3. provenance: App\ classes load from THIS build tree ----------------------------------------------------------
Step '3 provenance'
$probe = Join-Path $work 'provenance.php'
Set-Content -LiteralPath $probe -Encoding ascii -Value "<?php require __DIR__ . '/build/vendor/autoload.php'; echo (new ReflectionClass(App\Services\Edge\EdgePackageBuilder::class))->getFileName();"
$loaded = ([string](& $PhpExe $probe)).Trim() -replace '/', '\'
Remove-Item -LiteralPath $probe -Force
$expected = (Join-Path $build 'app\Services\Edge\EdgePackageBuilder.php')
$realLoaded = $(if ($loaded -and (Test-Path -LiteralPath $loaded)) { (Resolve-Path -LiteralPath $loaded).ProviderPath } else { $loaded })
if ($realLoaded -ine $expected) { Fail "App\ classes load from '$loaded', not from the build tree '$expected' (linked closure?)" }
Write-Host "  EdgePackageBuilder loads from the build tree: $loaded"
Done '3_provenance'

# ---- 4. build (RELEASE mode, strict layout) -------------------------------------------------------------------------
Step '4 edge:build-package (release, strict)'
$env:EDGE_APP_VERSION = $Version
$env:APP_ENV = 'production'
$env:APP_DEBUG = 'false'
$buildArgs = @('artisan', 'edge:build-package', (Quote $out), "--vendor-from=$(Quote (Join-Path $build 'vendor'))",
    "--php-runtime=$(Quote $PhpRuntime)", "--gateway=$(Quote $Gateway)", "--mysql-runtime=$(Quote $MysqlRuntime)", '--mysql-profile=server',
    "--vc-redist=$(Quote $VcRedist)", '--strict-layout',
    "--signing-keystore=$(Quote $SigningKeystore)", "--signing-passphrase-file=$(Quote $SigningPassphraseFile)", '--no-interaction')
if ($CaBundle) { $buildArgs += "--ca-bundle=$(Quote $CaBundle)" }
$rc = Invoke-Native $PhpExe $buildArgs $build (Join-Path $logs '4-build-package.log')
if ($rc -ne 0) { Fail "edge:build-package exited $rc - see $logs\4-build-package.log(.err)" }
Done '4_build'

# ---- 5. strict audit with the keystore's public key -----------------------------------------------------------------
Step '5 edge:audit-package (strict, signature required)'
$auditJson = Join-Path $OutRoot "$name.audit.json"
$rc = Invoke-Native $PhpExe @('artisan', 'edge:audit-package', (Quote $out), '--strict-layout', '--require-signature', "--public-key=$($ks.public_key)", '--json') $build $auditJson
$audit = Get-Content -LiteralPath $auditJson -Raw | ConvertFrom-Json
if ($rc -ne 0 -or -not $audit.ok) { Fail "the strict audit failed (exit $rc): $($audit.errors -join ' | ')" }
Done '5_audit'

# ---- 6. zip ------------------------------------------------------------------------------------------------------
Step '6 zip'
& $WinTar -a -c -f "$out.zip" -C $OutRoot $name
if ($LASTEXITCODE -ne 0) { Fail 'zip failed' }
$zipSha = Get-Sha256 "$out.zip"
Done '6_zip'

# ---- 7. source proof: app/ (minus vendor) == git archive ------------------------------------------------------------
Step '7 source proof'
$identical = 0; $mismatch = @(); $onlyPkg = @(); $excluded = 0
$appRoot = Join-Path $out 'app'
Get-ChildItem -LiteralPath $src -Recurse -File -Force | ForEach-Object {
    $rel = $_.FullName.Substring($src.Length + 1)
    $pkgFile = Join-Path $appRoot $rel
    if (Test-Path -LiteralPath $pkgFile -PathType Leaf) {
        if ((Get-Sha256 $_.FullName) -eq (Get-Sha256 $pkgFile)) { $identical++ } else { $mismatch += $rel }
    } else { $excluded++ }
}
Get-ChildItem -LiteralPath $appRoot -Recurse -File -Force | Where-Object { $_.FullName -notlike (Join-Path $appRoot 'vendor\*') } | ForEach-Object {
    $rel = $_.FullName.Substring($appRoot.Length + 1)
    if (-not (Test-Path -LiteralPath (Join-Path $src $rel) -PathType Leaf)) { $onlyPkg += $rel }
}
$unexpected = @($onlyPkg | Where-Object { $_ -ne 'edge-build-manifest.json' -and $_ -notlike '*.gitkeep' })
if ($mismatch.Count -gt 0) { Fail "SOURCE PROOF: $($mismatch.Count) app/ file(s) differ from git archive $short (first: $($mismatch[0]))" }
if ($unexpected.Count -gt 0) { Fail "SOURCE PROOF: file(s) in app/ that the commit does not have: $($unexpected | Select-Object -First 5)" }
Write-Host "  $identical files byte-identical to git archive $short, 0 mismatches, $($onlyPkg.Count) generated (manifest + .gitkeep), $excluded excluded by the boundary"
Done '7_source_proof'

# ---- 8. summary ----------------------------------------------------------------------------------------------------
$manifest = Get-Content -LiteralPath (Join-Path $out 'package-manifest.json') -Raw | ConvertFrom-Json
$size = (Get-ChildItem -LiteralPath $out -Recurse -File -Force | Measure-Object -Property Length -Sum).Sum
$summary = [ordered]@{
    package = $out; zip = "$out.zip"; zip_sha256 = $zipSha; zip_bytes = (Get-Item -LiteralPath "$out.zip").Length
    version = $manifest.edge_app_version; build_mode = $manifest.build_mode; git_commit = $manifest.git_commit; source_dirty = $manifest.source_dirty
    package_hash = $manifest.package_hash; artifact_manifest_hash = $manifest.artifact_manifest_hash; file_count = $manifest.file_count; bytes = $size
    signing_key_id = $ks.key_id; signing_key_role = $keyRole
    audit_ok = $audit.ok; signature_ok = $audit.signature_ok; checksums_signature_ok = $audit.checksums_signature_ok
    vc_redist = $manifest.components.prereqs.vc_redist.version; mysql_profile = $manifest.components.mysql.profile; mysql_bytes = $manifest.components.mysql.size_bytes
    source_proof = [ordered]@{ identical = $identical; mismatched = 0; generated = $onlyPkg.Count; excluded_by_boundary = $excluded }
    warnings = @($audit.warnings); timings_seconds = $timings; total_seconds = [math]::Round(((Get-Date) - $t0).TotalSeconds, 1)
    next = $(if ($keyRole -eq 'release') { 'LEAD: record in the custody register, publish with edge:release:publish' } else { 'DRY RUN (DEV key): the LEAD re-runs this recipe with the P5B custody keystore + passphrase file' })
}
[System.IO.File]::WriteAllText((Join-Path $OutRoot "$name.summary.json"), ($summary | ConvertTo-Json -Depth 5), (New-Object System.Text.UTF8Encoding($false)))
if (-not $KeepBuildTree) { Remove-Item -LiteralPath $build -Recurse -Force }
Write-Host ""
Write-Host "RELEASE BUILD OK: $out ($keyRole-signed, key $($ks.key_id)); zip sha256 $zipSha; summary $OutRoot\$name.summary.json"
exit 0
