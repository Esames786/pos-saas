# Bingoo Edge 0.9 — "Setup Wizard + Shift/Order Continuity" brief (21 Nov 2026)

Written for: engineers/agents (any model) who will build Edge 0.9. Self-contained: what Bingoo Edge is, what happened on
21 Nov 2026 at Khatri Biryani, what the owner wants next, the hard rules, the proposed design, the work split, and the gates.
No secrets in this file. Paths are in the `pos-saas` Laravel 12 monorepo (production branch `feat/14d-2-plan-upgrade-requests`,
Edge development branch `feat/edge-config-refresh-v1`).

---

## 1. What Bingoo Edge is (today, 0.8.5-edge)

Bingoo POS is a multi-tenant Laravel SaaS (Cloud, `bingoopos.com`, one DB per tenant). **Edge** is a Windows "Branch Server"
appliance installed in a restaurant: PHP 8.3 runtime + nginx TLS gateway + local MySQL 8, running a restricted copy of the same
Laravel app in `branch_server` mode. It is a **warm standby**: it mirrors configuration from the Cloud every minute and can take
over selling for ONE branch when the internet is down.

Core contract (permanent owner requirements, must not change):
- **Cloud = financial truth.** Edge sales are "ingested" into the Cloud after the outage; the Cloud never resumes writing for the
  branch while the Edge holds authority (branch lease fencing in every Cloud write path).
- **One authority at a time** via a per-branch **lease**: the Cloud renews it on every accepted heartbeat (TTL 120 s). The appliance
  may take over only after `last_ack + TTL + 30 s skew`, and only when all gates pass. **No automatic failover** (`AUTO_FAILOVER_ENABLED=no`).
- **Manual takeover and handback** by an owner/manager: status page `https://bingoo-edge.local:8443/edge/local/pos/health` with two
  buttons **Switch to Offline** / **Return to Online** (0.8.4+; permission `tenant.offline-edge.security`, confirm, CSRF, audit).
  Handback is blocked until the outbox is empty and the Cloud acknowledged.
- **One shared cashier Blade view** for Online and Edge (`resources/views/tenant/pos/...`), runtime differences only through the
  `PosRuntime` route/capability map; no `url()` literals, no runtime branches in the view (static + MySQL gates enforce this).
- Signed packages (Ed25519, key id `a2eaf8a3d3e66a48`), package boundary audit (no .env/.pem/.key/tests inside), CA-trusted HTTPS on
  the LAN (per-branch CA → server cert with DNS+IP SAN), **TLS verification never disabled**, secrets never on argv (files or prompts).
- Edge local login = **employee code + Edge PIN/password** (separate from the Cloud password), enrolled through a Cloud-signed
  15-minute **enrollment assertion** (`POST /settings/offline-edge/enrollment-assertions`, Ed25519 key `EDGE_ENROLLMENT_SIGNING_KEY`
  on the Cloud, public key on the appliance).

Key code (Cloud side, now in production): `routes/edge.php` (Edge API on the CENTRAL domain `https://bingoopos.com/api/edge/*`:
pair, bootstrap, compatibility, authority heartbeat/handback, config refresh, sync ingestion), `app/Services/Edge/*`
(`EdgePairingService`, `EdgeBootstrapService`, `EdgeEnrollmentIssuer`, `EdgeInbound*IngestionService`, `BranchOperatingModeService`
fencing), tenant UI `/settings/offline-edge` (`OfflineEdgeController`), entitlement `OfflineEdgeEntitlementService` (3 gates:
plan module `offline_edge`, `EDGE_FEATURE_ENABLED`, installer artifact — the third is still "Not available yet").
Appliance side: `routes/edge_runtime.php` + `config/edge.php` `route_allowlist`, `app/Http/Controllers/Edge/EdgeLocal*`,
`EdgeAuthorityService` (gates, takeOver, handback), `EdgeHandbackOrchestrator`, `EdgeLocalShiftController`, sync outbox/sender,
local print worker (TCP 9100 to LAN printers), `scripts/edge/*.ps1` (Install-EdgeAppliance, Register-EdgeServices, New-EdgeBranchCA,
New-EdgeServerCertificate, Test-EdgeCashierTrust, Backup/Restore, Uninstall), package builder `EdgePackageBuilder`
(`php artisan edge:build-package`, `edge:audit-package`).

## 2. What happened on 21 Nov 2026 (first production install, Khatri Biryani)

