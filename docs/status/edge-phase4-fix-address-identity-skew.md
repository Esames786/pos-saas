# Edge Phase 4 — F1 fix: address identity skew after the Cloud/appliance upgrade

Team F1 · 18 Oct 2026 · worktree `pos-saas-edge`, branch `feat/edge-config-refresh-v1` on top of f6ed8ba (Phase 4 at 41650b4) ·
source + isolated tests only, NOT committed · owner rule: "after the Cloud upgrade the appliance proceeds without manual data editing".

Defect found by the W15 end-to-end proof (`docs/status/edge-phase4-w15-proof.md` F1): after the Phase 4 migration ran on the Cloud tenant
AND on the already-bootstrapped dev appliance, every config refresh was refused
`REFRESH_CONFLICT: address id 1 is held locally by a different address identity` — the appliance stayed on revision 17 while the Cloud
advertised 18 (`STANDBY_FRESH_ENOUGH=false`), and a supervised takeover was refused. The installed LAB appliance (bootstrapped 19 Sep,
addresses present) would have hit the same wall at its next update.

## 1. Root cause

`database/migrations/tenant/2026_10_06_000001_edge_phase4_offline_customer_identity.php` added `customer_addresses.address_uuid` and
backfilled it with `Str::ulid()` PER ROW wherever it ran. It is a tenant migration, so it runs twice for the same rows:

| side | how it runs | what it did to a pre-existing Cloud address (id 1) |
|---|---|---|
| Cloud tenant | `migrate --database=tenant` | minted ULID A |
| appliance (already bootstrapped) | `edge:local:schema-upgrade` (runs the tenant migrations on the local DB) | minted ULID B |

The applier's address engine (`EdgeLocalConfigRefreshApplier::upsertCustomerAddresses`) matches by `address_uuid` first, then by numeric
id; a row under the Cloud's id whose uuid differed from the payload's was treated as a foreign identity and refused — correct for a
till-born row, wrong for a row the appliance only ever COPIED from the Cloud. `EdgeCustomerRefreshMySqlTest` did not catch it because
every appliance row it staged already carried the Cloud's uuid.

## 2. The rule — Cloud-owned rows take the Cloud's identity

1. **The appliance never mints an identity for a Cloud-origin row.** In the tenant migration the `address_uuid` backfill now runs only
   when `! App\Support\EdgeRuntime::isBranchServer()`. On a Branch Server the column is added and the (nullable) unique index is
   created, pre-existing rows stay `NULL`, and the next refresh/bootstrap carries the Cloud's uuid in. Till-born rows
   (`edge_origin = 'local'`) are minted at creation by `HasCanonicalIdentity` exactly as Phase 4 shipped — unchanged.
2. **The refresh applier adopts the Cloud uuid in place** for a local row under the Cloud's id whose uuid is `NULL` (an appliance
   upgraded with the fixed migration) or whose `edge_origin` is not `'local'` with any other uuid (the already-upgraded dev appliance:
   a Cloud-owned row with a locally minted/stale value). Same id, same row, `edge_origin → cloud`, no second row, no deletion, no
   deactivation. Applied symmetrically to `upsertCustomers` (a Cloud-origin customer with a stale `customer_uuid` adopts the Cloud's
   and the one soft uuid reference the appliance holds — `edge_local_table_reservations.customer_uuid` — is repointed).
3. **`REFRESH_CONFLICT` remains only for a real identity clash**: a LOCAL-origin row (`edge_origin = 'local'`) holding a different
   uuid under the Cloud's id. It still rolls the WHOLE refresh back and the revision does not advance (impossible with the reserved local
   id range `EdgeLocalCustomerService::LOCAL_ID_FLOOR`; modelled deliberately in the tests).
4. **The bootstrap importer needs no change**: `EdgeLocalBootstrapImporter` clears the config tables and inserts the package rows
   wholesale (Cloud ids + Cloud uuids); it has no uuid-vs-id check.

The rule is documented in the migration's class doc and in the applier's `upsertCustomers` / `upsertCustomerAddresses` docblocks.

## 3. Exposure analysis — every identity the applier matches by uuid

