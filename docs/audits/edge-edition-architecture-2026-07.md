# Bingoo Edge Edition — Architecture & Product Decision (EDGE-EDITION-ARCHITECTURE-1)

Status: **design only** — 2026-07. No code, no installer, no entitlement, no sync
engine, no pairing/bootstrap built in this sprint. `EDGE_FEATURE_ENABLED` remains
`false`. Production deploy remains deferred. The complete SaaS source is **never**
shipped to a customer.

Companion documents:
- `docs/audits/edge-edition-boundary-manifest-2026-07.md` — file/module include/exclude allowlist.
- `docs/ops/EDGE_INSTALLER_PRODUCT_RUNBOOK.md` — customer install/pair/update/backup runbook.
- `docs/audits/offline-pos-architecture-2026-07.md` — the Branch-Edge decision this builds on.
- `docs/audits/sale-idempotency-design-2026-07.md` — the sync-replay foundation (+HARDEN-1).

---

## 1. Executive product decision

**Bingoo Offline / Branch Edge is a sellable module (`offline_edge`)**, delivered as a
self-service one-click Windows installer (`BingooEdgeSetup.exe`), matching the customer
experience of the existing Print Agent installer (*Download → Next → Install → enter
pairing code → Ready*).

Runtime shipped to the customer = a **restricted Laravel "Edge" artifact + local
MariaDB/MySQL**, running as **one Branch Server per branch**. All POS terminals are
plain browsers pointed at the Branch Server's LAN URL — no per-device app.

The cloud remains the **only official accounting authority**: official stock ledger,
FEFO, COGS and journals are posted **only** in the cloud, by replaying the Edge's
canonical sale payload (keyed by `client_uuid`) through the existing
`SalesService::finalizePaidSale` pipeline. Edge never produces official GL/COGS
records; its local records are operational, not authoritative.

Distribution model options (both documented; self-service is the launch model):
- **Customer-owned Windows server** — self-service `BingooEdgeSetup.exe`. Launch model.
- **Managed locked appliance** — premium, stronger source protection. Future option.

---

## 2. Concurrency wording correction (carried from SALE-IDEMPOTENCY-HARDEN-1)

The HARDEN-1 concurrency evidence must be described honestly. What was actually run:

> **Parallel-client replay test:** 12 callers, same `client_uuid`, same sale, one
> posting, no 500 — 12 barrier-synchronised `curl POST /pos` against a single
> `php artisan serve` process.

`php artisan serve` is a **single PHP process** (one request served at a time), so this
proves the *client contract* (12 concurrent callers all converge on one sale, all get a
non-500 answer, exactly one `sales_orders` row) but does **not** prove *simultaneous
multi-worker transaction overlap* on the DB unique index. That stronger guarantee still
rests on the DB unique constraint + the `UniqueConstraintViolationException` handler,
which is correct by construction but not yet exercised under true parallelism.

**Production gate added:** before `EDGE_FEATURE_ENABLED` / production deploy, run a
**production-like multi-worker concurrency certification** (Apache/Nginx + PHP-FPM or
`php artisan octane` / multiple `serve` workers behind a load balancer, or an equivalent
verified multi-worker harness) firing overlapping identical-`client_uuid` requests, and
confirm: one posted sale, one row, zero 500s, losers → replay or retryable 503 PENDING.
The idempotency implementation is **not** modified in this design sprint.

---

## 3. Cloud ↔ Edge authority split (the hard rule)

```
Edge (branch server)                    Cloud (bingoopos.com)
--------------------                    ---------------------
owns local OPERATIONAL state            owns OFFICIAL accounting
POS UI + catalog/price snapshot         official stock ledger + FEFO
cashier PIN/session, terminals          COGS + journals (GL)
floors/tables/sessions, held orders     customer receivables / supplier payables
quick/takeaway/cash-delivery orders     purchases / GRN / stock adjustments+transfers
cashier shifts, cash payments           manufacturing
receipt/KOT jobs (local)                full finance UI + company reporting
local sale queue + sync state           SaaS billing / plans / provisioning
device health + local backup            cross-tenant administration
client_uuid assignment                  finalizePaidSale (the ONE posting path)
```

