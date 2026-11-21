# Bingoo Edge 0.9 - SETUP WIZARD window (WPF, PowerShell 5.1). A thin shell over BingooEdgeWizard.psm1: every button calls
# the same step function the headless mode runs; long steps run in a background runspace and stream their log lines here.
# Secrets are typed into PasswordBoxes and handed to the module as SecureString; nothing secret is ever displayed or logged.
#   -SelfTest : load the window definition and resolve every named control, then exit (no window shown) - CI / parse check.
param(
    [string]$PackageRoot,
    [object]$Answers = @{},
    [switch]$SelfTest
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName PresentationFramework, PresentationCore, WindowsBase, System.Xaml
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$modulePath = Join-Path $here 'BingooEdgeWizard.psm1'
Import-Module $modulePath -Force -DisableNameChecking

[xml]$xaml = @'
<Window xmlns="http://schemas.microsoft.com/winfx/2006/xaml/presentation" xmlns:x="http://schemas.microsoft.com/winfx/2006/xaml"
        Title="Bingoo Edge - Branch Server setup" Width="900" Height="640" WindowStartupLocation="CenterScreen" Background="#0F172A" Foreground="#E2E8F0" FontFamily="Segoe UI" FontSize="14">
  <Window.Resources>
    <Style TargetType="Button"><Setter Property="Padding" Value="14,6"/><Setter Property="Margin" Value="6,0,0,0"/><Setter Property="MinWidth" Value="96"/></Style>
    <Style TargetType="TextBlock"><Setter Property="TextWrapping" Value="Wrap"/><Setter Property="Foreground" Value="#E2E8F0"/></Style>
    <Style TargetType="TextBox"><Setter Property="Padding" Value="4"/><Setter Property="Margin" Value="0,2,0,8"/></Style>
    <Style TargetType="PasswordBox"><Setter Property="Padding" Value="4"/><Setter Property="Margin" Value="0,2,0,8"/></Style>
    <Style TargetType="CheckBox"><Setter Property="Foreground" Value="#E2E8F0"/><Setter Property="Margin" Value="0,4,0,4"/></Style>
  </Window.Resources>
  <DockPanel>
    <Border DockPanel.Dock="Left" Width="210" Background="#1E293B" Padding="14">
      <StackPanel>
        <TextBlock Text="Bingoo Edge" FontSize="22" FontWeight="SemiBold" Foreground="#CAA23F"/>
        <TextBlock Text="Branch Server setup" Foreground="#94A3B8" Margin="0,0,0,18"/>
        <ListBox Name="Steps" Background="Transparent" BorderThickness="0" Foreground="#94A3B8" IsHitTestVisible="False"/>
      </StackPanel>
    </Border>
    <Border DockPanel.Dock="Bottom" Background="#1E293B" Padding="12">
      <DockPanel LastChildFill="False">
        <TextBlock Name="Status" DockPanel.Dock="Left" VerticalAlignment="Center" Foreground="#94A3B8" MaxWidth="420"/>
        <Button Name="BtnNext" DockPanel.Dock="Right" Content="Next" IsDefault="True"/>
        <Button Name="BtnBack" DockPanel.Dock="Right" Content="Back"/>
      </DockPanel>
    </Border>
    <Grid Margin="22">
      <!-- 0 Welcome -->
      <StackPanel Name="PageWelcome">
        <TextBlock Text="Welcome" FontSize="22" FontWeight="SemiBold" Margin="0,0,0,12"/>
        <TextBlock Name="WelcomeText"/>
        <TextBlock Margin="0,14,0,0" Foreground="#94A3B8" Text="Nothing on this PC changes until you press Install. Keep the shop selling on the Online POS meanwhile - this Branch Server only becomes a warm standby."/>
      </StackPanel>
      <!-- 1 Prerequisites -->
      <DockPanel Name="PagePrereq" Visibility="Collapsed">
        <TextBlock DockPanel.Dock="Top" Text="Checking this PC" FontSize="22" FontWeight="SemiBold" Margin="0,0,0,10"/>
        <Button DockPanel.Dock="Bottom" Name="BtnRecheck" Content="Check again" HorizontalAlignment="Left" Margin="0,10,0,0"/>
        <ScrollViewer VerticalScrollBarVisibility="Auto"><StackPanel Name="CheckList"/></ScrollViewer>
      </DockPanel>
      <!-- 2 Setup code -->
      <StackPanel Name="PageCode" Visibility="Collapsed">
        <TextBlock Text="Connect to your Bingoo Cloud" FontSize="22" FontWeight="SemiBold" Margin="0,0,0,10"/>
        <TextBlock Text="On bingoopos.com open Settings &gt; Offline Edge &gt; Set up a Branch Server and type the setup code it shows (valid 60 minutes, single use). Your Cloud password never comes to this PC."/>
        <TextBlock Text="Setup code" Margin="0,14,0,0"/>
        <TextBox Name="TxtCode" FontSize="20" CharacterCasing="Upper" MaxLength="16" Width="260" HorizontalAlignment="Left"/>
        <TextBlock Text="Name of this Branch Server (shown on the Cloud)"/>
        <TextBox Name="TxtDevice" Width="360" HorizontalAlignment="Left"/>
        <Button Name="BtnRedeem" Content="Check code" HorizontalAlignment="Left" Margin="0,4,0,0"/>
        <Border Name="RedeemBox" Visibility="Collapsed" Margin="0,16,0,0" Padding="12" Background="#172033" BorderBrush="#334155" BorderThickness="1" CornerRadius="8">
          <StackPanel>
            <TextBlock Name="RedeemText" FontSize="16"/>
            <CheckBox Name="ChkRedeemOk" Content="Yes, this is the right business and branch"/>
          </StackPanel>
        </Border>
      </StackPanel>
      <!-- 3 Passwords + LAN -->
      <StackPanel Name="PagePasswords" Visibility="Collapsed">
        <TextBlock Text="Passwords and network" FontSize="22" FontWeight="SemiBold" Margin="0,0,0,10"/>
        <StackPanel Name="MySqlPanel">
          <TextBlock Text="MySQL administrator (root) password for this Branch Server - at least 10 characters. Keep it in your password manager; it is typed once and never shown."/>
          <PasswordBox Name="PwRoot" Width="320" HorizontalAlignment="Left"/>
          <TextBlock Text="Type it again"/>
          <PasswordBox Name="PwRoot2" Width="320" HorizontalAlignment="Left"/>
        </StackPanel>
        <TextBlock Foreground="#94A3B8" Text="The Edge database password and the certificate password are generated automatically and never shown (they are not needed by anyone)."/>
        <TextBlock Text="This PC's LAN address (reserve it on the router)" Margin="0,14,0,0"/>
        <TextBox Name="TxtIp" Width="200" HorizontalAlignment="Left"/>
        <TextBlock Text="Branch Server name on the LAN"/>
        <TextBox Name="TxtHost" Width="260" HorizontalAlignment="Left"/>
        <TextBlock Text="HTTPS port"/>
        <TextBox Name="TxtPort" Width="100" HorizontalAlignment="Left"/>
      </StackPanel>
      <!-- 4 Progress (install / update) -->
      <DockPanel Name="PageProgress" Visibility="Collapsed">
        <TextBlock DockPanel.Dock="Top" Name="ProgressTitle" Text="Installing" FontSize="22" FontWeight="SemiBold" Margin="0,0,0,10"/>
        <StackPanel DockPanel.Dock="Bottom" Name="FailPanel" Visibility="Collapsed" Margin="0,10,0,0">
          <TextBlock Name="FailText" Foreground="#FCA5A5"/>
          <StackPanel Orientation="Horizontal" Margin="0,8,0,0">
            <Button Name="BtnRollback" Content="Undo what was done" Margin="0"/>
            <Button Name="BtnRetry" Content="Try again"/>
            <Button Name="BtnLog" Content="Open the log"/>
          </StackPanel>
        </StackPanel>
        <ListBox Name="LogList" Background="#172033" Foreground="#E2E8F0" BorderBrush="#334155" FontFamily="Consolas" FontSize="12"/>
      </DockPanel>
      <!-- 5 Users & PINs -->
      <DockPanel Name="PageUsers" Visibility="Collapsed">
        <TextBlock DockPanel.Dock="Top" Text="Users and Edge PINs" FontSize="22" FontWeight="SemiBold" Margin="0,0,0,6"/>
        <TextBlock DockPanel.Dock="Top" Foreground="#94A3B8" Text="These are your Bingoo users for this branch. Give each person who may sell offline their OWN PIN (6+ digits). They log in on the Offline POS with their employee code and this PIN." Margin="0,0,0,8"/>
        <StackPanel DockPanel.Dock="Bottom" Margin="0,8,0,0">
          <CheckBox Name="ChkSame" Content="Use ONE PIN for the ticked users (not recommended: you cannot tell who sold what)"/>
          <StackPanel Name="SamePanel" Orientation="Horizontal" Visibility="Collapsed">
            <TextBlock Text="Shared PIN " VerticalAlignment="Center"/><PasswordBox Name="PwSame" Width="140"/>
            <TextBlock Text="  again " VerticalAlignment="Center"/><PasswordBox Name="PwSame2" Width="140"/>
          </StackPanel>
          <Button Name="BtnEnroll" Content="Set PINs" HorizontalAlignment="Left" Margin="0,6,0,0"/>
        </StackPanel>
        <ScrollViewer VerticalScrollBarVisibility="Auto"><StackPanel Name="UserList"/></ScrollViewer>
      </DockPanel>
      <!-- 6 Update plan -->
      <StackPanel Name="PageUpdate" Visibility="Collapsed">
        <TextBlock Text="Update this Branch Server" FontSize="22" FontWeight="SemiBold" Margin="0,0,0,10"/>
        <TextBlock Name="UpdateText"/>
        <CheckBox Name="ChkApprove" Content="I approve this update" Margin="0,14,0,0" IsEnabled="False"/>
      </StackPanel>
      <!-- 7 Finish -->
      <StackPanel Name="PageFinish" Visibility="Collapsed">
        <TextBlock Text="Done" FontSize="22" FontWeight="SemiBold" Margin="0,0,0,10"/>
        <TextBlock Name="FinishText"/>
        <StackPanel Orientation="Horizontal" Margin="0,16,0,0">
          <Button Name="BtnOpenStatus" Content="Open status page" Margin="0"/>
          <Button Name="BtnOpenLog" Content="Open the log"/>
        </StackPanel>
      </StackPanel>
    </Grid>
  </DockPanel>
</Window>
'@

$window = [Windows.Markup.XamlReader]::Load((New-Object System.Xml.XmlNodeReader $xaml))
$ui = @{}
foreach ($n in $xaml.SelectNodes('//*[@Name]')) { $ui[$n.Name] = $window.FindName($n.Name) }
if ($SelfTest) {
    $missing = @($ui.Keys | Where-Object { $null -eq $ui[$_] })
    Write-Output ("UI self-test: " + $ui.Count + " named controls, missing " + $missing.Count)
    exit $(if ($missing.Count -eq 0) { 0 } else { 1 })
}

$ctx = Initialize-WizContext -Answers $Answers -PackageRoot $PackageRoot
$installed = Test-WizInstalled $ctx
$script:mode = $(if ($installed) { 'update' } else { 'install' })
$pages = $(if ($installed) { @('PageWelcome', 'PagePrereq', 'PageUpdate', 'PageProgress', 'PageFinish') } else { @('PageWelcome', 'PagePrereq', 'PageCode', 'PagePasswords', 'PageProgress', 'PageUsers', 'PageFinish') })
$titles = @{ PageWelcome = 'Welcome'; PagePrereq = 'Check this PC'; PageCode = 'Setup code'; PagePasswords = 'Passwords'; PageProgress = $(if ($installed) { 'Update' } else { 'Install' }); PageUsers = 'Users and PINs'; PageUpdate = 'Update plan'; PageFinish = 'Finish' }
$script:page = 0
$script:checks = @()
$script:plan = $null
$script:userRows = @()
$script:result = 1
$sync = [hashtable]::Synchronized(@{ Queue = New-Object 'System.Collections.Concurrent.ConcurrentQueue[string]'; Busy = $false; Error = $null; Result = $null })
$script:bg = $null
$script:usersDone = $false
$script:users = @()
$ctx.PendingPins = $null

function Set-Status([string]$text) { $ui.Status.Text = $text }

function Show-Page([int]$i) {
    $script:page = $i
    foreach ($p in @('PageWelcome', 'PagePrereq', 'PageCode', 'PagePasswords', 'PageProgress', 'PageUsers', 'PageUpdate', 'PageFinish')) { $ui[$p].Visibility = 'Collapsed' }
    $ui[$pages[$i]].Visibility = 'Visible'
    $ui.Steps.Items.Clear()
    for ($k = 0; $k -lt $pages.Count; $k++) { [void]$ui.Steps.Items.Add($(if ($k -eq $i) { '> ' } elseif ($k -lt $i) { 'v ' } else { '  ' }) + $titles[$pages[$k]]) }
    $ui.BtnBack.IsEnabled = ($i -gt 0 -and $pages[$i] -notin @('PageProgress', 'PageUsers', 'PageFinish'))
    $ui.BtnNext.Content = $(if ($pages[$i] -eq 'PageFinish') { 'Close' } elseif ($pages[$i] -eq 'PagePasswords') { 'Install' } elseif ($pages[$i] -eq 'PageUpdate') { 'Update' } else { 'Next' })
    Update-NextEnabled
}

function Update-NextEnabled {
    $p = $pages[$script:page]
    $ok = -not $sync.Busy
    if ($p -eq 'PagePrereq') { $ok = $ok -and $script:checks.Count -gt 0 -and -not ($script:checks | Where-Object { $_.Status -eq 'fail' }) }
    if ($p -eq 'PageCode') { $ok = $ok -and [bool]$ui.ChkRedeemOk.IsChecked }
    if ($p -eq 'PageProgress') { $ok = $false }
    if ($p -eq 'PageUsers') { $ok = $ok -and [bool]$script:usersDone }
    if ($p -eq 'PageUpdate') { $ok = $ok -and [bool]$ui.ChkApprove.IsChecked }
    $ui.BtnNext.IsEnabled = $ok
}

# Run $code (text of a scriptblock using $ctx) in a background runspace; stream log lines; then call $onDone on the UI thread.
function Start-Background([string]$code, [scriptblock]$onDone) {
    $sync.Busy = $true; $sync.Error = $null; $sync.Result = $null
    Update-NextEnabled
    $rs = [RunspaceFactory]::CreateRunspace()
    $rs.ApartmentState = 'STA'
    $rs.Open()
    $rs.SessionStateProxy.SetVariable('ctx', $ctx)
    $rs.SessionStateProxy.SetVariable('sync', $sync)
    $rs.SessionStateProxy.SetVariable('modulePath', $modulePath)
    $ps = [PowerShell]::Create()
    $ps.Runspace = $rs
    [void]$ps.AddScript({
        param($code)
        $ErrorActionPreference = 'Stop'
        Import-Module $modulePath -DisableNameChecking
        $ctx.Progress = { param($l, $m) $sync.Queue.Enqueue($l + '|' + $m) }
        try { $sync.Result = & ([scriptblock]::Create($code)) } catch { $sync.Error = $_.Exception.Message } finally { $ctx.Progress = $null; $sync.Busy = $false }
    }).AddArgument($code)
    $script:bg = @{ Ps = $ps; Rs = $rs; Handle = $ps.BeginInvoke(); Done = $onDone }
    $script:timer.Start()
}

# ONE dispatcher timer (UI thread) drains the background log queue and runs the page's completion block. Only one background
# step runs at a time (Busy disables Next), so the state lives in $script:bg - no closures across scopes.
$script:timer = New-Object Windows.Threading.DispatcherTimer
$script:timer.Interval = [TimeSpan]::FromMilliseconds(300)
$script:timer.Add_Tick({
    $line = $null
    while ($sync.Queue.TryDequeue([ref]$line)) {
        $parts = $line.Split('|', 2)
        [void]$ui.LogList.Items.Add($parts[1])
        $ui.LogList.ScrollIntoView($ui.LogList.Items[$ui.LogList.Items.Count - 1])
        Set-Status $parts[1]
    }
    if (-not $sync.Busy -and $script:bg) {
        $script:timer.Stop()
        $bg = $script:bg
        $script:bg = $null
        try { $bg.Ps.EndInvoke($bg.Handle) } catch { }
        $bg.Ps.Dispose(); $bg.Rs.Dispose()
        & $bg.Done
        Update-NextEnabled
    }
})
function New-Brush([string]$hex) { return (New-Object Windows.Media.BrushConverter).ConvertFromString($hex) }

function Show-Checks {
    $ui.CheckList.Children.Clear()
    foreach ($c in $script:checks) {
        $row = New-Object Windows.Controls.DockPanel
        $row.Margin = New-Object Windows.Thickness(0, 4, 0, 4)
        $badge = New-Object Windows.Controls.TextBlock
        $badge.Width = 60
        $badge.FontWeight = [Windows.FontWeights]::SemiBold
        $badge.Text = $c.Status.ToUpper()
        $badge.Foreground = New-Brush $(if ($c.Status -eq 'ok') { '#86EFAC' } elseif ($c.Status -eq 'warn') { '#FCD34D' } else { '#FCA5A5' })
        [Windows.Controls.DockPanel]::SetDock($badge, 'Left')
        [void]$row.Children.Add($badge)
        if ($c.Fix) {
            $btn = New-Object Windows.Controls.Button
            $btn.Content = 'Fix'
            $btn.Tag = $c.Fix
            [Windows.Controls.DockPanel]::SetDock($btn, 'Right')
            $btn.Add_Click({
                param($sender, $e)
                $fix = [string]$sender.Tag
                $r = [Windows.MessageBox]::Show("Apply the fix for '$fix' on this PC?", 'Bingoo Edge', 'YesNo', 'Question')
                if ($r -eq 'Yes') {
                    try { Invoke-WizFix $ctx $fix; Invoke-Checks } catch { [Windows.MessageBox]::Show($_.Exception.Message, 'Bingoo Edge') | Out-Null }
                }
            })
            [void]$row.Children.Add($btn)
        }
        $t = New-Object Windows.Controls.TextBlock
        $t.Text = $c.Name + ': ' + $c.Detail
        [void]$row.Children.Add($t)
        [void]$ui.CheckList.Children.Add($row)
    }
    Update-NextEnabled
}

function Invoke-Checks {
    Set-Status 'Checking this PC...'
    $ui.CheckList.Children.Clear()
    Start-Background 'Test-WizPrerequisites $ctx' { $script:checks = @($sync.Result); if ($sync.Error) { Set-Status $sync.Error } else { Set-Status 'Checks done.' }; Show-Checks }
}

function Show-Users {
    $ui.UserList.Children.Clear()
    $script:userRows = @()
    foreach ($u in $script:users) {
        $row = New-Object Windows.Controls.StackPanel
        $row.Orientation = 'Horizontal'
        $row.Margin = New-Object Windows.Thickness(0, 3, 0, 3)
        $chk = New-Object Windows.Controls.CheckBox
        $chk.Width = 26
        $chk.IsChecked = ([bool]$u.employee_code -and -not $u.enrolled)
        $chk.IsEnabled = [bool]$u.employee_code
        $label = New-Object Windows.Controls.TextBlock
        $label.Width = 330
        $roles = (@($u.roles) -join ', ')
        $label.Text = $u.name + '  (' + $(if ($u.employee_code) { $u.employee_code } else { 'no employee code - set one on the Cloud' }) + ')' + $(if ($roles) { '  ' + $roles } else { '' }) + $(if ($u.enrolled) { '  - PIN already set' } else { '' })
        $pw = New-Object Windows.Controls.PasswordBox
        $pw.Width = 110; $pw.Margin = New-Object Windows.Thickness(6, 0, 0, 0)
        $pw2 = New-Object Windows.Controls.PasswordBox
        $pw2.Width = 110; $pw2.Margin = New-Object Windows.Thickness(6, 0, 0, 0)
        $res = New-Object Windows.Controls.TextBlock
        $res.Margin = New-Object Windows.Thickness(8, 0, 0, 0); $res.Width = 200
        foreach ($el in @($chk, $label, $pw, $pw2, $res)) { [void]$row.Children.Add($el) }
        [void]$ui.UserList.Children.Add($row)
        $script:userRows += [pscustomobject]@{ User = $u; Check = $chk; Pin = $pw; Pin2 = $pw2; Result = $res }
    }
}

function Start-Install {
    $ui.LogList.Items.Clear()
    $ui.FailPanel.Visibility = 'Collapsed'
    $ui.ProgressTitle.Text = 'Installing - this takes 5 to 20 minutes'
    Start-Background @'
Install-WizMySql $ctx
New-WizCertificates $ctx
Invoke-WizApplianceInstall $ctx
if (Get-WizValue $ctx.Answers 'services.register' $true) { Add-WizFirewallRule $ctx; Add-WizHostsLine $ctx }
Invoke-WizArtisan $ctx @('edge:local:report-status', 'bootstrapped', '--message=installed by the setup wizard') | Out-Null
Get-WizLocalUsers $ctx
'@ {
        if ($sync.Error) {
            $ui.FailText.Text = 'The installation stopped: ' + $sync.Error + "`nNothing is selling from this PC; the shop keeps using the Online POS."
            $ui.FailPanel.Visibility = 'Visible'
            Set-Status 'Stopped - choose Undo or Try again.'
        } else {
            $script:users = @($sync.Result)
            Show-Users
            Show-Page ([array]::IndexOf($pages, 'PageUsers'))
            Set-Status 'Installed and connected. Now set the PINs.'
        }
    }
}

