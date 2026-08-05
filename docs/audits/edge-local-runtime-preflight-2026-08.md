# EDGE-LOCAL-RUNTIME-PREFLIGHT-1 — code-grounded offline runtime architecture

Status: **audit / design only** — 2026-08. No code, no migrations, no sync, no Local-Mode
activation, no deploy, no Print-Agent binary changes. Branch `feat/14d-2-plan-upgrade-requests`,
HEAD `693b1af`. The **cloud POS is canonical**; this document maps the minimum-change path to a
real disconnected Branch POS runtime that reuses it. `EDGE_FEATURE_ENABLED=false`.

Companion docs: `edge-edition-architecture-2026-07.md`, `edge-edition-boundary-manifest-2026-07.md`,
`branch-bootstrap-snapshot-design-2026-07.md`, `branch-device-pairing-design-2026-07.md`,
`offline-pos-architecture-2026-07.md`, `project_pos_printing_track` (memory).

---

## 1. Current implementation map (IMPLEMENTED / DOCUMENTED-ONLY / NOT-IMPLEMENTED)

Verified against current code:

| Edge component | Status | Evidence |
|---|---|---|
| `offline_edge` sellable module + 3-gate entitlement | **IMPLEMENTED** | `OfflineEdgeEntitlementService` (entitlement/rollout/installer), `EnsureTenantSubscriptionAccess` |
| Branch/device pairing (6-digit HMAC code, single-use, limits) | **IMPLEMENTED** | `EdgePairingService`, master `edge_devices`/`edge_pairing_codes`, `POST /api/edge/pair` |
| Device authentication (X-Edge-Device-ID + Bearer secret) | **IMPLEMENTED** | `AuthenticateEdgeDevice` middleware, `GET /api/edge/device/me` |
| Bootstrap create / download / acknowledge | **IMPLEMENTED** | `EdgeBootstrapService`, `EdgeBootstrapApiController`, master `edge_bootstrap_snapshots(+_sections)` |
| Bootstrap schema/version (`edge-bootstrap-v1`) + integrity (sha256/gzip) | **IMPLEMENTED** | `SCHEMA_VERSION`, per-section + manifest hash, stored-payload verify |
| Branch lifecycle `cloud/inactive→pending→active→closing→suspended` | **IMPLEMENTED** | `BranchOperatingModeService::TRANSITIONS`, `branches.local_edge_status` |
| Cloud sale-lock for active/closing/suspended (split-brain guard) | **IMPLEMENTED** | `BranchOperatingModeService::assertSaleMutationAllowed` (wired into 9 sale paths) |
| `APP_ROLE` (cloud/branch_server), `EDGE_TENANT_CODE`, `EDGE_BRANCH_ID` (env-only) | **IMPLEMENTED** | `config/app.php`, `assertBranchServerBoundToBranch` |
| `EDGE_FEATURE_ENABLED` feature flag | **IMPLEMENTED** | `config('app.edge_feature_enabled')`, default false |
| Edge installer (`BingooEdgeSetup.exe`) | **DOCUMENTED-ONLY** | `EDGE_INSTALLER_PRODUCT_RUNBOOK.md`; `installerIsAvailable()` returns false (no artifact) |
| Restricted Edge build artifact / source boundary | **DOCUMENTED-ONLY** | `edge-edition-boundary-manifest-2026-07.md`; no builder exists |
| Signed offline entitlement lease/grace | **DOCUMENTED-ONLY** | design in architecture doc; not built |
| **Disconnected local POS runtime, local auth, local sale persistence, local print queue, sync engine, reconciliation, activation** | **NOT-IMPLEMENTED** | no code |

**The cloud side of the handshake (entitlement → pairing → device auth → bootstrap → device
`ready`) is real; everything that would make a Branch Server actually *sell* offline is not.**

## 2. Reusable cloud components (the leverage)
Grounded in current code, these run on a branch-bound Laravel with **little or no change** — this
is the whole reason the topology is viable:
- **POS surface**: `POSController@index`, `resources/views/tenant/pos/index.blade.php` (one-screen
  workspace), `partials/table-board.blade.php`, `partials/table-bill-preview.blade.php`.
- **Sale capture inputs**: `SalesTotalsService` (server-authoritative totals via
  `POST /api/pos/totals/quote`), `SaleIdempotencyService` (client_uuid + canonical payload hash),
  `PromotionService` (usage-limit counter is cloud-authoritative — see §K/N).
- **Restaurant/tables**: `RestaurantTableSessionController` (open/bill-requested/close/move/merge/
  show/bill-preview), `HeldSaleController`, split-bill.
