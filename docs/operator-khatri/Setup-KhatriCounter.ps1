# Bingoo Edge - Khatri Biryani: ONE-FILE counter setup (run on every cashier PC, and on the owner's laptop).
# What it does (idempotent, safe to re-run):
#   1. trusts the Khatri branch CA (public certificate embedded below) in the machine's Trusted Root store
#   2. adds the hosts line  192.168.100.52  bingoo-edge.local
#   3. creates three desktop shortcuts for ALL users (Public Desktop): BINGOO Online / BINGOO Offline / Branch Server Status
#   4. checks https://bingoo-edge.local:8443 by name and by IP with FULL certificate validation (never bypassed)
# It relaunches itself elevated (UAC prompt -> Yes). Nothing else is changed; no password, no secret inside.
# Usage: right-click -> Run with PowerShell   (or:  powershell -ExecutionPolicy Bypass -File .\Setup-KhatriCounter.ps1)

$ErrorActionPreference = 'Stop'
$EdgeIp   = '192.168.100.52'
$EdgeHost = 'bingoo-edge.local'
$Port     = 8443
$CaThumb  = '125DFE0A8EF21BDB6CA844EF987856E29E0D5EE4'
$CaPem = @'
-----BEGIN CERTIFICATE-----
MIIFWzCCA0OgAwIBAgIQbu/MmHP7vIVH2YwMH0sCETANBgkqhkiG9w0BAQsFADBG
MRgwFgYDVQQKDA9CaW5nb28gUE9TIEVkZ2UxKjAoBgNVBAMMIUJpbmdvbyBFZGdl
IEJyYW5jaCBDQSAoS2hhdHJpLTAxKTAeFw0yNjExMjEwNjQzMDNaFw0zMTExMjEw
NjUzMDJaMEYxGDAWBgNVBAoMD0JpbmdvbyBQT1MgRWRnZTEqMCgGA1UEAwwhQmlu
Z29vIEVkZ2UgQnJhbmNoIENBIChLaGF0cmktMDEpMIICIjANBgkqhkiG9w0BAQEF
AAOCAg8AMIICCgKCAgEAwSJl+pcaoJmwPcYoKz9PuTg2wh4JcB1/uJL79GO9r7jM
4KUJVewgVvgv7UQj30iUZJJF0qzmFYPioYtD76jeJ02/bClBI4HmuJ1qAIvMhGik
yq65tK/rCXu4exZf6uaV+2tmwh9KuuwUhjcHpiX70iR53AQ0fpMig4NymqS2JQU+
ySRanKEoJkw01xy7Kl+xmCJqfCfEDA+GI9lzDCiAi0PVYQLIV7TuOEHszZfYY95f
dG3UxoV9DF/vSFi3mA4tdEoPqOFC3Yq27kyXFowm5TEIECm/pyfsrmZMn04Zv0yD
DJzXyKmL9KYFqZspHva6qXi0NJ4bmTe//Zbe+v3HZ9Eaxu6AmMu4XEqUImBQLi4A
Q+hMS/iGxDUOW4+8popHk961l19fMy7Bwt67ADgIngGuETSYrcZqM7txbR8C0Z2C
1LglXWtabguehP0mF1SDpoAY1TWBdDsFdWpAQN86+zb+kJwIxp1z1eS948kzN7im
O/WsJcHfvTwdpu0WdB26yljGrg+Y0ckE3z1fHZHjdYV0i0G2RjhC39KbJQVCJd/Y
3pf/1vFHo7oxpUan+ll5ndJ0Tm9UdSoA5BocWZmn6E9I99EIe9SGxm8VJApI4vaf
SganjvQ2ZaskK1quDjfhqK7CLsvWqP8Q6XXklMC9AIC4U31OBUxDAbEEgoynUXUC
AwEAAaNFMEMwDgYDVR0PAQH/BAQDAgEGMBIGA1UdEwEB/wQIMAYBAf8CAQAwHQYD
VR0OBBYEFOoyh3yu3KqRZxGqp4rAGAG+yDbIMA0GCSqGSIb3DQEBCwUAA4ICAQBq
IOv2O6O87dokqFpMujjVYK2junafuns11VdUiWUv8bXPxUqJqQpXOaNHApr/SIU6
2JMVoViNjeFbctNKSeCLMGlH1iNVK550OHNCUXVeceSWPVpE1sUBXSzfEEvnbDns
T9gculoi8BhbrwPeO/TWywF9cEGg6cosCEwtTXrfdFCzEsmwO6SF4ED6Unu4L6oc
Hhzi4ZHzcTVphVIr2T0PnrCUEICrbDji41bGXUM/122DTcW0Sw605lSAYzgk6LAt
qPAAY1YYEAjhv63V4tDhfh9Gm+Q/BveCJQwxzUaRHz6rmkfPFGoxWj1yX8lP7gKO
CFVCsO94JhnuPuTWfG8CF/SIePEqcteRL/OwdOoLkWRv0rEa+PdP/lBzH+Va9tWx
h3zvjpb8XEM94FLQNHsyuC6w+lCRyIxApYEIpZt4JdtQgJz42+KDEVVLARPPjXUY
CdIUxPbLUGk3JlCEotH4LBr9V28VM2A1Hxc7LOmEXviS83ju2pGlIvyTvSwVMmTV
8WvxsBDZT9wRLofOf4/38ezpeHX79+eWXyNoH+7vLytAC9CPkSvS5Op83G0o95SR
VsOCRQH71miWXhy8UKq4MaKZayGNZiJI3ZVQjUiY+nYIOUknIg4dYTqEmjfx/lav
NryJ4883lMzeNeP6xi4P/dwDqVz//pKiI4qEq6l2OA==
-----END CERTIFICATE-----
'@

