# CP5 scenario s (stream D) - drives the REAL counter wizard WINDOW of a downloaded Install-Offline-Counter-*.ps1:
# the file's own data + functions are loaded (its top-level elevation code is not run: the sandbox driver is already the
# administrator), the WPF window is built by the file's own Initialize-CounterWindow, shown, and clicked like a cashier would:
#   install: certificate (no tick -> nothing; tick + "No" -> nothing; tick + "Yes" -> trusted), hosts line + shortcuts,
#            HTTPS checks by name and IP (full validation), own Edge PIN (typed into the PasswordBoxes), counter, finish
#   repair:  the same window again (setup detected) -> everything idempotent
# Only the two-button message boxes are answered automatically (their text is recorded); every other line is the file's code,
# with the REAL system primitives (LocalMachine\Root, hosts, Public Desktop) - so the real run is refused outside the sandbox.
#   -Simulate <dir>  HOST dry run: primitives -> <dir> (temp hosts / desktop / ProgramData, in-memory store), network stubbed.
param(
    [Parameter(Mandatory = $true)][string]$WizardFile,
    [Parameter(Mandatory = $true)][string]$Evidence,
    [string]$PinFile = '',
    [string]$Prefix = 's-',
    [string]$Simulate = '',
    [switch]$InSandbox
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Off

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $Simulate -and -not ($InSandbox -and $isAdmin -and $env:USERNAME -eq 'WDAGUtilityAccount')) {
    throw 'refusing: the REAL counter wizard run changes the certificate store, the hosts file and the Public Desktop - it runs only inside the Windows Sandbox (administrator WDAGUtilityAccount, -InSandbox). Use -Simulate <dir> elsewhere.'
}
if ([Threading.Thread]::CurrentThread.GetApartmentState() -ne [Threading.ApartmentState]::STA) { throw 'run with powershell.exe -STA' }
New-Item -ItemType Directory -Force -Path $Evidence | Out-Null

# ---- load the file's data + functions (never its top-level code) ----
$tokens = $null; $errs = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($WizardFile, [ref]$tokens, [ref]$errs)
if (@($errs).Count -gt 0) { throw ('the counter wizard does not parse: ' + ($errs | Select-Object -First 1)) }
$functions = @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $false))
$firstFunction = ($functions | ForEach-Object { $_.Extent.StartOffset } | Measure-Object -Minimum).Minimum
foreach ($st in $ast.EndBlock.Statements) {
    if ($st -is [System.Management.Automation.Language.AssignmentStatementAst] -and $st.Extent.StartOffset -lt $firstFunction) { . ([scriptblock]::Create($st.Extent.Text)) }
}
foreach ($f in $functions) { . ([scriptblock]::Create($f.Extent.Text)) }
$ErrorActionPreference = 'Stop'

foreach ($assembly in @('PresentationFramework, Version=4.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35', 'PresentationCore, Version=4.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35',
        'WindowsBase, Version=4.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35', 'System.Xaml, Version=4.0.0.0, Culture=neutral, PublicKeyToken=b77a5c561934e089')) {
    [void][System.Reflection.Assembly]::Load($assembly)
}

# ---- the only replaced function in a real run: the message boxes answer from a queue, their text is recorded ----
$global:Answers = New-Object System.Collections.Queue
$global:Dialogs = New-Object System.Collections.ArrayList
function Show-Message {
    param([string]$Text, [string]$Title, [string]$Buttons = 'OK', [string]$Icon = 'Information')
    $answer = [System.Windows.MessageBoxResult]::No
    if ($global:Answers.Count -gt 0) { $answer = $global:Answers.Dequeue() }
    [void]$global:Dialogs.Add([ordered]@{ title = $Title; text = $Text; answer = [string]$answer })
    return $answer
}

