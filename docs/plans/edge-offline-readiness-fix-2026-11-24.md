# Edge offline readiness fix: Khatri "Switch to Offline" would be refused (24 Nov 2026)

Branch `fix/edge-offline-readiness-20261124` from production `be57aeba`. Cloud-only change. The installed 0.9.0-edge
appliance (`3328afb8`) is not touched.

## The defect

The Khatri Branch Server status page (0.9.0, warm standby, 24 Nov 03:28 PKT) shows
`Offline readiness: blocked: STOCK_AUTHORITY_READY, AUTHORITY_TAKEOVER_SAFE, STANDBY_FRESH_ENOUGH`.

- `AUTHORITY_TAKEOVER_SAFE` false while online is correct (the lease is live). Not touched.
- `STANDBY_FRESH_ENOUGH` has a supervised accept-stale override.
- `STOCK_AUTHORITY_READY` has **no** override. While it is false, a supervised takeover during an internet outage is
  refused (`EdgeAuthorityService::takeOver`: only `['STANDBY_FRESH_ENOUGH']` may be overridden).

## Root cause (two separate Cloud defects)

### A. The Cloud issues a stock baseline the appliance must refuse (this is why the gate is false today)

`STOCK_AUTHORITY_READY` = `EdgeOperationalBaselineService::currentAccepted() !== null`. The appliance gets its baseline in
`EdgeStandbyFreshnessService::refreshStockIfBehind` → `EdgeBaselineClient::fetch` → `assertResolvable` → `accept()` (initial)
or `EdgeBaselineCutoverService::acceptCutover` (revision moved). The Cloud endpoint (`EdgeBaselineApiController::issue`) echoes the
appliance's own `source_revision`, so a config revision move is followed by a cutover in the SAME tick. Churn alone does
not keep the gate false.

What keeps it false is `assertResolvable`, which refuses the WHOLE package when:
- `BASELINE_EMPTY`: the package has no items. `EdgeBaselineIssuanceService::issue` omits every product whose official on-hand is
  ≤ 0. On 21 Oct the Khatri owner ordered "stock abhi kisi ka track nh krna ha": 12 stocked items were written out to cost.
- `BASELINE_UNKNOWN_PRODUCT`: an item names a product the appliance does not hold. `issue()` sums ALL `stock_balances` of the
  branch, but the bootstrap/config ships only the sellable set + active-recipe ingredients + modifier-linked products.
  Khatri's 21 Oct record lists purchase-only crates/packaging that kept stock (`7 UP DIET (CRT)`, `BIG APPLE (CRT)`,
  `Container 750 ML`, `Spoon`). They are not POS products, so they are not shipped.

Either way, no baseline was ever accepted. The gate was already false right after the 21 Nov install (config rev 15). The LAB
passed because all its stock was on shipped POS products.

### B. The config revision churns (~140/hour): every print-agent heartbeat and dine-in table change mints one

`EdgeBootstrapService::sourceRevision` includes `max(updated_at)|count` of `printers` and `restaurant_tables`.
- `PrintAgentApiController::heartbeat` folds printer health onto every reachable printer with an Eloquent builder `update()`,
  which also sets `updated_at`. Print-job completion and failure do the same.
- Every dine-in open, bill or close writes `restaurant_tables.status` (occupancy).

Neither column is configuration for the appliance: printer health is not shipped, and the appliance keeps its own table
occupancy (`EdgeLocalConfigRefreshApplier::mergeOccupancyStatus`). Effects once A is fixed:
1. Every tick re-applies the config and cuts the baseline over (full delete + insert of the balances, plus one audit row).
2. If the internet drops between that tick's config apply and its baseline fetch, the accepted baseline is left at the old
   revision. `STOCK_AUTHORITY_READY` is then false with no override, and takeover is refused for the whole outage. Under churn
   this window opens on almost every tick.
3. `STANDBY_FRESH_ENOUGH` is almost always false (applied ≠ advertised), so every outage needs the stale override.

