# CP5 prep (stream B, HOST side, NOT elevated, changes nothing outside C:\Users\Dell\BingooEdgeLab\e09\b\cp5):
# assembles the Windows Sandbox kit and writes the .wsb files.
#   cp5\kit\        the sandbox scripts (this repo's tests\Fixtures\EdgeWizardCp5\sandbox), the DEV update PUBLIC key facts,
#                   prereqs\ = the Visual C++ 2015-2022 x64 runtime MSIs from this PC's Package Cache (+ the runtime DLLs as a
#                   fallback) - a clean sandbox has no VC++ runtime and PHP / MySQL / nginx need it
#   cp5\evidence\   the ONLY writable mapped folder
#   cp5\BingooEdge-CP5-auto.wsb    a..e headless (0.8.5 install, wizard update, auto-update + rollback, counter wizard)
#   cp5\BingooEdge-CP5-manual.wsb  a, then the owner clicks through the WPF wizard; desktop "CP5 continue (c-e)"
#   cp5\BingooEdge-CP5-fresh.wsb   f: the 0.9 wizard FRESH INSTALL (a separate sandbox session)
# Mapped READ-ONLY: kit, pkgs (cp5\pkgs), the real signed 0.8.5 package (never modified), the Cloud code tree (cp5\src\tree).
param(
    [string]$Cp5 = 'C:\Users\Dell\BingooEdgeLab\e09\b\cp5',
    [string]$Repo = 'D:\laragon2\www\pos-saas-e09-b',
    [string]$Rel085 = 'C:\Users\Dell\.bingoo-edge-release\releases\BingooEdge-0.8.5-edge',
    [string]$DevFacts = 'C:\Users\Dell\BingooEdgeLab\e09\dev-signing\dev-public-facts.json',
    [int]$MemoryMB = 6144
)
$ErrorActionPreference = 'Stop'
$kit = Join-Path $Cp5 'kit'
$evidence = Join-Path $Cp5 'evidence'
$tree = Join-Path $Cp5 'src\tree'
$pkgs = Join-Path $Cp5 'pkgs'
foreach ($p in @($Rel085, $tree, $pkgs, (Join-Path $pkgs 'BingooEdge-0.9.0-dev'), (Join-Path $pkgs 'BingooEdge-0.9.1-dev'), (Join-Path $pkgs 'BingooEdge-0.9.2-dev'))) {
    if (-not (Test-Path $p)) { throw "missing: $p" }
}
New-Item -ItemType Directory -Force -Path $kit, (Join-Path $kit 'prereqs\vcrt'), $evidence | Out-Null
Copy-Item -Force (Join-Path $Repo 'tests\Fixtures\EdgeWizardCp5\sandbox\*') $kit

# PUBLIC facts only (the key id and the public key; never the keystore or its passphrase)
$facts = Get-Content -Raw $DevFacts | ConvertFrom-Json
[IO.File]::WriteAllText((Join-Path $kit 'dev-public-facts.json'), (([ordered]@{ public_key = [string]$facts.public_key; key_id = [string]$facts.key_id; note = 'DEV update key (rehearsal only)' }) | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))

# Visual C++ runtime for the sandbox: the cached MSIs (installed with msiexec inside the sandbox) + the DLLs as a fallback
$cache = 'C:\ProgramData\Package Cache'
foreach ($pair in @(@('vc_runtimeMinimum_x64.msi', 'minimum'), @('vc_runtimeAdditional_x64.msi', 'additional'))) {
    $src = Get-ChildItem -Path $cache -Recurse -Filter $pair[0] -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $src) { Write-Warning "$($pair[0]) not found in the Package Cache"; continue }
    $dst = Join-Path $kit ('prereqs\' + $pair[1])
    New-Item -ItemType Directory -Force -Path $dst | Out-Null
    Copy-Item -Force $src.FullName $dst
    Get-ChildItem -Path $src.DirectoryName -Filter '*.cab' | Copy-Item -Destination $dst -Force   # each MSI keeps its external cab1.cab
}
Remove-Item -Force (Join-Path $kit 'prereqs\*.msi') -ErrorAction SilentlyContinue
foreach ($dll in @('vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll', 'msvcp140_1.dll', 'msvcp140_2.dll', 'concrt140.dll')) {
    $src = Join-Path $env:SystemRoot ('System32\' + $dll)
    if (Test-Path $src) { Copy-Item -Force $src (Join-Path $kit 'prereqs\vcrt') }
}

function New-Wsb([string]$Name, [string]$ScenarioArg) {
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
  <MemoryInMB>$MemoryMB</MemoryInMB>
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
$w = @(
    (New-Wsb 'BingooEdge-CP5-auto.wsb' 'auto'),
    (New-Wsb 'BingooEdge-CP5-manual.wsb' 'manual'),
    (New-Wsb 'BingooEdge-CP5-fresh.wsb' 'fresh')
)
Get-ChildItem -Recurse $kit | Where-Object { -not $_.PSIsContainer } | ForEach-Object { '{0,10:N0}  {1}' -f $_.Length, $_.FullName.Substring($Cp5.Length + 1) }
$w | ForEach-Object { 'wsb: ' + $_ }
