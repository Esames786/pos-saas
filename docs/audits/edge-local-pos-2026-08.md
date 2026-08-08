# EDGE-LOCAL-POS-1 — branch-local shifts, sales and operational stock (WIP)

Status: **FOUNDATION IN PROGRESS — uncommitted WIP on `feat/14d-2-plan-upgrade-requests` (base `10d3e0d`).
NOT deployed. No routes/dine-in/held/KOT yet.** Production stays Cloud (`APP_ROLE=cloud`, Edge dormant).

## Scope of the sprint
First real Branch Server local POS operational runtime: local cashier auth, branch-bound terminal, local
shift, quick-sale/takeaway cash sale, operational stock decrement, KOT business events — all against the
edge-local MariaDB with Cloud master unavailable. **No sync, no Cloud GL/COGS/FEFO locally, no Local Mode
activation, no real pilot.** Backup MVP remains a hard release gate before the first real offline sale.

## Grounded authority boundary (executable)
The paid-sale pipeline was code-grounded end-to-end. Classification:
- **Operational (Edge-safe):** sale/lines/payment rows + canonical identities, operational stock quantity
  decrement, KOT batches/print intents, shift/business-date binding, sales operational subledger, shift
  counters, table-session close.
- **Cloud-only accounting authority (NEVER offline):** GL journal (`JournalService::post/reverse`,
  `JournalPostingService::postPaidSale`), cash/bank finance movement (`postSalesCashBankMovement`),
  COGS/FEFO valuation (`InventoryService::postOutFefo/postIn/transfer`), department custody
  (`DepartmentConsumptionService::processSaleOrder`), `SalesService::finalizePaidSale` itself.

**Low-level fencing = GREEN (frozen):** every official mutator above fails CLOSED on a branch_server
(`EdgeBranchServerFencingMySqlTest` 8/61 — refusals leave `quantity_on_hand`/`average_cost` VALUES and all
journal/finance tables untouched; real Cloud `post` + a real Cloud finance suite `DepartmentHandoverTest`
3/17 prove Cloud is unfenced). Guards sit OUTSIDE any swallowing try/catch. Pure helpers
(`resolveVariant`) stay usable.

## Grounded cash semantics (Cloud reference, reused by Edge)
`payment.amount` = amount APPLIED to the invoice; `tendered_amount` = physical cash handed over;
per-payment `change_amount = max(tendered − amount, 0)` (SalesOrderController payment create);
`sale.paid_amount = Σ payments.amount`; `sale.change_amount = max(paid − grand_total, 0)`;
shift counters (`total_sales`, `total_discount`, `total_tax`, `expected_cash`, `total_cash/card/…`)
increment by the APPLIED amounts (`updateShiftTotals`), and shift close compares `expected_cash`.
Example (grand 100, tendered 500): amount=100, tendered=500, payment.change=400, paid=100, sale.change=0,
expected_cash += 100. **Edge MVP: exactly ONE cash payment row per sale (split payments refused).**

## Shared services (single source of truth, no Cloud/Edge drift)
- `SaleIdempotencyService::canonicalSalePayload` — the canonical sale-intent builder (moved from the
  controller; both Cloud and Edge hash through it; Edge feeds it the EFFECTIVE authoritative intent).
- `SalePricingService` — price/tax resolution extracted from `SalesOrderController` (behavior preserved on
  Cloud; Edge adds a stricter trust boundary: standard-line price/tax NEVER taken from the request).
- `SaleOperationalSettlementService` — the operational settlement (sales subledger + shift counters)
  extracted verbatim from `SalesService`; **Cloud `finalizePaidSale` and `EdgeLocalPosService` both call
  it**, Edge inside the same sale transaction (rolls back with the sale).

## EdgeLocalPosService (offline paid-sale orchestrator) — locked contract
- Authority from `EdgeBranchContext::requireCurrent()` (tenant/branch/device/activation_epoch), never the
  request; terminal validated against the bound branch; cashier must BE the authenticated tenant user
  (active, Edge-eligible, branch-authorized), re-validated with the terminal INSIDE the transaction
  (TOCTOU); the open shift is LOCKED via `ShiftService::lockOpenShiftForTerminal` in the same transaction;
  business_date from the locked shift.