**Hard rule:** Edge sends a canonical sale payload + `client_uuid`; cloud replays it
through the existing official `finalizePaidSale`. Edge's local stock/accounting numbers
are **never copied as official cloud records** — the cloud recomputes them from the
payload. This is why `finalizePaidSale` (inventory `postOutFefo` + `recipeConsumption`
COGS + `postSalesLedger` journals — confirmed by inspection) must remain **cloud-only**.

---

## 4. Edge dependency boundary (inspection summary)

Inspected: `POSController`, `SalesOrderController`, `HeldSaleController`,
`RestaurantTableSessionController`, `ShiftController`, `PrintJobController`, the 115
`app/Models/Tenant/*` models, and the `pos` / `restaurant` / `printing` view trees.

Classification legend: `EDGE_REQUIRED` (ship), `SHARED_CANDIDATE` (ship, shared logic),
`CLOUD_ONLY` (never ship), `REMOVE_FROM_EDGE` (physically exclude),
`NEEDS_EDGE_REPLACEMENT` (replace with an Edge-specific version).

### EDGE_REQUIRED — controllers/UI
`POSController`, `SalesOrderController` (capture path only — see §5),
`HeldSaleController`, `RestaurantFloor/Table/TableSession/Waiter` controllers,
`ShiftController`, `TerminalController`, `ComboController`, `ModifierGroupController`,
`PaymentMethodController` (cash-family only), `DeliveryChannel/RiderController`
(own/manual delivery only), `CustomerController` (local cache, quick-store only),
`Printer*/PrintJob/PrintDocument/PrintAgent/CategoryPrinterMapping` controllers.
Views: `tenant/pos/**`, `tenant/restaurant/**`, `tenant/printing/**`.

### SHARED_CANDIDATE — services (ship, keep identical to avoid drift)
`SalesTotalsService` (customer-facing totals; depends only on `PromotionService` —
**not** finance-coupled), `UnitConversionService`, `PrintJobService`,
`PrintRoutingService`, `EscPosPayloadService`, `BranchOperatingModeService`,
`SaleIdempotencyService`. **`PromotionService` caveat:** it enforces a *global*
`used_count < usage_limit` counter — that counter is cloud-authoritative, so
usage-limited promotions are online-only offline (see §6).

### EDGE_REQUIRED — models (operational subset, ~30 of 115)
`Branch, Terminal, User, ManagerPin, Category, CategoryTranslation, Product,
ProductVariant, ProductBarcode, ProductBranchPrice, ProductTranslation, Combo,
ComboComponent, Modifier, ModifierGroup, Unit, UnitConversion, PaymentMethod,
Customer, DeliveryChannel, DeliveryRider, RestaurantFloor, RestaurantTable,
RestaurantTableSession, RestaurantWaiter, SalesOrder, SalesOrderLine, SalePayment,
HeldSale(via SalesOrder), Shift, CashCountLine, Printer, PrintJob, PrintAgent,
CategoryPrinterMapping, TerminalPrinterSetting, UserPrinterSetting,
ReceiptLayoutSetting, ServiceChargeSetting, VoidReason, Promotion, PromotionTarget,
StockBalance(read-only snapshot — see §5)`.

### CLOUD_ONLY / REMOVE_FROM_EDGE — models & controllers (never ship)
All finance: `Account, JournalEntry, JournalLine, SalesLedger, CustomerLedger,
SupplierLedger, CashBankAccount*, DailyClosing, ExpenseVoucher*, OpeningBalance*`.
All purchasing/supply: `Supplier, SupplierPayment, PurchaseOrder*, PurchaseBill*,
PurchaseReturn*, GoodsReceipt*`. All stock authority: `StockLedger, StockAdjustment*,
StockTransfer*, StockCount*, InventoryBatch, Department*` (department stock).
All manufacturing: `ManufacturingBom*, ManufacturingConsumption*, ManufacturingScrap*,
ManufacturingRejection*, ProductionOrder, KitchenProduction*, KitchenWastage,
MaterialRequisition*, FinishedGoodReceipt*, WipJob*, Recipe*, RecipeConsumption`.
Controllers: `Finance/*`, `Reports/*`, `Manufacturing/*`, `Purchase*`, `Supplier*`,
`StockAdjustment/Transfer/Count`, `Department*`, `TenantBilling`, `TenantUpgrade`,
`TenantUser` (provisioning), `KitchenProduction`.