$ui.ChkSame.Add_Checked({
    $r = [Windows.MessageBox]::Show('One PIN for several people means nobody can tell who rang a sale or opened the drawer. Use it only for a short trial. Continue?', 'Bingoo Edge', 'YesNo', 'Warning')
    if ($r -eq 'Yes') { $ui.SamePanel.Visibility = 'Visible' } else { $ui.ChkSame.IsChecked = $false }
})
$ui.ChkSame.Add_Unchecked({ $ui.SamePanel.Visibility = 'Collapsed' })
$ui.ChkRedeemOk.Add_Checked({ Update-NextEnabled })
$ui.ChkRedeemOk.Add_Unchecked({ Update-NextEnabled })
$ui.ChkApprove.Add_Checked({ $ctx.ApprovedPlanHash = $script:plan.PlanHash; Update-NextEnabled })
$ui.ChkApprove.Add_Unchecked({ $ctx.ApprovedPlanHash = $null; Update-NextEnabled })
$ui.BtnRecheck.Add_Click({ Invoke-Checks })

$ui.BtnRedeem.Add_Click({
    $code = $ui.TxtCode.Text.Trim()
    if ($code.Length -lt 6) { Set-Status 'Type the setup code from the Cloud page.'; return }
    $ctx.Secrets['setup_code'] = ConvertTo-WizSecure $code
    $ui.TxtCode.Text = ''
    if ($ui.TxtDevice.Text.Trim()) { if ($ctx.Answers -is [hashtable]) { $ctx.Answers['device_name'] = $ui.TxtDevice.Text.Trim() } else { $ctx.Answers | Add-Member -Force -NotePropertyName device_name -NotePropertyValue $ui.TxtDevice.Text.Trim() } }
    Set-Status 'Checking the code with the Cloud...'
    Start-Background 'Invoke-WizRedeem $ctx $ctx.Secrets["setup_code"] | Out-Null; $ctx.Secrets.Remove("setup_code"); "ok"' {
        if ($sync.Error) { Set-Status ('Code refused: ' + $sync.Error); return }
        $ui.RedeemText.Text = 'Business: ' + $ctx.Setup.TenantName + ' (' + $ctx.Setup.TenantCode + ")`nBranch: " + $ctx.Setup.BranchName + ' (#' + $ctx.Setup.BranchId + ")`nCloud: " + $ctx.Setup.CloudUrl
        $ui.RedeemBox.Visibility = 'Visible'
        Set-Status 'Code accepted - confirm the business and branch.'
    }
})

