# CP5 scenario s (stream D) - a small Chrome DevTools Protocol client for HEADLESS Microsoft Edge (PowerShell 5.1, no module):
# one browser WebSocket, one page attached with flatten=true (sessionId), downloads allowed into a folder, screenshots as PNG.
# Dot-source it. Values sent to the page (passwords, PINs) travel only over the loopback DevTools socket and are never logged;
# Write-CdpLog records method names, never parameters.

$script:Cdp = @{ Ws = $null; Id = 0; Pending = $null; Buffer = (New-Object byte[] 1048576); Message = (New-Object System.IO.MemoryStream)
    Events = (New-Object System.Collections.ArrayList); Session = $null; Process = $null; Log = $null; Port = 0 }

function Write-CdpLog([string]$Text) {
    if ($script:Cdp.Log) { Add-Content -LiteralPath $script:Cdp.Log -Value ((Get-Date -Format 'HH:mm:ss') + ' ' + $Text) -Encoding ASCII }
}

function Find-EdgeBrowser {
    foreach ($p in @("${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe", "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe")) {
        if ($p -and (Test-Path -LiteralPath $p)) { return $p }
    }
    return $null
}

function Start-CdpBrowser {
    param([string]$ProfileDir, [int]$Port, [string]$ResolverRules, [string]$LogFile)
    $exe = Find-EdgeBrowser
    if (-not $exe) { throw 'Microsoft Edge (msedge.exe) was not found' }
    $script:Cdp.Log = $LogFile
    $script:Cdp.Port = $Port
    New-Item -ItemType Directory -Force -Path $ProfileDir | Out-Null
    $arguments = @('--headless=new', "--remote-debugging-port=$Port", '--remote-allow-origins=*', "--user-data-dir=$ProfileDir", '--no-first-run',
        '--no-default-browser-check', '--disable-sync', '--disable-background-networking', '--disable-component-update', '--window-size=1366,900', '--lang=en-US')
    if ($ResolverRules) { $arguments += ('--host-resolver-rules=' + $ResolverRules) }
    $arguments += 'about:blank'
    $line = ($arguments | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }) -join ' '
    $script:Cdp.Process = Start-Process -FilePath $exe -ArgumentList $line -PassThru -WindowStyle Hidden
    Write-CdpLog ('msedge started, pid ' + $script:Cdp.Process.Id)
    $deadline = (Get-Date).AddSeconds(60)
    $version = $null
    while (-not $version -and (Get-Date) -lt $deadline) {
        try { $version = Invoke-RestMethod -UseBasicParsing -Uri "http://127.0.0.1:$Port/json/version" -TimeoutSec 5 } catch { Start-Sleep -Milliseconds 500 }
    }
    if (-not $version) { throw 'the DevTools endpoint did not answer' }
    $script:Cdp.Ws = New-Object System.Net.WebSockets.ClientWebSocket
    $script:Cdp.Ws.ConnectAsync([Uri]$version.webSocketDebuggerUrl, [Threading.CancellationToken]::None).Wait()
    $target = Send-Cdp 'Target.createTarget' @{ url = 'about:blank' } -Browser
    $attached = Send-Cdp 'Target.attachToTarget' @{ targetId = $target.targetId; flatten = $true } -Browser
    $script:Cdp.Session = $attached.sessionId
    foreach ($m in @('Page.enable', 'Runtime.enable', 'Network.enable')) { [void](Send-Cdp $m @{}) }
    return [ordered]@{ browser = [string]$version.Browser; protocol = [string]$version.'Protocol-Version'; pid = $script:Cdp.Process.Id }
}

function Stop-CdpBrowser {
    try { if ($script:Cdp.Ws) { $script:Cdp.Ws.Dispose() } } catch { }
    if ($script:Cdp.Process) {
        & taskkill.exe /PID $script:Cdp.Process.Id /T /F 2>$null | Out-Null
        Write-CdpLog ('msedge stopped, pid ' + $script:Cdp.Process.Id)
    }
    $script:Cdp.Ws = $null; $script:Cdp.Process = $null; $script:Cdp.Session = $null; $script:Cdp.Pending = $null
}