- **Printing**: `PrintRoutingService` (order-type-aware `kotRoutesForSale`,
  `reminderRoutesForSale`, `receiptPrinter`, `defaultKotPrinter`), `PrintJobService`
  (`print_jobs.logical_key` idempotency), `EscPosPayloadService`, `DirectPayPrintOrchestrator`
  (`orchestrate`, `markReminderDecision`, `sales_orders.direct_pay_print_state`).
- **KOT/cancellation**: `kot_batches`/`kot_batch_lines`, `KotCancellationService`
  (`recordLineCancellations`, `cancelHeldOrder`, `queueCorrectionReminders`),
  `ManagerApprovalService`, `manager_approvals`, branch `held_kot_cancellation_approval_mode`.
- **Identity/RBAC**: `Tenant\User`, spatie roles/permissions, `users.allowed_order_types` /
  `default_order_type`.
- **Split-brain guard**: `BranchOperatingModeService::assertSaleMutationAllowed` — already wired
  into all 9 cloud sale-mutating paths, so the CLOUD refuses to mutate an `active` branch's sales.

**Cloud-only, must NOT ship / must be REPLACED on Edge** (verified): `SalesService::finalizePaidSale`
calls `recipeConsumptionService->consumeForSalesOrderLine` (COGS), `inventoryService->postOutFefo`
(FEFO), `postSalesLedger` + `JournalPostingService` (GL). That method is the official-accounting
boundary and is exactly what an Edge capture must **not** run locally.

## 3. Missing Edge runtime pieces (what genuinely must be built)
Local bootstrap import + local operational DB + hard branch binding; local authentication/session;
local operational sale capture (a `finalizePaidSale` replacement with **no** GL/COGS/FEFO); local
Direct-Pay orchestration persistence; local Print-Agent queue served by the Branch Server; local
manager-approval-while-disconnected; a local event **outbox**; the cloud **sync/replay** engine;
reconciliation (provisional→official); controlled `pending→active` activation. (Details §C–§S.)

## 4. Local database architecture
- **Engine**: **MariaDB/MySQL** on the Branch Server (NOT SQLite) — the existing code is MySQL-
  flavored; reusing it needs zero query rewrite (already the decision in `offline-pos-architecture`).
- **Single tenant, single branch** — no tenant switching, no master SaaS DB, no other tenants.
- **Materialized-from-bootstrap (read-mostly)**: the branch-scoped snapshot sections become local
  reference tables — `branch, terminals, categories, units, products, product_variants,
  product_barcodes, product_branch_prices, modifier_groups, modifiers, combos, combo_components,
  payment_methods (cash only), restaurant_floors/tables/waiters, delivery_channels (own)/riders,
  printers, receipt_layout_settings, category_printer_mappings, terminal_printer_settings,
  service_charge_settings, void_reasons, users, roles, restrictions`.
- **Local operational (writable)**: `sales_orders, sales_order_lines, sale_payments, held-sale
  state, restaurant_table_sessions, shifts, cash_count_lines, kot_batches, kot_batch_lines,
  print_jobs, manager_approvals, cancellation events`, plus NEW `edge_local_outbox` (per-event
  sync state) and `edge_local_meta` (bootstrap import cursor / schema version / branch binding).
- **Local migrations/versioning**: the Edge artifact carries the tenant migrations; a local
  `edge_schema_version` gates a version-compatibility check against the cloud sync contract on
  every reconnect.
- **Atomic bootstrap import**: import into a **staging schema/transaction**, verify each section's
  sha256 against the acknowledged manifest, then flip to live in one transaction; a crash mid-
  import leaves the previous live set intact and the import is **restartable** (idempotent by
  snapshot UUID + per-section hash — the acknowledgment contract already guarantees completeness).
- **Never copied locally**: master/tenant DB credentials, `APP_KEY`, billing/subscription,
  finance accounts/journals/GL/COGS/cost layers, purchasing/AP, manufacturing, official inventory
  ledgers/FEFO, other branches, subscription proofs, Print-Agent tokens, user password hashes
  (unless the §E opt-in is chosen). Enforced by the bootstrap allowlist + the build boundary.

