# Edge Phase 4 — Gap C: Standby Recent Orders follow the Online cashier list rule

**Team GC · 18 Oct 2026 · worktree `D:\laragon2\www\pos-saas-edge` · branch `feat/edge-config-refresh-v1` on HEAD `f6ed8ba` (uncommitted)**

Owner decision (approved verbatim): *"Standby Recent Orders must follow the Online cashier list rule. Extend the returnable-sale /
recent-order mirror so it carries restaurant_table_id (and the information required to render the same table label Online renders).
Target: same inclusion/exclusion rule as Online; cancelled sales treated the same as Online; same table label semantics; same
customer/order-type/status presentation where the shared view consumes it. Do not introduce an Edge-only Recent Orders rule."*

Background: `docs/status/edge-phase3-stage-b-cleanup.md` §4 and `docs/status/edge-phase3-samedata-comparison.md` §6 C — on a STANDBY
appliance the Recent Orders list was the returnable-sale mirror (paid / partially_returned only, no `restaurant_table_id`), so it had no
cancelled sales, no "Table G6 ·" label, showed the storage marker `cloud_mirror` as the status and formatted totals differently.

---

## 1. The shared rule — `app/Support/Pos/RecentOrders.php` (ONE rule, two callers)

| Concern | Rule (identical on both runtimes) |
|---|---|
| Inclusion | every sale of the branch with `status NOT IN ('held')` and a `sale_no` — **cancelled INCLUDED** (the list is the order history; what may be *returned* is a different rule, §4) |
| Narrowing | only the order types the operator may run (`effectiveAllowedOrderTypes`); an optional `order_type` filter narrows, never widens; `UserDataScope::applyToSales` |
| Order / limit | newest first by the **Cloud's sale id**; 50 rows (`RecentOrders::LIMIT`) |
| Row | `id · sale_no · order_type · status · payment_status · customer (customer_name → customer.name → "Walk-in") · delivery_channel · delivery_channel_type · delivery_rider · waiter · vehicle_number · table (restaurantTable.table_no) · total (number_format 2, thousands separator) · time (TenantClock 'd M, h:i A') · ago · printing` |

Callers:

- **Online** `POSController::recentSales` → `RecentOrders::applyInclusion(...)->orderByDesc('sales_orders.id')->limit(LIMIT)` + `RecentOrders::present($s, RecentOrders::directPayPrinting($s))`. Its only runtime truth: the direct-pay print state.
- **Edge** `EdgeLocalOrderLifecycleService::recentSales` → the same two calls. Its runtime truths, and only these:
  - **ordering key** = the Cloud's id: `edge_returnable_sales.cloud_sales_order_id` for a mirrored sale, the sale envelope's ACK (`edge_sync_outbox.ack_payload.sales_order_id`) for a LOCAL sale the Cloud has acknowledged; a local sale the Cloud has not numbered yet sorts first. So the Edge list is in Online's order even when local ids are not (proved: the oldest Cloud sale mirrored last still lists last).
  - **status of a mirrored sale** = its CLOUD status from the mapping (`paid` / `partially_returned` / `cancelled` …), never `cloud_mirror`.
  - **print state** = this appliance's `print_jobs`; a mirrored sale was printed by the Cloud's terminal and carries no resumable print state here (Online's "Printing needs attention" on such a row is Cloud-terminal truth the appliance cannot resume — the one deliberate, documented difference).
  - Additive Edge-only keys kept for Edge callers: `sale_uuid`, `grand_total`, `time_iso`, `edge_sync_state`, `origin` (`online` | `local`).
- `EdgeLocalHeldSalesController::recentSales` is now a pass-through (the W-B time/ago post-processing moved into the shared presenter).

Static gate: `tests/Feature/Edge/EdgeRecentOrdersSharedRuleTest.php` — both runtimes call `applyInclusion` / `present` / `LIMIT`, neither owns a
`held` filter, a row builder or a total format of its own; the shared view's `loadRecentSales` / `posOrderMeta` reads only keys the presenter ships.

## 2. The mirror — projection, package, appliance storage

**Population** (`EdgeReturnableSaleProjectionService::populationIds`) = **union** of

1. RETURNABLE: `status IN (paid, partially_returned)` within `edge.returns.cache_window_days` (14) — unchanged, for returns;
2. RECENT (new): the branch's newest `edge.returns.recent_orders_mirror_limit` (**200**, `EDGE_RECENT_ORDERS_MIRROR_LIMIT`) sales with
   `status NOT IN ('held')` and a `sale_no` — cancelled included — a superset of any Online list of 50 after the operator's
   order-type / data-scope narrowing. The Edge list then takes its own newest 50 from this population, so the two lists carry the same rows.

**Package** (`POST /api/edge/returnable/refresh`, `EdgeReturnableCacheApiController` → `package()`) — additive only:

| Level | New keys |
|---|---|
| package | `generation` (2), `recent_limit` |
| sale | `restaurant_table_id`, `delivery_channel_id`, `delivery_rider_id`, `payment_status`, `returnable` (bool: status ∈ RETURNABLE_STATUSES) |

`restaurant_waiter_id`, `customer_id/name/phone`, `vehicle_number`, `order_type`, `sale_date`, `completed_at` … were already there. Bootstrap stays
**v8**: nothing in the bootstrap / config-refresh contract changed; tables, waiters, delivery channels and riders are already bootstrapped sections.

**Table label rule — resolved locally by identity, not shipped as text.** Online renders `restaurantTable.table_no` through the relation on the sale's
`restaurant_table_id`. The appliance stores the SAME id on the shadow row (guarded: only when the bootstrapped `restaurant_tables` row exists, like the
waiter), and the shared presenter renders the SAME relation on the SAME row (the bootstrapped copy of the Cloud's). This is the only rule that is the
same rule: a renamed table shows its current `table_no` on both sides after a config refresh, and a text snapshot would drift. The only divergence
window is a table created on the Cloud after the sale was first mirrored and before the config refresh ships it; the next package re-resolves the
id (refresh rule below), so the label appears once the row is there.

**Watermark** (`watermark()`): now also hashes `restaurant_table_id` per sale and the generation marker, so a cancellation after mirroring, a table
move or a partial return moves the advertised watermark and the standby worker re-pulls (`EdgeStandbyFreshnessService::refreshReturnableIfBehind`).
The first heartbeat after this change advertises a new watermark for every branch → one refresh that brings the recent-orders rows in.

**Appliance storage** (`EdgeReturnableSaleCacheService`): the shadow row keeps `sales_orders.status = cloud_mirror` (storage marker — never a report
population status, never synced); the Cloud status lives on `edge_returnable_sales.cloud_status`. `presentationColumns()` writes `customer_id`,
`customer_name/phone`, `restaurant_waiter_id`, `restaurant_table_id`, `delivery_channel_id`, `delivery_rider_id`, `vehicle_number`, `order_type`,
`payment_status`, `sale_date`, `completed_at` on **insert and on every refresh**. No local schema change (no new columns, no migration) — the dev
Edge instances serving this worktree live keep working on their current schema.

## 3. Refresh / update rule

A sale already mapped (`edge_returnable_sales.cloud_sales_order_id`) is **updated in place** on every package: totals (as before) **plus** all
presentation columns re-resolved, and the mapping's `cloud_status` / `cloud_updated_at`. Hence:

- cancelled after being mirrored → the row is listed as `cancelled` (as Online) and leaves the return flow on that package;
- table moved → the new `restaurant_table_id` → the new label;
- partially returned → `partially_returned` + the Cloud's `returned_quantity` on the shadow lines (existing recompute);
- the same package twice, or a sale arriving in a later package → never a duplicate (one shadow row, one mapping per Cloud id).

A LOCAL sale the Cloud acknowledged is still never shadowed (`skipped_local`) — it stays the local truth and orders by its ACK'd Cloud id.

Found while proving the refresh path: the package's ISO-8601 instants (`sale_date`, `completed_at`, "+00:00") written through the query builder
on refresh were re-read by MySQL 8 in the SESSION time zone (a 5-hour shift on an Asia/Karachi branch → `time` / `ago` wrong on the Edge row
after the first refresh). `EdgeReturnableSaleCacheService::instant()` now normalises every package instant to the app-timezone DATETIME string on
both the insert and the refresh path (the twin test applies the dine-in sale through the refresh path and asserts `time` / `ago` equal Online's).

## 4. The RETURN flow still accepts returnable statuses only

`EdgeReturnableSaleCacheService::scopeReturnable($query)` is the one explicit guard: a LOCAL sale in `paid / partially_returned`, **or** a mirrored
sale (`cloud_mirror`) whose mapping `cloud_status` ∈ `EdgeReturnableSaleProjectionService::RETURNABLE_STATUSES`. Applied in
`EdgeLocalReturnService::search`, `EdgeLocalReturnService::loadSale` (open + post, with lock) and `EdgeLocalReturnController::createPage`.
The former private `['paid','partially_returned', cloud_mirror]` filters — which would have offered every mirrored (now also cancelled) row — are gone.

## 5. Owner regression tests → test names

`tests/MySql/EdgeRecentOrdersMirrorHttpMySqlTest.php` — one database plays the Cloud (Online's `POSController::recentSales` answers on the
Cloud rows), then the projection is taken, the Cloud rows removed and the same database is the appliance (`EdgeReturnableSaleCacheService::apply`,
real `GET /edge/local/pos/recent-sales`, real return routes):

| Owner test | Test |
|---|---|
| dine-in sale with table | `test_dine_in_sale_with_table_is_mirrored_with_the_online_table_label` — package carries `restaurant_table_id`; shadow row points at the bootstrapped table; Edge row `table = "G6"`, waiter, customer, `paid`, total `1,250.50`; Online keys identical |
| quick/takeaway sale without table | `test_quick_sale_without_table_is_mirrored_with_no_label` — `table = null` both sides, vehicle, Walk-in, identical keys |
| cancelled sale handling | `test_cancelled_sale_is_listed_exactly_as_online_and_is_not_returnable` — projected; listed first (newest) with its table as Online; the return search omits it, `GET /returns/sales/{id}` → 422, the shared return page opens without it; held check absent on both |
| refresh/update of the mirror | `test_refresh_updates_the_mirrored_row_without_duplicates` — re-apply idempotent (3/3/3); next package cancels the dine-in, moves + partially returns the quick sale → statuses/labels follow, counts unchanged, returnability follows (returnable 2 = 3 − 1) |
| shared POS Recent Orders rendering | `test_shared_pos_recent_orders_on_edge_equals_the_online_rendering_for_the_same_data` — HTTP JSON twin: same `sale_no` order (Cloud id order although local ids differ), every Online key equal per row, type filter twin; the shared view renders the Edge page and consumes exactly those keys |
| return flow refuses a non-returnable mirrored sale | `test_the_return_flow_refuses_a_non_returnable_mirrored_sale` — `POST /edge/local/pos/returns` → 422 and nothing written; `processReturn` throws `ValidationException['sale']`; control: the paid mirrored sale is `can_return` on the same fresh cache |

`tests/Feature/Edge/EdgeRecentOrdersSharedRuleTest.php` — the static "one rule" gate (3 tests).

## 6. Files changed

| File | Change |
|---|---|
| `app/Support/Pos/RecentOrders.php` | NEW — the shared rule (inclusion, limit, presenter, Online print-state adapter) |
| `app/Http/Controllers/Tenant/POSController.php` | `recentSales` calls the shared rule |
| `app/Services/Edge/EdgeLocalOrderLifecycleService.php` | `recentSales` calls the shared rule; Cloud-id ordering; Cloud status for mirrored rows |
| `app/Http/Controllers/Edge/EdgeLocalHeldSalesController.php` | `recentSales` pass-through |
| `app/Services/Edge/EdgeReturnableSaleProjectionService.php` | population = returnable ∪ recent-orders; additive sale/package fields; watermark covers the table |
| `app/Services/Edge/EdgeReturnableSaleCacheService.php` | `presentationColumns()` on insert + refresh; `localIdIfKnown()`; `scopeReturnable()` |
| `app/Services/Edge/EdgeLocalReturnService.php` | search / loadSale use `scopeReturnable` (private status list removed) |
| `app/Http/Controllers/Edge/EdgeLocalReturnController.php` | createPage uses `scopeReturnable` |
| `config/edge.php` | `returns.recent_orders_mirror_limit` (200) |
| `tests/MySql/EdgeRecentOrdersMirrorHttpMySqlTest.php` | NEW — 6 owner tests |
| `tests/Feature/Edge/EdgeRecentOrdersSharedRuleTest.php` | NEW — static gate |
| `docs/status/edge-phase4-gap-c-recent-orders.md` | this report |

Not touched: `tools/edge-browser-proof/**`, `docs/status/edge-phase4-*.md` (other than this new file), the LAB, other worktrees, dev databases.
Every saved PHP file passed `php -l` on save.

## 7. Gates

All on the isolated `_t15` databases (`DB_DATABASE=pos_test_master_edgewt_t15 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t15
EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t15`) / sqlite in-memory for the Feature suites, on the final tree:

| Gate | Result |
|---|---|
| `tests/MySql/EdgeRecentOrdersMirrorHttpMySqlTest` (new) | **OK 6 tests, 98 assertions** (1:20) |
| MySQL filter `EdgeReturnable\|EdgeCashierReturn\|EdgeSharedPos\|EdgeLocalPos\|EdgeHeld\|EdgeCashierOrderLifecycle\|EdgeBootstrap\|EdgeConfigRefresh\|EdgeCustomer\|EdgeRecentOrders` | **OK 135 tests, 2,512 assertions** (6:16) |
| Baseline of the touched code before the new tests (`EdgeReturnSyncHttp\|EdgeCashierReturn\|EdgeCashierOrderLifecycle\|EdgeSharedPosContract`, incl. the two-DB return sync proof) | **OK 19 tests, 568 assertions** (2:49) |
| `vendor/bin/phpunit tests/Feature/Edge` (EDGE_NODE_BIN set) | **OK 175 tests, 37,026 assertions** |
| `vendor/bin/phpunit tests/Feature/Pos` | **OK 26 tests, 456 assertions** |

## 8. Not done / notes for the coordinator

- **Print state of mirrored rows**: Online's `printing.resume_available` for a Cloud-printed sale is not mirrored (the appliance cannot resume the
  Cloud terminal's printing); the Edge row carries `printing: null` for a mirrored sale. Everything else the shared view draws is identical.
- **`ago`** is computed at request time on both sides from the same `sale_date` (identical for the same instant; the twin test uses sale dates
  hours in the past so a second boundary cannot flake it).
- The browser proof's `06-recent-orders` pixel pair should now match on the same dataset once the dev Cloud advertises the new watermark and the
  dev Edge refreshes (one heartbeat cycle); not re-run here (tools/edge-browser-proof is another team's).
- `EdgeLocalReturnService::search` still labels a mirrored hit `status: 'paid'` (unchanged; only returnable rows reach it now).