Cloud: production deployed (`dc0babbf`, 11 migrations: 4 master + 7 per tenant, all 13 tenants), Khatri enabled
(`EDGE_FEATURE_ENABLED=true`, enrollment signing key generated into `.env`, `offline_edge` on plan `khatri_restaurant` only).

Shop PC (Windows 10 Pro, 8 GB, also runs the Online Print Agent): clock was 12 h wrong + sleep on → fixed; static IP 192.168.100.52
(router DHCP pool .2–.65, reservation added); MySQL 8.0.30 as service `BingooEdgeMySQL` (loopback only); branch CA + server cert;
package **0.8.5-edge** installed, paired (device `a7d57f56-…`), bootstrap acknowledged, 7 scheduled tasks, firewall 8443/80;
6 users enrolled (owner + 4 counters + manager) with PINs; CA trusted + hosts line + 3 desktop shortcuts on the server PC, the
owner's laptop and one counter; **STANDBY** confirmed (no takeover performed; shop was open).

Defects found on site (all fixed on the Edge branch / kit, commits 29926f71, eacf489f, a3d03ed6, 42303dc5, 07823fdb; docs
`docs/status/khatri-onsite-install-steps-084-2026-11-21.md`, `docs/status/edge-package-portable-php-runtime-2026-11-21.md`):
1. 0.8.4 package shipped Laragon's `php.ini` with laptop-absolute paths → every PHP extension failed on a foreign PC → rebuilt
   0.8.5 from a portable runtime (`extension_dir="ext"`, CA bundle as `cacert.txt`). TODO: builder must refuse such a php.ini.
2. Docs told the installer `-CloudUrl https://<tenant>.bingoopos.com` → `404 api/edge/pair`; the Edge API lives on the CENTRAL domain.
3. Windows 10 Task Scheduler rejects `RepetitionDuration [TimeSpan]::MaxValue` → 3650 days.
4. Notepad saved `pairing.code` as `.txt`; hidden PIN prompt accepted an empty Enter; assertion JSON hand-copied.
5. MySQL: `log_bin=OFF` ENABLES binlog named "OFF" (use `skip-log-bin`); with `skip-name-resolve` root@localhost unreachable over TCP
   (add root@127.0.0.1 via one-time `--init-file`).
6. Blade: `@if` glued to a word rendered literally in the status page Printers row.

**Owner verdict: far too hard.** Every step was a command or a Notepad file run by the owner over RustDesk during service hours,
even though all users already existed in the POS. A one-file counter script (`docs/operator-khatri/Setup-KhatriCounter.ps1`:
embedded CA → Trusted Root, hosts line, 3 shortcuts with the Bingoo icon, TLS-validated check) was the only "one click" part.

## 3. What the owner wants in 0.9 (verbatim intent)

A. **Setup wizard** downloadable from the Cloud's Offline Edge screen (like the Print Agent installer): installs MySQL/runtime, logs
   in to the Cloud and picks the branch (no pairing-code file), asks for every secret in the UI (DB password, cert/PFX password,
   recovery key, Edge secrets — nothing in Notepad), **fetches all tenant users and asks a PIN for each** (owner, staff, whoever),
   and on Finish **auto-creates the Online / Offline / Status links** on the server PC. The same wizard must **install OR update**
   an existing appliance (Khatri on 0.8.5 → 0.9 in place, no re-pairing).
B. **Cashier one-click setup** downloadable from the Offline screen: CA trust, hosts line, shortcuts, icon; phones via QR/IP.
C. **Shift and order continuity**: today Edge starts "from zero" after Switch to Offline — no open shift, no open dine-in table
   orders, no held bills (the bootstrap carries menu/users/customers/tables/printers/settings only). Owner: "why reopen the shift
   when the PIN user is logged in on the same terminal? the open table orders should already be there." Wanted: open shifts, open
   table orders and held sales mirrored continuously into the standby; at takeover the Edge shift opens by itself as a
   continuation of the online shift (same terminal, opening cash = online running cash, linked by shift uuid); tables show their
   open orders as drafts; at handback Edge sales merge into the online shift so the day has ONE shift and ONE report per counter.

## 4. Proposed design (keep the contract, hide it behind UI)