if ($Simulate) {
    # ---- HOST dry run: the 8 system primitives -> a temp folder, the Branch Server -> a stub ----
    foreach ($d in @('etc', 'Public\Desktop', 'Users\cashier\Desktop', 'ProgramData')) { New-Item -ItemType Directory -Force -Path (Join-Path $Simulate $d) | Out-Null }
    [IO.File]::WriteAllText((Join-Path $Simulate 'etc\hosts'), "# hosts`r`n127.0.0.1 localhost`r`n")
    $global:FakeStore = New-Object System.Collections.ArrayList
    [void]$global:FakeStore.Add([pscustomobject]@{ Thumbprint = 'C0FFEE0000000000000000000000000000000001'; CommonName = 'CP5 Sandbox Cloud Test CA'; Organization = 'CP5 Rehearsal (not Bingoo)'; NotAfter = '2030-01-01' })
    function Get-CounterPaths { return @{ Hosts = (Join-Path $Simulate 'etc\hosts'); PublicDesktop = (Join-Path $Simulate 'Public\Desktop'); IconPath = (Join-Path $Simulate 'ProgramData\BingooPOS\bingoo.ico'); StateFile = (Join-Path $Simulate 'ProgramData\BingooPOS\counter\state.json'); LogDir = (Join-Path $Simulate 'ProgramData\BingooPOS\logs') } }
    function Get-UserDesktops { return @(Join-Path $Simulate 'Users\cashier\Desktop') }
    function Test-CaInStore { param([string]$Thumbprint) return @($global:FakeStore | Where-Object { $_.Thumbprint -eq $Thumbprint }).Count -gt 0 }
    function Get-RootCertificates { return @($global:FakeStore) }
    function Add-CaToStore { param($Certificate, [bool]$Confirmed) if (-not $Confirmed) { throw 'not confirmed' }; [void]$global:FakeStore.Add((ConvertTo-CaFacts $Certificate)) }
    function Remove-CaFromStore { param([string]$Thumbprint, [bool]$Confirmed) if (-not $Confirmed) { throw 'not confirmed' }; foreach ($c in @($global:FakeStore | Where-Object { $_.Thumbprint -eq $Thumbprint })) { $global:FakeStore.Remove($c) } }
    function Clear-DnsCache { }
    function Update-IconCache { }
    function Update-UiNow { }
    function Invoke-EdgeHttp {
        param([string]$Url, [string]$Method = 'GET', [string]$JsonBody = '', [int]$TimeoutMs = 10000)
        if ($Url.EndsWith('/edge/local/enroll')) { return @{ Ok = $true; Kind = 'ok'; Status = 201; Body = '{"ok":true,"user_id":2}'; Message = '' } }
        if ($Url.EndsWith('/edge/local/health')) { return @{ Ok = $true; Kind = 'ok'; Status = 200; Body = ('{"status":"ok","enrollment_key_sha256":"' + $SignedProfileKeySha256 + '","terminals":[{"id":' + [Math]::Max(1, $TerminalId) + ',"active":true}]}'); Message = '' } }
        return @{ Ok = $true; Kind = 'ok'; Status = 200; Body = '<html></html>'; Message = '' }
    }
}

# ---- helpers ----
function Click($Button) { $Button.RaiseEvent((New-Object System.Windows.RoutedEventArgs -ArgumentList ([System.Windows.Controls.Primitives.ButtonBase]::ClickEvent))); Pump }
function Pump { if ($Window -and $Window.IsVisible) { try { $Window.Dispatcher.Invoke([Action] { }, [System.Windows.Threading.DispatcherPriority]::ApplicationIdle) } catch { } } }
function Save-Shot([string]$Name) {
    try {
        Pump
        $root = $Window.Content
        $root.Background = $Window.Background
        if ($Window.IsVisible) { $w = [int]$root.ActualWidth; $h = [int]$root.ActualHeight }
        else { $w = 800; $h = 660; $root.Measure((New-Object System.Windows.Size -ArgumentList $w, $h)); $root.Arrange((New-Object System.Windows.Rect -ArgumentList 0, 0, $w, $h)); $root.UpdateLayout() }
        $bmp = New-Object System.Windows.Media.Imaging.RenderTargetBitmap -ArgumentList $w, $h, 96, 96, ([System.Windows.Media.PixelFormats]::Pbgra32)
        $bmp.Render($root)
        $enc = New-Object System.Windows.Media.Imaging.PngBitmapEncoder
        $enc.Frames.Add([System.Windows.Media.Imaging.BitmapFrame]::Create($bmp))
        $file = Join-Path $Evidence ($Prefix + 'wizard-' + $Name + '.png')
        $fs = [IO.File]::Create($file)
        try { $enc.Save($fs) } finally { $fs.Dispose() }
        return (Split-Path -Leaf $file)
    } catch { return ('screenshot failed: ' + $_.Exception.Message) }
}
function Open-Window {
    $doc = [xml]$Xaml
    $script:Window = [Windows.Markup.XamlReader]::Load((New-Object System.Xml.XmlNodeReader -ArgumentList $doc))
    Initialize-CounterWindow -XamlDoc $doc
    Show-Page 'PageWelcome'
    if (-not $Simulate) { $Window.Show(); $Window.Activate() | Out-Null }
    Pump
}
function Close-Window { try { $Window.Close() } catch { }; Pump }
function Get-EdgeHostsLines {
    $all = Read-HostsLines   # the file's helper returns its array whole; unroll it before filtering
    return , @($all | Where-Object { ($_ -split '#', 2)[0] -match ('(^|\s)' + [regex]::Escape($EdgeHost) + '(\s|$)') })
}
function Get-ShortcutFacts {
    $desk = (Get-CounterPaths).PublicDesktop
    $icon = (Get-CounterPaths).IconPath
    $iconOk = $false
    if (Test-Path -LiteralPath $icon) { $b = [IO.File]::ReadAllBytes($icon); $iconOk = ($b.Length -gt 30 -and $b[2] -eq 1 -and $b[6] -eq 128 -and $b[22] -eq 0x89 -and $b[23] -eq 0x50) }
    $list = @()
    foreach ($name in $ShortcutNames) {
        $f = Join-Path $desk ($name + '.url')
        $t = ''
        if (Test-Path -LiteralPath $f) { $t = [IO.File]::ReadAllText($f) }
        $url = ''
        if ($t -match '(?m)^URL=(.+)$') { $url = $Matches[1].Trim() }
        $list += [ordered]@{ name = $name; present = [bool]$t; url = $url; icon = [bool]($t -match ('(?m)^IconFile=' + [regex]::Escape($icon) + '\s*$')) }
    }
    return [ordered]@{ icon_file_is_128px_png_ico = $iconOk; shortcuts = $list }
}
function Get-ForeignRoots { return @(Get-RootCertificates | Where-Object { -not (Test-BingooBranchCa $_) } | ForEach-Object { $_.Thumbprint } | Sort-Object) }

