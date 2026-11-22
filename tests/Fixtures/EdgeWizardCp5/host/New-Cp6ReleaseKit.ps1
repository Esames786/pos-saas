# CP6 (stream B, HOST side, NOT elevated): the RELEASE variant of the CP5 Windows Sandbox kit - ONE targeted chain for the
# release-signed 0.9.0-edge that Khatri would receive: prereqs, cloud, a (the real 0.8.5 install), w-update (the REAL wizard UI,
# 0.8.5 -> 0.9.0-edge), b (same device, K5, tasks, PIN logins). It changes nothing outside -Root (the CP5 kit stays untouched).
#   <Root>\kit\        the CP5 sandbox scripts (this repo) + cp5-variant.json (package / version) + update-public-facts.json
#                      (the RELEASE update PUBLIC key + its key id - never the keystore or its passphrase)
#   <Root>\src\tree\   the Cloud the release targets: git archive <TreeCommit> of <TreeRepo> + the copied no-dev vendor closure
#   <Root>\rel085\     a byte copy of the real signed 0.8.5 package (the original is only read)
#   <Root>\evidence\   the ONLY writable mapped folder
#   -ReleaseDir        mapped READ-ONLY as C:\cp5\pkgs: must hold <PackageName>\ when the sandbox starts (filled by the release owner)
param(
    [string]$Root = 'D:\BingooEdgeLab-e09\cp5-release',
    [string]$Repo = 'D:\laragon2\www\pos-saas-e09-b',
    [string]$TreeRepo = 'D:\laragon2\www\pos-saas-e09-integ',
    [string]$TreeCommit = '0cc1cb2a',
    [string]$Vendor = 'C:\Users\Dell\BingooEdgeLab\e09\e\vendor-closure-7d7da307\vendor',
    [string]$Rel085Source = 'C:\Users\Dell\.bingoo-edge-release\releases\BingooEdge-0.8.5-edge',
    [string]$ReleaseDir = 'D:\BingooEdgeLab-e09\cp6\release',
    [string]$PackageName = 'BingooEdge-0.9.0-edge',
    [string]$Version = '0.9.0-edge',
    [string]$PublicKeyFile = 'D:\BingooEdgeLab-e09\cp6\release-public\edge-update-signing-v1.public.txt',
    [string]$KeyIdFile = 'D:\BingooEdgeLab-e09\cp6\release-public\key_id.txt',
    [string]$Only = 'w-update+b',
    [int[]]$MemoryMB = @(4096, 6144),
    [switch]$SkipTree
)
$ErrorActionPreference = 'Stop'
foreach ($p in @($Repo, $TreeRepo, $Vendor, $Rel085Source, $PublicKeyFile, $KeyIdFile)) { if (-not (Test-Path $p)) { throw "missing: $p" } }
if ($PublicKeyFile -match '(?i)keystore|passphrase|secret') { throw 'the release kit takes the PUBLIC key file only' }
$kit = Join-Path $Root 'kit'; $evidence = Join-Path $Root 'evidence'; $tree = Join-Path $Root 'src\tree'; $rel = Join-Path $Root 'rel085\BingooEdge-0.8.5-edge'
New-Item -ItemType Directory -Force -Path $kit, $evidence, $tree, $rel | Out-Null

# ---- kit: the CP5 sandbox scripts + the release variant facts (public only)
Copy-Item -Force (Join-Path $Repo 'tests\Fixtures\EdgeWizardCp5\sandbox\*') $kit
foreach ($c in @(Get-ChildItem -Path $kit -Filter '*.cmd')) {
    $t = [IO.File]::ReadAllText($c.FullName) -replace "`r?`n", "`r`n"
    [IO.File]::WriteAllText($c.FullName, $t, (New-Object Text.ASCIIEncoding))
}
$pub = (Get-Content -Raw $PublicKeyFile).Trim()
$keyId = (Get-Content -Raw $KeyIdFile).Trim()
if ($pub -notmatch '^[A-Za-z0-9+/]{43}=$') { throw 'the public key file does not hold one base64 Ed25519 public key' }
$utf8 = New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText((Join-Path $kit 'update-public-facts.json'), (([ordered]@{ public_key = $pub; key_id = $keyId; note = 'RELEASE update key - PUBLIC only (CP6)' }) | ConvertTo-Json), $utf8)
[IO.File]::WriteAllText((Join-Path $kit 'cp5-variant.json'), (([ordered]@{ variant = 'release'; package_090 = $PackageName; version_090 = $Version; update_key_id = $keyId
    tree_commit = $TreeCommit; scenarios = "prereqs, cloud, a, $($Only -replace '\+', ', ')" }) | ConvertTo-Json), $utf8)