### 4.1 Continuity (stream A) — "mirror → adopt → merge"
- **Mirror**: extend the existing config-refresh channel (`EdgeConfigRefreshClient` / Cloud `EdgeConfigRefreshApiController`) with a
  per-branch **live-state section** polled every 30–60 s: open shifts (per terminal: shift uuid, opening cash, running totals),
  open restaurant table sessions + their unpaid orders (lines, notes, KOT state), held sales. Stored in new edge-local tables
  (`edge_mirror_shifts`, `edge_mirror_open_orders`, `edge_mirror_held_sales`) with `mirrored_at` and the Cloud revision. Read-only
  while in standby; the health page shows "live state mirrored up to HH:MM:SS".
- **Adopt** (inside `EdgeAuthorityService::takeOver`, after the gates pass, same transaction): for each terminal with a mirrored open
  shift, open an Edge shift with `continues_cloud_shift_uuid`, opening cash = mirrored running cash; convert mirrored open orders
  into Edge **drafts** on their tables (clearly flagged "mirrored up to HH:MM:SS — confirm before payment"), held sales into Edge
  held sales. Nothing is a financial fact until rung on Edge. If the mirror is older than N minutes, adopt still works but the UI
  warns (and the existing STANDBY_FRESH_ENOUGH gate may block, as today).
- **Merge** (in `EdgeHandbackOrchestrator` + Cloud ingestion): Edge sales already carry `processing_shift_uuid`; the ingestion maps
  an Edge shift that `continues_cloud_shift_uuid` onto the online shift (sales, payments, cash movements), and the online shift
  stays open and continues. Reports show one shift per counter; the Edge segment is still auditable (flag + time window).
  Dine-in table sessions that were adopted and then paid on Edge close the Cloud session on ingestion; adopted-but-untouched
  drafts are discarded at handback (the Cloud copy remains the truth).
- Cashier view: no new Blade branches; use the existing shared-view capability map (`PosRuntime` keys) for the "mirrored draft"
  badge and the shift-continuation banner on both runtimes (hidden on Cloud).

### 4.2 Server wizard (stream B)
- A Windows app (PowerShell + WPF/WinForms, or a tiny Go/.NET host) **signed and shipped inside the package**, launched by one
  `Setup-BingooEdge.exe/.ps1`: pages = Welcome → Prerequisites check (admin, disk, clock/NTP, sleep, LAN IP + DHCP reservation
  hint) → Cloud sign-in (tenant URL + owner credentials → the Cloud issues the pairing code and the enrollment public key to the
  wizard; no code typing) → Branch pick → Passwords (MySQL root/edge, PFX, optional recovery key; typed once, kept in memory, passed
  by temp files that are deleted — reuse the existing `--password-file` contract) → Install (MySQL service, certs, package verify,
  db-init, pair, bootstrap, services, firewall, hosts) → **Users & PINs** (list fetched from the bootstrap users section; owner
  types a PIN per user or taps "same PIN"; the wizard calls the Cloud bulk-assertion endpoint and runs `edge:local:enroll
  --credential-file` per user) → Finish (shortcuts + icon, health check, QR for phones, "Open status page").
- **Update mode**: if `C:\ProgramData\BingooEdge\config\appliance.env` exists, the wizard verifies the new package and runs the
  existing signed-update path (`edge:local:update` + `edge:local:schema-upgrade`), re-registers services, re-runs health. No
  re-pairing, no data loss; rollback = pointer rollback (already exists).
- Fix the Register-EdgeServices duration for Win10 in the package; portable php.ini enforced by the builder (stream E).

### 4.3 Cloud side (stream C)
- Offline Edge page: "Download Branch Server Setup" (serves the signed package zip; fills entitlement gate 3) and "Download Counter
  Setup for this branch" (generated per branch: CA embedded, hosts line, shortcuts, icon; plus a QR with the IP URL for phones).
- New endpoints for the wizard: start-pairing (returns pairing code + enrollment public key to an authenticated owner session),
  bulk enrollment assertions (`user_ids[]`), install progress (device shows "installing…/bound"), live-state mirror section.
- Admin UX: device row shows version, last heartbeat, pending update, "Update available" when a newer signed package is published.

### 4.4 Cashier bundle (stream D)
- Generalise `docs/operator-khatri/Setup-KhatriCounter.ps1` into a Cloud-generated `Setup-<branch>-Counter.ps1` (+ `.exe` wrapper
  if signing is available): CA → Trusted Root, hosts line, 3 shortcuts (Online, Offline, Status) with the Bingoo icon, TLS check,
  one summary screen. Android/iOS: QR → `https://<ip>:8443/...` + CA download page with per-platform instructions.

