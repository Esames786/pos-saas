# Bingoo Edge 0.9 - SETUP WIZARD entry point (started by Setup-BingooEdge.cmd, elevated).
#
#   Setup-BingooEdge.cmd                          -> the window (WPF): Welcome ... Finish; install OR update is detected
#   Start-BingooEdgeWizard.ps1 -Headless -AnswersFile answers.json [-Mode install|update]
#                                                  -> the same steps without a window (rehearsal / support), secrets from hidden
#                                                     prompts (or TEST-only env injection on a LAB rehearsal)
#
# Install vs update: an appliance whose <DataRoot>\config\appliance.env and <InstallRoot>\appliance.json exist is UPDATED in
# place (no re-pairing, users and data kept); otherwise a fresh install runs. The answers file holds NO secret (setup code,
# passwords and PINs are typed in the window / at the prompt). Exit 0 = installed/updated, 1 = failed, 2 = rolled back.
param(
    [switch]$Headless,
    [string]$AnswersFile,
    [ValidateSet('auto', 'install', 'update')][string]$Mode = 'auto',
    [string]$PackageRoot
)
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
Import-Module (Join-Path $here 'BingooEdgeWizard.psm1') -Force -DisableNameChecking

$answers = @{}
if ($AnswersFile) {
    if (-not (Test-Path $AnswersFile)) { Write-Host "Answers file not found: $AnswersFile" -ForegroundColor Red; exit 1 }
    $answers = Get-Content -Raw -Path $AnswersFile | ConvertFrom-Json
    $raw = Get-Content -Raw -Path $AnswersFile
    if ($raw -match '(?i)"(password|pin|secret|setup_code|token|pairing_code)"\s*:\s*"[^"]+"') {
        Write-Host 'The answers file must not contain secrets (password / pin / setup code / token). Remove them; the wizard asks for them.' -ForegroundColor Red
        exit 1
    }
}
if (-not $PackageRoot) {
    # Packaged layout: <package>\scripts\wizard\Start-BingooEdgeWizard.ps1 (or app\scripts\edge\wizard in the app tree).
    $candidate = Split-Path -Parent (Split-Path -Parent $here)
    if (-not (Test-Path (Join-Path $candidate 'package-manifest.json'))) { $candidate = Split-Path -Parent (Split-Path -Parent $candidate) }
    $PackageRoot = Get-WizValue $answers 'package_root' $candidate
}

if (-not $Headless) {
    & (Join-Path $here 'BingooEdgeWizard.Ui.ps1') -PackageRoot $PackageRoot -Answers $answers
    exit $LASTEXITCODE
}

$ctx = Initialize-WizContext -Answers $answers -Headless -PackageRoot $PackageRoot
if ($Mode -eq 'auto') { $Mode = $(if (Test-WizInstalled $ctx) { 'update' } else { 'install' }) }
Write-WizLog $ctx 'step' "mode: $Mode"
try {
    if ($Mode -eq 'update') { $result = Invoke-WizUpdate $ctx } else { $result = Invoke-WizInstall $ctx }
    $summary = $result | Select-Object * -ExcludeProperty HealthReport
    Write-Host ''
    Write-Host '==== Bingoo Edge setup result ====' -ForegroundColor Cyan
    $summary | Format-List | Out-String | Write-Host
    $out = Get-WizValue $answers 'result_file' $null
    if ($out) { [IO.File]::WriteAllText($out, ($summary | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false))) }
    if ($Mode -eq 'update' -and $result.Result -ne 'updated') { exit 2 }
    exit 0
} catch {
    Write-WizLog $ctx 'error' ('SETUP FAILED: ' + $_.Exception.Message)
    Write-Host "Log: $($ctx.LogFile)" -ForegroundColor Yellow
    $out = Get-WizValue $answers 'result_file' $null
    if ($out) { [IO.File]::WriteAllText($out, (@{ Mode = $Mode; Result = 'failed'; Error = $_.Exception.Message } | ConvertTo-Json), (New-Object Text.UTF8Encoding($false))) }
    exit 1
} finally {
    Remove-WizSecureDir $ctx
}