| identity | exposed? | why |
|---|---|---|
| `customer_addresses.address_uuid` | **YES (fixed)** | column + per-row backfill shipped with Phase 4 (2026_10_06) — the appliance had the rows (bootstrap v7 customer book since 25 Sep / W6) BEFORE the column existed, so schema-upgrade minted locally. |
| `customers.customer_uuid` | **no** | 2026_08_08_000010 backfilled it on the Cloud long ago; on an appliance that migration runs at `edge:local:db-init` on an EMPTY DB (nothing to mint); the customer book arrived with bootstrap v7 and v7/v8 export `customer_uuid`; the importer inserts it and the pre-Phase-4 applier updated the row by numeric id with every exported column (`mutable()` strips only `id`), so the appliance copy was always overwritten with the Cloud's uuid. A pre-v8 Cloud-origin row therefore carries the Cloud uuid and Phase 4's uuid match cannot conflict. A `NULL` was already adopted (pre-G3 branch). The same ownership rule is nevertheless implemented and proven for customers (test e) so a future stale value can never block a refresh. |
| `customer_uuid_aliases.alias_uuid` | no | Cloud-minted facts (the Cloud records the alias at ingestion); upserted/deleted by key, never minted on the appliance. |
| `edge_local_table_reservations.customer_uuid` | no (soft reference) | copied from the customer row at reservation time; repointed by `mergeLocalCustomer` (alias rule) and now also by `repointCustomerUuid` on a customer adoption. |
| `restaurant_table_sessions.session_uuid`, `sales_orders.sale_uuid`, lines, payments, KOT lines, approvals | no | appliance-born operational rows minted ONCE on the appliance and owned by it; the applier never touches them (operational state is out of the refresh). |

### Where could a locally minted/stale address uuid live before adoption? (brief item 3)

- `customer_addresses.address_uuid` itself — adopted in place. Complete.
- Sale envelopes / outbox: `EdgeSaleEnvelopeBuilder::customerIdentity` ships `address_uuid` ONLY for `edge_origin = 'local'` addresses
  (for a synced customer only its till-born ones; a local customer's addresses are all till-born). Those are minted at creation, come back
  from the Cloud under the same uuid and match by rule 1 — never adopted. A Cloud-origin address's uuid (NULL or skewed) is therefore
  never in an envelope. Proven impossible by construction; test (b) also makes an offline sale before the refresh.
- Reservations carry `customer_uuid` only (no address uuid). Sales snapshot the address TEXT (`delivery_address` style columns), not a uuid.
- Returnable-sale cache / recent-orders mirror (Gap C): keyed by `sale_uuid`, no customer/address uuid.
- Nothing else in `app/` references `address_uuid` (grep): `EdgeIdentity` registry, the model trait, the importer PLAN, the ingestion
  service (Cloud side, creates once by uuid) and the applier.

## 4. Files changed

- `database/migrations/tenant/2026_10_06_000001_edge_phase4_offline_customer_identity.php` — address backfill guarded by
  `! EdgeRuntime::isBranchServer()`; ownership rule in the class doc. Still additive/idempotent/re-runnable; the schema-upgrade path
  applies it cleanly on an existing appliance DB (proven).
- `app/Services/Edge/EdgeLocalConfigRefreshApplier.php` — `upsertCustomers` and `upsertCustomerAddresses`: conflict only for a
  local-origin row; Cloud-owned rows adopt the Cloud uuid in place (logged); new `repointCustomerUuid()` for the soft uuid references;
  docblocks carry the rule.
- `tests/MySql/EdgeCustomerRefreshMySqlTest.php` — four new tests (below).
- `tests/MySql/EdgeCustomerIdentityMigrationMySqlTest.php` — NEW: the migration on both sides through the real upgrader.
- this document.

Not touched: `C:\Users\Dell\BingooEdgeLab`, the dev servers' databases, the Gap C files (`EdgeReturnableSale*`,
`EdgeLocalOrderLifecycleService`, `EdgeLocalReturnService/Controller`, `POSController`, `EdgeLocalHeldSalesController`,
`app/Support/Pos/RecentOrders.php` and their tests).

## 5. Tests

`tests/MySql/EdgeCustomerRefreshMySqlTest.php` (ONE MySQL DB plays the Cloud, then the appliance; the REAL `buildSections`, the REAL applier):