### NEEDS_EDGE_REPLACEMENT
- `SalesOrderController::store()` finalize step → **`EdgeSaleCaptureService`** (§5).
- `InventoryService::postOutFefo` / `recipeConsumption` / `postSalesLedger` → **omitted**
  on Edge; the cloud performs these on sync.
- `SalesReturnController` → **blocked** on Edge (returns are against official cloud state).
- Manager approvals → **local-only** approval on Edge (no cloud round-trip offline).

Full per-file allowlist lives in the boundary manifest doc.

---

## 5. Edge local sale-capture pipeline (`EdgeSaleCaptureService`)

The valuable `finalizePaidSale` engine (inventory/COGS/GL) stays cloud-only. Edge gets a
separate operation:

```
EdgeSaleCaptureService::capture(payload)
  1. validate local branch/terminal/user (bound branch only)
  2. compute customer-facing totals via the SHARED SalesTotalsService
     (discounts, tax, tips, service charge) — identical maths, no drift
  3. save operational SalesOrder + lines + modifiers + cash payments
  4. update table-session / held-order state
  5. assign client_uuid (already the POS contract)
  6. assign a RESERVED/local receipt number from invoice_range_state
  7. queue receipt/KOT print jobs locally (existing PrintJobService)
  8. mark sale sync_state = pending_sync
  9. NEVER post official GL / COGS / official stock ledger
```

### Local stock model — recommendation
Options considered:
- **A. Cached stock snapshot only** (display last-known qty; no local decrement).
- **B. Lightweight provisional decrement** (decrement a local `edge_stock_snapshot`
  counter per sale; no FEFO, no cost, no GL) ← **recommended**.
- **C. Full inventory tables without FEFO/accounting** (rejected — drags in batch/ledger
  surface, drift risk, larger attack surface, more to protect).

**Recommendation: B.** Smallest safe model that still gives cashiers live
"stock running low / out" visibility on the POS screen. It is a *provisional operational
counter only* — the cloud's `postOutFefo` on sync is the authority and may legitimately
diverge (other branches, purchases, adjustments). Edge shows an approximate on-hand,
labelled as branch-local/last-synced, never as an accounting figure.

### Negative-stock policy interaction
- The branch `allow_negative_stock` flag is part of the bootstrap snapshot and is
  **read-only** on Edge (only the cloud can change it).
- If `allow_negative_stock = OFF`: Edge *warns* on oversell but must **not hard-block a
  cash sale offline** solely on a possibly-stale snapshot — blocking a paying customer on
  stale data is worse than a backorder. Edge captures the sale, flags it
  `oversold_local = true`, and the **cloud is the real gate on sync**: `postOutFefo` runs
  with `allowNegative` = the branch's true policy, so a disallowed oversell surfaces as a
  **sync exception** (SYNC-EXCEPTION-DASHBOARD-1), never as silent negative stock.
- If `allow_negative_stock = ON`: backorder proceeds locally and syncs normally.
- Cloud behaviour is unchanged and never inherits Edge's local snapshot policy.

---

## 6. POS feature coverage (every current feature classified)