## 5. Local authentication architecture
**Cloud reality (verified)**: `config/auth.php` guard `tenant` = session driver over
`Tenant\User`; `AuthController::login` uses `auth('tenant')->attempt(email+password)` against the
bcrypt `users.password`. **But the bootstrap snapshot deliberately omits `password`/`remember_token`.**
So offline login needs one of:
- **Option A (recommended): a dedicated, encrypted local-credential section.** Ship each branch
  user's bcrypt `password` hash (bcrypt is one-way; the box is the branch's own locked appliance)
  in a **separate, opt-in, encrypted-at-rest** bootstrap section, plus the manager PIN **hash**
  (never plaintext). Existing credentials then work offline with **zero new role system** — the
  same `attempt()` + spatie permissions run locally. Password changes / deactivation / revocation
  propagate on the next bootstrap refresh (a lightweight delta), and a revoked/deactivated user is
  dropped from the credential section.
- **Option B: Edge-specific local PIN/password** provisioned per user at pairing/first-run. Avoids
  ever shipping cloud hashes, but adds a provisioning flow and a second credential to manage.

Recommendation: **A** for MVP (least friction, reuses `attempt()` + effective permissions),
gated behind the entitlement + a per-branch encryption key derived from the device secret, with a
short local-session TTL and a bootstrap-refresh cadence. Manager approval offline uses the same
authenticated local manager identity + existing permission check (§L). No manager PIN or secret is
ever exported in plaintext.

## 6. POS dependency matrix (A=unchanged, B=branch-bound, C=local-specific, D=cloud-only/blocked)
| Request (route) | Class |
|---|---|
| Login (`tenant.login`) | **B** (local auth §E) |
| POS page (`tenant.pos.index`) + bootstrap | **B** |
| Categories/products/search, `api/pos/*` lookups | **A/B** (read local tables) |
| Tables board (`api.pos.table-board`), Open/Continue table | **B** (`RestaurantTableSessionController`) |
| Held Orders (`held-sales.*`, `api.pos.held-sales`) | **B** |
| Add Round | **B** (preserved logic, local writes) |
| Bill Preview — cart | **A** (browser) |
| Bill Preview — table session (`table-sessions.bill-preview`) | **B** (local session aggregation) |
| Totals quote (`api.pos.totals.quote`, `SalesTotalsService`) | **B** (promo usage-limit is cloud — §N) |
| Hold Sale | **B** |
| Review & Pay (`pos.store`) | **C** — replace `finalizePaidSale` with local capture (no GL/COGS/FEFO) |
| KOT / Addition KOT (`PrintJobService`, `kot_batches`) | **B** (jobs served by local agent §J) |
| Reminder / Updated Reminder (`PrintRoutingService::reminderRoutesForSale`) | **B** |
| Receipt | **B** |
| Cancellation (`KotCancellationService`, `void-kot-item`) | **B** (local approval §L) |
| Manager approval (`ManagerApprovalService`) | **C** — local authenticated approval, no cloud round-trip |
| Shift open/close (`shifts.*`) | **B/C** — local cash drawer, no final close with pending sync (§M) |
| Card/wallet/aggregator/credit/purchasing/AP/mfg/finance UI | **D** — blocked/absent in artifact |

## 7. First-offline feature matrix (verified against §6 + data policy)
**Supported (phase 1):** Dine-In, Takeaway, Quick Sale, own/manual Delivery; **cash** only;
tables/open-check; Held Sale; Add Round; Review & Pay; KOT; Addition KOT + `(R)`; Reminder /
Updated Reminder; Receipt; **pre-payment** cancellation; local manager approval; local Print Agent.
**Blocked/phased:** card/wallet/bank gateways, customer credit, aggregator APIs, purchasing/AP,
manufacturing, official finance/GL, **completed-sale return/refund** (Pending-Cloud until sale sync +
inventory + finance reconcile), official COGS/FEFO, cloud administration. (The bootstrap already
ships cash-only payments + own-only delivery + a `restrictions` section — enforce it client-side.)

## 8. Local printing path
The existing **Windows Print Agent** re-points its poll URL to the Branch Server's local
`print_jobs` API (no binary change) — reuse the current queue contract, `logical_key` idempotency,
routing (`PrintRoutingService`), payloads (`EscPosPayloadService`), and printed/failed acks.
Supported jobs: KOT, Addition KOT, `(R)`, Reminder, Updated Reminder, duplicates, Cancel KOT,
cancellation Reminder, Receipt. **Cloud sync carries event HISTORY, never re-creates physical print
jobs** (a synced sale must not reprint the kitchen ticket). **Physical LAN certification stays a
separate release gate** (currently BLOCKED — no real hardware; see §T).