$ui.BtnEnroll.Add_Click({
    $pins = @{}
    $same = [bool]$ui.ChkSame.IsChecked
    if ($same) {
        if ($ui.PwSame.Password -ne $ui.PwSame2.Password) { Set-Status 'The two shared PINs differ.'; return }
        $why = Test-WizPin $ui.PwSame.SecurePassword
        if ($why) { Set-Status ('Shared PIN: ' + $why); return }
    }
    foreach ($r in $script:userRows) {
        if (-not $r.Check.IsChecked) { continue }
        if ($same) { $pins[[int]$r.User.id] = $ui.PwSame.SecurePassword.Copy(); continue }
        if ($r.Pin.Password -ne $r.Pin2.Password) { Set-Status ('PINs for ' + $r.User.name + ' differ.'); return }
        $why = Test-WizPin $r.Pin.SecurePassword
        if ($why) { Set-Status ($r.User.name + ': ' + $why); return }
        $pins[[int]$r.User.id] = $r.Pin.SecurePassword.Copy()
    }
    if ($pins.Count -eq 0) { $script:usersDone = $true; Update-NextEnabled; return }
    $ctx.PendingPins = $pins
    Set-Status 'Setting PINs...'
    Start-Background '$r = Invoke-WizEnrollUsers $ctx $ctx.PendingPins; $ctx.PendingPins = $null; $r' {
        if ($sync.Error) { Set-Status ('PINs not set: ' + $sync.Error); return }
        foreach ($res in @($sync.Result)) {
            $row = $script:userRows | Where-Object { [int]$_.User.id -eq [int]$res.UserId } | Select-Object -First 1
            if ($row) { $row.Result.Text = $(if ($res.Ok) { 'PIN set' } else { $res.Detail }); $row.Result.Foreground = New-Brush $(if ($res.Ok) { '#86EFAC' } else { '#FCA5A5' }); $row.Pin.Clear(); $row.Pin2.Clear(); if ($res.Ok) { $row.Check.IsChecked = $false } }
        }
        $ui.PwSame.Clear(); $ui.PwSame2.Clear()
        $script:usersDone = $true
        Set-Status 'PINs saved. Add more, or press Next.'
    }
})

