# Edge development branch — rehearsal promotion record (16 Nov 2026)

Owner decision (16 Nov 2026): "Rehearsal head dc9f1cec accepted. APPROVE controlled CODE-ONLY promotion into the Edge
development branch, not the canonical production branch." This record is the evidence for that promotion, the three
migration reviews the owner asked for, and the prepared (not applied) canonical fix for `print_prepares`.

Standing rules kept: LAB 0.8.2-edge untouched and STANDBY_READY; no LAB MySQL freeze / switchover / takeover / handback;
no production deployment, no production-branch mutation; no transaction reset anywhere; no release built or installed.

## 1. Promotion

| item | value |
|---|---|
| rehearsal head (source) | `dc9f1cec` on `integ/canonical-rehearsal-082` (worktree `D:/laragon2/www/pos-saas-integ-082`, clean) |
| target Edge head before | `8874f342` on `feat/edge-config-refresh-v1` (= `origin/feat/edge-config-refresh-v1`) |
| merge-base | `8ec1d687` |
| target-only commits | one: `8874f342` (the F-1 gate line reshuffle — the identical hunk is in the rehearsal as `25fbb425`) |
| rehearsal-only commits | 316 (canonical 073238de..5c74107f integrated, the five catch-up chunks, the gate fixes, the Phase F report, the rebuilt system map) |
| dry run | `git merge-tree --write-tree 8874f342 dc9f1cec` → clean, tree `3c785732` |
| merge | `git merge --no-ff dc9f1cec` → **`e23f74d3`** (parents `8874f342` + `dc9f1cec`), tree `3c785732` — **identical to the rehearsal tree** (`git diff dc9f1cec e23f74d3` is empty), so the full gates recorded in `edge-agent-b-canonical-rehearsal.md` §12 apply to this tree unchanged and were not repeated |
| target changes lost | none — `8874f342` is an ancestor of `e23f74d3` |
| force push | no — normal merge commit, pushed with a plain `git push` |

Preserved behaviours, each with its proof on the merged tree:

| behaviour | proof |
|---|---|
| F-1 standby write gate (shared view: `POS.canMutate`, data-pos-gate buttons stay disabled, cart kept across authority transitions) | `tests/Feature/Edge` + `tests/Feature/Pos` 238/238 on `e23f74d3`; `PosFrontendRegressionTest` + `PosScriptScopeRegressionTest` 16/16 |
| ONE shared POS view (no Edge-only layout delta; `PosRuntime` route map; closed `PosPageData::KEYS`) | `SharedPosViewRenderTest` (in the 238) + `PosScreenRendersHttpMySqlTest` (focused MySQL run below) |
| Edge authority (lease, 422 refusals in standby) | `EdgeCashierAuthorityHttpMySqlTest` + the Feature/Edge authority classes |
| customer identity (offline add-customer, uuid origin) | `EdgeCashierCustomerIdentityMySqlTest` |
| H1 stable processing-shift identity | `EdgeCashierReturnsHttpMySqlTest` / return classes |
| C-2 return refusals (REFUSE_NOT_CAP) | `EdgeCashierDealsDiscountsHttpMySqlTest` + return classes |
| counter-wise shift isolation (SHIFT-LIST-COUNTER-1 security fix, chunk 2) | `EdgeShiftCounterScopeMySqlTest` 4/4 |
| manifest (10 canonical migrations classified, UNCLASSIFIED = 0, review flags kept) | `EdgeTenantMigrationManifestTest` + `EdgeTenantMigrationUpgradeMySqlTest` |

Focused verification on `e23f74d3` (isolated `*_integ` DB set on 3306, one process at a time):

| run | result |
|---|---|
| SQLite `tests/Feature/Edge` + `tests/Feature/Pos` | OK (238 tests, 42 979 assertions) |
| SQLite `tests/Unit` — PosFrontend / PosScriptScope / manifest | OK (16 tests, 93 assertions) |
| MySQL focused classes (manifest upgrade, counter scope, deals + C-2, ingestion, cashier screen, canonical alignment, shared view HTTP, returns, identity, authority, shifts) | 77 tests, 1046 assertions, 0 failures, exit 0 (12:57–13:00 UTC) |

## 2. Migration safety — the three non-additive items stay BLOCKED from release execution

"UNCLASSIFIED = 0 does not mean all migrations are owner-approved." Each of the three keeps `OWNER_REVIEW_REQUIRED => true`
in `database/migrations/edge-tenant-manifest.php`; no release containing them is built or installed until the owner approves
each one separately.

### Copy-of-LAB evidence (how it was produced)

