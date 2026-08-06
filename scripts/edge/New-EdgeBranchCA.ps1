<#
.SYNOPSIS
    EDGE-RUNTIME-BOUNDARY-1 (J) — create a per-branch local Certificate Authority for a Bingoo Edge
    Branch Server. FOUNDATION/SKELETON: safe, syntactically-verified automation intended to be driven
    by the future appliance installer. It does NOT deploy anything and does NOT weaken TLS.

.DESCRIPTION
    Generates a self-signed branch-local root CA whose PUBLIC certificate is distributed to the
    managed POS terminals so they trust the Branch Server certificate (issued by New-EdgeServerCertificate).

    Security rules enforced here:
      * The CA PRIVATE key is created non-exportable by default (it stays on the branch server).
      * ONLY the CA PUBLIC certificate (.crt) is exported for distribution — never the private key.
      * Nothing is committed to source control; outputs go to an operator-chosen directory.

.PARAMETER BranchName
    Human label for the branch (used in the CA subject only).

.PARAMETER OutDir
    Directory to write the exported public CA certificate to. Created if missing.

.PARAMETER ValidYears
    CA validity in years (default 5).

.PARAMETER Exportable
    Switch: allow the CA private key to be exportable (for escrow). OFF by default — prefer a
    non-exportable key with a documented recovery procedure (see scripts/edge/README.md).

.EXAMPLE
    .\New-EdgeBranchCA.ps1 -BranchName "Gulberg-01" -OutDir C:\bingoo-edge\ca
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [Parameter(Mandatory = $true)][string] $BranchName,
    [Parameter(Mandatory = $true)][string] $OutDir,
    [int] $ValidYears = 5,
    [switch] $Exportable
)

$ErrorActionPreference = 'Stop'

if ($ValidYears -lt 1 -or $ValidYears -gt 20) {
    throw "ValidYears must be between 1 and 20."
}

if (-not (Test-Path $OutDir)) {
    New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
}

$subject = "CN=Bingoo Edge Branch CA ($BranchName), O=Bingoo POS Edge"
$keyExportPolicy = if ($Exportable) { 'Exportable' } else { 'NonExportable' }

Write-Host "Creating branch-local CA: $subject (valid $ValidYears years, key=$keyExportPolicy)"

if ($PSCmdlet.ShouldProcess($subject, "Create self-signed branch CA")) {
    $ca = New-SelfSignedCertificate `
        -Type Custom `
        -Subject $subject `
        -KeyUsage CertSign, CRLSign `
        -KeyUsageProperty All `
        -KeyExportPolicy $keyExportPolicy `
        -KeyAlgorithm RSA -KeyLength 4096 `
        -HashAlgorithm SHA256 `
        -NotAfter (Get-Date).AddYears($ValidYears) `
        -CertStoreLocation 'Cert:\LocalMachine\My' `
        -TextExtension @('2.5.29.19={text}CA=true&pathlength=0')

    # Export ONLY the public certificate for distribution to POS terminals.
    $publicPath = Join-Path $OutDir 'bingoo-edge-branch-ca.crt'
    Export-Certificate -Cert $ca -FilePath $publicPath -Type CERT | Out-Null

    Write-Host "CA thumbprint : $($ca.Thumbprint)"
    Write-Host "Public CA cert: $publicPath  (distribute THIS to POS terminals)"
    Write-Host "Private key   : remains in Cert:\LocalMachine\My (key policy: $keyExportPolicy)"
    Write-Warning "Never distribute or commit the CA private key. See scripts/edge/README.md for renewal/recovery."
}
