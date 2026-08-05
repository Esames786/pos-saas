# EDGE-LOCAL-RUNTIME-PREFLIGHT — code-grounded offline runtime architecture

Status: **audit / design only**. Revised as **EDGE-LOCAL-RUNTIME-PREFLIGHT-HARDEN-1** (2026-08).
No code, no migrations, no sync, no Local-Mode activation, no deploy, no Print-Agent binary changes.
Branch `feat/14d-2-plan-upgrade-requests`, HEAD `75172e9` (PREFLIGHT-1 baseline). The **cloud POS is
canonical**; this maps the minimum-*safe* path to a real disconnected Branch POS that reuses it.
`EDGE_FEATURE_ENABLED=false`.

> **This revision exists because PREFLIGHT-1 was challenged as a code review and did not survive it
> unchanged.** Several PREFLIGHT-1 statements were assumptions declared as "verified"; §0 lists every
> one that changed and every newly discovered blocker. Nothing in this doc is implementation-approved.
> The first coding sprint is gated behind the contracts below being closed — see §18.

Companion docs: `edge-edition-architecture-2026-07.md`, `edge-edition-boundary-manifest-2026-07.md`,
`branch-bootstrap-snapshot-design-2026-07.md`, `branch-device-pairing-design-2026-07.md`,
`offline-pos-architecture-2026-07.md`, `print-routing-reminder-preflight-2026-08-03.md`,
`project_pos_printing_track` (memory).

---

## 0. Corrections to PREFLIGHT-1 + process governance