- Source: the LAB **appliance** database `bingoo_edge_lab_local` on the shared instance (the schema an Edge release migrates),
  read only: `mysqldump --single-transaction --routines --triggers --events --hex-blob` at 2026-11-16 12:5x UTC → 630 610 bytes,
  0 bytes of errors, SHA-256 `daec0689…` (`C:\Users\Dell\BingooEdgeLab\evidence\migrev-20261116\`, plaintext LAB data inside the
  owner's profile — delete when no longer needed).
- Loaded into a NEW disposable schema `bingoo_edge_lab_local_migrev_1116` (209/209 tables). Nothing on `bingoo_edge_lab_local`,
  `pos_lab_*`, the 3317 instance or production was written.
- Applied with Laravel's migrator on the `tenant` connection pointed at the copy (the same migrator the appliance's
  `EdgeLocalSchemaUpgrader` uses: pending = files − recorded, one migration per batch), the executed statements captured from the
  connection query log; existing rows compared before/after on the **pre-migration column list** (`before-*.txt` / `after-*.txt`).
- Row facts on the LAB appliance copy: `purchase_bills` 0 rows, `receipt_layout_settings` 0 rows, `product_pack_units` 0 rows,
  `sales_order_lines` 25 rows (0 with a pack); the LAB **Cloud** tenant `pos_lab_tenant_edge` (read only): 0 / 0 / 0 / 20 (0 with a pack).
  So on the LAB no existing row is affected by any of the three; the "existing-row effect" lines below describe a populated tenant.
- Full log: `evidence\migrev-20261116\run-log.txt`; DDL: `pretend-ddl.txt` (pretend cannot run the guards) + the query-log lines in `run-log.txt`.

### R-1 `2026_10_23_000001_decimal_pack_sizes` (canonical 7308db7e, UNITS-PACKS F1) — approval WITHHELD

| aspect | evidence |
|---|---|
| exact DDL executed on the copy (283.87 ms total) | `SET SESSION lock_wait_timeout = 10` · `ALTER TABLE product_pack_units MODIFY factor DECIMAL(15,4) NOT NULL` (64 ms) · for each of `sales_order_lines` (94 ms), `sales_return_lines`, `goods_receipt_lines`, `purchase_order_lines`, `purchase_bill_lines`, `purchase_return_lines`, `stock_adjustment_lines`, `stock_transfer_lines`: `ALTER TABLE <t> ADD COLUMN pack_size DECIMAL(15,4) NULL, ALGORITHM=INSTANT` (6–43 ms) · `SET SESSION lock_wait_timeout = DEFAULT` |
| existing-row effect | none rewritten: `product_pack_units.factor` values are preserved by the INT→DECIMAL widening (unique `(product_id, factor)` kept — verified after); every line keeps `pack_factor`, `pack_size` is NULL on every existing line (readers take `pack_size`, else `pack_factor`); 25/25 LAB lines byte-identical before/after on the old columns |
| upgrade behaviour | guarded (`factorIsWhole()`, `hasColumn('pack_size')`): a second `up()` on the migrated copy executed only the two `SET SESSION` statements; the eight ADD COLUMNs are INSTANT (no table copy — on a Cloud tenant with 62 277 sale lines the type change on the tiny `product_pack_units` is the only non-instant statement); `lock_wait_timeout 10` so a live counter is never queued behind it |
| rollback | `down()` tested on the copy (146.75 ms): drops the eight `pack_size` columns and, because no fractional factor exists, `MODIFY factor INT UNSIGNED NOT NULL`; rows unchanged (0 diff lines); if a fractional pack existed the factor column would stay DECIMAL by design |
| non-additive part | the column TYPE change on `product_pack_units.factor` (MODIFY = table rebuild of a tiny table) — this is why it stays OWNER_REVIEW_REQUIRED |
| appliance need | `sales_order_lines` / `sales_return_lines` are operational on the appliance and the shared return arithmetic reads `CounterPacks::sizeOf`, so a 0.8.3 appliance needs the Cloud shape once the Cloud has it |

### `2026_11_03_000003_let_a_purchase_bill_be_voided` (canonical PURCHASE-EDIT-1) — purchase_bills status ENUM MODIFY — approval WITHHELD

| aspect | evidence |
|---|---|
| exact DDL executed on the copy (96.91 ms) | `SHOW COLUMNS FROM purchase_bills WHERE Field = 'status'` → `enum('draft','posted','paid','partial')` · `ALTER TABLE purchase_bills MODIFY COLUMN status ENUM('draft','posted','paid','partial','voided') NOT NULL DEFAULT 'draft'` (48 ms) · `ALTER TABLE purchase_bills ADD voided_at TIMESTAMP NULL` · `ADD voided_by_user_id BIGINT UNSIGNED NULL` · `ADD void_reason VARCHAR(255) NULL` |
| algorithm | appending a member at the END of an ENUM is metadata-only: the same statement with `ALGORITHM=INSTANT` succeeded on the copy; removing a member needs a rebuild (`ERROR 1846 … Need to rebuild the table`), which only matters for a rollback |
| existing-row effect | none: no value changes, the existing members keep their ordinal positions (append only), the three new columns are NULL; the migration rebuilds the ENUM from `SHOW COLUMNS` so a tenant whose list differs keeps its own list + `'voided'` |
| upgrade behaviour | guarded: `str_contains(Type, "'voided'")` and `hasColumn('voided_at')` — a second `up()` on the migrated copy executed only the `SHOW COLUMNS` read |
| rollback | `down()` is intentionally empty ("no safe rollback once a row is voided"): `migrate:rollback` only deletes the migrations row; the ENUM member and the three columns stay (verified) |
| non-additive part | the ENUM MODIFY — a column definition change, hence OWNER_REVIEW_REQUIRED even though it is append-only |
| Edge relevance | `EDGE_SCHEMA_SAFE`: purchase bills are Cloud screens (`PurchaseBill*` excluded from the package); no Edge code reads `purchase_bills.status` |

### `2026_11_02_000002_order_note_toggle_default_off` (canonical KOT-ORDER-NOTE-1 (2)) — data rewrite — approval WITHHELD

| aspect | evidence |
|---|---|
| prerequisite | `2026_11_02_000001_add_order_note_toggle` (additive): `ALTER TABLE receipt_layout_settings ADD show_order_note TINYINT(1) NOT NULL DEFAULT '1' AFTER show_bill_barcode` (17.55 ms) |
| exact DDL + DML executed on the copy (11.37 ms) | `ALTER TABLE receipt_layout_settings MODIFY show_order_note TINYINT(1) NOT NULL DEFAULT '0'` (6.5 ms) · `UPDATE receipt_layout_settings SET show_order_note = 0` (0.25 ms, 0 rows on the LAB) |
| existing-row effect | **every layout row is set to OFF** — this is the data rewrite. Canonical's justification (migration docblock): counter-typed order notes over 30 days on prod = 0 at every tenant except 6 automatic "Split from …" notes; the column had arrived the same morning so nobody had chosen ON. On a tenant where an operator HAS since turned it ON, the rewrite would turn it OFF again — that is the risk the owner is weighing |
| upgrade behaviour | on an appliance the upgrader never re-applies a recorded migration (pending = files − recorded). **Finding:** the migration's only guard is `hasColumn`, so a second `up()` called by hand re-executes the UPDATE (verified on the copy) — harmless through the upgrader, but not idempotent by itself |
| rollback | `down()` restores `DEFAULT '1'` only (verified, 39.92 ms); the rows stay OFF — the rewrite is not reversible by the migration |
| non-additive part | the DML rewrite (DATA_REWRITE = true) → OWNER_REVIEW_REQUIRED |
| Edge relevance | `EDGE_REQUIRED`: the Edge print worker renders the canonical KOT Blade from the replicated `receipt_layout_settings` row (chunk 3 added the column to the bootstrap section), so the appliance must carry the column with the same default as the Cloud |

Release rule in force: no Edge release containing any of these three is built or installed until each is approved by the owner.
The manifest flags enforce the review (`EdgeTenantMigrationManifestTest` asserts them).

## 3. PRINT_PREPARES — prepared canonical fix (NOT applied)

- Finding (rehearsal gate 2): canonical `2b054814` created `print_prepares` without classifying it in
  `TenantResetTransactionsCommand`; the fail-closed guard therefore refuses the reset ("not classified as wipe or keep:
  print_prepares"). The owner confirmed the fail-closed behaviour is correct.
- Prepared fix: `docs/canonical-fixes/0001-print_prepares-reset-classification.patch` — one line, classifies `print_prepares`
  as WIPE (agent-side PDF preparation cache, cascade FK to printers, cleaned daily; same nature as `print_jobs`). Built against
  canonical's own file (`git show 5c74107f:…`) and checked with `git apply --cached --check` on a temporary index of `5c74107f`:
  applies cleanly. Nothing was written to the production checkout; the production branch is unchanged.
- The same classification is already in the Edge tree (`25fbb425`, merged here), where `TenantResetFailClosedMySqlTest` is green.
- Not executed anywhere: no transaction reset on LAB or production.

## 4. State after this record

| item | value |
|---|---|
| LAB | 0.8.2-edge (a6113772) STANDBY_READY, health 200 over verified TLS, 7/7 workers, `secrets\admin.pass` still absent, 3317 prepared and idle, nothing frozen |
| production | branch `feat/14d-2-plan-upgrade-requests` head `5c74107f`, checkout untouched |
| rehearsal worktree | `dc9f1cec`, clean, branch not pushed (its content now lives in the Edge branch) |
| disposable artefacts left | schema `bingoo_edge_lab_local_migrev_1116` (migrated copy) and the dump + evidence folder above — delete on the owner's word |