### 4.5 Packaging (stream E)
- `EdgePackageBuilder`: sanitise/validate `php.ini` (relative `extension_dir`, no absolute host paths, bundled CA as `cacert.txt`),
  fail the build otherwise; embed Win10-safe task durations; default `-CloudUrl` = central domain; package `README` updated.

## 5. Work split and estimate (parallel agents; one lead integrates)

| stream | scope | est. elapsed |
|---|---|---|
| A Continuity | mirror tables + channel, adopt in takeover, merge in handback/ingestion, shared-view badges, MySQL tests both runtimes | 8–10 days |
| B Server wizard | wizard app, install + update modes, users & PINs page, finish page | 8–10 days |
| C Cloud | downloads on the Offline Edge page, wizard endpoints, bulk assertions, progress, "update available" | 4–5 days |
| D Cashier bundle | per-branch generated setup + QR/phone page | 2 days |
| E Packaging | builder php.ini validation, Win10 task durations, central-domain default | 1–2 days |
| Integration + LAB acceptance + signed 0.9 + update rehearsal on a Khatri copy | sequential after A–E | 4–5 days |

Critical path ≈ A or B + integration → **about 3 weeks** to a signed 0.9 installable as an in-place update at Khatri.

## 6. Non-negotiable rules for every agent

- No change to the authority engine's safety: lease TTL/skew, gates, manual-only takeover/handback, no stale override on the UI,
  fencing on the Cloud. Continuity ADOPTS mirrored state as drafts; it never fabricates financial facts.
- One shared cashier Blade; runtime differences only via `PosRuntime` keys; no `url()`/`asset()` literals; static + MySQL gates
  (`SharedPosViewRenderTest`, `EdgeSharedPosRegressionStaticGateTest`, `EdgeSharedPosRegressionGateMySqlTest`) must stay green.
- Every new Edge route goes on `config/edge.php` `route_allowlist` and the `EdgeBranchServerRegistrationTest` census; every new
  permission into `PosPermissionCatalog`.
- Secrets never on argv or in logs; files read-then-deleted; TLS verification never disabled; packages signed; boundary audit clean.
- Migrations: additive only on the Cloud; appliance migrations under `database/migrations/edge/`; rehearse on prod-schema copies.
- Tests: SQLite Feature/Unit fast suite + the MySQL suite (`phpunit.mysql.xml`) with isolated DB sets whose names contain `edge`
  (Edge-local DB guard); no concurrent heavy MySQL runs; never label a failing suite green.
- No production deploy, no Khatri change, no LAB Local Mode without the owner's explicit GO; the LAB acceptance of the SAME
  signed package precedes any real takeover; supervised drill with the owner present before first real use.
- Rebuild the system map (`php tools/system-map/build.php --tenant-db=… --master-db=…`) after schema/route/controller changes.

## 7. Acceptance for 0.9 (what "done" means)

1. A fresh Windows 10/11 PC: download from the Offline Edge page → wizard → STANDBY_READY with all listed users able to log in on
   Edge, shortcuts present, no command typed by the owner. ≤ 20 min.
2. Khatri (0.8.5): the same wizard updates in place; device id, pairing, users, data unchanged; health green.
3. Counter PC: one click → CA trusted, hosts, shortcuts, green padlock. Phone: QR → login page without warning after CA install.
4. LAB drill: internet cut → after lease lapse Switch to Offline → every terminal's Edge shift is already open as a continuation,
   open table orders appear as drafts, cashier pays them → internet back → Return to Online → Cloud shows ONE shift per counter with
   the Edge sales inside, stock/reports correct, no duplicate receipts/KOT, outbox 0, audit trail complete.
5. All gates in §6 green; signed package; system map rebuilt; docs: operator guide rewritten around the wizard.

## 8. Pointers

- Today's reports: `docs/status/khatri-morning-report-2026-11-21.md` (closing state), `docs/status/khatri-cloud-edge-go-no-go-2026-11-21.md`,
  `docs/status/khatri-onsite-install-steps-084-2026-11-21.md`, `docs/status/edge-package-portable-php-runtime-2026-11-21.md`,
  `docs/status/khatri-offline-edge-enablement-procedure-2026-11-21.md`, operator docs `docs/operator-khatri/*`.
- Program history and permanent requirements: memory notes `offline-edge-program-status`, `edge-operating-model-scope-lock`,
  `feedback-edge-setup-wizard` (owner feedback of 21 Nov).
- System map: `docs/system-map/index.html` (every table/route/screen; list the impact before coding).
