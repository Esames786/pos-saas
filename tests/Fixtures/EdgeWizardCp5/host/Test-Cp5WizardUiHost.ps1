# CP5 (stream B, HOST, NOT elevated): pre-flight of the UI Automation driver against the REAL (packaged) setup wizard window.
# Opens the wizard (no Setup-BingooEdge.cmd: that would ask for elevation) and drives Welcome -> "Check this PC", reading the
# check rows by their AutomationIds, one screenshot per page. With -CloudWork (a disposable host Cloud started by the driver's
# -CloudSmoke -KeepCloud) it goes on: Setup code (typed through UI Automation, redeemed against that Cloud, confirmed) ->
# Passwords and network (fields read) - and STOPS there: Install is never pressed. Every root points at a scratch folder;
# services / firewall / MySQL install are off in the answers so the non-elevated host passes the checks with warnings only.
# Also probes a plain WPF PasswordBox: is ValuePattern.SetValue accepted (the sandbox driver falls back to keystrokes if not)?
param(
    [string]$Repo = 'D:\laragon2\www\pos-saas-e09-b',
    [string]$PackageRoot = 'D:\BingooEdgeLab-e09\cp5\pkgs\BingooEdge-0.9.0-dev',
    [string]$Out = 'D:\BingooEdgeLab-e09\cp5\host-preflight\ui',
    [string]$CloudWork = '',
    [string]$Kit = 'D:\BingooEdgeLab-e09\cp5\kit',
    [string]$CloudSrc = 'D:\BingooEdgeLab-e09\cp5\src\tree'
)
$ErrorActionPreference = 'Stop'
New-Item -ItemType Directory -Force -Path $Out | Out-Null
. (Join-Path $Repo 'tests\Fixtures\EdgeWizardCp5\sandbox\Cp5UiAutomation.ps1')
$scratch = Join-Path $Out 'roots'
$cloudUrl = $(if ($CloudWork) { 'http://127.0.0.1:9831' } else { 'http://127.0.0.1:9' })
$answers = [ordered]@{ cloud_url = $cloudUrl; allow_http_cloud = $true; install_root = (Join-Path $scratch 'install'); data_root = (Join-Path $scratch 'data')
    log_dir = (Join-Path $Out 'wizard-logs'); device_name = 'HOST-UI-PREFLIGHT'; services = [ordered]@{ register = $false; firewall = $false; gateway = $false }
    mysql = [ordered]@{ mode = 'existing' }; lan = [ordered]@{ hostname = 'bingoo-edge.local'; https_port = 8443 } }
