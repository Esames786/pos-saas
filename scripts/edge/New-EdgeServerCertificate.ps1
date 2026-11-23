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

.PARAMETER ValidDays
    Server certificate validity in days (default 730 = 2 years, maximum 825). Apple iOS / macOS refuse a TLS server
    certificate whose validity period (NotBefore..NotAfter) exceeds 825 days EVEN when its CA is trusted, so phones on
    the branch LAN would see a certificate error. NotBefore is set one day in the past (tolerates a terminal clock
    that runs slow) and NotAfter = NotBefore + ValidDays, so the period is exactly ValidDays.

.PARAMETER ValidYears
    DEPRECATED alias of -ValidDays (1 = 365 days, 2 = 730 days); refused above 2. Do not combine with -ValidDays.

.EXAMPLE
    .\New-EdgeServerCertificate.ps1 -Hostname bingoo-edge.local -ReservedIp 192.168.1.50 -CaThumbprint ABC123... -OutDir C:\bingoo-edge\certs
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [Parameter(Mandatory = $true)][string] $Hostname,
    [Parameter(Mandatory = $true)][string] $ReservedIp,
    [Parameter(Mandatory = $true)][string] $CaThumbprint,
    [Parameter(Mandatory = $true)][string] $OutDir,
    [int] $ValidDays = 730,
    [int] $ValidYears,
    # P4 - the TLS gateway (nginx) needs the certificate + private key as PEM files. -ExportPfx issues the key
    # EXPORTABLE and writes an encrypted PFX to OutDir (password read from -PfxPasswordFile, never argv); the
    # appliance then runs `edge:local:gateway-cert <pfx> --password-file=...` which converts it to PEM under
    # <DataRoot>\certs with a restricted ACL. Without -ExportPfx the key stays non-exportable in the store.
    [switch] $ExportPfx,
    [string] $PfxPasswordFile
)

$ErrorActionPreference = 'Stop'

# Validate the reserved IP is a real IPv4 address.
[System.Net.IPAddress] $parsedIp = $null
if (-not [System.Net.IPAddress]::TryParse($ReservedIp, [ref] $parsedIp)) {
    throw "ReservedIp '$ReservedIp' is not a valid IP address."
}

# Edge 0.9: Apple platforms reject server certificates valid longer than 825 days, even from a trusted CA.
$MaxValidDays = 825
if ($PSBoundParameters.ContainsKey('ValidYears')) {
    if ($PSBoundParameters.ContainsKey('ValidDays')) {
        throw 'Use -ValidDays only (-ValidYears is a deprecated alias and cannot be combined with it).'
    }
    if ($ValidYears -lt 1 -or $ValidYears -gt 2) {
        throw "ValidYears (deprecated) must be 1 or 2: iOS/macOS refuse server certificates valid longer than $MaxValidDays days. Use -ValidDays (max $MaxValidDays)."
    }
    Write-Warning "-ValidYears is deprecated; use -ValidDays (default 730, max $MaxValidDays)."
    $ValidDays = 365 * $ValidYears
}
if ($ValidDays -lt 1 -or $ValidDays -gt $MaxValidDays) {
    throw "ValidDays must be between 1 and $MaxValidDays (iOS/macOS refuse server certificates valid longer than $MaxValidDays days)."
}
$notBefore = (Get-Date).AddDays(-1)
$notAfter = $notBefore.AddDays($ValidDays)

$ca = Get-Item -Path ("Cert:\LocalMachine\My\" + $CaThumbprint) -ErrorAction SilentlyContinue
if (-not $ca) {
    throw "Branch CA with thumbprint $CaThumbprint not found in Cert:\LocalMachine\My. Run New-EdgeBranchCA.ps1 first."
}

if (-not (Test-Path $OutDir)) {
    New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
}

Write-Host "Issuing Branch Server certificate for $Hostname / $ReservedIp (signed by CA $CaThumbprint)"

if ($PSCmdlet.ShouldProcess("$Hostname / $ReservedIp", "Issue server certificate from branch CA")) {
    # SAN must include BOTH the hostname as a DNS entry AND the reserved IP as a true IPAddress entry.
    # VERIFIED semantics: `-DnsName @($Hostname,$ReservedIp)` renders the IP as another *DNS* SAN
    # (NOT an IPAddress SAN), which fails validation when a client connects by IP. The correct
    # mechanism is an explicit SAN TextExtension (OID 2.5.29.17) with DNS= and IPAddress=.
    $cert = New-SelfSignedCertificate `
        -Type SSLServerAuthentication `
        -Subject "CN=$Hostname, O=Bingoo POS Edge" `
        -TextExtension @("2.5.29.17={text}DNS=$Hostname&IPAddress=$ReservedIp") `
        -Signer $ca `
        -KeyAlgorithm RSA -KeyLength 2048 `
        -HashAlgorithm SHA256 `
        -NotBefore $notBefore `
        -NotAfter $notAfter `
        -KeyExportPolicy $(if ($ExportPfx) { 'Exportable' } else { 'NonExportable' }) `
        -CertStoreLocation 'Cert:\LocalMachine\My'

    # Belt and braces: never leave a certificate in the store that Apple clients would refuse.
    $periodDays = ($cert.NotAfter - $cert.NotBefore).TotalDays
    if ($periodDays -gt $MaxValidDays) {
        Remove-Item -Path ("Cert:\LocalMachine\My\" + $cert.Thumbprint) -Force -ErrorAction SilentlyContinue
        throw "Issued certificate validity is $([math]::Round($periodDays, 2)) days (> $MaxValidDays); removed from the store."
    }

    $publicPath = Join-Path $OutDir 'bingoo-edge-server.crt'
    Export-Certificate -Cert $cert -FilePath $publicPath -Type CERT | Out-Null

    if ($ExportPfx) {
        if (-not $PfxPasswordFile -or -not (Test-Path $PfxPasswordFile)) {
            throw 'ExportPfx requires -PfxPasswordFile (a file holding the PFX password; it is deleted after use).'
        }
        $pfxRaw = Get-Content -Path $PfxPasswordFile -Raw
        if ($null -eq $pfxRaw -or ([string]$pfxRaw).Trim() -eq '') { throw 'The PFX password file is empty.' }
        $pfxPassword = ConvertTo-SecureString -String (([string]$pfxRaw).Trim()) -AsPlainText -Force
        Remove-Item -Path $PfxPasswordFile -Force -ErrorAction SilentlyContinue
        $pfxPath = Join-Path $OutDir 'bingoo-edge-server.pfx'
        Export-PfxCertificate -Cert $cert -FilePath $pfxPath -Password $pfxPassword -ChainOption BuildChain | Out-Null
        Write-Host "PFX (for edge:local:gateway-cert): $pfxPath - delete it after the gateway import."
    }
    Write-Host "Server cert thumbprint: $($cert.Thumbprint)"
    Write-Host "Public server cert    : $publicPath"
    Write-Host "SAN                    : DNS=$Hostname, IP=$ReservedIp"
    Write-Host "Valid                  : $($cert.NotBefore.ToString('yyyy-MM-dd')) .. $($cert.NotAfter.ToString('yyyy-MM-dd')) ($([math]::Round($periodDays)) days, max $MaxValidDays)"
    Write-Warning "Bind this certificate to the Branch Server web listener (installer step). Do NOT export the private key or disable TLS verification on terminals."
}