- `client_uuid` REQUIRED; replay-vs-conflict via the shared payload hash over the EFFECTIVE intent (bound
  branch, validated terminal, `order_source=pos`, discounts normalized off, ignored price/tax/print flags
  stripped); only a `client_uuid` unique collision is an idempotency race (others rethrow).
- quick_sale/takeaway only; cash only; discounts/promo/combo/dine-in/delivery refused; standard-line
  price/tax resolved server-side (tamper ignored).
- ONE ULID: `sale_uuid = U`, `sale_no = SO-{branch}-{terminal}-{U}` (display label traceable to identity).
- Sale marked `edge_sync_state='pending'` + `edge_activation_epoch`; `edge_operational_stock_posted` set
  TRUE only after every operational stock movement succeeds (same txn); **`inventory_posted` stays FALSE**
  (Cloud posts official FEFO/COGS at sync). Line `unit_cost=0/cost_total=0` are NON-AUTHORITATIVE schema
  placeholders — no local profit/COGS reporting may consume them; Cloud recomputes at sync.

## Current temporary scaffolding (NOT final)
The stock decrement currently still writes the official `stock_balances`/`stock_ledgers` (quantity-only,
cost=0). This is **test scaffolding until H10**: the final design uses Edge-ONLY tables under
`database/migrations/edge/` (`edge_operational_stock_baselines/balances/movements`, no valuation columns,
canonical sale_uuid/line_uuid references) with an accepted-baseline authority (branch/device/epoch/
generation/hash bound; no baseline → no sale) and a **baseline-replacement fence** (no reset while
unsynced operational activity exists — prevents oversell). Every final Edge sale test must assert official
stock tables unchanged.

## H10/I — Edge-only operational stock (EXECUTABLE GREEN)
Three EDGE-ONLY tables under `database/migrations/edge/` (`2026_08_08_000003`): `edge_operational_stock_
baselines` (immutable baseline identity bound to branch/device/activation_epoch/generation + content hash),
`edge_operational_stock_balances` (`balance_key = {baseline}-{product}-{variant|0}` unique, quantity only),
`edge_operational_stock_movements` (movement_uuid ULID, canonical `sale_uuid`/`line_uuid` references,
balance_after, epoch). **NO valuation columns anywhere** — "cost unknown" can never masquerade as an official
zero cost. `EdgeOperationalStockService` reworked to write ONLY these tables (InventoryService used solely for
the `resolveVariant` helper). `EdgeOperationalBaselineService` = the accepted-baseline authority: no baseline →
sale refused before mutation; exact same baseline retry → idempotent; same identity + different hash →
conflict; **any different baseline while one is accepted → REFUSED (replacement fence)** — proven: B1 qty 10 →
sale consumes 3 → B2 refused, balance stays 7, movement + pending sale preserved. No artisan command / HTTP
route exists for baselines (tests/QA call the service directly — no production "sell anyway" path).
Proof: orchestrator suite (16/56) + stock matrix `EdgeOperationalStockMySqlTest` (10/35): stock_item, recipe
yield=1 and yield≠1, order-type-specific ingredient, unit conversion (50 g→0.05 KG), missing-conversion
hard-block, modifier consume+conversion+missing-linked-product block, variant-keyed balance, negative-stock
ON/OFF, **multi-component atomic rollback** (component A restored when component B fails) — with official
`stock_balances`/`stock_ledgers` asserted untouched in every Edge test.

## Real-path proofs (auth / TOCTOU / collision classification)
- **Real Edge local-auth → sale:** a genuine Argon2id `edge_local_user_credentials` row (current epoch) →
  `EdgeLocalAuthService::verifyForLogin` + `login()` → tenant session → local sale; `created_by_user_id` =
  that cashier; the user's **Cloud password is proven NOT to authenticate** on the Edge path.
- **2C TOCTOU (both cases):** via the production no-op `beforeSaleTransaction()` seam, the cashier (case A)
  and terminal (case B) are deactivated AFTER preflight passes and BEFORE the in-transaction reload — the
  sale is refused and NOTHING persists (no sale/payment/stock movement/settlement).