## The fix (Cloud only)

1. **Config watermark (B).** For `printers` and `restaurant_tables`, `sourceRevision` hashes exactly the configuration content the
   appliance receives instead of `max(updated_at)|count`.
   - Printers: the shipped columns, over the shipped set (branch + global printers).
   - Tables: the shipped columns, with `status` reduced to `inactive` / `active`. Only that distinction is configuration on
     the appliance.

   Real edits still mint a revision: IP, port, name, role, enabled, a table added, renamed or deleted, a table disabled or
   re-enabled. Health pings and occupancy no longer do. The bootstrap/refresh sections are unchanged.
2. **Baseline issuance (A).** `EdgeBaselineIssuanceService::issue` restricts the items to the products/variants the appliance
   holds: the shipped `products` / `product_variants` sections, via the new `EdgeBootstrapService::deliveredProductIdentities`,
   pinned equal to `buildSections`.
   - If that leaves nothing, it states the official position explicitly as zero rows for the shipped products. This is the
     same selling position: the appliance reads a missing balance row as 0 (`EdgeOperationalStockService::decrement`).
   - The stock watermark (advertised on every heartbeat) is unchanged. It is computed the same way as before, so the
     heartbeat path gets no new queries and the accepted baseline still equals the advertised position.

### Why this is safe (split-brain, exactly-once)

- Not touched: lease/TTL, fencing, `AUTHORITY_TAKEOVER_SAFE`, auto-takeover (stays off), the outbox, ingestion, handback,
  release publication, and every appliance file.
- The baseline is still the Cloud's official on-hand.
  - Products the appliance cannot sell or consume are no longer named. They were never usable offline.
  - Positive quantities of shipped products are unchanged.
  - The appliance still re-validates branch, epoch, revision, integrity hash and drain.
  - A standby still never writes the Cloud.
- One config revision still has one config meaning. The only new non-determinism: two builds of the same revision may carry a
  different live table `status`, which the appliance ignores. It shows up only if two ticks apply the same revision concurrently
  (the handback orchestrator plus the worker). The loser then reports `REVISION_CONFLICT` for that tick instead of
  `already_applied`, writes nothing, and the next tick sees applied = advertised.

## Impact (system map, built 2026-11-23 on 9d93ee96: 250 tables / 441 FKs / 762 screens)

- **Tables, read only (no schema change, no migration):**
  - `printers` (106 screens use it) and `restaurant_tables` (92): read by the watermark. Their write paths are unchanged.
  - `stock_balances` (122), `products`, `product_variants`, `recipes`, `recipe_ingredients`, `modifiers`, `modifier_groups`:
    read by the baseline issuance.
- **Master `edge_branch_config_revisions`:** after deploy, each paired branch gets ONE new revision (the watermark format
  changed). After that, new revisions come only from real edits.
- **Appliance data (unchanged code):**
  - `edge_local_meta` (one config refresh);
  - `edge_operational_stock_baselines` / `_balances` (Khatri: first accepted baseline);
  - `edge_baseline_cutovers` (one row per real edit instead of about one per tick).
- **Screens / endpoints:**
  - device API `POST /api/edge/authority/heartbeat` (advertised revision);
  - `POST /api/edge/config/refresh` (package revision);
  - `POST /api/edge/sync/baseline` (items);
  - `POST /api/edge/bootstrap/snapshots` (watermark);
  - the appliance status page readiness (reads only);
  - the Cloud Offline Edge page (shows the revision).
  - No tenant screen changes.
- **Tests:**
  - new: `EdgeStandbyChurnReadinessHttpMySqlTest` (Khatri shape end to end + churn + outage + supervised takeover);
  - new methods in `EdgeConfigWatermarkMySqlTest` (churn ≠ revision, real edits = revision; delivered identities = shipped sections);
  - regression: `EdgeStandbyFreshnessHttpMySqlTest`, `EdgeBaselineRoundTripHttpMySqlTest`, `EdgeBaselineCutoverMySqlTest`,
    `EdgeHandbackOrchestratorHttpMySqlTest`, `EdgeAuthorityPartitionTest`, `EdgeBootstrapV7MySqlTest`,
    `EdgeBootstrapApproverEligibilityMySqlTest`, `EdgeCustomerRefreshMySqlTest`, plus the config-refresh test files.