Remove-Item -Force (Join-Path $kit 'dev-public-facts.json') -ErrorAction SilentlyContinue   # never the DEV key in this kit
Get-ChildItem $kit -Filter 'diag-*.ps1' | Remove-Item -Force

# ---- the Cloud tree: git archive of the release commit + the copied no-dev vendor closure (composer.lock unchanged)
if (-not $SkipTree) {
    $tar = Join-Path $env:TEMP ('cp6-tree-' + $TreeCommit + '.tar')
    & git -C $TreeRepo archive --format=tar -o $tar $TreeCommit
    if ($LASTEXITCODE -ne 0) { throw "git archive $TreeCommit failed" }
    & tar.exe -x -f $tar -C $tree
    if ($LASTEXITCODE -ne 0) { throw 'tar -x failed' }
    Remove-Item -Force $tar
    & robocopy.exe $Vendor (Join-Path $tree 'vendor') /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "vendor copy failed (robocopy $LASTEXITCODE)" }
    [IO.File]::WriteAllText((Join-Path $Root 'src\tree-commit.txt'), $TreeCommit, (New-Object Text.ASCIIEncoding))
}

# ---- a byte copy of the real 0.8.5 package (only read from its release folder)
& robocopy.exe $Rel085Source $rel /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 | Out-Null
if ($LASTEXITCODE -ge 8) { throw "0.8.5 copy failed (robocopy $LASTEXITCODE)" }
$a = Get-ChildItem -Recurse -File $Rel085Source | Measure-Object -Sum Length
$b = Get-ChildItem -Recurse -File $rel | Measure-Object -Sum Length
if ($a.Count -ne $b.Count -or $a.Sum -ne $b.Sum) { throw "0.8.5 copy differs: $($a.Count)/$($a.Sum) vs $($b.Count)/$($b.Sum)" }

# ---- the .wsb files (auto: prereqs, cloud, a + $Only)
function New-Wsb([string]$Name, [int]$Mem) {
    $map = @(
        @{ host = $kit; box = 'C:\cp5\kit'; ro = 'true' },
        @{ host = $ReleaseDir; box = 'C:\cp5\pkgs'; ro = 'true' },
        @{ host = $rel; box = 'C:\cp5\rel085'; ro = 'true' },
        @{ host = $tree; box = 'C:\cp5\cloudsrc'; ro = 'true' },
        @{ host = $evidence; box = 'C:\cp5\evidence'; ro = 'false' }
    )
    $folders = ($map | ForEach-Object { "    <MappedFolder>`r`n      <HostFolder>$($_.host)</HostFolder>`r`n      <SandboxFolder>$($_.box)</SandboxFolder>`r`n      <ReadOnly>$($_.ro)</ReadOnly>`r`n    </MappedFolder>" }) -join "`r`n"
    $xml = "<Configuration>`r`n  <VGpu>Disable</VGpu>`r`n  <Networking>Enable</Networking>`r`n  <AudioInput>Disable</AudioInput>`r`n  <VideoInput>Disable</VideoInput>`r`n  <PrinterRedirection>Disable</PrinterRedirection>`r`n  <ClipboardRedirection>Enable</ClipboardRedirection>`r`n  <ProtectedClient>Disable</ProtectedClient>`r`n  <MemoryInMB>$Mem</MemoryInMB>`r`n  <MappedFolders>`r`n$folders`r`n  </MappedFolders>`r`n  <LogonCommand>`r`n    <Command>C:\cp5\kit\Start-Cp5.cmd auto $Only</Command>`r`n  </LogonCommand>`r`n</Configuration>`r`n"
    $path = Join-Path $Root $Name
    [IO.File]::WriteAllText($path, $xml, $utf8)
    return $path
}
$w = foreach ($m in $MemoryMB) { New-Wsb ('BingooEdge-CP6-release-' + [int]($m / 1024) + 'g.wsb') $m }

$pkg = Join-Path $ReleaseDir $PackageName
[ordered]@{ root = $Root; kit_files = @(Get-ChildItem $kit -File).Count; update_key_id = $keyId; tree_commit = $TreeCommit
    tree_vendor_packages = @(Get-ChildItem (Join-Path $tree 'vendor') -Directory).Count; rel085_files = $b.Count
    release_package = $pkg; release_package_present = (Test-Path (Join-Path $pkg 'package-manifest.json')); wsb = @($w) } | ConvertTo-Json