$out = [ordered]@{ file = (Split-Path -Leaf $WizardFile); real_run = (-not $Simulate); started = (Get-Date).ToString('s'); install = [ordered]@{}; repair = [ordered]@{} }
$foreignBefore = Get-ForeignRoots
$hostsBefore = @(Get-EdgeHostsLines).Count

# ================================================================== install, exactly as the cashier clicks it
$i = $out.install
Open-Window
$i.welcome = [ordered]@{ origin_status = [string]$State.Origin.Status; origin_text = $ui.TxtOrigin.Text; mode = $State.Mode; business = $ui.TxtTenant.Text
    branch = $ui.TxtBranch.Text; server = $ui.TxtServer.Text; ip = $ui.TxtServerIp.Text; ca_sha256 = $ui.TxtWelcomeSha.Text; setup_state = $ui.TxtSetupState.Text; shot = (Save-Shot 'i1-welcome') }
Click $ui.BtnNext
$i.certificate = [ordered]@{ page = $State.Page; status = $ui.TxtCaStatus.Text; next_enabled_before = [bool]$ui.BtnNext.IsEnabled; trusted_before = (Test-CaInStore $CaThumbprint); shot = (Save-Shot 'i2-certificate') }
Click $ui.BtnTrustCa
$i.certificate.trusted_after_click_without_tick = (Test-CaInStore $CaThumbprint)
$ui.ChkTrustCa.IsChecked = $true
$global:Answers.Enqueue([System.Windows.MessageBoxResult]::No)
Click $ui.BtnTrustCa
$i.certificate.trusted_after_tick_and_no = (Test-CaInStore $CaThumbprint)
$global:Answers.Enqueue([System.Windows.MessageBoxResult]::Yes)
Click $ui.BtnTrustCa
$i.certificate.trusted_after_tick_and_yes = (Test-CaInStore $CaThumbprint)
$i.certificate.status_after = $ui.TxtCaStatus.Text
$i.certificate.next_enabled_after = [bool]$ui.BtnNext.IsEnabled
$i.certificate.dialogs = @($global:Dialogs)
$i.certificate.shot_after = (Save-Shot 'i3-certificate-trusted')

Click $ui.BtnNext
Click $ui.BtnApplyNetwork
$i.network = [ordered]@{ page = $State.Page; ok = [bool]$State.NetworkOk; result = $ui.TxtNetworkResult.Text; hosts_lines_for_edge = @(Get-EdgeHostsLines); shortcuts = (Get-ShortcutFacts); shot = (Save-Shot 'i4-hosts-shortcuts') }

Click $ui.BtnNext
$i.checks = [ordered]@{ page = $State.Page; ok = [bool]($State.Checks -and $State.Checks.Ok); lines = @($State.Checks.Lines); hints = @($State.Checks.Hints); shot = (Save-Shot 'i5-checks') }

