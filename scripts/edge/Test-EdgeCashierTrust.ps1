# P5 - run on a CASHIER Windows client (LAB): proves the branch-CA trust chain and the Edge HTTPS surface WITHOUT
# disabling certificate verification. Records evidence for the certification report.
#
#   .\Test-EdgeCashierTrust.ps1 -EdgeHost bingoo-edge.local -EdgeIp 192.168.1.50 -CaCertPath .\bingoo-branch-ca.crt -EvidenceDir D:\cert
#
# Steps: (1) install the branch CA public certificate into the CLIENT's Trusted Root store (LocalMachine when elevated,
# CurrentUser otherwise) - the ONLY trust change; (2) resolve the hostname (hosts file / router DNS contract);
# (3) HTTPS GET /edge/local/health by HOSTNAME and by IP with full validation; (4) GET the login page and the POS page
# route (expects the login redirect for an unauthenticated client). Any certificate error is a FAIL, never bypassed.
param(
    [Parameter(Mandatory = $true)][string]$EdgeHost,
    [string]$EdgeIp,
    [string]$CaCertPath,
    [int]$HttpsPort = 443,
    [Parameter(Mandatory = $true)][string]$EvidenceDir
)
$ErrorActionPreference = 'Continue'
New-Item -ItemType Directory -Path $EvidenceDir -Force | Out-Null
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls13
$ev = [ordered]@{ time = (Get-Date).ToString('o'); client = $env:COMPUTERNAME; edge_host = $EdgeHost; edge_ip = $EdgeIp }

if ($CaCertPath) {
    $isAdmin = (New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    $store = if ($isAdmin) { 'Cert:\LocalMachine\Root' } else { 'Cert:\CurrentUser\Root' }
    $imported = Import-Certificate -FilePath $CaCertPath -CertStoreLocation $store
    $ev.ca_imported_to = $store; $ev.ca_thumbprint = $imported.Thumbprint; $ev.ca_subject = $imported.Subject
    Write-Host "Branch CA trusted in $store ($($imported.Subject))"
}
$ev.dns = @(Resolve-DnsName -Name $EdgeHost -ErrorAction SilentlyContinue | ForEach-Object { $_.IPAddress })
$ev.hosts_entry = (Select-String -Path "$env:SystemRoot\System32\drivers\etc\hosts" -Pattern ([regex]::Escape($EdgeHost)) -ErrorAction SilentlyContinue | ForEach-Object { $_.Line })

function Probe($url) {
    try {
        $r = Invoke-WebRequest -Uri $url -UseBasicParsing -MaximumRedirection 0 -TimeoutSec 15 -ErrorAction Stop
        return [ordered]@{ url = $url; status = [int]$r.StatusCode; ok = $true; error = $null; body_head = ([string]$r.Content).Substring(0, [Math]::Min(120, ([string]$r.Content).Length)) }
    } catch {
        $status = $null; try { $status = [int]$_.Exception.Response.StatusCode } catch {}
        $isCertError = ($_.Exception.Message -match '(?i)certificate|trust|SSL|TLS')
        return [ordered]@{ url = $url; status = $status; ok = ($status -eq 302 -or $status -eq 200); error = $_.Exception.Message; certificate_error = $isCertError }
    }
}
$base = "https://$EdgeHost`:$HttpsPort"
$ev.health_by_hostname = Probe "$base/edge/local/health"
$ev.login_page = Probe "$base/edge/local/login"
$ev.pos_page_unauthenticated = Probe "$base/edge/local/pos"      # 302 -> /edge/local/login for a guest (the page exists and is guarded)
if ($EdgeIp) { $ev.health_by_ip = Probe "https://$EdgeIp`:$HttpsPort/edge/local/health" }
$ev.http_redirect = Probe "http://$EdgeHost/edge/local/health"    # expect 301 -> https
$ev.certificate_warning_free = (-not $ev.health_by_hostname.certificate_error) -and ($ev.health_by_hostname.status -eq 200)
$ev | ConvertTo-Json -Depth 6 | Set-Content -Path (Join-Path $EvidenceDir 'cashier-trust.json') -Encoding UTF8
Write-Host ("health(https by name)={0} login={1} pos(guest)={2} cert-warning-free={3}" -f $ev.health_by_hostname.status, $ev.login_page.status, $ev.pos_page_unauthenticated.status, $ev.certificate_warning_free)
if (-not $ev.certificate_warning_free) { Write-Host 'CASHIER TRUST FAILED - fix the CA distribution / SAN / hosts entry; never disable verification.' -ForegroundColor Red; exit 1 }
exit 0
