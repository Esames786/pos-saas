# Bingoo Edge — Installer & Product Runbook (EDGE-EDITION-ARCHITECTURE-1)

Status: **design only** — 2026-07. Describes the intended customer-facing product and
operational behaviour of `BingooEdgeSetup.exe`. **Nothing here is built yet.**
`EDGE_FEATURE_ENABLED` remains `false`; production deploy deferred. Architecture rationale
lives in `docs/audits/edge-edition-architecture-2026-07.md`; the ship/exclude allowlist in
`docs/audits/edge-edition-boundary-manifest-2026-07.md`.

---

## 1. What the customer buys and runs

- **Sellable module `offline_edge`** (included in higher plans or a paid add-on, optionally
  licensed per branch server / device).
- **One `BingooEdgeSetup.exe` per branch**, installed on one Windows PC = the *Branch
  Server*. All POS terminals are browsers on the Branch Server's LAN URL.
- The Branch Server runs a **restricted Laravel Edge build + local MariaDB/MySQL**. It
  handles POS/restaurant/shift/printing locally and **syncs sales to the cloud**, which
  remains the only official accounting authority.

Customer experience deliberately matches the Print Agent installer:
**Download → Next → Install → enter pairing code → Ready.**

---

## 2. Customer workflow (owner)

```
1.  Buy / receive offline_edge entitlement.
2.  Settings → Offline Branch Edge.
3.  Select the branch to run offline.
4.  System confirms an available branch/device license.
5.  System shows a 6-digit pairing code (15-min TTL, single-use, rate-limited).
6.  Download BingooEdgeSetup.exe (versioned).
7.  Run it on the branch PC → Next → Install.
8.  Wizard asks for cloud URL (may be prefilled) + the pairing code.
9.  Edge pairs to your tenant + selected branch.
10. Bootstrap downloads your catalog, prices, tax, users, tables, printers.
11. Readiness checks: database, web service, sync worker, printing.
12. Wizard shows the LAN URL (e.g. http://bingoo-edge.local:8787 or http://<ip>:8787).
13. Open that URL from another terminal's browser.
14. Run a receipt test print and a KOT test print.
15. Click "Activate Local POS" — branch goes pending → active ONLY if checks pass.
16. From now, that branch's sales run locally; the cloud sale screen for it is locked.
```

**If anything fails** (install error, bootstrap incomplete, printing not verified): the
branch stays **pending**, cloud sales for it keep working, and nothing is locked. Retry or
uninstall safely.

---

## 3. Installer internals (engineering reference)

### Components packaged / managed
```
restricted Laravel Edge artifact · PHP runtime · local web server ·
MariaDB/MySQL · queue/sync worker · task scheduler · Print Agent ·
Windows services + auto-start · firewall/LAN rules · stable local URL ·
backup service · update service · health/status page · logs · uninstaller/repair
```

### Filesystem & services
```
Program files      C:\Program Files\Bingoo Edge\
App + runtime      C:\Program Files\Bingoo Edge\{app, php, mysql, agent}
Mutable data       C:\ProgramData\BingooEdge\
  config           C:\ProgramData\BingooEdge\config\   (edge.env, device token — ACL-locked)
  logs             C:\ProgramData\BingooEdge\logs\
  db data          C:\ProgramData\BingooEdge\mysql-data\
  backups          C:\ProgramData\BingooEdge\backups\
Services (auto-start):
  BingooEdgeWeb        local web server (Edge app)
  BingooEdgeMySQL      local database
  BingooEdgeWorker     queue + sync worker
  BingooEdgeScheduler  scheduler (heartbeat, backup, lease renew)
  BingooPrintAgent     existing print agent, pointed at local Edge URL
Run as a restricted local service account (not the logged-in user, not admin at runtime).
```

### Ports, firewall, LAN URL
```
Web port    default 8787 — probe on install; if busy, pick next free and record it.
DB port     local-only 3307 — bound to 127.0.0.1, never exposed to the LAN.
Firewall    inbound allow on the web port for the local subnet ONLY.
LAN URL     prefer a stable hostname (mDNS "bingoo-edge.local" or machine hostname);
            always also display the raw http://<ip>:<port> as a fallback and print it
            on the readiness screen.
```