| brief | test | proves |
|---|---|---|
| (a) | `test_a_cloud_origin_address_with_a_null_uuid_adopts_the_cloud_identity_on_refresh_and_keeps_its_id` | appliance rows with `address_uuid NULL` under the Cloud ids → refresh fills the Cloud uuids, ids kept, no duplicate, revision advances |
| (b) | `test_the_real_skew_a_cloud_origin_address_minted_a_different_uuid_on_the_appliance_adopts_the_cloud_identity_without_conflict` | the REAL skew: NULL rows on both sides, the migration's `up()` run as the Cloud (role cloud) and again on the appliance copy with the pre-fix behaviour (role cloud = per-row mint) → different uuids asserted; an offline sale and a till-born address exist; refresh revision 17 → 18 APPLIED, Cloud uuids adopted, till-born uuid kept, no duplicate / deletion / deactivation, customers untouched (they never skewed), replay no-op, next revision stable |
| (c) | `test_a_local_origin_address_holding_a_different_uuid_under_the_cloud_id_still_refuses_the_whole_refresh` | local-origin row, different uuid, same id → `REFRESH_CONFLICT: address id N …`, row unchanged, the revision's new customer NOT inserted (whole rollback), revision not advanced — plus the pre-existing customer variant `test_an_id_held_by_a_different_identity_refuses_the_whole_refresh` |
| (e) | `test_a_cloud_origin_customer_with_a_stale_uuid_adopts_the_cloud_identity_and_its_reservation_follows` | Cloud-origin customer with a stale `customer_uuid` + a reservation holding it → Cloud uuid adopted in place, active, one row, reservation repointed, address untouched |

`tests/MySql/EdgeCustomerIdentityMigrationMySqlTest.php`:

| brief | test | proves |
|---|---|---|
| (d) + schema-upgrade path | `test_the_schema_upgrade_on_a_branch_server_leaves_pre_existing_address_uuid_null_while_the_cloud_backfills_it` | a pre-Phase-4 appliance (migration `down()` + record removed, Cloud book present) upgraded through the REAL `EdgeLocalSchemaUpgrader` (what `edge:local:update` runs): the migration is the only pending tenant migration, applies cleanly (no `last_error`), column + nullable unique index + alias table present, every pre-existing `address_uuid` NULL, `phone_normalized` still backfilled; re-running `up()` on the appliance mints nothing and leaves a till-born uuid alone; the same `up()` as the Cloud mints a distinct ULID per row exactly once (generate-once on a re-run) |

The existing 8 tests of `EdgeCustomerRefreshMySqlTest` are unchanged and still green (the uuid-match, merge-away, alias and
import rules).

## 6. What the LAB update must do

**Nothing manual on the appliance data.** With the fixed build, `edge:local:update` → child-process `edge:local:schema-upgrade` applies
`2026_10_06_000001` on the LAB appliance leaving every existing `address_uuid` NULL; the first accepted v8 refresh
(`docs/status/edge-next-lab-update-checklist.md` §1 step 4) adopts the Cloud uuids by numeric id and the appliance becomes fresh. The
3-row `UPDATE … SET address_uuid` alignment that W15 had to do on the disposable dev appliance is not needed anywhere — and the dev
appliance, if it is kept, is also healed by its next refresh (its skewed rows are Cloud-origin → adopted).

**Checklist amendment (Team W's second item, dev-DB state, not code):** §1 step 1 says "run the tenant migrations on the LAB master/tenant
DBs" — the LAB MASTER must ALSO take `php artisan migrate --database=master` (default path `database/migrations`), which includes
`2026_09_27_000001_widen_edge_devices_version_columns` (the build-report columns). Without it the heartbeat's build report is
swallowed by the try/catch in `EdgeAuthorityLeaseService::recordBuildReport` and step 5's "LAB Cloud device page shows the new
version" cannot pass. The dev clone's master still has it pending — same fix there before any further W-series proof relies on the
version display.

Order unchanged: Cloud first (tenant + master migrations), appliance second; the appliance on 0.7.0 keeps refusing v8 packages
cleanly (`SCHEMA_UNSUPPORTED`) until it is updated.

## 7. Gate counts (_t17 databases; Feature suites on sqlite)

| gate | result |
|---|---|
| MySQL filter `EdgeCustomer\|EdgeConfigRefresh\|EdgeBootstrap\|EdgeCleanMachine\|EdgeLocalSchema\|EdgeSchemaUpgrade\|EdgeUpdate` on `pos_test_*_edgewt_t17` (includes the 4 new refresh tests + the new migration class; EdgeCleanMachineInstall ran, no skips) | **OK — 62 tests, 790 assertions** |
| `EdgeCustomerRefreshMySqlTest` + `EdgeCustomerIdentityMigrationMySqlTest` alone | OK — 12 tests, 116 assertions |
| `tests/Feature/Edge` (sqlite, EDGE_NODE_BIN set) | **OK — 175 tests, 37,026 assertions** |
| `tests/Feature/Pos` | **OK — 26 tests, 456 assertions** |
