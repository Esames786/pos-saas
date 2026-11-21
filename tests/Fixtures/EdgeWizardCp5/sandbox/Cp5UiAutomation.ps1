# CP5 (stream B) - UI Automation helpers for driving the REAL Bingoo Edge setup wizard window (WPF) like a person would:
# find controls by AutomationId (named XAML controls + the ids the wizard sets on its run-time rows), type, tick, press,
# read the status line, and take a screenshot of the window per page (secret fields are blacked out before saving).
# Dot-sourced by Invoke-Cp5Sandbox.ps1 (and by the host pre-flight). PowerShell 5.1, no external module.
Add-Type -AssemblyName UIAutomationClient, UIAutomationTypes, System.Drawing, System.Windows.Forms

$script:UiWindowTitle = 'Bingoo Edge - Branch Server setup'
$UiA = [System.Windows.Automation.AutomationElement]
$UiTree = [System.Windows.Automation.TreeScope]

function Find-UiWindow([int]$TimeoutSec = 120) {
    $cond = New-Object System.Windows.Automation.PropertyCondition($UiA::NameProperty, $script:UiWindowTitle)
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    do {
        $w = $UiA::RootElement.FindFirst($UiTree::Children, $cond)
        if ($w) { return $w }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)
    throw "UI_WINDOW_NOT_FOUND: '$($script:UiWindowTitle)' did not appear within $TimeoutSec s"
}
function Find-Ui($Window, [string]$Id, [int]$TimeoutSec = 15, [switch]$Optional) {
    $cond = New-Object System.Windows.Automation.PropertyCondition($UiA::AutomationIdProperty, $Id)
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    do {
        $e = $Window.FindFirst($UiTree::Descendants, $cond)
        if ($e -and -not $e.Current.IsOffscreen) { return $e }
        Start-Sleep -Milliseconds 300
    } while ((Get-Date) -lt $deadline)
    if ($Optional) { return $null }
    throw "UI_CONTROL_NOT_FOUND: $Id"
}
function Test-UiVisible($Window, [string]$Id) { return [bool](Find-Ui $Window $Id 1 -Optional) }
function Get-UiText($Window, [string]$Id) { $e = Find-Ui $Window $Id 2 -Optional; if ($e) { return [string]$e.Current.Name }; return $null }
function Get-UiStatus($Window) { return Get-UiText $Window 'Status' }
function Get-UiPage($Window) {
    # the left "Steps" list marks the current page with "> " (panels themselves have no automation peer)
    $list = Find-Ui $Window 'Steps' 5
    foreach ($item in $list.FindAll($UiTree::Children, [System.Windows.Automation.Condition]::TrueCondition)) {
        $n = [string]$item.Current.Name
        if ($n.StartsWith('> ')) { return $n.Substring(2).Trim() }
    }
    return $null
}
function Wait-UiPage($Window, [string]$Page, [int]$TimeoutSec = 120) {
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    do { if ((Get-UiPage $Window) -eq $Page) { return $true }; Start-Sleep -Milliseconds 500 } while ((Get-Date) -lt $deadline)
    throw "UI_PAGE_TIMEOUT: page '$Page' not reached (now '$(Get-UiPage $Window)', status '$(Get-UiStatus $Window)')"
}
function Wait-UiEnabled($Window, [string]$Id, [int]$TimeoutSec = 180) {
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    do { $e = Find-Ui $Window $Id 2 -Optional; if ($e -and $e.Current.IsEnabled) { return $e }; Start-Sleep -Milliseconds 500 } while ((Get-Date) -lt $deadline)
    throw "UI_NOT_ENABLED: $Id after $TimeoutSec s (status '$(Get-UiStatus $Window)')"
}
function Invoke-UiButton($Window, [string]$Id, [int]$TimeoutSec = 60) {
    $b = Wait-UiEnabled $Window $Id $TimeoutSec
    $b.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern).Invoke()
    Start-Sleep -Milliseconds 400
}
function Set-UiText($Window, [string]$Id, [string]$Value) {
    $e = Find-Ui $Window $Id 15
    $e.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern).SetValue($Value)
}
function Set-UiSecret($Window, [string]$Id, [string]$Value) {
    # PasswordBox: ValuePattern.SetValue when the peer allows it, else focus + keystrokes (alphanumeric secrets only)
    $e = Find-Ui $Window $Id 15
    try { $e.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern).SetValue($Value); return 'value-pattern' }
    catch {
        $e.SetFocus()
        Start-Sleep -Milliseconds 200
        [System.Windows.Forms.SendKeys]::SendWait($Value)
        return 'keystrokes'
    }
}
function Set-UiChecked($Window, [string]$Id, [bool]$Checked = $true) {
    $e = Wait-UiEnabled $Window $Id 60
    $t = $e.GetCurrentPattern([System.Windows.Automation.TogglePattern]::Pattern)
    $want = $(if ($Checked) { [System.Windows.Automation.ToggleState]::On } else { [System.Windows.Automation.ToggleState]::Off })
    if ($t.Current.ToggleState -ne $want) { $t.Toggle() }
    Start-Sleep -Milliseconds 300
}
function Get-UiListItems($Window, [string]$Id) {
    $l = Find-Ui $Window $Id 5 -Optional
    if (-not $l) { return @() }
    return @($l.FindAll($UiTree::Children, [System.Windows.Automation.Condition]::TrueCondition) | ForEach-Object { [string]$_.Current.Name })
}
function Get-UiChecks($Window) {
    # the prerequisite rows: Check_<id> (text) + CheckStatus_<id> (OK / WARN / FAIL)
    $out = [ordered]@{}
    $cond = New-Object System.Windows.Automation.PropertyCondition($UiA::ControlTypeProperty, [System.Windows.Automation.ControlType]::Text)
    foreach ($e in $Window.FindAll($UiTree::Descendants, $cond)) {
        $id = [string]$e.Current.AutomationId
        if ($id -like 'CheckStatus_*') { $k = $id.Substring(12); if (-not $out.Contains($k)) { $out[$k] = @{} }; $out[$k].status = [string]$e.Current.Name }
        elseif ($id -like 'Check_*') { $k = $id.Substring(6); if (-not $out.Contains($k)) { $out[$k] = @{} }; $out[$k].text = [string]$e.Current.Name }
    }
    return $out
}
function Save-UiShot($Window, [string]$Path, [string[]]$MaskIds = @()) {
    try {
        $wp = $Window.GetCurrentPattern([System.Windows.Automation.WindowPattern]::Pattern)
        if ($wp.Current.WindowVisualState -eq [System.Windows.Automation.WindowVisualState]::Minimized) { $wp.SetWindowVisualState([System.Windows.Automation.WindowVisualState]::Normal) }
    } catch { }
    try { $Window.SetFocus() } catch { }
    Start-Sleep -Milliseconds 600
    $r = $Window.Current.BoundingRectangle
    $bmp = New-Object System.Drawing.Bitmap([int]$r.Width, [int]$r.Height)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    try {
        $g.CopyFromScreen([int]$r.X, [int]$r.Y, 0, 0, $bmp.Size)
        foreach ($id in $MaskIds) {
            $e = Find-Ui $Window $id 1 -Optional
            if ($e) { $m = $e.Current.BoundingRectangle; $g.FillRectangle([System.Drawing.Brushes]::Black, [int]($m.X - $r.X), [int]($m.Y - $r.Y), [int]$m.Width, [int]$m.Height) }
        }
        $bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    } finally { $g.Dispose(); $bmp.Dispose() }
    return $Path
}
function Close-UiWindow($Window) {
    try { $Window.GetCurrentPattern([System.Windows.Automation.WindowPattern]::Pattern).Close() } catch { }
}
