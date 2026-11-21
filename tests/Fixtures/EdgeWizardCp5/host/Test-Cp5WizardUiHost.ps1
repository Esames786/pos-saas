# CP5 prep (stream B, HOST, NOT elevated): pre-flight of the UI Automation driver against the REAL setup wizard window.
# Opens the wizard (no Setup-BingooEdge.cmd: that would ask for elevation), drives Welcome -> "Check this PC", reads the check
# rows by their AutomationIds, takes a screenshot per page, then closes the window. Nothing is installed: the answers point
# every root at a scratch folder and the wizard never gets past the prerequisite page (it is not elevated, so Next stays
# disabled by design). Proves the automation ids, page detection, screenshots and closing work before the sandbox run.
param(
    [string]$Repo = 'D:\laragon2\www\pos-saas-e09-b',
    [string]$PackageRoot = 'D:\BingooEdgeLab-e09\cp5\pkgs\BingooEdge-0.9.0-dev',
    [string]$Out = 'D:\BingooEdgeLab-e09\cp5\host-preflight\ui'
)
$ErrorActionPreference = 'Stop'
New-Item -ItemType Directory -Force -Path $Out | Out-Null
. (Join-Path $Repo 'tests\Fixtures\EdgeWizardCp5\sandbox\Cp5UiAutomation.ps1')
$scratch = Join-Path $Out 'roots'
$answers = [ordered]@{ cloud_url = 'http://127.0.0.1:9'; allow_http_cloud = $true; install_root = (Join-Path $scratch 'install'); data_root = (Join-Path $scratch 'data')
    log_dir = (Join-Path $Out 'wizard-logs'); services = [ordered]@{ register = $false; firewall = $false; gateway = $false }; mysql = [ordered]@{ mode = 'existing' } }
$af = Join-Path $Out 'answers-ui-preflight.json'
[IO.File]::WriteAllText($af, ($answers | ConvertTo-Json -Depth 5), (New-Object Text.UTF8Encoding($false)))
$wiz = Join-Path $Repo 'scripts\edge\wizard\Start-BingooEdgeWizard.ps1'
$p = Start-Process powershell.exe -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -STA -File "' + $wiz + '" -AnswersFile "' + $af + '" -PackageRoot "' + $PackageRoot + '"') -PassThru
$doc = [ordered]@{ pid = $p.Id }
try {
    $w = Find-UiWindow 90
    $doc.window = $w.Current.Name
    $doc.page_1 = Get-UiPage $w
    $doc.welcome_text = Get-UiText $w 'WelcomeText'
    $doc.shot_1 = Save-UiShot $w (Join-Path $Out '01-welcome.png')
    Invoke-UiButton $w 'BtnNext'
    Wait-UiPage $w 'Check this PC' 30 | Out-Null
    $deadline = (Get-Date).AddSeconds(120)
    do { Start-Sleep -Seconds 1; $s = Get-UiStatus $w } while ($s -ne 'Checks done.' -and (Get-Date) -lt $deadline)
    $doc.status_after_checks = $s
    $doc.checks = Get-UiChecks $w
    $doc.next_enabled = (Find-Ui $w 'BtnNext').Current.IsEnabled
    $doc.recheck_button = [bool](Find-Ui $w 'BtnRecheck' 3 -Optional)
    $doc.shot_2 = Save-UiShot $w (Join-Path $Out '02-prereq.png')
    Invoke-UiButton $w 'BtnBack'
    $doc.page_after_back = Get-UiPage $w
} catch {
    $doc.error = $_.Exception.Message
} finally {
    if ($w) { Close-UiWindow $w }
    if (-not $p.WaitForExit(20000)) { & taskkill.exe /PID $p.Id /T /F | Out-Null; $doc.killed = $true }
    $doc.exit = $p.ExitCode
}
[IO.File]::WriteAllText((Join-Path $Out 'ui-preflight.json'), ($doc | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false)))
$doc | ConvertTo-Json -Depth 6
