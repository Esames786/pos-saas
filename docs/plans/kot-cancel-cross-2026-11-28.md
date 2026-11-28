# KOT-CANCEL-CROSS-1 — crossed cancel heading, bigger order type and TABLE lines (28 Nov 2026)

## Ask

Khatri's owner, 28 Nov 2026, marked up a real cancel ticket (order HS-20261128101254-292, jobs #152481 / #152493):

- put big crosses beside the cancel heading;
- make the order type line (DINE IN / TAKEAWAY …) a bit bigger;
- make the TABLE line a bit bigger.

He approved a before/after mock-up built from the real printer bytes (https://claude.ai/artifact/DBaZYA5bviuanyWTb3G12q)
and said "apply kar k deploy kardena". Nothing like a cross existed before (code, layout settings and branches were searched).

Why it matters: a cancel ticket differed from the order's own ticket only by the word CANCEL, at the size of every other big
line, and its item rows are identical. In a rush the kitchen can cook it again.

## What changes

Three KOT layout settings (Edit Layout → KOT), all OFF / blank by default, so every other business prints today's ticket byte
for byte:

| Setting | Column | Paper when set |
|---|---|---|
| Cancel KOT: big XX CANCEL KOT XX heading | `show_cancel_cross` BOOLEAN DEFAULT 0 | a cancel ticket's heading prints `XX CANCEL KOT #n XX`, at least double size (2x2; a 3x3 KOT font stays 3x3); replaces the stars on a cancel ticket |
| Order Type Font (px) | `order_type_font_size` TINYINT NULL | the DINE IN / TAKEAWAY / DELIVERY / QUICK SALE line at its own band (19 = double size, 21 columns) |
| Table Font (px) | `table_font_size` TINYINT NULL | the TABLE line at its own band |

The normal / addition / duplicate headings, the waiter line and the item rows do not change.

## Impact (system map, `docs/system-map/index.html`)

- **Table:** `receipt_layout_settings` (+3 nullable / default-0 columns). Read by 71 tenant routes (POS, restaurant board,
  printing jobs, print agent, reports' ESC/POS, offline-edge via EdgeBootstrapService). No route reads them except through
  `EscPosPayloadService::kot()`, `kot.blade.php` and the layout screen.
- **Paper:** every KOT built by `PrintJobService::queueKot()` / `queueCancellationKot()` → `EscPosPayloadService::kot()` →
  `raw_payload` → print agent (unchanged bytes for any branch that sets nothing).
- **Screens:** Printing → Layouts (Edit Layout modal: two KOT-only size boxes, one KOT-only switch; the live preview shows a
  cancel ticket while the switch is on); the browser KOT / Print Here (`PrintDocumentController::preview` → `kot.blade.php`).
- **Edge:** manifest entry `2026_11_28_000001_add_kot_cancel_cross_and_line_sizes` = `EDGE_SCHEMA_SAFE` (carried, ships and
  runs in the next package). Deliberately **not** added to the `EdgeBootstrapService` receipt_layout_settings column list:
  `EdgeLocalConfigRefreshApplier::upsertSection()` updates a row with every key it receives, and Khatri's installed 0.9.0
  appliance has no such columns, so listing them would fail its config refresh. Until the Edge owner adds them (after every
  paired appliance runs a package carrying the migration) the Branch Server prints today's KOT — it reads the settings
  null-safe.
- **No** new route, permission, Master switch, queue or money/stock/GL path.

## Deploy safety

- The paper and the blade read the new values null-safe: a row from before the migration prints today's ticket.
- `ReceiptLayoutController::store()` writes a blank box / unticked switch only when it clears a saved value, so a layout save
  in the deploy's pull → migrate second never names a missing column (proved by a test that drops the columns and saves).

## Tests

`tests/MySql/KotCancelCrossMySqlTest` (8): nothing set = today's ticket; sizes make the two lines 2x2 (centred in 21 columns,
waiter / heading / item rows unchanged); the cross marks only the cancel ticket (and replaces stars); a 3x3 KOT font keeps
3x3; browser KOT + live preview follow the same settings; the layout screen offers / saves / clears them on the KOT only;
before the columns exist tickets print and saves work; the bootstrap does not send the columns yet.
Plus `EdgeTenantMigrationUpgradeMySqlTest::test_kot_cancel_cross_and_line_sizes_upgrade_an_older_appliance` and the manifest
count (239).

Sabotage (each turned the right test red, the others stayed green): order-type size ignored; table size ignored; cross
disabled on paper; cross disabled in the blade; the save guard without the switch (Unknown column in the deploy second); the
column added to the bootstrap list.

Results (28 Nov 2026, worktree `pos-saas-kotx`, test DBs `pos_test_{master,tenant_edge,edge_local}_kxc`):

- `KotCancelCrossMySqlTest` 8/8 (68 assertions).
- Related MySQL classes (Kot|Layout|Thermal|Bill|Print|Receipt|Reminder|EdgeTenantMigration|EdgeCanonicalAlignment|EdgeLocalPrint|EdgeBootstrap|EdgeConfigRefresh|EdgeLocalImport): 452 tests, 450 green; the 2 red ran green alone (17/17): `EdgeBootstrapV7` = a catering FK left by an earlier class in the same run, `CateringDocumentPrint` = empty route catalog on a fresh master (`system:routes-sync`).
- A first run named the tenant test DB without "edge": the Edge upgrade tests drop columns to fake an old appliance, the DB-name guard refused the upgrade, and 129 later tests failed on the missing columns. Not this change — rerun with Edge-valid names.
- Fast suite (sqlite): 663 tests, 657 green; the 6 red are red on the base commit 2c447221 too (catering sidebar, closure capture in SupplierController, report payload, agent download x2, rider reassignment).

## Rollout

After deploy, Khatri only (KOT layout row, branch 1): `show_cancel_cross = 1`, `order_type_font_size = 19`,
`table_font_size = 19`. The owner or client can change or clear them in Edit Layout → KOT. Every other tenant stays as is.

## Live — 28 Nov 2026 ~11:38 UTC (4:38 PM PKT), prod `c4978a53`

- `bash deploy.sh` exit 0 (log `/root/deploy-kot-cancel-cross-2026-11-28-1138.log`).
- Permissions: 14 snapshots (master + 13 tenants) before = after, 0 lost, 0 gained. Smoke 13/13 the same (`tb_diff=0`,
  `neg_on_disallowed=0`, `department_negative=0`). Chart of accounts identical on all 13. Log ERRORs today 29 → 29.
- Columns present on all 13 tenants; no row used them before the Khatri change.
- Khatri (khatribiryani) KOT layout #1, branch 1: `show_cancel_cross=1`, `order_type_font_size=19`, `table_font_size=19`
  (11:39:49 UTC). Before-backup of every Khatri layout row on the laptop (session scratchpad `kb_layout_before_20261128.json`).
  Every other tenant stays OFF / blank.
- Proof (in memory, job rows untouched): the real order HS-20261128101254-292 re-rendered — cancel job #152493 prints
  ` XX CANCEL KOT #2 XX` at 2x2, both jobs print `DINE IN` and `TABLE: 2` at 2x2; waiter, items and the normal heading as before.
- Branch Server (0.9.0, status ready) pulled config/refresh at 11:40:27 → 200 after the change; heartbeat 200. It is not sent the
  new columns (by design, see Edge above) and prints today's KOT if it takes over.