function Receive-CdpMessage([datetime]$Deadline) {
    while ($true) {
        if (-not $script:Cdp.Pending) {
            $segment = New-Object 'System.ArraySegment[byte]' -ArgumentList (, $script:Cdp.Buffer)
            $script:Cdp.Pending = $script:Cdp.Ws.ReceiveAsync($segment, [Threading.CancellationToken]::None)
        }
        $ms = [int]($Deadline - (Get-Date)).TotalMilliseconds
        if ($ms -le 0 -or -not $script:Cdp.Pending.Wait($ms)) { return $null }
        $r = $script:Cdp.Pending.Result
        $script:Cdp.Pending = $null
        $script:Cdp.Message.Write($script:Cdp.Buffer, 0, $r.Count)
        if ($r.EndOfMessage) {
            $text = [Text.Encoding]::UTF8.GetString($script:Cdp.Message.ToArray())
            $script:Cdp.Message.SetLength(0)
            return ($text | ConvertFrom-Json)
        }
    }
}

function Send-Cdp {
    param([string]$Method, $Params = @{}, [switch]$Browser, [int]$TimeoutSec = 60)
    $script:Cdp.Id++
    $id = $script:Cdp.Id
    $msg = @{ id = $id; method = $Method; params = $Params }
    if (-not $Browser) { $msg.sessionId = $script:Cdp.Session }
    $bytes = [Text.Encoding]::UTF8.GetBytes(($msg | ConvertTo-Json -Depth 12 -Compress))
    $script:Cdp.Ws.SendAsync((New-Object 'System.ArraySegment[byte]' -ArgumentList (, $bytes)), [System.Net.WebSockets.WebSocketMessageType]::Text, $true, [Threading.CancellationToken]::None).Wait()
    Write-CdpLog ('-> ' + $Method)
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while ((Get-Date) -lt $deadline) {
        $m = Receive-CdpMessage $deadline
        if ($null -eq $m) { break }
        if ($m.PSObject.Properties['id'] -and [int]$m.id -eq $id) {
            if ($m.PSObject.Properties['error']) { throw ('CDP ' + $Method + ': ' + $m.error.message) }
            return $m.result
        }
        [void]$script:Cdp.Events.Add($m)
    }
    throw ('CDP ' + $Method + ' timed out')
}

function Invoke-CdpJs {
    param([string]$Expression, [switch]$Await, [int]$TimeoutSec = 60)
    $r = Send-Cdp 'Runtime.evaluate' @{ expression = $Expression; returnByValue = $true; awaitPromise = [bool]$Await } -TimeoutSec $TimeoutSec
    if ($r.PSObject.Properties['exceptionDetails']) { throw ('page script failed: ' + $r.exceptionDetails.text) }
    return $r.result.value
}

function ConvertTo-JsString([string]$Value) { return ($Value | ConvertTo-Json -Compress) }

function Wait-CdpPage {
    param([string]$UrlPattern, [int]$TimeoutSec = 60)
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    $last = $null
    while ((Get-Date) -lt $deadline) {
        try {
            $last = Invoke-CdpJs 'JSON.stringify({href: location.href, ready: document.readyState})' -TimeoutSec 10 | ConvertFrom-Json
            if ($last.ready -eq 'complete' -and $last.href -match $UrlPattern) { return $last.href }
        } catch { }
        Start-Sleep -Milliseconds 400
    }
    $where = '?'
    if ($last) { $where = $last.href }
    throw ('the page did not reach ' + $UrlPattern + ' (at ' + $where + ')')
}

function Open-CdpPage([string]$Url, [string]$UrlPattern, [int]$TimeoutSec = 60) {
    $nav = Send-Cdp 'Page.navigate' @{ url = $Url }
    if ($nav.PSObject.Properties['errorText'] -and $nav.errorText) { throw ('navigation to ' + $Url + ' failed: ' + $nav.errorText) }
    return Wait-CdpPage $UrlPattern $TimeoutSec
}

function Save-CdpScreenshot([string]$Path) {
    $shot = Send-Cdp 'Page.captureScreenshot' @{ format = 'png'; captureBeyondViewport = $true }
    [IO.File]::WriteAllBytes($Path, [Convert]::FromBase64String($shot.data))
    return (Split-Path -Leaf $Path)
}

function Wait-CdpDownload([string]$Folder, [int]$TimeoutSec = 60) {
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while ((Get-Date) -lt $deadline) {
        $done = @(Get-ChildItem -LiteralPath $Folder -File -ErrorAction SilentlyContinue | Where-Object { $_.Extension -notin @('.crdownload', '.tmp') -and $_.Length -gt 0 })
        if ($done.Count -gt 0) { return $done[0].FullName }
        try { [void](Receive-CdpMessage ((Get-Date).AddMilliseconds(500))) } catch { Start-Sleep -Milliseconds 500 }
    }
    return $null
}