| Feature | Edge phase |
|---|---|
| Quick sale (cash) | **Phase 1 offline** |
| Takeaway (cash) | **Phase 1 offline** |
| Manual / cash delivery (own rider/channel) | **Phase 1 offline** |
| Dine-in table board (open/select/continue) | **Phase 1 offline** |
| Table sessions open/move/merge/close | **Phase 1 offline** |
| Held orders (hold/recall) | **Phase 1 offline** |
| Line discounts | **Phase 1 offline** |
| Tips | **Phase 1 offline** |
| Service charge | **Phase 1 offline** |
| Products / categories / barcodes | **Phase 1 offline** |
| Combos / modifiers | **Phase 1 offline** |
| Cashier shifts (open/close, cash recon) | **Phase 1 offline** |
| Cash payment | **Phase 1 offline** |
| Local receipt print | **Phase 1 offline** |
| Local KOT print | **Phase 1 offline** |
| Reprint receipt/KOT | **Phase 1 offline** |
| Split **cash** payment | **Phase 1 offline** (cash splits only) |
| Multi-tender incl. non-cash split | **Phase 2 offline** (needs local settle rules) |
| Local (offline) manager approval for overrides | **Phase 2 offline** |
| Basic customer/address cache + quick-store | **Phase 2 offline** |
| Card gateway verification | **online-only** |
| External aggregator live APIs | **online-only** |
| Credit / customer-ledger sales (receivables) | **blocked** |
| Returns against official cloud state | **blocked** |
| Usage-limited / global-counter promotions | **blocked** offline (global counter is cloud-authoritative) |
| Purchases / GRN / suppliers | **blocked** (cloud-only) |
| Stock adjustments / transfers / counts | **blocked** (cloud-only) |
| Manufacturing / production / recipes-as-consumption GL | **blocked** (cloud-only) |
| Cloud manager approvals (round-trip) | **blocked** offline |
| Full finance / reporting UI | **blocked** (not shipped) |

We do not claim "complete POS" — the table above is the contract of what MVP Edge does
and does not do.

---

## 7. Restricted build strategy

| Option | Verdict |
|---|---|
| **A. Build-time stripped Laravel Edge artifact from this repo** | **Selected** |
| B. Separate Edge app + shared private Composer packages | future refactor; more upfront cost |
| C. Full SaaS image with route/module lockdown only | **rejected** — cloud modules physically present = weak protection |
| D. New Electron/Node/.NET POS backend | **rejected** — full rewrite, query rewrite, UI drift |

**Reason for A:** fastest reuse of the current POS views/controllers, zero DB query
rewrite (DB is env-driven), no duplicate desktop POS UI, least drift, and it
*physically excludes* cloud-only modules (not just hides routes).

**Allowlist build manifest** (future `EDGE-BUILD-STRIPPER-1`, not built now):
```
tools/edge-builder/
  edge-manifest.php        # allowlist of paths/services/views/models that ship
  build-edge.ps1           # produce the stripped artifact from a clean checkout
  verify-edge-artifact.php # assert excluded modules/routes/creds are ABSENT
```
The builder must **exclude by allowlist** (ship only what is listed) rather than
blacklist, so a new cloud module added later is excluded by default.

---

## 8. `offline_edge` entitlement & offline license/grace

Commercial module (mirrors the manufacturing-entitlement pattern):
```
module key:   offline_edge
display name: Offline Branch Edge
sold as:      included in selected higher plans, OR paid add-on,
              optionally licensed per active branch server / device
```

Entitlement must gate **every** step (download gating alone is insufficient — pairing and
all server APIs re-check):
```
Local POS setup page · Download BingooEdgeSetup.exe · Generate pairing code ·
Pair Branch Server · Bootstrap snapshot · Heartbeat · Sync APIs ·
Activate Local POS Mode · licensed Edge branch/device count
```

**Offline license lease/grace (policy, not hard-coded here):**
```
- Signed entitlement LEASE issued at pairing (branch-scoped, expiry-stamped, cloud-signed).
- Renewed on each successful heartbeat while online; cached locally on the Edge box.
- Edge verifies the signature locally; a valid unexpired lease keeps selling OFFLINE.
- Warning banner shown to owner/cashier before expiry.
- On expiry with no renewal → controlled degradation (keep selling through a grace
  window, then read-only), NEVER an instant hard stop on brief internet loss.
- Revocation (subscription cancelled / device de-licensed) is enforced on next
  reconnect, or at controlled lease expiry if the box never reconnects.
```
Grace-window duration options to decide in `OFFLINE-EDGE-ENTITLEMENT-1`:
- Short (e.g. 72h): tightest revenue protection, higher risk of a false lockout in a
  genuine long outage.
