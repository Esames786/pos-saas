# CP5 (stream B, HOST side, NOT elevated, changes nothing outside the cp5 folder it is given - D:\BingooEdgeLab-e09\cp5 by
# the owner's rule that disposable packages / staging / evidence live on D:): assembles the Windows Sandbox kit and writes the
# .wsb files - every mapped folder on D:.
#   cp5\kit\        the sandbox scripts (this repo's tests\Fixtures\EdgeWizardCp5\sandbox) and the DEV update PUBLIC key facts.
#                   No VC++ stop-gap any more: the sandbox installs the runtime with stream E's Install-EdgePrereqs.ps1 and the
#                   verified VC_redist.x64.exe the 0.9.0-dev package carries (this PC's Package Cache bundle is stripped).
#   cp5\evidence\   the ONLY writable mapped folder
#   cp5\BingooEdge-CP5-auto-<6g|4g>.wsb    a, w-update, b, c, d, e, s, h, g1 g3 g4 g2
#   cp5\BingooEdge-CP5-manual-<6g|4g>.wsb  a, then the owner clicks through the WPF wizard; desktop "CP5 continue (c-e)"
#   cp5\BingooEdge-CP5-fresh-<6g|4g>.wsb   w-fresh + f: the 0.9 wizard FRESH INSTALL (a separate sandbox session)
# Mapped READ-ONLY: kit, pkgs, a byte copy of the real signed 0.8.5 package (cp5\rel085, the original is never touched), the
# Cloud code tree (cp5\src\tree).
param(
    [string]$Cp5 = 'D:\BingooEdgeLab-e09\cp5',
    [string]$Repo = 'D:\laragon2\www\pos-saas-e09-b',
    [string]$Rel085 = 'D:\BingooEdgeLab-e09\cp5\rel085\BingooEdge-0.8.5-edge',
    [string]$DevFacts = 'C:\Users\Dell\BingooEdgeLab\e09\dev-signing\dev-public-facts.json',
    [int[]]$MemoryMB = @(6144, 4096)
)
$ErrorActionPreference = 'Stop'
$kit = Join-Path $Cp5 'kit'
$evidence = Join-Path $Cp5 'evidence'
$tree = Join-Path $Cp5 'src\tree'
$pkgs = Join-Path $Cp5 'pkgs'
foreach ($p in @($Rel085, $tree, $pkgs, (Join-Path $pkgs 'BingooEdge-0.9.0-dev'), (Join-Path $pkgs 'BingooEdge-0.9.1-dev'), (Join-Path $pkgs 'BingooEdge-0.9.2-dev'))) {
    if (-not (Test-Path $p)) { throw "missing: $p" }
}
foreach ($v in @('0.9.0-dev', '0.9.1-dev', '0.9.2-dev')) {   # every 0.9 package must carry E's prerequisite (strict layout)
    foreach ($f in @('prereqs\VC_redist.x64.exe', 'scripts\Install-EdgePrereqs.ps1')) { if (-not (Test-Path (Join-Path $pkgs "BingooEdge-$v\$f"))) { throw "BingooEdge-$v lacks $f" } }
}
New-Item -ItemType Directory -Force -Path $kit, $evidence | Out-Null
Remove-Item -Recurse -Force (Join-Path $kit 'prereqs') -ErrorAction SilentlyContinue
Copy-Item -Force (Join-Path $Repo 'tests\Fixtures\EdgeWizardCp5\sandbox\*') $kit
foreach ($c in @(Get-ChildItem -Path $kit -Filter '*.cmd')) {   # cmd.exe wants CRLF
    $t = [IO.File]::ReadAllText($c.FullName) -replace "`r?`n", "`r`n"
    [IO.File]::WriteAllText($c.FullName, $t, (New-Object Text.ASCIIEncoding))
}

# PUBLIC facts only (the key id and the public key; never the keystore or its passphrase)
$facts = Get-Content -Raw $DevFacts | ConvertFrom-Json
[IO.File]::WriteAllText((Join-Path $kit 'dev-public-facts.json'), (([ordered]@{ public_key = [string]$facts.public_key; key_id = [string]$facts.key_id; note = 'DEV update key (rehearsal only)' }) | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))


function New-Wsb([string]$Name, [string]$ScenarioArg, [int]$Mem) {
    $map = @(
        @{ host = $kit; box = 'C:\cp5\kit'; ro = 'true' },
        @{ host = $pkgs; box = 'C:\cp5\pkgs'; ro = 'true' },
        @{ host = $Rel085; box = 'C:\cp5\rel085'; ro = 'true' },
        @{ host = $tree; box = 'C:\cp5\cloudsrc'; ro = 'true' },
        @{ host = $evidence; box = 'C:\cp5\evidence'; ro = 'false' }
    )
    $folders = ($map | ForEach-Object {
            "    <MappedFolder>`r`n      <HostFolder>$($_.host)</HostFolder>`r`n      <SandboxFolder>$($_.box)</SandboxFolder>`r`n      <ReadOnly>$($_.ro)</ReadOnly>`r`n    </MappedFolder>"
        }) -join "`r`n"
    $xml = @"
<Configuration>
  <VGpu>Disable</VGpu>
  <Networking>Enable</Networking>
  <AudioInput>Disable</AudioInput>
  <VideoInput>Disable</VideoInput>
  <PrinterRedirection>Disable</PrinterRedirection>
  <ClipboardRedirection>Enable</ClipboardRedirection>
  <ProtectedClient>Disable</ProtectedClient>
  <MemoryInMB>$Mem</MemoryInMB>
  <MappedFolders>
$folders
  </MappedFolders>
  <LogonCommand>
    <Command>C:\cp5\kit\Start-Cp5.cmd $ScenarioArg</Command>
  </LogonCommand>
</Configuration>
"@
    $path = Join-Path $Cp5 $Name
    [IO.File]::WriteAllText($path, $xml, (New-Object Text.UTF8Encoding($false)))
    return $path
}
$w = @()
foreach ($mem in $MemoryMB) {
    $tag = [string][int]($mem / 1024) + 'g'
    foreach ($s in @('auto', 'manual', 'fresh')) { $w += New-Wsb ("BingooEdge-CP5-$s-$tag.wsb") $s $mem }
}
Get-ChildItem -Recurse $kit | Where-Object { -not $_.PSIsContainer } | ForEach-Object { '{0,10:N0}  {1}' -f $_.Length, $_.FullName.Substring($Cp5.Length + 1) }
$w | ForEach-Object { 'wsb: ' + $_ }