- **2A real catch path — the executable test caught a REAL bug:** `UniqueConstraintViolationException`
  messages embed the full INSERT SQL, whose column list contains `client_uuid` for EVERY sales_orders
  violation — so a whole-message substring classifier misclassified unrelated collisions (e.g. a duplicate
  `sale_uuid`) as idempotency races. Fixed to match only the violated KEY NAME (`for key '…client_uuid…'`).
  Proven by a REAL frozen-ULID duplicate-`sale_uuid` violation through the real write path (must propagate,
  never became replay/PENDING) + the two-process same-client_uuid race (loser's real MySQL collision resolves
  the winner). A fabricated-message unit test would never have caught this.

## Authority correction pass (after checkpoint ec9a9d3 — review found 7 gaps; all closed executably)
1. **Baseline authority is DEVICE-bound**: `accept()`/`currentAccepted()` now resolve by branch + device_uuid +
   activation_epoch (a baseline for device A proven to give device B NO selling authority — sale refused,
   nothing persisted).
2. **Fail closed on corruption**: >1 accepted baseline for one binding throws a controlled corruption error —
   never `first()`-arbitrated (forged-second-row test).
3. **Content hash is COMPUTED authority**: the service canonicalizes the items itself (validated rows,
   quantities normalized to the persistence precision, duplicates rejected, deterministic sort) and stores its
   own SHA-256; a caller-supplied hash must match or acceptance refuses. Proven: order-independence, stale-hash-
   over-changed-payload refusal, duplicate-row refusal.
4. **source_revision must match** the imported `edge_local_meta.source_revision` (wrong revision refused).
5. **generation is internal** (fixed 1 for the INITIAL baseline — no fabricated generation authority).
6. **DB-level single-accepted-baseline invariant** (migration `2026_08_08_000004`): unique `active_binding_key`
   = SHA-1(branch|device|epoch) on accepted rows. Genuine two-process first-acceptance race proven: exactly ONE
   accepted baseline + ONE balance set; the loser gets a controlled outcome. The race also surfaced a REAL
   third path — a MySQL gap-lock **deadlock (1213)** between the two zero-row lockForUpdate scans — which the
   service now converts by retrying once (the retry observes the winner and lands on the idempotent/conflict/
   fence path) instead of leaking a raw QueryException.
7. **Append-only movements**: the baseline FK is now RESTRICT (was cascade) — deleting a baseline with sale
   movement history fails and the history/balance survive (proven).
8. **Settlement idempotency contract corrected**: sales_ledgers rows are idempotent (firstOrCreate); the shift
   counters are atomic increments and `settle()` is NOT independently re-entrant — correctness comes from the
   orchestrators invoking it exactly once inside the successful transaction (replays return before settlement;
   rollback reverts the increments). Docblock now says exactly that.
9. **Authenticated principal is REQUIRED at the service boundary**: a bare User model is no longer authority —
   `auth('tenant')` must exist AND match, preflight and in-txn (unauthenticated call refused with nothing
   persisted; authenticated A supplying B refused). The race worker now establishes the tenant principal
   explicitly (`Auth::guard('tenant')->setUser`), the credential-login path being separately proven by the real
   EdgeLocalAuthService integration test.
10. **`beforeSaleTransaction()` seam audited**: protected, unconditionally no-op in production, no
    request/env/config selects behaviour, nothing binds a callback at runtime; subclass-only test seam.
11. **Real Cloud cash-semantics proof** (`SaleCashSemanticsMySqlTest`): the REAL Cloud `SalesOrderController::
    store` with grand 100 / tendered 500 persists amount=100, tendered=500, payment change=400, paid=100,
    sale change=0; shift total_sales/expected_cash/total_cash each +100 (never 500); and the REAL close
    calculation (assertClosableUnderLock + variance) reconciles counted 100 against expected_cash=100 →
    variance 0.

## Foundation micro-closure (`ca02646`) — FOUNDATION GREEN + FROZEN
- **`source_revision` is mandatory, current-binding strict authority**: acceptance requires a non-empty
  revision equal to the imported `edge_local_meta.source_revision` — an omitted/null revision can NOT bypass
  the binding (A match ✓ / B wrong refused ✓ / C omitted refused ✓). `currentAccepted()` is revision-STRICT:
  when the binding's imported revision moves past the baseline's, selling authority **lapses fail-closed**
  (D ✓) until the future reconciliation/cutover protocol; the replacement fence stays revision-agnostic inside
  `accept()` so a revision change cannot sneak a new baseline in (✓).