$flow = Get-PageFlow
if ($flow -contains 'PagePin') {
    Click $ui.BtnNext
    $i.pin = [ordered]@{ page = $State.Page; who = $ui.TxtPinWho.Text; expiry = $ui.TxtPinExpiry.Text; shot_before = (Save-Shot 'i6-pin') }
    if ($PinFile) {
        $pin = (Get-Content -Raw -LiteralPath $PinFile).Trim()
        $ui.PwPin1.Password = $pin
        $ui.PwPin2.Password = $pin
        $pin = $null
        $i.pin.typed_dots = $ui.PwPin1.Password.Length
        Click $ui.BtnSetPin
        $i.pin.status = $State.PinStatus
        $i.pin.result = $ui.TxtPinResult.Text
        $i.pin.boxes_cleared = ($ui.PwPin1.Password.Length -eq 0 -and $ui.PwPin2.Password.Length -eq 0)
        $i.pin.shot_after = (Save-Shot 'i7-pin-set')
    }
}
if ($flow -contains 'PageTerminal') {
    Click $ui.BtnNext
    $i.counter = [ordered]@{ page = $State.Page; hint = $ui.TxtTerminalHint.Text; status = $ui.TxtTerminalStatus.Text; known = [bool]($State.TerminalStatus -and $State.TerminalStatus.Known); shot = (Save-Shot 'i8-counter') }
}
Click $ui.BtnNext
$i.finish = [ordered]@{ page = $State.Page; title = $ui.TxtFinishTitle.Text; body = $ui.TxtFinishBody.Text; exit_code = $State.ExitCode; open_offline_visible = ([string]$ui.BtnOpenOffline.Visibility -eq 'Visible'); shot = (Save-Shot 'i9-finish') }
$i.pages = @($flow)
Close-Window

# ================================================================== repair: the same file again, everything idempotent
$global:Dialogs.Clear()
$r = $out.repair
Open-Window
$r.welcome = [ordered]@{ mode = $State.Mode; repair_preselected = [bool]$ui.RbRepair.IsChecked; setup_state = $ui.TxtSetupState.Text; shot = (Save-Shot 'r1-welcome') }
Click $ui.BtnNext
$r.certificate = [ordered]@{ status = $ui.TxtCaStatus.Text; ok_without_asking = [bool]$State.CaOk; dialogs = $global:Dialogs.Count }
Click $ui.BtnNext
Click $ui.BtnApplyNetwork
$r.network = [ordered]@{ ok = [bool]$State.NetworkOk; result = $ui.TxtNetworkResult.Text; hosts_lines_for_edge = @(Get-EdgeHostsLines).Count }
Click $ui.BtnNext
$r.checks_ok = [bool]($State.Checks -and $State.Checks.Ok)
$repairFlow = Get-PageFlow   # returned whole (comma operator): assign first, then iterate
foreach ($p in $repairFlow) { if ($State.Page -ne 'PageFinish') { Click $ui.BtnNext } }
$r.finish = [ordered]@{ page = $State.Page; title = $ui.TxtFinishTitle.Text; exit_code = $State.ExitCode; shot = (Save-Shot 'r2-finish') }
Close-Window

$foreignAfter = Get-ForeignRoots
$out.non_bingoo_roots_untouched = (($foreignBefore -join ',') -eq ($foreignAfter -join ','))
$out.non_bingoo_roots = @($foreignAfter).Count
$out.hosts_lines_before = $hostsBefore
$sc = $i.network.shortcuts.shortcuts
$out.verdict = [ordered]@{
    origin_verified           = $i.welcome.origin_status -eq 'signed'
    ca_only_after_confirmation = (-not $i.certificate.trusted_before) -and (-not $i.certificate.trusted_after_click_without_tick) -and (-not $i.certificate.trusted_after_tick_and_no) -and $i.certificate.trusted_after_tick_and_yes -and (-not $i.certificate.next_enabled_before) -and $i.certificate.next_enabled_after
    one_hosts_line            = $i.network.ok -and @($i.network.hosts_lines_for_edge).Count -eq 1
    shortcuts_with_icon       = $i.network.shortcuts.icon_file_is_128px_png_ico -and @($sc | Where-Object { $_.present -and $_.icon }).Count -eq 3 -and ($sc | Where-Object { $_.name -eq 'BINGOO Online' }).url -eq $UrlOnline -and ($sc | Where-Object { $_.name -eq 'BINGOO Offline' }).url -eq $UrlOffline
    checks_by_name_and_ip     = $i.checks.ok
    own_pin_set               = (-not $PinFile) -or ($i.pin -and $i.pin.status -eq 'set' -and $i.pin.boxes_cleared)
    offline_counter_ready     = $i.finish.title -eq 'Offline Counter Ready' -and $i.finish.exit_code -eq 0
    repair_idempotent         = $r.welcome.repair_preselected -and $r.certificate.ok_without_asking -and $r.certificate.dialogs -eq 0 -and $r.network.ok -and $r.network.hosts_lines_for_edge -eq 1 -and $r.checks_ok -and $r.finish.title -eq 'Offline Counter Ready'
    non_bingoo_roots_untouched = $out.non_bingoo_roots_untouched
}
$out.verdict.all = -not ($out.verdict.Values -contains $false)
$out.finished = (Get-Date).ToString('s')
[IO.File]::WriteAllText((Join-Path $Evidence ($Prefix + 'wizard-ui.json')), ($out | ConvertTo-Json -Depth 10), (New-Object Text.UTF8Encoding($false)))
$out.verdict | ConvertTo-Json -Compress
if ($out.verdict.all) { exit 0 } else { exit 1 }