- Medium (e.g. 7–14d): balanced; recommended starting point.
- Long (e.g. 30d): most customer-friendly, weakest against non-payment abuse.
**Core requirement:** *temporary internet loss must never immediately disable selling.*

---

## 9. Self-service customer workflow

```
1.  Customer buys / is granted offline_edge.
2.  Owner opens Settings → Offline Branch Edge.
3.  Owner selects a branch.
4.  System verifies an available branch/device license.
5.  System mints a short-lived 6-digit pairing code (reuse print-agent pairing:
    HMAC, 15-min TTL, single-use, 5-attempt, rate-limited).
6.  Owner downloads the versioned BingooEdgeSetup.exe.
7.  Wizard installs the local runtime + services.
8.  Wizard asks only for cloud URL + pairing code (cloud URL may be prefilled).
9.  Edge pairs to the selected tenant/branch (entitlement re-checked here).
10. Bootstrap downloads branch POS data (catalog, prices, tax, users, tables, printers).
11. Setup verifies DB + web service + sync worker + printing.
12. LAN URL is displayed.
13. Owner tests the URL from another terminal browser.
14. Receipt + KOT test prints pass.
15. Branch moves pending → active ONLY after readiness checks pass.
16. Cloud direct sale mutation for that branch becomes locked (existing guard).
```

**Failure behaviour (safety):** installer failure or incomplete bootstrap → branch stays
`pending`, cloud sales remain available, **no accidental branch lock**. This is already
enforced by BRANCH-OPERATING-MODE-1/HARDEN-1 (pending keeps cloud sales; only readiness
promotes to active).

---

## 10. Windows one-click installer (internals)

Customer experience mirrors the Print Agent (*Download → Next → Install → pairing code →
Ready*). Internally the Edge installer is much larger and manages a full local stack.

Packaged/managed components:
```
restricted Laravel Edge artifact · PHP runtime · local web server ·
MariaDB/MySQL · queue/sync worker · scheduler · printing component (Print Agent) ·
Windows services + auto-start · firewall/LAN rules · stable local hostname/URL ·
backup service · update service · health/status page · logs · uninstaller/repair
```
Design points:
```
install dir          C:\Program Files\Bingoo Edge\
data/config/logs     C:\ProgramData\BingooEdge\{data,config,logs}
DB data dir          C:\ProgramData\BingooEdge\mysql-data
services             BingooEdgeWeb, BingooEdgeMySQL, BingooEdgeWorker, BingooEdgeScheduler, BingooPrintAgent
ports                web (e.g. 8787) + MySQL (local-only 3307) — probe & fall back if busy
firewall             inbound allow on the web port for the LAN subnet only
LAN URL              prefer a stable host (mDNS/hostname) with IP fallback shown
first-run wizard     cloud URL + pairing code → pair → bootstrap → readiness → LAN URL
repair/update        re-verify services, re-run pending migrations, restart workers
rollback             failed install restores previous version + DB backup; branch stays pending
```
**No secrets embedded:** the installer carries **no tenant secret and no permanent device
token**. The device token is minted at pairing (device-scoped, revocable) and stored
under a restricted service account; the installer image is identical for every customer.

Full operational detail in `docs/ops/EDGE_INSTALLER_PRODUCT_RUNBOOK.md`.

---

## 11. Print Agent reuse

The existing Windows Print Agent is **reused as-is**, only re-pointed:
```
Cloud mode  : Agent polls cloud   /api/.../print_jobs
Edge mode   : Agent polls local   Edge Server /api/.../print_jobs (LAN)
```
Preferred flow: **Edge Setup bundles/installs the existing Print Agent** and configures
its server URL to the local Edge URL during the wizard (pairing/config inside the same
wizard). Preserved unchanged: TCP-9100 support, receipt routing, KOT routing,
printed/failed acks, service auto-start, logs/status. Existing cloud-connected Print
Agent installs keep working untouched.