## First deploy (attended window)

The deploy is a fast-forward with `deploy.sh`, nothing to migrate, snapshot compare before and after. Expected on Khatri within
about 2 ticks (~70 s) of the deploy:
1. The first heartbeat advertises revision N+1 (new watermark format).
2. That tick pulls and applies the config (`refreshed:N->N+1`).
3. In the same tick, the stock refresh fetches the now-acceptable package → `refreshed:initial`.

From the next tick: `config current`, `stock current`; status page `STOCK_AUTHORITY_READY ok` and `STANDBY_FRESH_ENOUGH ok`.

## Evidence (24 Nov 2026, laptop MySQL test DBs `pos_test_*_edgefix24`)

The appliance-side files are byte-identical between the installed 0.9.0 build (`3328afb8`) and `be57aeba`, and this branch
does not touch them. So the two-database bridge test runs the installed appliance's exact code against the fixed Cloud.
The files: `EdgeAuthorityService`, `EdgeOperationalBaselineService`, `EdgeStandbyFreshnessService`, `EdgeBaselineClient`,
`EdgeBaselineCutoverService`, `EdgeLocalConfigRefreshApplier`, `EdgeOperationalStockService`, `EdgeAuthorityTick`.

- **On the unfixed Cloud (`be57aeba`), the new tests reproduce production:**
  - `error:BASELINE_UNKNOWN_PRODUCT` for a stocked purchase-only crate;
  - `error:BASELINE_EMPTY` for a branch without official stock;
  - the first print-agent / table churn tick mints revision 2.
  - A throwaway proof (not committed) took a busy standby through churn, an outage mid-tick, and the lease lapse. The result:
    `REFUSED Local Mode cannot start — failing gates: STOCK_AUTHORITY_READY, STANDBY_FRESH_ENOUGH [config revision 5
    applied, Cloud advertised 4; no accepted stock baseline at the current config revision]`. The same happened WITH the
    supervisor's stale override.
- **On this branch:**
  - The new tests pass: 8 tests, 156 assertions.
  - In the same scenario, every gate passes after the lapse, and the supervised takeover succeeds with no override, from the
    Cloud position at the cut.
  - Focused Edge regression (27 classes, 148 tests) has 3 failures from one pre-existing cause, test-DB order pollution:
    - `EdgeLocalImportMySqlTest` leaves a unit conversion in the shared Cloud test DB.
    - Four later classes clear `units` with FK checks off, so the next config refresh ships a conversion to deleted units.
    - The same failures occur on the unfixed code in the same order.
    - `EdgeStandbyFreshnessHttpMySqlTest` alone passes 4/4, and the polluted order with the hardened new test passes 18/18.

## Not in scope (reported)

- **Returnable projection:** "no returnable-sale information has been received from the Cloud" while ~9 MB is downloaded per
  tick.
  - The cache watermark is written only at the end of ONE apply transaction. A failure on any row rolls back everything, so the
    next tick downloads the package again.
  - It does not gate takeover (not in `gates()`; it only decides whether offline returns of Cloud sales fail closed).
  - The failing row needs the appliance's tick output (`returnable: error:…`) before any fix.
- **Optional 0.9.1 appliance hardening** (not needed for Khatri once B is fixed): fetch the baseline for a new config revision
  BEFORE applying that revision, so a WAN drop can never separate them.
- **Residual watermark churn:** customer credit postings (`customers.current_balance`) and promo use (`promotions.used_count`).
  Rare at Khatri. Hashing the customer book on every heartbeat would be too heavy for a hotfix.