# ---- self-elevate -------------------------------------------------------------------------------------------
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host 'Relaunching as administrator (click Yes on the prompt)...' -ForegroundColor Yellow
    Start-Process powershell -Verb RunAs -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -NoExit -File "' + $PSCommandPath + '"')
    exit
}

$result = [ordered]@{}

# ---- 1. CA -> Trusted Root (LocalMachine) ----------------------------------------------------------------------
$caFile = Join-Path $env:TEMP 'bingoo-edge-branch-ca-khatri-01.crt'
Set-Content -Path $caFile -Value $CaPem -Encoding ASCII
$have = Get-ChildItem Cert:\LocalMachine\Root | Where-Object Thumbprint -eq $CaThumb
if (-not $have) { Import-Certificate -FilePath $caFile -CertStoreLocation Cert:\LocalMachine\Root | Out-Null; $result.ca = 'installed' } else { $result.ca = 'already trusted' }
Remove-Item $caFile -ErrorAction SilentlyContinue

# ---- 2. hosts line --------------------------------------------------------------------------------------------
$hosts = "$env:SystemRoot\System32\drivers\etc\hosts"
$lines = @(Get-Content $hosts -ErrorAction SilentlyContinue)
$keep  = $lines | Where-Object { $_ -notmatch "\s$([regex]::Escape($EdgeHost))\s*$" }
$keep += "$EdgeIp  $EdgeHost"
if (($keep -join "`n") -ne ($lines -join "`n")) { Set-Content -Path $hosts -Value $keep -Encoding ASCII; $result.hosts = 'written' } else { $result.hosts = 'already present' }
ipconfig /flushdns | Out-Null

# ---- 3. shortcuts for all users ---------------------------------------------------------------------------------
$desktop = Join-Path $env:PUBLIC 'Desktop'
$links = [ordered]@{
    'BINGOO Online'        = 'https://khatribiryani.bingoopos.com/pos'
    'BINGOO Offline'       = "https://$EdgeHost`:$Port/edge/local/login"
    'Branch Server Status' = "https://$EdgeHost`:$Port/edge/local/pos/health"
}
foreach ($name in $links.Keys) {
    $p = Join-Path $desktop ($name + '.url')
    Set-Content -Path $p -Value @("[InternetShortcut]", "URL=$($links[$name])", "IconIndex=0") -Encoding ASCII
}
$result.shortcuts = "3 on $desktop"

# ---- 4. verify with full TLS validation ------------------------------------------------------------------------
foreach ($u in @("https://$EdgeHost`:$Port/edge/local/health", "https://$EdgeIp`:$Port/edge/local/health")) {
    try { $r = Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 15; $result[$u] = "OK $($r.StatusCode)" }
    catch { $result[$u] = "FAILED: $($_.Exception.Message)" }
}

Write-Host ''
Write-Host '==== Khatri counter setup ====' -ForegroundColor Cyan
$result.GetEnumerator() | ForEach-Object { '{0,-52} {1}' -f $_.Key, $_.Value }
if (($result.Values | Where-Object { $_ -like 'FAILED*' })) {
    Write-Host 'Something failed: check that this PC is on the shop LAN (192.168.100.x) and the Branch Server is on.' -ForegroundColor Red
} else {
    Write-Host 'All good. Close and reopen Chrome, then use the three desktop shortcuts.' -ForegroundColor Green
}