$ui.BtnRollback.Add_Click({
    Start-Background 'Undo-WizInstall $ctx; "undone"' { Set-Status $(if ($sync.Error) { 'Undo failed: ' + $sync.Error } else { 'Undone. You can close the wizard or try again with a NEW setup code.' }) }
})
$ui.BtnRetry.Add_Click({
    $r = [Windows.MessageBox]::Show('Try again? The steps already done are undone first. A new setup code may be needed if pairing had started.', 'Bingoo Edge', 'YesNo', 'Question')
    if ($r -eq 'Yes') { Start-Background 'Undo-WizInstall $ctx; "undone"' { Show-Page ([array]::IndexOf($pages, 'PageCode')); $ui.RedeemBox.Visibility = 'Collapsed'; $ui.ChkRedeemOk.IsChecked = $false } }
})
foreach ($b in @($ui.BtnLog, $ui.BtnOpenLog)) { $b.Add_Click({ Start-Process notepad.exe -ArgumentList $ctx.LogFile }) }
$ui.BtnOpenStatus.Add_Click({
    $lan = $ctx.Lan
    if ($lan) { Start-Process ('https://' + $lan.Hostname + $(if ($lan.HttpsPort -ne 443) { ':' + $lan.HttpsPort } else { '' }) + '/edge/local/pos/health') }
})

