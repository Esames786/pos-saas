# Can every cashier terminal reach the Branch Server? (LAN reach check)

Goal: from each counter PC / tablet / phone that will use **BINGOO Offline**, `https://bingoo-edge.local:8443/edge/local/login`
opens with a **green padlock**. Three things make that true: name resolution, TCP reachability, certificate trust.

Values from the install worksheet: Branch Server reserved IP `192.168.100.___`, hostname `bingoo-edge.local`, gateway port `8443`,
CA file `khatri-01-ca.crt` (public certificate of the branch CA, exported on the Branch Server; it contains no key).

## 1. Name: hosts line (every device; mDNS is NOT relied on)

Windows (elevated Notepad → `C:\Windows\System32\drivers\etc\hosts`, one line):
```
192.168.100.___  bingoo-edge.local
```
Android / iOS cannot edit hosts without root — those devices open the Branch Server by IP instead:
`https://192.168.100.___:8443/edge/local/login` (the certificate also carries the IP in its SAN, so the padlock stays green once the CA
is trusted).

Check: `ping bingoo-edge.local` resolves to the reserved IP (a ping reply may be blocked by Windows firewall — resolution is what matters).

## 2. Reachability: TCP 8443

Windows PowerShell on the counter:
```powershell
Test-NetConnection bingoo-edge.local -Port 8443        # TcpTestSucceeded : True
```
Phone/tablet: open the URL in the browser; a certificate warning at this stage still proves reachability (fix trust in step 3).

If `False`:
- counter on the main LAN (`192.168.100.x`): check the Branch Server firewall rule "Bingoo Edge gateway" (inbound TCP 8443,80) and that
  the gateway task is running (`Get-ScheduledTask | Where TaskName -like 'Bingoo*'` on the Branch Server);
- counter behind an extender on `192.168.1.x`: the connection goes OUT through that extender's NAT to the main LAN — this works as long as
  the extender is powered and the Branch Server is on the main LAN. If it still fails, the extender has client isolation on, or the
  Branch Server is itself behind a different extender (it must be on the main `.100` LAN).

## 3. Trust: install the CA certificate (every device)

- Windows (per user, no admin): double-click `khatri-01-ca.crt` → Install Certificate → Current User → Place all certificates in the
  following store → **Trusted Root Certification Authorities** → Finish → Yes. Machine-wide instead: elevated, Local Machine.
  Check from PowerShell: `scripts\Test-EdgeCashierTrust.ps1 -Hostname bingoo-edge.local -Port 8443` (from the package `scripts\` folder) → OK.
- Android: Settings → Security → Encryption & credentials → Install a certificate → CA certificate → choose the `.crt`. Chrome then
  trusts it. (Some Android builds show a persistent "network may be monitored" notice — expected for a user-installed CA.)
- iOS/iPadOS: AirDrop or email the `.crt` → Settings → Profile Downloaded → Install → then Settings → General → About → Certificate
  Trust Settings → enable full trust for the Khatri CA.

## 4. Final check per device (write the result in the table)

Open **BINGOO Offline** on the device: green padlock, the Branch Server login page, badge area present. Log in with an enrolled test
user is NOT needed for this check (login happens on the real day).

| device | IP / subnet | hosts line or by-IP | TCP 8443 | CA trusted | padlock green |
|---|---|---|---|---|---|
| Counter 1 | | | | | |
| Counter 2 | | | | | |
| Delivery counter | | | | | |
| Manager phone | | | | | |

Keep this table with the install evidence. A device that fails any column cannot use BINGOO Offline; it still works on BINGOO Online.