### First-run wizard sequence
```
cloud URL + pairing code  →  POST pair (entitlement re-checked, device token minted)  →
bootstrap snapshot  →  DB/web/worker/print readiness  →  show LAN URL + test prints  →
enable "Activate Local POS".
```

### Repair / update / uninstall / rollback
```
Repair    re-verify + restart services, re-run pending migrations, re-test printing.
Update    signed package; version-compatibility check vs cloud sync contract; DB backup
          BEFORE migrate; forward migrate; rollback to previous version on failure.
Uninstall optional final backup + optional cloud upload; remove services; keep data unless
          the user explicitly chooses "remove all data".
Rollback  a failed install/update restores the previous version + DB backup and leaves the
          branch pending (never active on a broken box).
```

### Secrets rule
The installer image is **identical for every customer**. It carries **no tenant secret and
no permanent device token**. The device token is minted **at pairing**, device-scoped and
revocable, and stored ACL-locked under the service account. **No cloud DB credentials ever
live on the box.**

---

## 4. Print Agent reuse

Reuse the existing Windows Print Agent unchanged; only re-point its server URL to the local
Edge URL during the wizard.
```
Cloud mode : Agent → cloud   print_jobs API
Edge  mode : Agent → local   Edge print_jobs API (LAN)
Preserved  : TCP-9100, receipt routing, KOT routing, printed/failed acks, auto-start,
             logs/status. Existing cloud-agent installs are untouched.
```
**Not yet certified:** offline LAN printing end-to-end. `LOCAL-PRINT-LAN-1` will certify
internet-disconnected receipt + KOT printing, multiple printers/routes, reboot recovery,
and failed-printer retry.

---

## 5. Entitlement & offline license (operational view)

```
- offline_edge gates: setup page, download, pairing-code generation, pairing, bootstrap,
  heartbeat, sync APIs, "Activate Local POS", and licensed branch/device count.
- Download gating is NOT sufficient — pairing + every server API re-check entitlement.
- A cloud-signed, branch-scoped LEASE is issued at pairing and renewed on heartbeat.
- The Edge box verifies the lease locally and keeps selling OFFLINE while it is valid.
- Brief internet loss NEVER stops selling. Before lease expiry the owner is warned; on
  expiry with no renewal the box degrades through a grace window, then read-only.
- Revocation is enforced on next reconnect (or at controlled lease expiry).
Grace-window length is a policy decision for OFFLINE-EDGE-ENTITLEMENT-1 (recommended start:
7–14 days). Do not hard-code it yet.
```

---

## 6. Backup & disaster recovery

```
Backup   hourly local DB backup + encrypted copy; optional cloud upload when online;
         restore wizard in the app.
Health   status page + downloadable diagnostics bundle (logs, versions, sync-queue depth,
         last heartbeat, printer status).
Box dies:
  1. Fall back to paper at the counter.
  2. Reinstall Edge on a replacement PC.
  3. Re-pair the SAME branch via controlled recovery.
  4. Restore the latest backup.
  5. Reconcile the pending invoice sequence (invoice_range_state) before resuming.
  6. Unsynced sales replay idempotently by client_uuid — no double-post.
```

---

## 7. Go-live gates (must all pass before EDGE_FEATURE_ENABLED / prod)

```
[ ] production-like MULTI-WORKER idempotency concurrency certification
[ ] entitlement + branch/device limits enforced on every gated step
[ ] pairing + bootstrap E2E on a clean Windows machine
[ ] sync replay E2E (cloud finalizePaidSale, one posting, tb=0)
[ ] cloud sale-lock E2E (active/closing/suspended)
[ ] local receipt + KOT with internet PHYSICALLY disconnected
[ ] installer on a clean Windows machine (no dev tools)
[ ] reboot / service auto-recovery
[ ] backup + restore drill
[ ] mode enter/exit reconciliation (shift/cash/invoice sequence)
[ ] all-tenant financial smoke (tb=0 / neg=0 / dept=0)
```

QA uses a dedicated QA tenant or a restorable snapshot — not the live demo tenant. Demo
sale **#78** is a retained idempotency QA artifact; do not delete its records manually.