$ui.BtnBack.Add_Click({ if ($script:page -gt 0) { Show-Page ($script:page - 1) } })
$ui.BtnNext.Add_Click({
    $p = $pages[$script:page]
    switch ($p) {
        'PageWelcome' { Show-Page 1; Invoke-Checks }
        'PagePrereq' {
            if ($installed) {
                Show-Page ([array]::IndexOf($pages, 'PageUpdate'))
                $ui.UpdateText.Text = 'Reading the update plan...'
                Start-Background 'Get-WizUpdatePlan $ctx' {
                    $script:plan = $sync.Result
                    if ($sync.Error -or -not $script:plan.Ok) { $ui.UpdateText.Text = 'This package cannot be installed here: ' + $(if ($sync.Error) { $sync.Error } else { $script:plan.Error }); return }
                    $txt = 'Installed: ' + $script:plan.From + "`nNew: " + $script:plan.To + "`nWho sells now: " + $(if ($script:plan.Authority -eq 'standby') { 'the Cloud (OK to update)' } else { $script:plan.Authority + ' - press Return to Online first' }) + "`nWaiting to sync: " + $script:plan.Pending
                    $txt += "`nDatabase changes: " + $(if ($script:plan.Migrations.Count -eq 0) { 'none' } else { $script:plan.Migrations.Count.ToString() + ' (a full backup is taken first and restored if the update fails)' }) + "`nPlan: " + $script:plan.PlanHash
                    $ui.UpdateText.Text = $txt
                    $ui.ChkApprove.IsEnabled = ($script:plan.Authority -eq 'standby' -and $script:plan.Pending -eq 0)
                }
            } else {
                Show-Page 2
                $ui.TxtDevice.Text = Get-WizValue $ctx.Answers 'device_name' ($env:COMPUTERNAME + ' Branch Server')
            }
        }
        'PageCode' {
            Show-Page 3
            $lan = Get-WizLanInfo
            $ui.TxtIp.Text = Get-WizValue $ctx.Answers 'lan.ip' $(if ($lan) { $lan.Ip } else { '' })
            if ($ui.TxtIp.Text -eq 'auto' -and $lan) { $ui.TxtIp.Text = $lan.Ip }
            $ui.TxtHost.Text = Get-WizValue $ctx.Answers 'lan.hostname' 'bingoo-edge.local'
            $ui.TxtPort.Text = [string](Get-WizValue $ctx.Answers 'lan.https_port' 8443)
            $ui.MySqlPanel.Visibility = $(if ((Get-WizValue $ctx.Answers 'mysql.mode' 'install') -eq 'install') { 'Visible' } else { 'Collapsed' })
        }
        'PagePasswords' {
            if ($ui.MySqlPanel.Visibility -eq 'Visible') {
                if ($ui.PwRoot.Password.Length -lt 10) { Set-Status 'The MySQL password needs at least 10 characters.'; return }
                if ($ui.PwRoot.Password -ne $ui.PwRoot2.Password) { Set-Status 'The two MySQL passwords differ.'; return }
                $ctx.Secrets['mysql_root'] = $ui.PwRoot.SecurePassword.Copy()
                $ui.PwRoot.Clear(); $ui.PwRoot2.Clear()
            }
            $ip = [Net.IPAddress]::None
            if (-not [Net.IPAddress]::TryParse($ui.TxtIp.Text.Trim(), [ref]$ip)) { Set-Status 'Type this PC''s LAN IP address (e.g. 192.168.100.52).'; return }
            $lanAnswers = @{ ip = $ui.TxtIp.Text.Trim(); hostname = $ui.TxtHost.Text.Trim(); https_port = [int]$ui.TxtPort.Text; http_port = [int](Get-WizValue $ctx.Answers 'lan.http_port' 80) }
            if ($ctx.Answers -is [hashtable]) { $ctx.Answers['lan'] = $lanAnswers } else { $ctx.Answers | Add-Member -Force -NotePropertyName lan -NotePropertyValue ([pscustomobject]$lanAnswers) }
            Show-Page ([array]::IndexOf($pages, 'PageProgress'))
            Start-Install
        }
        'PageUpdate' {
            Show-Page ([array]::IndexOf($pages, 'PageProgress'))
            $ui.ProgressTitle.Text = 'Updating - the Branch Server restarts its services for a few minutes'
            $ui.BtnRollback.Visibility = 'Collapsed'; $ui.BtnRetry.Visibility = 'Collapsed'
            Start-Background 'Invoke-WizUpdate $ctx' {
                if ($sync.Error) { $ui.FailText.Text = 'The update stopped before changing anything that matters: ' + $sync.Error; $ui.FailPanel.Visibility = 'Visible'; return }
                $r = $sync.Result
                $script:result = $(if ($r.Result -eq 'updated') { 0 } else { 2 })
                $ui.FinishText.Text = $(if ($r.Result -eq 'updated') { 'Updated ' + $r.From + ' -> ' + $r.To + '. Health: ' + $r.Health + '. From now on updates arrive automatically in the maintenance window.' } else { 'The new version was not healthy, so the Branch Server went back to ' + $r.From + ' (' + $r.Rollback + '). Nothing was lost. Reasons: ' + ($r.Reasons -join '; ') })
                Show-Page ([array]::IndexOf($pages, 'PageFinish'))
            }
        }
        'PageUsers' {
            Show-Page ([array]::IndexOf($pages, 'PageFinish'))
            $ui.FinishText.Text = 'Finishing: desktop links, reports to the Cloud, health check...'
            Start-Background '$s = New-WizShortcuts $ctx; $h = Get-WizHealth $ctx; Send-WizReports $ctx "ready" ("setup wizard finished, health " + [string](Get-WizValue $h "status" "?")); @{ Shortcuts = $s; Health = $h }' {
                if ($sync.Error) { $ui.FinishText.Text = 'Installed, but finishing reported: ' + $sync.Error; return }
                $h = $sync.Result.Health
                $script:result = 0
                $ui.FinishText.Text = 'The Branch Server is installed as a WARM STANDBY.' + "`nStatus: " + [string](Get-WizValue $h 'status_label' (Get-WizValue $h 'status' '?')) + "`nDesktop links: BINGOO Online, BINGOO Offline, Branch Server Status." + "`nCounters: download 'Counter Setup' from the Cloud Offline Edge page and run it on each counter PC."
            }
        }
        'PageFinish' { $window.Close() }
    }
})

Show-Page 0
$ui.WelcomeText.Text = $(if ($installed) {
    'A Bingoo Edge Branch Server is already installed on this PC. This wizard will UPDATE it in place with the package in this folder (' + [string](Get-WizPackageManifest $ctx).edge_app_version + '). Pairing, users and data stay as they are.'
} else {
    'This wizard installs the Bingoo Edge Branch Server (version ' + [string](Get-WizPackageManifest $ctx).edge_app_version + ') on this PC: its own database, a secure LAN certificate, the connection to your Bingoo Cloud, and a PIN for each of your users. You need: the setup code from the Cloud page, and about 20 minutes.'
})
[void]$window.ShowDialog()
Remove-WizSecureDir $ctx
exit $script:result