## 9. Identity / idempotency model
Reuse the existing identities so **nothing double-posts on sync/retry**: sale `client_uuid` +
canonical payload hash (`SaleIdempotencyService`), KOT event/batch identity (`kot_batches` +
sequence), `print_jobs.logical_key`, cancellation-event identity, manager-approval identity. Edge
mints them **locally** at capture time; the cloud sync replays by the SAME identity so a
reconnect/retry never creates another sale, KOT, Reminder, cancellation, or payment posting. This
is the same contract the hardened cloud Direct-Pay + sale-idempotency already enforce.

## 10. Inventory boundary
**Cloud stays the official stock/accounting authority.** Edge phase-1 gets: a **read-only stock
baseline** from bootstrap (display "approx / last-synced", never an accounting figure) and an
**optional lightweight provisional decrement** per sale (no FEFO, no cost, no GL) for cashier
oversell visibility. `allow_negative_stock` ships read-only; Edge **warns but never hard-blocks a
paid cash sale** on possibly-stale local data (blocking a paying customer on stale data is worse
than a backorder) — the cloud is the real gate on sync (`postOutFefo` with the branch's true
policy; a disallowed oversell surfaces as a **sync exception**, never silent negative stock). Edge
is **never** authoritative for cost layers, COGS, FEFO, or valuation.

## 11. Cancellation / approval (offline)
Reuse cloud semantics exactly: **unsent** line → normal remove; **sent** line → reason + branch
policy + manager approval (if `manager_required`) + immutable event + Cancel KOT + cancellation
Reminder. `manager_required` uses the authenticated **local** manager identity/permission (§E);
`auto_approve` uses the current local branch policy snapshot (still records reason/audit/event +
Cancel KOT). **Completed-sale returns/refunds remain blocked (Pending-Cloud)** until the sale has
synced and inventory/finance can reconcile. Cancellation Reminders go to current eligible **and**
historical Reminder printers (already the cloud behavior).

## 12. Shift behavior
One local **open shift** per branch is required to transact; local cash-drawer tracking + offline
cash payments accumulate against it. **Rule (verify at build): a final branch shift-close is not
permitted while required offline sync/reconciliation remains pending** — otherwise cash totals
could be closed before the cloud has the sales. Shift open/close reuses `ShiftController` logic,
branch-bound.

## 13. Sync boundary (design only — not built)
Two distinct streams, kept separate:
- **Operational event sync** (up): local sales, lines, payments, KOT batches/lines, Reminder
  snapshots, cancellations, approvals, shift events — each with its local identity (§9), ordered by
  dependency (sale → lines → payments → KOT → cancellation), retried with acknowledgment, replayed
  idempotently into the cloud via the existing `finalizePaidSale` pipeline (cloud does the official
  posting). Conflicts (e.g. disallowed oversell, promo usage-limit exceeded, stale price) become
  **sync exceptions** for review, never silent corruption.
- **Official inventory/finance posting** (cloud-only): happens during replay; Edge never posts GL/
  COGS/FEFO. A per-event **provisional → official** state transition is recorded so the dashboard
  shows what has been reconciled. `invoice_range_state` (gapless numbering) is reserved for the
  sync engine.

## 14. Recovery
What must survive each failure: **all committed local operational data + the outbox**.
| Failure | Behavior |
|---|---|
| Internet outage | keep selling over LAN; outbox grows; sync resumes on reconnect |
| Branch Server / Windows / PHP / DB restart | services auto-start; local DB + outbox intact; in-flight request lost, client retries by client_uuid |
| Print Agent restart | re-polls local queue; `logical_key` prevents dup jobs |
| Router restart | LAN drops briefly; terminals reconnect; no data loss |
| Power outage | **UPS required** (runbook) so MySQL/PHP shut down cleanly; else DB corruption risk |
| Local DB corruption | restore from the last local backup; replay outbox; reconcile invoice sequence |
| Replacement Branch Server | re-pair the SAME branch via controlled recovery → fresh bootstrap → restore backup → reconcile pending numbering; unsynced sales replay idempotently |
Operational runbook recommendation: **UPS on the Branch Server + router**, hourly local encrypted
backup, spare-box drill.

## 15. Security boundary
Hard **tenant + branch binding** (`EDGE_TENANT_CODE`/`EDGE_BRANCH_ID`, env-only, already enforced);
device credential is the paired secret (sha256 on cloud, ACL-locked on box); **no** cloud/master DB
credentials, `APP_KEY`, billing/subscription/admin routes, provisioning, or cross-tenant data in
the artifact (the build boundary + route allowlist enforce this). Local auth/session (§E) + spatie
permission checks + CSRF stay in force; branch-context spoofing is impossible (branch is env-bound,
not request-derived). **The Edge artifact must not become a usable copy of SaaS administration** —
finance/purchasing/manufacturing/billing/provisioning controllers/views/routes are absent.

