<#
.SYNOPSIS
    EDGE-RUNTIME-BOUNDARY-1 (J/K) — issue the Branch Server TLS certificate from the branch-local CA,
    with a SAN covering BOTH the LAN hostname and the DHCP-reserved IP. FOUNDATION/SKELETON.

.DESCRIPTION
    The pilot contract (docs/audits/edge-runtime-boundary-2026-08.md) is: managed Windows POS
    terminals on a LAN; a Branch Server on a fixed / DHCP-reserved IP; TLS via a branch-local CA +
    server certificate. mDNS ".local" is NOT reliable across every Windows LAN, so the SAN includes
    the reserved IP and the terminals reach the server by hostname (hosts file / router DNS) or IP.

    This script does NOT disable certificate verification and does NOT enable plain HTTP.

.PARAMETER Hostname
    LAN hostname for the Branch Server, e.g. bingoo-edge.local (matches config('edge.lan.hostname')).

.PARAMETER ReservedIp
    The DHCP-reserved LAN IPv4 address of the Branch Server (added to the SAN as an IPAddress).

.PARAMETER CaThumbprint
    Thumbprint of the branch CA created by New-EdgeBranchCA.ps1 (the signer).

.PARAMETER OutDir
    Directory for the exported server public certificate (.crt). The private key stays in the store.

.PARAMETER ValidYears
    Server certificate validity (default 2).

.EXAMPLE
    .\New-EdgeServerCertificate.ps1 -Hostname bingoo-edge.local -ReservedIp 192.168.1.50 -CaThumbprint ABC123... -OutDir C:\bingoo-edge\certs
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [Parameter(Mandatory = $true)][string] $Hostname,
    [Parameter(Mandatory = $true)][string] $ReservedIp,
    [Parameter(Mandatory = $true)][string] $CaThumbprint,
    [Parameter(Mandatory = $true)][string] $OutDir,
    [int] $ValidYears = 2
)

$ErrorActionPreference = 'Stop'

# Validate the reserved IP is a real IPv4 address.
[System.Net.IPAddress] $parsedIp = $null
if (-not [System.Net.IPAddress]::TryParse($ReservedIp, [ref] $parsedIp)) {
    throw "ReservedIp '$ReservedIp' is not a valid IP address."
}

if ($ValidYears -lt 1 -or $ValidYears -gt 10) {
    throw "ValidYears must be between 1 and 10."
}

$ca = Get-Item -Path ("Cert:\LocalMachine\My\" + $CaThumbprint) -ErrorAction SilentlyContinue
if (-not $ca) {
    throw "Branch CA with thumbprint $CaThumbprint not found in Cert:\LocalMachine\My. Run New-EdgeBranchCA.ps1 first."
}

if (-not (Test-Path $OutDir)) {
    New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
}

Write-Host "Issuing Branch Server certificate for $Hostname / $ReservedIp (signed by CA $CaThumbprint)"

if ($PSCmdlet.ShouldProcess("$Hostname / $ReservedIp", "Issue server certificate from branch CA")) {
    # SAN must include BOTH the hostname (DNS) and the reserved IP (IPAddress). New-SelfSignedCertificate
    # renders -DnsName IPv4 values as IPAddress SAN entries in addition to DNS, so we pass both.
    $cert = New-SelfSignedCertificate `
        -Type SSLServerAuthentication `
        -Subject "CN=$Hostname, O=Bingoo POS Edge" `
        -DnsName @($Hostname, $ReservedIp) `
        -Signer $ca `
        -KeyAlgorithm RSA -KeyLength 2048 `
        -HashAlgorithm SHA256 `
        -NotAfter (Get-Date).AddYears($ValidYears) `
        -KeyExportPolicy NonExportable `
        -CertStoreLocation 'Cert:\LocalMachine\My'

    $publicPath = Join-Path $OutDir 'bingoo-edge-server.crt'
    Export-Certificate -Cert $cert -FilePath $publicPath -Type CERT | Out-Null

    Write-Host "Server cert thumbprint: $($cert.Thumbprint)"
    Write-Host "Public server cert    : $publicPath"
    Write-Host "SAN                    : DNS=$Hostname, IP=$ReservedIp"
    Write-Warning "Bind this certificate to the Branch Server web listener (installer step). Do NOT export the private key or disable TLS verification on terminals."
}