**Honest status:** the Print Agent code is architecturally compatible and reusable for
LAN printing, but **offline LAN printing is not yet certified**. `LOCAL-PRINT-LAN-1` will
certify: internet physically disconnected → receipt prints locally, KOT prints locally,
multiple printers/routes, Windows-reboot recovery, failed-printer retry.

---

## 12. Source & credential protection

**Hard truth:** software on a customer-controlled machine cannot be made absolutely
unextractable (interpreted PHP especially). We reduce risk in layers, not absolutes.

Layers (in shipping priority):
```
cloud-only sensitive engines (finance/COGS/GL/manufacturing/billing/tenancy absent) ·
restricted allowlist artifact · no .git / tests / docs / dev-composer-deps ·
production-compiled assets, no source maps · restricted Windows service account ·
tight local file ACLs · device-scoped REVOCABLE token · NO cloud DB credentials on box ·
signed update manifest + checksum verification · single-tenant local DB only
```
**Optional hardening — ionCube / SourceGuardian:** evaluated as *optional*, **not** a
launch dependency. Do not adopt an encoder until compatibility is proven across: PHP
version, Laravel framework internals, queues, reflection/container behaviour, the Windows
loader, and the update pipeline. The strongest protection is architectural (the sensitive
engines simply aren't in the box), not the encoder.

Distribution comparison: customer-owned Windows server (selected, self-service, weaker
physical control) vs managed locked appliance (premium, stronger protection, higher ops
cost) — offer the appliance as a future upsell for security-sensitive customers.

---

## 13. Local database boundary

Local **MariaDB/MySQL**, single-purpose:
```
single tenant · single licensed branch · NO tenant switching ·
NO full master SaaS DB · NO other tenants' data ·
minimal local master/config rows only where framework boot requires them
```
Required tenant tables map to the EDGE_REQUIRED models in §4 (catalog, pricing, tax,
users/PINs, terminals, floors/tables/sessions, held/sales orders + lines + payments,
shifts, printers/print jobs, promotions snapshot, `StockBalance` snapshot). Classified as:
`required unchanged`, `required with reduced columns` (strip cost/GL columns where safe),
`Edge-specific`, `cloud-only/excluded`.

Edge-specific tables (design names; **no migrations created this sprint**):
```
edge_device_config      edge_catalog_cursor    edge_sync_queue
edge_sync_attempts      edge_sync_errors       edge_entitlement_lease
edge_update_state       edge_health_events     invoice_range_state
```

---

## 14. Updates, backup & disaster recovery

```
Updates : automatic SIGNED updates + manual "Check for Updates"; version compatibility
          gate against the cloud sync contract; safe forward migration; rollback to the
          previous Edge version on failure.
Backup  : hourly local DB backup; encrypted copy; optional cloud upload when online;
          restore wizard.
Health  : health/status page + downloadable diagnostics bundle (logs, versions, sync
          queue depth, last-heartbeat, printer status).
```
**Single-point-of-failure (box dies):**
```
paper fallback → reinstall Edge on a replacement PC → pair the SAME branch through a
controlled recovery path → restore the latest backup → reconcile the pending invoice
sequence (invoice_range_state) before resuming → any unsynced sales replay idempotently
by client_uuid (no double-post).
```

---

## 15. Production gates (before EDGE_FEATURE_ENABLED / prod deploy)

```
[ ] production-like MULTI-WORKER idempotency concurrency certification (§2)
[ ] offline entitlement + branch/device limits enforced on every gated step
[ ] pairing + bootstrap E2E on a clean Windows machine
[ ] sync replay E2E (cloud finalizePaidSale, one posting, tb=0)
[ ] cloud sale-lock E2E (active/closing/suspended)
[ ] local receipt + KOT E2E with internet PHYSICALLY disconnected
[ ] installer on a clean Windows machine (no dev tools present)
[ ] reboot / service auto-recovery
[ ] backup + restore drill
[ ] mode enter/exit reconciliation (shift/cash/invoice sequence)
[ ] all-tenant financial smoke (tb=0 / neg=0 / dept=0)
```
QA policy: use a **dedicated QA tenant or a restorable DB snapshot** for transaction
tests — do not leave permanent transactions in the normal demo tenant. Demo sale **#78**
(HARDEN-1 idempotency QA) is retained as a QA artifact; its sale/inventory/journal
records are **not** to be deleted manually.

---

## 16. Timeline (honest)

This is a full local-server product, not a browser agent. Realistic effort assuming one
focused engineer, sequential:
```
OFFLINE-EDGE-ENTITLEMENT-1        ~1–1.5 wk   module + gated UI/download/API re-checks
BRANCH-DEVICE-PAIRING-1           ~1 wk       entitlement + branch/device limits + pair API
BRANCH-BOOTSTRAP-SNAPSHOT-1       ~1.5–2 wk   catalog/settings/users/tables/printers + cursor
EDGE-SALE-CAPTURE-1               ~2 wk       EdgeSaleCaptureService + local snapshot model
OFFLINE-SYNC-ENGINE-1            ~2–3 wk      cloud replay + invoice-range reservation + retries
EDGE-BUILD-STRIPPER-1            ~1.5–2 wk    allowlist artifact + verify-artifact gate
EDGE-BUILD-PACKAGING-1          ~3–4 wk       Windows Setup.exe (PHP+MySQL+services+wizard)
LOCAL-PRINT-LAN-1                ~1 wk        offline LAN print certification
SYNC-EXCEPTION-DASHBOARD-1       ~1 wk
MODE-RECONCILIATION-1            ~1.5 wk
installer/update/backup hardening ~2 wk
pilot                            ~2–4 wk      supervised single-customer pilot
```
**Honest end-to-end: ~4–5 months** to a certified pilot, dominated by
packaging/installer, sync-engine correctness, and reconciliation. No shortcut makes the
Windows local-stack installer trivial. Do not overpromise a "few weeks" offline mode.

---

## 17. Implementation roadmap

```
1.  EDGE-EDITION-ARCHITECTURE-1      ← this sprint (design)
2.  OFFLINE-EDGE-ENTITLEMENT-1       module/add-on + gated UI/download APIs
3.  BRANCH-DEVICE-PAIRING-1          entitlement + branch/device limits
4.  BRANCH-BOOTSTRAP-SNAPSHOT-1      catalog/settings/users/tables/printers
5.  EDGE-SALE-CAPTURE-1              local operational capture, NO official GL
6.  OFFLINE-SYNC-ENGINE-1            cloud finalizePaidSale replay + invoice-range
7.  EDGE-BUILD-STRIPPER-1            restricted production artifact + verify gate
8.  EDGE-BUILD-PACKAGING-1           Windows Setup.exe
9.  LOCAL-PRINT-LAN-1                offline LAN printing certification
10. SYNC-EXCEPTION-DASHBOARD-1
11. MODE-RECONCILIATION-1
12. installer / update / backup hardening
13. pilot
```

**Next sprint: `OFFLINE-EDGE-ENTITLEMENT-1`.**

---

## Update — OFFLINE-EDGE-ENTITLEMENT-1 implemented (2026-07)
The `offline_edge` module + access gates are now built (tenant-level only). See
`docs/audits/offline-edge-entitlement-design-2026-07.md`. Three independent gates —
entitlement (`plan.hasEnabledModuleKey('offline_edge')`), rollout (`EDGE_FEATURE_ENABLED`),
installer availability (`config('app.edge_installer_*')` + file on disk) — via
`App\Services\Edge\OfflineEdgeEntitlementService`. `/settings/offline-edge` landing +
`/download` gate self-render 403 `EDGE_FEATURE_DISABLED` / 503 `EDGE_INSTALLER_NOT_AVAILABLE`
(no fake EXE, never 500). NOT built: installer, pairing, bootstrap, sync, device/branch
licensing, lease/grace. `offline_edge` is attached to no plan by default.