### 0.1 Process deviation (recorded, as required)
In the PREFLIGHT-1 run the recent POS/printing commits were **deployed to production** (prod HEAD →
`693b1af`). That deploy was requested in the preceding *handoff* message ("make to deploy the work i
didn't deploy it yet"), so it was authorized — **but it happened in the same run as an audit-only
task, and in that run 3 printing feature tests failed and were classed as a harness issue while the
deploy proceeded.** That is a release-discipline deviation regardless of the deploy authorization.

**Standing rule adopted (governance, not code):**
- An **audit/design prompt = zero production mutations.** No deploy, migrate, or Local-Mode activation
  inside an audit task, even if convenient.
- **Deployment requires an explicit, separate approval**, and a **green deterministic test run** first.
  "Tests fail but I know why, deploy anyway" is not permitted without explicit sign-off.
- The `PrintRoutingFoundationTest` local failures (SQLite tenant harness missing base `categories`
  table) are a **test-infrastructure gap that must be fixed before the next production deploy**, not
  waved through again.

### 0.2 Every PREFLIGHT-1 statement corrected here
| # | PREFLIGHT-1 said | Reality (code-verified) | Where fixed |
|---|---|---|---|
| C1 | Bootstrap schema is **`edge-bootstrap-v1`** | **`edge-bootstrap-v3`** — `EdgeBootstrapService::SCHEMA_VERSION = 'edge-bootstrap-v3'` (REMINDER-PRINT-1 bumped v1→v3). My "verified against current code" was false. | §1 |
| C2 | Local auth **Option A: ship bcrypt password hashes + manager PIN hash** to the box (recommended) | **Rejected.** Existing locked design does **not** export manager PIN hashes; reusable cloud hashes create offline-cracking → cloud-account-compromise risk. New Edge-specific verifier. | §5 |
| C3 | Edge just needs "no `APP_KEY`" | Incomplete. The Branch Server **needs its own device-local `APP_KEY`** (`EDGE_LOCAL_APP_KEY`) for sessions/cookies/local encryption; only the *cloud* `APP_KEY` must never be shipped. | §5b |
| C4 | `allow_negative_stock=false` → Edge **warns but never hard-blocks** a paid cash sale | **Rejected.** With the policy OFF, Edge must **hard-block locally** on its own operational quantity; a cloud "sync exception" cannot undo a paid, delivered, printed sale. | §10 |
| C5 | Split-brain guard is covered by `assertSaleMutationAllowed` | Only covers **sale** mutations. Adjustments / transfers / GRN / counts / manufacturing can still mutate an active branch's stock from cloud. New audit + block list required. | §10b |
| C6 | "Reuse existing identities so nothing double-posts" — sale/KOT/logical-key/cancellation/approval are enough | **Overclaimed.** `sales_order_lines`, `sale_payments`, `kot_batch_lines`, `shifts`, `customers` have **integer id only**; cancellation → line and approval → target are **integer FKs** that don't survive replay remapping. UUID additions required. | §9 |
| C7 | Outbox replays "directly via `finalizePaidSale`" | Direct replay is unsafe — the cloud sale path can mint a new number, new print jobs, new KOT, re-run promotions. A dedicated **ingestion boundary** must precede official posting. | §7/§13 |
| C8 | Offline numbering: `invoice_range_state` gapless sequence "reserved for the sync engine" | Current cloud `nextSaleNo()` = **`SO-<YmdHis>-<rand>`** — **not gapless, not branch-reserved.** The real design question is different and must be closed **before** offline sales, not deferred to sync. | §8 |
| C9 | "**One shift per branch**" | **Wrong.** `shifts` is keyed `branch_id + terminal_id` (index `[branch_id, terminal_id, status]`) — shifts are **per-terminal**; a branch can have several open at once. | §12 |
| C10 | Print Agent: "no binary change required" (stated as fact) | Base URL/token **are** configurable (verified: `baseUrl` via config/`POS_BASE_URL`/interactive prompt) — but HTTPS/self-signed local cert, hostname changes, and ACK-loss duplicate printing are **unproven**. State as hypothesis + blocker. | §8b |
| C11 | Recovery: "hourly backup → restore → replay outbox" | **Contradiction** — the outbox lives in the same DB; a corrupt DB loses the sales *and* their outbox rows. Needs a second durable mechanism + explicit RPO/RTO. | §14 |
| C12 | "Branch-context spoofing is **impossible** (env-bound)" | Overclaim. Env binds the *server*; a request can still carry `branch_id=other`. Mandatory branch-bound middleware + query/service assertions required. | §15 |
| C13 | Restricted Edge artifact is "documented-only", listed after the local runtime | **Dependency reversed** — the strip/boundary must land **before** a full app runs on a branch, else security debt. | §16b |
| C14 | New work is "small — five pieces" | Bounded, yes; **small, no.** `OfflineSyncEngine` alone is likely the largest remaining component. | §17 |
| C15 | Config down-sync ≈ "next bootstrap refresh" (one line) | Under-specified. Ongoing config/permission/price/policy down-sync is a real component with its own version cursor + apply rules. | §13b |
| C16 | Entitlement lease "documented-only", not in the roadmap | Must be **mandatory before activation**, added to the roadmap. | §14b/§17 |

---

## 1. Current implementation map (IMPLEMENTED / DOCUMENTED-ONLY / NOT-IMPLEMENTED)
Verified against current code (`75172e9`):

| Edge component | Status | Evidence |
|---|---|---|
| `offline_edge` sellable module + 3-gate entitlement | **IMPLEMENTED** | `OfflineEdgeEntitlementService`, `EnsureTenantSubscriptionAccess` |
| Branch/device pairing (6-digit HMAC, single-use, limits) | **IMPLEMENTED** | `EdgePairingService`, master `edge_devices`/`edge_pairing_codes`, `POST /api/edge/pair` |
| Device authentication (device-id + Bearer secret) | **IMPLEMENTED** | `AuthenticateEdgeDevice`, `GET /api/edge/device/me` |
| Bootstrap create / download / acknowledge | **IMPLEMENTED** | `EdgeBootstrapService`, `EdgeBootstrapApiController`, master `edge_bootstrap_snapshots(+_sections)` |
| **Bootstrap schema `edge-bootstrap-v3`** + sha256/gzip integrity | **IMPLEMENTED** | `EdgeBootstrapService::SCHEMA_VERSION = 'edge-bootstrap-v3'` (line 30); per-section + manifest hash; stored-payload verify |
| Bootstrap exports config: perms, roles, order-types, cancellation policy, Reminder/routing config | **IMPLEMENTED** | `userSection`, category-mapping/`order_type`, `printers.supports_reminder`; **passwords + manager PIN hashes NOT exported** |
| Branch lifecycle `cloud/inactive→pending→active→closing→suspended` | **IMPLEMENTED** | `BranchOperatingModeService::TRANSITIONS`, `branches.local_edge_status` |
| Cloud **sale**-lock for active/closing/suspended | **IMPLEMENTED (partial guard)** | `assertSaleMutationAllowed` — **sales only**, see §10b |
| `APP_ROLE`, `EDGE_TENANT_CODE`, `EDGE_BRANCH_ID` (env-only) | **IMPLEMENTED** | `config/app.php`, `assertBranchServerBoundToBranch` |
| `EDGE_FEATURE_ENABLED` flag (default false) | **IMPLEMENTED** | `config('app.edge_feature_enabled')` |
| Edge installer, restricted build artifact, signed entitlement lease | **DOCUMENTED-ONLY** | runbook/boundary docs; no artifact/builder; `installerIsAvailable()` false |
| **Disconnected local POS runtime, local auth, local sale persistence, local print queue, sync engine, reconciliation, activation** | **NOT-IMPLEMENTED** | no code |

**The cloud handshake (entitlement → pairing → device auth → bootstrap → device `ready`) is real;
everything that would make a Branch Server actually *sell* offline is not.**

## 2. Reusable cloud components (the leverage)
Run on a branch-bound Laravel with little/no change:
- **POS surface**: `POSController@index`, `resources/views/tenant/pos/index.blade.php` (one-screen
  workspace), `partials/table-board.blade.php`, `partials/table-bill-preview.blade.php`.
- **Sale inputs**: `SalesTotalsService` (`POST /api/pos/totals/quote`), `SaleIdempotencyService`
  (`sales_orders.client_uuid` + `client_payload_hash`), `PromotionService` (usage-limit counter is
  cloud-authoritative — §7 feature matrix blocks usage-limited promos offline).
- **Tables/holds**: `RestaurantTableSessionController` (open/bill-requested/close/move; **merge stays
  hidden**), `HeldSaleController`, split-bill.
- **Printing**: `PrintRoutingService`, `PrintJobService` (`print_jobs.logical_key`/`copy_no`),
  `EscPosPayloadService`, `DirectPayPrintOrchestrator` (`sales_orders.direct_pay_print_state`).
- **KOT/cancellation**: `kot_batches`/`kot_batch_lines`, `KotCancellationService`,
  `ManagerApprovalService`, `manager_approvals`, branch `held_kot_cancellation_approval_mode`.
- **RBAC**: `Tenant\User`, spatie roles/permissions, `users.allowed_order_types`/`default_order_type`.
- **Sale-lock guard**: `assertSaleMutationAllowed` (sales only — §10b widens this).

**Cloud-only, must be REPLACED on Edge (verified):** `SalesService::finalizePaidSale` runs
`recipeConsumptionService->consumeForSalesOrderLine` (COGS), `inventoryService->postOutFefo` (FEFO),
`postSalesLedger` + `JournalPostingService` (GL). This is the official-accounting boundary and must
**not** run locally.

## 3. Missing Edge runtime pieces
Local bootstrap import + local operational DB + hard branch binding; local auth/session (Edge-specific
verifier); local sale **capture** (no GL/COGS/FEFO); local Direct-Pay persistence; local Print-Agent
queue; offline manager approval; durable **local journal + outbox** (crash-survivable); a cloud
**ingestion + sync/replay** engine; config down-sync; reconciliation (provisional→official);
signed entitlement lease; controlled `pending→active` activation. (Details §5–§17.)

## 4. Local database architecture
- **Engine**: MariaDB/MySQL on the Branch Server (not SQLite) — code is MySQL-flavored; zero query
  rewrite.
- **Single tenant, single branch** — no tenant switching, no master SaaS DB, no other tenants.
- **Materialized-from-bootstrap (read-mostly)** reference tables: branch/terminals/categories/units/
  products/variants/barcodes/branch-prices/modifiers/combos, cash `payment_methods`, floors/tables/
  waiters, own delivery channels/riders, printers, receipt layouts, category_printer_mappings,
  terminal_printer_settings, service_charge_settings, void_reasons, users, roles, restrictions,
  **customers baseline** (§8), **tax config** (§8).
- **Local operational (writable)**: sales_orders/lines, sale_payments, held state,
  restaurant_table_sessions, shifts, cash_count_lines, kot_batches/lines, print_jobs,
  manager_approvals, sales_order_line_cancellations, NEW `edge_local_meta` (import cursor / schema
  version / branch binding / entitlement lease), NEW `edge_local_outbox` (per-event sync state), NEW
  `edge_local_journal` (append-only durability — §14), NEW provisional-stock table (§10).
- **Atomic bootstrap import**: stage → verify each section sha256 against the acknowledged manifest →
  flip to live in one transaction; crash mid-import leaves prior live set intact; restartable and
  idempotent by snapshot UUID + per-section hash. **Import must assert `schema_version == v3`** and
  refuse mismatches (`EdgeBootstrapException::SCHEMA_UNSUPPORTED` already exists cloud-side).
- **Never copied locally**: cloud/master DB creds, cloud `APP_KEY`, billing/subscription, finance
  GL/COGS/cost layers, purchasing/AP, manufacturing, official inventory/FEFO ledgers, other branches,
  Print-Agent cloud tokens, cloud password hashes.

## 5. Local authentication architecture (Option A rejected)
**Cloud reality (verified):** guard `tenant` = session over `Tenant\User`; `AuthController::login`
uses `auth('tenant')->attempt(email+password)` vs bcrypt `users.password`. **Bootstrap deliberately
omits `password`/`remember_token` and manager PIN hashes.**

**Do NOT ship reusable cloud credentials.** If a branch PC is compromised, exported bcrypt hashes can
be cracked offline and — because they are the *same* cloud login — used to compromise the SaaS account.
Encryption-at-rest doesn't remove the risk (the app needs a runtime verifier).

**Adopted design — Edge-specific credential, same identity/role/permission:**
- User **identity, role, and effective permissions stay identical** (already exported in bootstrap).
- The **credential verifier is Edge-local and NOT reusable against the cloud**: a per-user Edge PIN or
  password provisioned at pairing/first-run, hashed with a device-local salt, stored only on the box.
  Compromise yields at most an Edge credential, never the cloud password.
- **Manager approval offline** uses the same authenticated local manager identity + existing
  permission check — no PIN hash copied from cloud.
- **Provisioning**: an authenticated Owner/Manager sets Edge credentials during pairing/first-run (or
  a one-time enrolment token per user). **Reset/revocation**: pushed on config down-sync (§13b); a
  disabled/removed cloud user is dropped locally and their sessions invalidated. **Long-offline**:
  credentials remain valid within the entitlement-lease grace (§14b); **session TTL** is short with
  local re-auth. Nothing here depends on the cloud being reachable.

## 5b. Cloud vs device-local application key
- The **cloud `APP_KEY` is never shipped** (it decrypts cloud secrets).
- The Branch Server **requires its own `EDGE_LOCAL_APP_KEY`** — device-specific, random, generated at
  install/first-run — for sessions, cookies, CSRF, and encrypting local values (Edge credential salts,
  cached secrets).
- **Do not derive all local encryption from the network bearer/device secret** — that couples
  credential rotation and backup-restore to the pairing secret. Keep the local app key independent;
  ideally protect it with **Windows DPAPI / a machine-protected secret store**, with a defined
  rotation + backup/restore procedure that survives Branch-Server replacement (§14).

## 6. POS dependency matrix (A=unchanged, B=branch-bound, C=local-specific, D=blocked)
| Request | Class |
|---|---|
| Login | **C** — Edge-local verifier (§5) |
| POS page + bootstrap | **B** |
| Categories/products/search, `api/pos/*` lookups | **A/B** (local tables) |
| Tables board, Open/Continue table | **B** (`RestaurantTableSessionController`) |
| Held Orders | **B** |
| Add Round | **B** (preserved logic, local writes) |
| Bill Preview — cart | **A** |
| Bill Preview — table session | **B** |
| Totals quote (`SalesTotalsService`) | **B** (usage-limited promos blocked offline — §7) |
| Hold Sale | **B** |
| Review & Pay | **C** — `EdgeSaleCaptureService` replaces `finalizePaidSale` (no GL/COGS/FEFO) |
| KOT / Addition KOT | **B** (jobs served by local agent §8b) |
| Reminder / Updated Reminder | **B** |
| Receipt | **B** |
| Cancellation (`void-kot-item`) | **B** (local approval §11) |
| Manager approval | **C** — local authenticated approval, no cloud round-trip |
| Shift open/close (per-terminal) | **C** — local operational close vs cloud-reconciled close (§12) |
| Card/wallet/aggregator/credit/purchasing/AP/mfg/finance UI | **D** — absent from artifact |

## 7. First-offline feature matrix — every item SUPPORTED or BLOCKED
| Feature | Phase 1 | Notes |
|---|---|---|
| Dine-In / Takeaway / Quick Sale | **SUPPORTED** | order types from `users.allowed_order_types` |
| Own / manual Delivery | **SUPPORTED** | own channels/riders in bootstrap; aggregator APIs BLOCKED |
| **Cash** payment | **SUPPORTED** | only `payment_methods.method_type=cash` |
| Card / wallet / bank / cheque / aggregator | **BLOCKED** | no offline gateway/settlement |
| Customer credit / AR | **BLOCKED** | official finance is cloud-only |
| Tables / open check / Held / Add Round | **SUPPORTED** | branch-bound |
| KOT / Addition KOT `(R)` / Reminder / Updated Reminder / Receipt | **SUPPORTED** | local print queue §8b |
| **Pre-payment** cancellation (+ manager approval) | **SUPPORTED** | §11 |
| **Completed-sale return/refund** | **BLOCKED (Pending-Cloud)** | needs sale sync + inventory + finance reconcile |
| Walk-in / anonymous customer | **SUPPORTED** | name/phone captured on sale row |
| Select **existing** customer | **SUPPORTED** | customers baseline shipped in bootstrap (§8) |
| Create **new** customer offline | **SUPPORTED w/ local UUID** | needs `customers` UUID + sync mapping (§9); dedupe on cloud |
| Delivery customer phone/address | **SUPPORTED** | `sales_orders.customer_*` + `delivery_address` on the row |
| **Fixed** discount / promo | **SUPPORTED** | value frozen on the sale payload |
| **Usage-limited / coupon / global-redemption** promo | **BLOCKED offline** | counter is cloud-authoritative; can't enforce a global limit offline |
| Tips (cash) | **DECISION: SUPPORTED** | recorded on sale, non-inventory; synced as-is (ledger `tip` enum exists) |
| Service charge | **SUPPORTED** | `service_charge_settings` shipped; frozen on payload |
| **Taxes** | **SUPPORTED, frozen** | tax amounts computed locally and **frozen on the sale/line payload**; cloud honors the payload, does not recompute |
| Void reasons | **SUPPORTED** | `void_reasons` shipped |
| Waiters / riders | **SUPPORTED** | shipped in bootstrap |

## 8. Offline invoice / document numbering (must be closed BEFORE offline sales)
**Verified current behavior:** `SalesService::nextSaleNo()` = `'SO-'.now()->format('YmdHis').'-'.rand(100,999)`
— a **timestamp+random** string with a `UNIQUE` constraint; **not gapless, not branch-scoped-reserved,
not a fiscal sequence.** (Same for `SR-` returns.) So PREFLIGHT-1's "reserved `invoice_range_state`
gapless range" was wrong on two counts: the cloud doesn't do gapless numbering, and the real risk is
different. `restaurant_table_sessions.session_no` and `manager_approvals.approval_no` are similar
unique strings.

**Design decision to lock before EDGE-LOCAL-POS-1 (open — recommendation, not yet approved):**
- **Recommended (least change):** Edge mints `sale_no` **locally in the identical `SO-<ts>-<rand>`
  format**; because we sync the whole captured row, the cloud **honors the Edge `sale_no` verbatim**
  (never regenerates it). `client_uuid` remains the true idempotency key. Collision risk = timestamp
  collision + same random across terminals → mitigate by adding a **short per-terminal token** to the
  offline `sale_no` (e.g. `SO-<ts>-<terminalcode>-<rand>`) so two terminals cannot collide, and assert
  uniqueness locally under the terminal's shift.
- **Alternative (if fiscal gapless numbering is ever required):** cloud issues each branch/terminal a
  **reserved range** at activation; Edge draws from it under a local atomic sequence lock. This is a
  **bigger change that also affects the cloud** (which is not gapless today) and should only be taken
  if a jurisdiction demands gapless fiscal invoices.
- Either way, define: **clock-skew handling** (offline clock drift → non-monotonic timestamps),
  **crash recovery** of the local sequence/lock, **duplicate-receipt** copies (already `copy_no`),
  **cancellation gaps**, and **Branch-Server replacement** (numbers already issued must not be reused).
- **Receipt-vs-cloud invariant:** the number **printed to the customer offline is the number the cloud
  stores** — no post-sync renumbering. This is a hard release gate.

## 8b. Local printing path — hypothesis, not verified fact
**Verified:** the Windows Print Agent's server URL/token **is configurable** — `baseUrl` from a config
file, `POS_BASE_URL`, or the interactive `Server URL:` prompt, pairing via `/api/print-agent/pair` →
`token`. So *re-pointing the base URL* to the Branch Server is genuinely a config change.
**NOT verified (do not claim "no binary change"):** local **HTTPS / self-signed cert** handling;
**hostname/IP change** resilience; **startup/reconnect** behavior on the branch LAN; **cloud-token vs
local-token** issuance on the Branch Server; the completed-job cache; and — carried from the printing
design — **ACK-loss can duplicate a physical print**. The Branch Server must expose the same
`/api/print-agent/{pair,heartbeat,pending,jobs/{id}/printed,jobs/{id}/failed}` contract and issue a
local token.
**Release blockers (open):** (1) physical LAN certification (BLOCKED — no client hardware);
(2) ACK-loss duplicate-printing must be certified or fixed before "exactly-once physical print" is
claimed. **Cloud sync carries print/event HISTORY and must never re-create a physical print job.**

## 9. Cross-system stable identity — audited per object
Integer local DB IDs **must never** be a sync contract (cloud replay assigns new integer IDs).
Verified current identity columns:

| Object | Durable cross-system id today | Verdict |
|---|---|---|
| sales_order | `client_uuid` (unique) + `sale_no` (unique) | **OK** |
| held order | = sales_order (`client_uuid`) | **OK** |
| kot_batch | `event_uuid` (unique) | **OK** |
| sales_order_line_cancellation | `event_uuid` (unique) | event OK, **but its target `sales_order_line_id` is an integer FK — mapping gap** |
| restaurant_table_session | `session_no` (unique string) | usable, but timestamp-style; treat as business key + add UUID for safety |
| manager_approval | `approval_no` (unique) + polymorphic `reference_id` (int) | approval OK, **target ref is integer — gap** |
| **sales_order_line** | **integer id only** | **GAP — add `line_uuid`** |
| **sale_payment** | **integer id only** | **GAP — add `payment_uuid`** |
| **kot_batch_line** | **integer id only** | **GAP — add `line_uuid`** |
| **shift** | **integer id only** | **GAP — add `shift_uuid`** |
| **customer (new offline)** | integer id + optional `code` | **GAP — add `customer_uuid` for offline-created** |
| print job | `logical_key` + `copy_no` | **OK for idempotency** |

**Minimum UUID additions before sync:** `sales_order_lines.line_uuid`, `sale_payments.payment_uuid`,
`kot_batch_lines.line_uuid`, `shifts.shift_uuid`, offline `customers.customer_uuid`; and every
cross-object reference synced (cancellation→line, approval→target, kot_line→sale_line) must carry the
**referenced UUID**, not the local integer FK, so the cloud remaps by UUID. This is a schema change
(additive) that must land in EDGE-LOCAL-POS-1 and be **mirrored in the cloud** for replay mapping.

## 10. Inventory boundary — hard local enforcement
**Cloud stays the official stock/accounting authority** (cost, COGS, FEFO, valuation — never on Edge).
But **operational availability during Local Mode is Edge's responsibility**, not a warning:
- At activation, cloud produces a **reconciled operational stock baseline** for the branch (a
  point-in-time official quantity per product/variant), shipped in bootstrap into a local
  provisional-stock table.
- Edge maintains: `local_available = baseline − local_sales + local_cancellations/restorations`.
- **`allow_negative_stock = false` → Edge HARD-BLOCKS** the sale locally on `local_available` (same
  UX as cloud backorder/oversell control), because a "sync exception" cannot undo a paid, delivered,
  printed, shift-counted sale.
- **`allow_negative_stock = true` → allow + record** (report on sync), matching cloud.
- Edge writes **no** cost/FEFO/GL; the cloud does official posting at replay (§13). Any residual
  drift (cloud stock moved despite §10b) surfaces as a reconciliation item, but the **customer-facing
  block decision is made locally and correctly.**

## 10b. Split-brain — ALL cloud branch-stock mutation paths (activation gate)
`assertSaleMutationAllowed` guards **sales only**. While Local Mode is `active` for a branch, the
cloud must also block/queue every other operation that mutates *that branch's* stock, or the local
baseline silently diverges. **Audit + block (each must be verified and gated):**
- stock adjustments, stock transfers (in/out), GRN / purchase receipts, stock counts / recounts,
  manufacturing consumption + FG receipts, purchase returns, any direct inventory correction,
  price/product edits that affect the active session (route to §13b, not silent).
- Enforcement: extend the operating-mode guard beyond sales to an **`assertBranchStockMutationAllowed`**
  applied to every one of those paths for `active/closing` branches (block with a clear "branch is in
  Local Mode" error, or defer to post-deactivation). **This is a named activation gate**, not
  optional.

## 11. Cancellation / approval (offline)
Reuse cloud semantics exactly: **unsent** line → normal remove; **sent** line → reason + branch policy
+ manager approval (if `manager_required`) + immutable `sales_order_line_cancellations` event
(`event_uuid`) + Cancel KOT + cancellation Reminder. `manager_required` uses the authenticated **local**
manager identity/permission (§5); `auto_approve` uses the current local policy snapshot (still records
reason/audit/event + Cancel KOT). **Completed-sale returns/refunds stay BLOCKED (Pending-Cloud).**
Cancellation Reminders reach current eligible **and** historical Reminder printers (cloud behavior).
The cancellation→line reference must sync by **line_uuid** (§9), not integer FK.

## 12. Shift behavior — per-terminal, two-tier close
**Verified:** `shifts` is keyed `branch_id + terminal_id + opened_by_user_id`, index
`[branch_id, terminal_id, status]` → **shifts are per-terminal**; a branch can have multiple open
shifts simultaneously (PREFLIGHT-1's "one shift per branch" was wrong).
**Two-tier close (so a multi-day outage never locks the business):**
- **Local operational close** — the cashier can always close a terminal's shift locally; it becomes
  `status = closed` locally with a sync flag `pending_cloud_reconciliation`. Day 2 opens a fresh
  local shift normally.
- **Cloud-reconciled close** — on reconnect, the outbox delivers the shift + its sales; the cloud
  finalizes/reconciles the shift totals. Only *this* is the official close.
- Never block the local close on pending sync. `ShiftController` logic is reused, branch/terminal-bound;
  the only addition is the reconciliation state.

## 13. Sync boundary — ingestion first, then official posting (design only)
Do **not** feed the outbox straight into `finalizePaidSale`. Two ordered stages:
1. **`EdgeSyncIngestionService` (cloud):** authenticate device → for each outbox item, **upsert by
   UUID** (sale `client_uuid`, line `line_uuid`, payment `payment_uuid`, kot `event_uuid`,
   cancellation `event_uuid`) → **map local references** to cloud rows by UUID → **validate against the
   snapshot policy the sale was captured under** (prices/tax/promo frozen on payload; reject only true
   conflicts) → **validate totals** → **honor the Edge `sale_no`** (no regeneration) → **record Edge
   origin** → **suppress all physical print orchestration** (`DirectPayPrintOrchestrator` /
   `PrintJobService` must be bypassed on ingest so no KOT/Reminder/Receipt job is created). Order:
   customer → sale → lines → payments → kot batches/lines → cancellations → approvals → shift.
2. **Official posting (`finalizePaidSale`, internally):** only after ingestion produces a canonical
   cloud `SalesOrder` does the cloud run COGS/FEFO/GL. `finalizePaidSale` is **reused as a subroutine
   behind ingestion**, never as the public sync entrypoint.
- **Proof obligation (release gate):** a replayed Edge sale creates **zero** physical print jobs and
  **zero** new numbers/promotions. Conflicts (oversell that violated policy, stale price, promo limit)
  become **sync exceptions** for review — never silent corruption.

## 13b. Config / policy down-sync (ongoing, not just first install)
Bootstrap is initial install; live branches need **incremental refresh**. Component
**`EdgeConfigRefreshService`** with a **config revision cursor** (bootstrap already computes a
`sourceRevision()` over printers/layouts/mappings/terminals — extend it):
- Covers: users disabled/added, roles/permissions changed, prices, products, printer routing,
  cancellation policy, void reasons, Edge-credential resets (§5).
- **Cursor + atomic apply**: cloud revision N → Edge downloads N+1 → verify hashes → apply in one
  transaction. Define behavior for edge cases: **price change mid open-check** (freeze the check at
  its captured prices), **disabled manager with an existing local session** (invalidate on next apply),
  **product deleted while in a held order** (keep the held line, block re-add), **pending cancellation
  under an old policy** (honor the policy snapshot recorded on the event).

## 14. Recovery — durability that actually survives corruption
PREFLIGHT-1's "hourly backup → restore → replay outbox" is self-contradictory: the outbox is in the
**same DB**, so a corrupt DB loses post-backup sales *and* their outbox rows.
**Adopted (define concrete RPO/RTO):**
- **Target: RPO ≤ a few minutes of cash sales (losing an hour is unacceptable); RTO ≤ minutes to
  resume selling.**
- **Second durable mechanism (at least one, ideally layered):** MySQL **binlog** enabled on the
  Branch Server; an **append-only local journal** (`edge_local_journal`) written in the same
  transaction as each sale/payment on a **separate disk/path**; frequent **encrypted incremental**
  copies; and **opportunistic cloud upload of the outbox whenever connectivity exists** (so unsynced
  sales are also off-box).
- **Restore path:** restore last full backup → replay binlog/journal to the last committed txn →
  reconcile numbering/outbox. **Prove:** unsynced cash sales survive (a) DB corruption and (b) full
  Branch-Server replacement.

| Failure | Behavior |
|---|---|
| Internet outage | keep selling; outbox+journal grow; opportunistic cloud upload when possible |
| Server/PHP/DB restart | services auto-start; DB+journal+outbox intact; in-flight request retried by `client_uuid` |
| Print Agent restart | re-polls local queue; `logical_key` prevents dup jobs (ACK-loss dup still an open blocker §8b) |
| Power outage | **UPS required**; else rely on binlog/journal replay |
| DB corruption | restore backup → replay binlog/journal → reconcile — **RPO ≤ minutes** |
| Branch-Server replacement | controlled re-pair (same branch) → fresh bootstrap → restore journal/backup → reconcile numbering; unsynced sales replay idempotently by UUID |

## 14b. Signed offline entitlement lease/grace (mandatory before activation)
Currently documented-only. A Branch Server that is offline for weeks must still decide if the SaaS
subscription is valid. **Cloud issues a signed lease** at activation and on each reconnect:
`{tenant, branch, device, entitlement, issued_at, expires_at, grace_until, schema_version, signature}`.
Edge verifies the signature locally; within `grace_until` it keeps operating; past it, it degrades per
policy. **This is on the mandatory roadmap (§17), not deferrable past activation.**

## 15. Security boundary
Hard **tenant + branch binding** (`EDGE_TENANT_CODE`/`EDGE_BRANCH_ID`, env). **Remove the "spoofing
impossible" claim** — env binds the *server*, not the request. Required:
- **`EnsureEdgeBranchBound` middleware** on every Edge route + **service/query assertions** that scope
  to the bound branch (a request carrying `branch_id=other` must be rejected, not implicitly trusted).
- **Local `EDGE_LOCAL_APP_KEY`** (§5b), secure cookies, CSRF, short session TTL, Edge credential
  hashing.
- **LAN TLS strategy** (self-signed/local CA), **Windows firewall LAN-only binding**, **terminal
  authorization**, **local Print-Agent credential**, **rate limiting**, **device-secret rotation**,
  **route allowlist** (no cloud admin/finance/purchasing/mfg/billing/provisioning routes present),
  **local audit log**.

## 16. New components (bounded, not "small")
| New | Why |
|---|---|
| `edge_local_meta`, `edge_local_outbox`, `edge_local_journal`, provisional-stock + provisional/official state cols, UUID columns (§9) | no local sync/outbox/journal/UUID identity exists |
| **`EdgeLocalBootstrapImporter`** (atomic staged import, schema-v3 assert) | bootstrap is cloud-serve only |
| **`EdgeLocalAuthProvider`** (Edge-specific verifier, §5) | cloud auth needs omitted `users.password` |
| **`EdgeSaleCaptureService`** (replaces `finalizePaidSale`; no GL/COGS/FEFO) | official posting must not run on Edge |
| Edge Print-Agent **local endpoint** (serve the `/api/print-agent/*` contract locally) | agent polls a base URL; Branch Server must host the contract + local token |
| **`EdgeConfigRefreshService`** (§13b) | ongoing down-sync is real work |
| **`EdgeSyncIngestionService`** + **`OfflineSyncEngine`** (outbox → ingest → official posting) | no sync exists; ingestion must precede `finalizePaidSale` |
| Signed **entitlement lease** issuer/verifier (§14b) | mandatory before activation |
| Restricted **build/stripper** + one-click installer (§16b) | documented only |

## 16b. Restricted artifact BEFORE full local runtime
**Reverse the dependency:** land the **build/boundary (EDGE-RUNTIME-BOUNDARY-1)** — a Branch Server
image where cloud admin/billing/other-tenant/purchasing/accounting-admin/manufacturing/provisioning
modules **do not exist** — *before* running a local POS. Do **not** ship the full unrestricted SaaS
app to a branch "to strip later"; that creates security debt on customer premises.

## 17. Implementation scope — re-estimated
Bounded but **not small.** `OfflineSyncEngine` alone includes: upload protocol + concrete endpoints
(`POST /api/edge/sync/{sales,payments,kot,cancellations,approvals,shifts,customers}` or a batched
envelope), retry/backoff, idempotent UUID upsert, dependency ordering, conflict/exception handling,
offline numbering honor (§8), cross-system line/payment identity mapping (§9), cancellation & shift
reconciliation, provisional→official stock reconciliation, ingestion/print-suppression (§13),
entitlement-lease refresh, version/schema compatibility, monitoring, and recovery replay. Treat it as
**the largest remaining engineering component**, with its own state machines (outbox item:
`pending→uploading→ingested→posted→reconciled | exception`) and tables.

**Revised roadmap (boundary-first, each gated):**
1. **EDGE-RUNTIME-BOUNDARY-1** — restricted build/stripper + route allowlist + one-click installer
   skeleton (no selling). *(moved earlier — §16b)*
2. **EDGE-LOCAL-RUNTIME-1** — local MariaDB + `EdgeLocalBootstrapImporter` (atomic, schema-v3 assert)
   + hard branch binding + `EnsureEdgeBranchBound` + `edge_local_meta`.
3. **EDGE-LOCAL-AUTH-1** — Edge-specific verifier + local sessions + effective permissions +
   manager-approval identity + `EDGE_LOCAL_APP_KEY`/DPAPI.
4. **EDGE-IDENTITY-1** — additive UUID columns (§9) on cloud **and** Edge + reference-by-UUID; offline
   numbering decision (§8) locked. *(prerequisite for any sync)*
5. **EDGE-LOCAL-POS-1** — tables/holds/Add-Round + **cash** Review & Pay via `EdgeSaleCaptureService`
   (durable Direct-Pay parity, no GL) + local KOT/Reminder/Receipt creation + **local hard-block stock
   (§10)**.
6. **EDGE-LOCAL-PRINT-1** — Branch-Server `/api/print-agent/*` local endpoint + re-pointed agent
   (+ HTTPS/token/reconnect verification), then physical LAN cert; ACK-loss dup resolved.
7. **EDGE-SPLITBRAIN-STOCK-1** — `assertBranchStockMutationAllowed` across all §10b paths.
8. **EDGE-CONFIG-REFRESH-1** — `EdgeConfigRefreshService` + revision cursor (§13b).
9. **OFFLINE-SYNC-ENGINE-1** — `EdgeSyncIngestionService` + `OfflineSyncEngine` (ingest → official
   posting, print-suppressed) + provisional→official + sync-exception surface.
10. **OFFLINE-RECOVERY-HARDEN-1** — binlog/journal durability, RPO/RTO proof, corruption + box-replace
    drills, two-tier shift close, multi-terminal concurrency.
11. **EDGE-ENTITLEMENT-LEASE-1** — signed lease/grace (§14b).
12. **EDGE-ACTIVATION-1** — controlled `pending→active` only after §18 gates pass.

## 18. Release / activation gates (mandatory before `pending → active`)
Offline numbering design locked + receipt-vs-cloud number invariant (§8); cross-system UUID identity
on cloud+Edge (§9); local hard-block stock proven (§10) + all split-brain stock paths blocked (§10b);
ingestion proven to create **zero** physical prints and honor Edge numbers/promos (§13); Edge-local
auth + manager-approval QA (§5); durability RPO/RTO proof — unsynced cash sales survive corruption and
box replacement (§14); signed entitlement lease enforced (§14b); config down-sync + open-check edge
cases (§13b); security-boundary audit + `EnsureEdgeBranchBound` (§15); restricted artifact contains no
cloud-admin surface (§16b); disconnect/reconnect with **no duplicated sale/payment/KOT/Reminder/print**;
finance/inventory invariants green on replay (`tb=0/neg=0/dept=0`); Branch-Server reboot recovery;
physical LAN printer cert + ACK-loss dup resolved (§8b); **green deterministic test harness** (fix the
SQLite `categories` gap first). Independent, still-open, unrelated to Edge: **Merge-Table 2-process
concurrency cert** (Merge UI stays hidden) + **manual viewport/browser QA**.

---

## Newly discovered blockers (surfaced by this hardening pass)
1. **No line/payment/kot-line/shift/customer UUIDs** — cross-system reconciliation impossible until
   added (§9). *(blocks all sync)*
2. **Offline numbering is undefined and the cloud isn't gapless** — `nextSaleNo` is timestamp+random;
   receipt-vs-cloud number invariant must be designed before offline sales (§8).
3. **Split-brain covers only sales** — adjustments/transfers/GRN/counts/manufacturing can corrupt the
   local baseline; needs `assertBranchStockMutationAllowed` (§10b).
4. **Direct `finalizePaidSale` replay would re-print and re-number** — ingestion boundary required
   (§13).
5. **Recovery is self-contradictory** — outbox-in-same-DB; needs binlog/journal + RPO/RTO (§14).
6. **Local auth had a security-regressing recommendation** — Option A would let a branch compromise
   crack cloud credentials (§5).
7. **Entitlement lease not on the roadmap** — long-offline validity undefined (§14b).
8. **Restricted artifact sequenced too late** — full SaaS on a branch = premises security debt (§16b).
9. **ACK-loss physical-print duplication** remains uncertified (§8b).

## What remains uncertain (needs a spike, not a claim)
- Print-Agent behavior against a **local HTTPS/self-signed** Branch Server, hostname/IP changes,
  reconnect, and whether ACK-loss dup can be eliminated without a binary change (§8b).
- Whether `session_no`/`approval_no` unique strings are safe as sync keys or also need UUIDs (§9 —
  currently recommend adding UUIDs).
- Exact reconciled **stock baseline** production at activation (which official quantity, at what
  instant, and how transfers-in-flight are handled) (§10).
- Real RPO achievable with binlog+journal on target branch hardware (§14).
- Whether any jurisdiction in scope **requires** gapless fiscal numbering (drives §8 choice).

## First implementation sprint — only if truly safe
**Not EDGE-LOCAL-RUNTIME-1 as the very first step.** Safe to start **only** with
**EDGE-RUNTIME-BOUNDARY-1** (restricted build/route-allowlist skeleton — pure boundary work, no
selling, no DB, no sync) **and, in parallel, the design-closure items that must precede any code:**
lock the **offline-numbering decision (§8)**, the **UUID-identity plan (§9)**, and the **stock
enforcement + split-brain block list (§10/§10b)**. Local runtime + auth (EDGE-LOCAL-RUNTIME-1 /
EDGE-LOCAL-AUTH-1) follow once the boundary exists. **No selling code until §8/§9/§10 contracts are
signed off.**

No implementation, migration, sync, activation, or deploy is performed by this document.