## 16. New components (smallest set; why existing code can't be reused as-is)
| New | Why |
|---|---|
| `edge_local_meta`, `edge_local_outbox`, provisional-stock + provisional/official state cols | no local sync/outbox tables exist |
| **`EdgeLocalBootstrapImporter`** (atomic staged import) | bootstrap is cloud-serve only; nothing imports it locally |
| **`EdgeSaleCaptureService`** (replaces `finalizePaidSale`: local sale + lines + payments + KOT + Reminder + Direct-Pay state, **no** GL/COGS/FEFO) | `finalizePaidSale` posts official accounting — must not run on Edge |
| **`EdgeLocalAuthProvider`** (verify against the encrypted local-credential section) | cloud auth needs `users.password` which bootstrap omits |
| Edge Print-Agent **local endpoint** (serve `print_jobs` on the LAN) | current agent polls the cloud; needs a local queue endpoint |
| **`OfflineSyncEngine`** (outbox → cloud idempotent replay) + reconnect worker/scheduler | no sync exists |
| Restricted **build/stripper** + one-click installer | documented only |
Everything else (POS UI, routing, KOT/Reminder/Receipt, cancellation, approvals, totals,
idempotency, table sessions, shifts) is **reused**.

## 17. Phased implementation roadmap (small sprints)
1. **EDGE-LOCAL-RUNTIME-1** — local MariaDB + `EdgeLocalBootstrapImporter` (atomic staged import) +
   hard branch binding + `edge_local_meta`. (No selling yet.)
2. **EDGE-LOCAL-AUTH-1** — encrypted local-credential section + `EdgeLocalAuthProvider` + local
   sessions + effective permissions + manager-approval identity.
3. **EDGE-LOCAL-POS-1** — tables/holds/Add-Round + **cash** Review & Pay via `EdgeSaleCaptureService`
   (durable Direct-Pay parity, no GL) + local KOT/Reminder/Receipt job creation.
4. **EDGE-LOCAL-PRINT-1** — Branch-Server local `print_jobs` endpoint + re-pointed Print Agent
   (then the blocked **physical LAN certification**).
5. **OFFLINE-SYNC-ENGINE-1** — `edge_local_outbox` → cloud idempotent replay via `finalizePaidSale`;
   provisional→official; sync-exception surface.
6. **OFFLINE-RECOVERY-HARDEN-1** — reboot/backup/reconciliation/multi-terminal + shift-close guard.
7. **EDGE-ACTIVATION-1** — controlled `pending → active` after all gates (§T).
Adjust boundaries if the code suggests safer cuts; do not treat these as fixed.

## 18. Release gates (mandatory before `pending → active`)
Real LAN printer certification (currently **BLOCKED** — client hardware); multi-terminal local
concurrency QA (same table / held sale / Add-Round / payment / cancellation / approval / print
creation, reusing DB row locks — see §Q); offline login + manager-approval QA; internet
disconnect/reconnect with **no duplicated sale/payment/KOT/Reminder** (identity model §9);
synchronization reconciliation (provisional→official, invoice sequence); finance/inventory
invariants green (`tb=0/neg=0/dept=0`); backup/restore drill; Branch-Server reboot recovery;
stale-config/policy handling (bootstrap refresh + entitlement lease); security-boundary audit;
and the still-open **Merge-Table 2-process concurrency certification** (Merge UI stays hidden) +
**manual viewport/browser QA** — both independent of this offline work.

---

## Q. Multi-terminal note
Several POS browsers share ONE local Branch DB. Reuse the existing DB **row-lock** protections
(table-session open/move/merge already lock; sale idempotency guards double-submit; `print_jobs`
`logical_key` converges concurrent KOT). Cloud-only assumptions that must be re-checked when all
terminals hit one local DB: promotion **usage-limit counters** (cloud-authoritative → treat as
online-only offline), and any code that assumed a single-writer cloud connection. `MERGE TABLE`
stays **hidden** until its two-process concurrency proof exists — do not enable it on Edge either.

## Bottom line
The cloud POS is now a strong reuse base: **most of the POS surface is class A/B** (reusable, at
most branch-bound). The genuinely new work is small and well-bounded — **local import, local auth,
one sale-capture replacement, a local print endpoint, and the sync engine** — plus recovery
hardening and activation. The official-accounting engine (`finalizePaidSale`) stays cloud-only by
construction. Recommended first sprint: **EDGE-LOCAL-RUNTIME-1** (local DB + atomic bootstrap
import + branch binding). No implementation in this document.