- **Only `eosb_active_binding_unique` is the accepted-baseline race**: classification is by the violated KEY
  NAME (same lesson as the client_uuid classifier — never whole-message text). An unrelated unique violation
  (duplicate globally-unique `baseline_uuid` on a different device binding) propagates as the original
  `UniqueConstraintViolationException` (✓). The deadlock retry remains limited to MySQL errno 1213.
- Final gate on this exact tree: targeted 44/159; **full authoritative MySQL 166/755 ZERO skips**;
  fast 113/31107; caches green; `git diff --check` clean. Foundation heads `ec9a9d3` → `cc2b339` →
  `ca02646` (all pushed; production unchanged at `10d3e0d`, Cloud-only, Edge dormant).

## Route slice 1 (`faa4939`) — the branch-local POS HTTP surface OPEN
Five routes under `edge/local/pos/*` (`terminals`, `terminal/select`, `shift`, `shift/open`, `sales`),
registered ONLY in `routes/edge_runtime.php` behind `edge.auth` + `edge.branch`, each name explicitly
on the `config/edge.php` allowlist. Census-proven on BOTH sides (branch_server registers exactly the
approved URI set incl. the new routes; Cloud gets genuine 404s — names not even registered). Real
branch_server-BOOTED HTTP proofs: terminals→select→shift-open→cash sale→replay idempotent→409 on
changed intent; cross-branch terminal refused; unauthenticated → local login redirect. Gate on that
tree: full authoritative MySQL 169/789 ZERO skips + fast 115/31,139.

## Route slice 1.1 — session authority freshness + complete shift HTTP lifecycle
- **`edge.auth` session FRESHNESS**: `auth()->check()` alone is no longer authority. Every protected
  request re-establishes a CURRENT principal: bound appliance + fresh user row (active, Edge-eligible,
  authorized for the bound branch) + an ACTIVE local credential matching the bound branch AND current
  `activation_epoch`. A stale session is logged out + invalidated (not merely refused). HTTP-proven
  A–E: user disabled ✓, branch revoked ✓, credential disabled ✓, epoch superseded ✓ (re-login refused
  until the single credential row is re-enrolled at the new epoch — `edge_cred_user_unique`), valid
  session works ✓; after a stale logout, restoring the row does NOT resurrect the session ✓.
- **`POST /edge/local/pos/shift/close`**: smallest shared extraction — new `ShiftService::closeShift()`
  (txn + `assertClosableUnderLock` + variance vs `expected_cash`) now used by BOTH the Cloud
  `ShiftController::close` (denomination counting stays in the controller) and the Edge endpoint.
  HTTP lifecycle proven: sale APPLIED 100/tendered 500 → `expected_cash` 100 → counted 100 → closed,
  variance 0, closer stamped; post-close sale refused; re-close refused; close-with-no-shift refused.
  `SaleCashSemanticsMySqlTest` now exercises the SHARED close (no hand-rolled calculation left).
- **Takeaway over HTTP** ✓ + the cashier's `allowed_order_types` policy refused THROUGH HTTP ✓ (stock
  unmoved on refusal ✓).
- **Master-DB-unavailable proof**: master connection pointed at a nonexistent database (probed dead),
  then the ENTIRE real HTTP flow (terminals → select → open → sale → close) runs green; Edge
  operational stock moves; official `stock_ledgers`/`journal_entries` stay 0.
- **Terminals endpoint never echoes a stale selection** (vanished/inactive terminal → selection
  cleared, dependent endpoints refuse).
- **Truthful readiness**: `local_pos` is now a computed state — `not_ready` (unbound / local_auth not
  ready / no POS schema) | `needs_operational_baseline` (runtime present, no accepted device-bound
  baseline) | `basic_runtime_ready`. It NEVER flips the global `operational_stock` verdict and
  `activation_ready` stays hard false.

