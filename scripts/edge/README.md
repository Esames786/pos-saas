# Bingoo Edge — Branch Server LAN TLS foundation

`EDGE-RUNTIME-BOUNDARY-1 (J/K)` — **foundation/skeleton only.** These scripts establish the
branch-local TLS trust model for the future Windows Branch Server appliance. They are safe,
syntactically verified, and **do not deploy anything**. Full certificate binding + terminal
provisioning is completed by the appliance installer in a later sprint.

## Pilot contract (locked)

- **First offline pilot = managed Windows POS terminals** on a branch LAN.
- **Branch Server** runs on a **fixed / DHCP-reserved LAN IP**.
- **TLS** = a **branch-local CA** + a **Branch Server certificate** issued by that CA. Terminals
  trust the CA public certificate; the server presents its cert. **No plain HTTP as the normal mode;
  no disabling of certificate verification.**

## Hostname / IP contract (K)

`config('edge.lan')` holds the contract: `hostname` (default `bingoo-edge.local`), `reserved_ip`,
and `name_mechanism`.

> **Windows practicality:** mDNS `.local` resolution is **not reliable on every Windows LAN** (it
> depends on the Bonjour/Apple mDNS responder being present, and some routers/AV block it). The
> **pilot mechanism is therefore a DHCP-reserved IP + a `hosts` file entry (or router DNS)** on each
> managed terminal, not mDNS. The server certificate SAN covers **both** the hostname and the
> reserved IP so terminals can connect by either without a certificate warning.

Recommended per-branch values (set in the Branch Server `.env`):

```
EDGE_LAN_HOSTNAME=bingoo-edge.local
EDGE_LAN_IP=192.168.1.50
EDGE_LAN_NAME_MECHANISM=hosts_file   # hosts_file | router_dns | mdns
```

## Scripts

1. **`New-EdgeBranchCA.ps1`** — creates the per-branch root CA. The private key is **non-exportable**
   by default and stays on the Branch Server; only the **public** CA `.crt` is exported for
   distribution to terminals.
   ```powershell
   .\New-EdgeBranchCA.ps1 -BranchName "Gulberg-01" -OutDir C:\bingoo-edge\ca
   ```
2. **`New-EdgeServerCertificate.ps1`** — issues the Branch Server TLS certificate from that CA, with a
   SAN of `DNS=<hostname>` + `IP=<reserved_ip>`.
   ```powershell
   .\New-EdgeServerCertificate.ps1 -Hostname bingoo-edge.local -ReservedIp 192.168.1.50 `
       -CaThumbprint <ca-thumbprint> -OutDir C:\bingoo-edge\certs
   ```

Both support `-WhatIf` (dry run) and validate their inputs (IP format, validity range, CA presence).

## Renewal

- **Server certificate** (default 2 yr): re-run `New-EdgeServerCertificate.ps1` with the same CA
  before expiry and re-bind it to the web listener. Terminals need no change (same CA).
- **CA** (default 5 yr): re-run `New-EdgeBranchCA.ps1`, re-issue the server cert, and **re-distribute
  the new public CA cert** to terminals. Plan CA rotation well before expiry.

## Hostname / IP change procedure

If the branch LAN hostname or reserved IP changes: update `.env` (`EDGE_LAN_*`), re-run
`New-EdgeServerCertificate.ps1` with the new SAN values, re-bind, and update each terminal's `hosts`
entry (or router DNS). The CA is unaffected.

## What remains for later installer work (explicitly NOT in this sprint)

- Binding the issued certificate to the actual Branch Server web listener (nginx/Caddy/IIS choice).
- Automated distribution + trust installation of the CA public cert to terminals.
- CA private-key escrow / machine-bound (DPAPI) recovery key handling (`EDGE-LOCAL-AUTH-1`).
- Certificate renewal automation + expiry monitoring surfaced on the health endpoint.
- The one-click appliance installer itself.

**Never commit generated certificates or private keys.** These scripts write outputs to an
operator-chosen directory outside the repository.