$af = Join-Path $Out 'answers-ui-preflight.json'
[IO.File]::WriteAllText($af, ($answers | ConvertTo-Json -Depth 5), (New-Object Text.UTF8Encoding($false)))
$wiz = Join-Path $PackageRoot 'app\scripts\edge\wizard\Start-BingooEdgeWizard.ps1'   # the PACKAGED wizard (signed artifact)
if (-not (Test-Path $wiz)) { $wiz = Join-Path $Repo 'scripts\edge\wizard\Start-BingooEdgeWizard.ps1' }
$p = Start-Process powershell.exe -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -STA -File "' + $wiz + '" -AnswersFile "' + $af + '" -PackageRoot "' + $PackageRoot + '"') -PassThru
$doc = [ordered]@{ pid = $p.Id; wizard = $wiz }
try {
    $w = Find-UiWindow 90
    $doc.window = $w.Current.Name
    $doc.page_1 = Get-UiPage $w
    $doc.welcome_text = Get-UiText $w 'WelcomeText'
    $doc.shot_1 = Save-UiShot $w (Join-Path $Out '01-welcome.png')
    Invoke-UiButton $w 'BtnNext'
    Wait-UiPage $w 'Check this PC' 30 | Out-Null
    $deadline = (Get-Date).AddSeconds(180)
    do { Start-Sleep -Seconds 1; $s = Get-UiStatus $w } while ($s -ne 'Checks done.' -and (Get-Date) -lt $deadline)
    $doc.status_after_checks = $s
    $doc.checks = Get-UiChecks $w
    $doc.next_enabled = (Find-Ui $w 'BtnNext').Current.IsEnabled
    $doc.shot_2 = Save-UiShot $w (Join-Path $Out '02-prereq.png')
    if ($CloudWork -and $doc.next_enabled) {
        Invoke-UiButton $w 'BtnNext'
        Wait-UiPage $w 'Setup code' 30 | Out-Null
        $doc.shot_3 = Save-UiShot $w (Join-Path $Out '03-code-empty.png')
        # a setup code from the disposable host Cloud (file in the scratch folder, read once, deleted, never printed)
        $env:CP5_TREE = $CloudSrc; $env:CP5_WORK = $CloudWork; $env:CP5_KIT = $Kit; $env:CP5_CLOUD_PORT = '9831'; $env:CP5_CLOUD_DB_PORT = '3407'
        $codeFile = Join-Path $CloudWork 'secrets\ui-preflight.code'
        & (Join-Path $PackageRoot 'php\php.exe') -c (Join-Path $CloudWork 'cloud\php.ini') (Join-Path $Kit 'cp5-cloud.php') setup-code $codeFile | Out-Null
        $code = (Get-Content -Raw $codeFile).Trim(); Remove-Item -Force $codeFile
        Set-UiText $w 'TxtDevice' 'HOST-UI-PREFLIGHT'
        Set-UiText $w 'TxtCode' $code
        $code = $null
        Invoke-UiButton $w 'BtnRedeem'
        $deadline = (Get-Date).AddSeconds(90)
        do { Start-Sleep -Seconds 1; $red = Get-UiText $w 'RedeemText' } while (-not $red -and (Get-Date) -lt $deadline)
        $doc.redeemed = $red
        $doc.code_field_after_redeem_empty = [string]::IsNullOrEmpty((Find-Ui $w 'TxtCode').GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern).Current.Value)
        $doc.shot_4 = Save-UiShot $w (Join-Path $Out '04-code-accepted.png') @('TxtCode')
        Set-UiChecked $w 'ChkRedeemOk' $true
        Invoke-UiButton $w 'BtnNext'
        Wait-UiPage $w 'Passwords' 30 | Out-Null
        $doc.passwords_page = [ordered]@{ ip = (Find-Ui $w 'TxtIp').GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern).Current.Value
            host = (Find-Ui $w 'TxtHost').GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern).Current.Value
            port = (Find-Ui $w 'TxtPort').GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern).Current.Value
            mysql_panel_visible = [bool](Find-Ui $w 'PwRoot' 2 -Optional); next_button = [string](Find-Ui $w 'BtnNext').Current.Name }
        $doc.shot_5 = Save-UiShot $w (Join-Path $Out '05-passwords-NOT-INSTALLED.png')
        Invoke-UiButton $w 'BtnBack'
        $doc.page_after_back = Get-UiPage $w
    }
} catch {
    $doc.error = $_.Exception.Message
} finally {
    if ($w) { Close-UiWindow $w }
    if (-not $p.WaitForExit(20000)) { & taskkill.exe /PID $p.Id /T /F | Out-Null; $doc.killed = $true }
    $doc.exit = $p.ExitCode
}

# PasswordBox probe: a throw-away WPF window with one PasswordBox; UIA sets a value, the window reports only its LENGTH
$probe = Join-Path $Out 'pwbox-probe.ps1'
$res = Join-Path $Out 'pwbox-probe.txt'
Remove-Item -Force $res -ErrorAction SilentlyContinue
@'
param([string]$Result)
Add-Type -AssemblyName PresentationFramework
$w = New-Object Windows.Window; $w.Title = 'CP5 PasswordBox probe'; $w.Width = 300; $w.Height = 120
$pb = New-Object Windows.Controls.PasswordBox; $pb.Name = 'ProbePw'; $w.Content = $pb
$w.Add_Closed({ [IO.File]::WriteAllText($Result, [string]$pb.Password.Length) })
[void]$w.ShowDialog()
'@ | Set-Content -Path $probe -Encoding ASCII
$pp = Start-Process powershell.exe -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -STA -File "' + $probe + '" -Result "' + $res + '"') -PassThru
try {
    $script:UiWindowTitle = 'CP5 PasswordBox probe'
    $pw = Find-UiWindow 60
    $doc.passwordbox_entry = Set-UiSecret $pw 'ProbePw' 'abc123XYZ9'
    Close-UiWindow $pw
    [void]$pp.WaitForExit(15000)
    $doc.passwordbox_length_seen_by_wpf = (Get-Content -Raw $res).Trim()
} catch { $doc.passwordbox_error = $_.Exception.Message; & taskkill.exe /PID $pp.Id /T /F | Out-Null }
[IO.File]::WriteAllText((Join-Path $Out 'ui-preflight.json'), ($doc | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false)))
$doc | ConvertTo-Json -Depth 6