## Restaurant layer — dine-in / held / Add Round / KOT business events / manager re-auth
Eight new `edge/local/pos/*` routes (board, table open, session close, held store, held KOT, held settle,
held cancel, manager verify) — each explicitly allowlisted + census-updated, same authority envelope
(`EdgeBranchContext` + authenticated principal + in-txn revalidation; `EdgeLocalPosService` extended, the
controller adds no authority). Reuses CLOUD semantics + frozen identities end-to-end:
- **Table session**: Cloud open semantics (shift lock FIRST then table row lock, one open session per
  table, table→occupied). Pre-minted ULID = `session_uuid` with `session_no = TS-{branch}-{ulid}` derived
  from it (migration `2026_08_08_000014` WIDENS `session_no` varchar 30→64 — additive, Cloud formats
  unaffected). Close/cancel endpoint refuses while draft/held orders remain; frees the table.
- **Held order / Add Round**: Cloud HeldSaleController contract — status `held`, shift_id + business_date
  FROZEN at first hold (a session keeps its own business_date), one open check per session,
  lines delete+recreate churn with `sale_uuid` durable (EDGE-IDENTITY), KOT-sent state carried over
  (`kot_sent_quantity = min(sent, newQty)`), and **per-line CAPTURED price**: a carried line keeps its
  stored unit_price even when the catalog moves (proven 100→150: carried 2×100 + new 1×150 = 350); NEW
  lines price from the catalog — a submitted price is never trusted. Held orders NEVER touch operational
  stock or settlement.
- **KOT business events**: through the REAL public `PrintJobService::queueKot` — `kot_batches`
  (sequence 1 = normal, 2+ = addition; immutable `event_uuid`) + `kot_batch_lines` (`kot_line_uuid`,
  `source_line_uuid` snapshot that survives the line churn, DELTA quantities). NO print transport runs:
  with zero printers configured routing degrades to the browser-fallback route and Edge completes it
  server-side (`markPrinted` — the same call the Cloud POS browser makes), so `kot_sent` bookkeeping is
  canonical and a later round sends only its true delta; no unsent delta → NO batch (proven).
- **Implicit cancellation + manager re-auth**: reducing a line below its kitchen-sent quantity REQUIRES a
  matching void (reason + exact qty); with branch mode `manager_required` the REAL
  `ManagerApprovalService::verifyPin` (manager_pins `Hash::check`; wrong PIN refused) mints a single-use
  approval consumed by the REAL `KotCancellationService` (payload-bound, 10-min expiry) — cancel-KOT batch
  (`event_type=cancel`) + `sales_order_line_cancellations` row with `source_line_uuid` +
  `referenced_kot_event_uuid` written, approval `consumed_at` stamped. Whole-order cancel same path.
  **NAMED GAP**: bootstrap deliberately never ships `pin_hash` (SECRET_FIELDS) → a real appliance has no
  manager_pins rows until a local manager-PIN enrollment exists (future sprint, like Edge credentials).
  The spatie permission graph DOES ship (importer reconstructs it), so `$user->can()` is real on Edge.
- **Settle**: same-row held→paid (durable sale_uuid), cash-only, client_uuid REQUIRED (replay proven —
  same sale, no second stock consumption), operational stock consumed ONCE for the FINAL quantities at
  settle, shared `SaleOperationalSettlementService` (ledger = exactly sale_total + sale_payment), and the
  shared `SalesService::closeRestaurantTableSession` (made public — smallest extraction; Cloud
  finalizePaidSale unchanged) closes the session + frees the table. The check's OWN open shift is
  row-locked and takes the APPLIED cash (close variance 0 proven end-to-end over HTTP).
- **Shift-close blockers**: an open check + open table session block `shift/close` (real
  `assertClosableUnderLock` rule) until settled/cancelled+closed — proven both ways over HTTP.
Proof: `EdgeLocalRestaurantHttpMySqlTest` (4 tests / 106 assertions, branch_server-BOOTED real HTTP;
official stock_ledgers/journal_entries stay 0 throughout).

## Release position
Offline production readiness ≈ **40–45%**. A basic local sale running ≠ production-safe appliance: still
missing operational stock authority (H10), sync, printing transport, backup/updater (hard gate), recovery,
activation/lease, physical unplug pilot. Appliance lifecycle (update-never-wipes, expand→contract schema
changes, pre-update verified backup) stays as locked in `edge-identity-2026-08.md` §AE.
