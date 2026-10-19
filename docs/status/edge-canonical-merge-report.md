# Canonical → Edge integration report (owner decision D8)

Date: 19–20 Oct 2026 · Team MERGE · worktree `D:\laragon2\www\pos-saas-edge`, branch `feat/edge-config-refresh-v1`.
Rule obeyed verbatim: canonical was merged INTO the Edge branch (never the other way), in ancestry order, one merge commit per
canonical merge point (small clean runs grouped, no conflicting point jumped), every conflict resolved by hand — canonical product
behaviour kept, Edge authority / sync / offline safety kept, ONE shared cashier view (no Edge POS copy resurrected: `resources/views/edge/pos/**`
stays absent, `EdgeSharedPosRegressionStaticGateTest` asserts it), no wholesale ours/theirs on any shared file. LAB, production and
the dev-server databases were not touched; the canonical checkout was read only (`git show` / `merge-tree` from the shared object store).
Nothing was pushed, nothing rebased.

## 1. Pre-mutation figures

| Item | Value |
|---|---|
| EDGE_HEAD before any mutation | `63748f87` (clean, pushed) |
| CANONICAL_REPO_HEAD (brief said `99e9365`) | **had moved** — `92b11486` at start (19 Oct); `8230eedc` when the session resumed (20 Oct); `ccc67fa1` when the last chunk was merged (re-checked immediately before every chunk). The actual head was used each time. |
| MERGE_BASE (`git merge-base`) | `b529c956` "Merge CATERING-RATE-HISTORY-1 + RATE-PARITY-1" |
| Canonical first-parent commits after the merge base | 179 (`b529c95..ccc67fa1`; 421 commits in all) |
| COMMITS_CANONICAL_AFTER_70c4e43 | 31 first-parent / 87 in all (`70c4e434..ccc67fa1`) |
| `git status` | clean before the first merge and after the last commit |
| Reconciliation inputs | `docs/status/edge-canonical-70c4e43-reconciliation.md` (5 textual + 6 semantic conflicts), `docs/status/edge-tenant-migration-manifest.md` §7/§10 (33 pre-classified migrations with `PENDING_MERGE_FROM`) |

Conflict scan method: before every chunk `git merge-tree --write-tree HEAD <sha>` was run over the remaining first-parent list; the
first conflicting point bounded the clean group before it.

## 2. Chunk ladder (merge commits by Team MERGE)

| # | Canonical merge point | Edge merge commit | Content | Textual conflicts |
|---|---|---|---|---|
| 1 | `bbb251d6` (35 fp commits: CATERING-ADOPT-PUNCHED-RATE-1 … REPORT-LINK-REDIRECT-1) | `f1793bc0` (+ fix `cabefd5d`: 10 markers dropped) | kitchen sheet A5/preview/density, KOT heading stars, reminder deal-name, customer balances/payments module, RATE-MODAL, QUICK-REPORT-WAITER-NULL-1, PRODUCT-TILE-SKU-1, WhatsApp report channel, GRN picker guard, catering edit-after-release/advance void | none |
| 2 | `64fd5cee` REPORT-CHANNEL-SETTINGS-1 | `49466ad4` | Quick Report button "Send to owner" | `tenant/pos/index.blade.php` (#qr-email) |
| 3 | `ca095657` (13 fp: REPORT-CHANNEL-SETTINGS-2 … AGENT-VERSION-TRUTH-1) | `b52c81c9` (+ fix `586dceb5`: 4 markers) | GRN extra charges/void, journal filters, WhatsApp template v2, kitchen sheet tags, catering send-to-printer | none |
| 4 | `6470be21` POS-REMINDER-PAUSE-1 | `1990e0aa` | reminder pause until shift close | `ShiftController.php` (use lines) + shared-view contract work |
| 5 | `0aae5ca4` (40 fp: GRN-CHARGES-VISIBLE-1 … CATERING-DOC-HEADER-1) | `1c85cb69` | payables/trial-balance, GL totals, PRINTER-FORM-PARITY-1, print agent 2.6.1–2.6.5, catering invoice void/A4/send, CANCEL-PAID-FREES-TABLE-1, WEBSITE-I18N-GEO-1 pieces, billing master migrations | none (+ Edge close-twin parity) |
| 6 | `7da4190d` MANUAL-JOURNAL-CASH-LINE-GUARD-1 | `64a26d4c` (+ fix `12afd313`: missing `use DB`) | R1–R3 cash-line guards, narration, reversal B1–B3, duplicate-submit | `Finance/ManualJournalController.php` (2 hunks) |
| 7 | `65e4461a` (12 fp: DASHBOARD-LAYOUT-1 … PNL-COST-BY-CATEGORY-1, online-ordering plans) | `106dc87e` | catering void buttons, supplier ledger labels, CASH-BOOK-VOID-1 + SUPPLIER-PAYMENT-VOID-1, P&L cost by category | none |
| 8 | `21c7be95` ONLINE-ORDERING-1 (36 direct commits + PRODUCT-LIST-EVERY-KIND-1) | `2b497156` | ordering website, delivery areas, POS website-orders inbox, WhatsApp, 1% billing, own domain | `POSController.php`, `AppServiceProvider.php` + shared-view contract work + artifact exclusions |
| 9 | `70c4e434` (8 fp: DELIVERY-PLACES-1 … PARTNER-SHARE-1) | `408a0990` (+ fix `a850aead`: Edge quick-report partner_share guard) | delivery places/radius, kitchen sheet foot, DASHBOARD-BILLING-ONE-BOX-1, BILLING-TENANT-CYCLE-1/HIDE-VOID-1, PARTNER-SHARE-1 | none |
| 10 | `a9499a10` PHARMACY-DEMO-1 | `9abf7b5a` | pharmacy demo, kitchenTickets prompt rule, returned medicine back to its batch | `POSController.php` |
| 11 | `40f7d6f0` (24 fp: SYSTEM-MAP-1 … DEPLOY-ATOMIC-CACHE-1) | `24c2a367` | system map + CLAUDE.md rule, PRIMARY-BUSINESS-1 + PRODUCT-SCREEN-V2/TABS/PARITY, MASTER-UNIVERSE-1, NEW-TENANT-OWNER-PERMS-1, USAGE-SNAPSHOT-TIME-1, PHARMACY-PACKS-1, REPORT-DELIVERY-V2-1, DOC-TOOLBAR-OFF-PAPER-1, PURCHASING-V2-1/2, PURCHASE-RETURN-V2, PURCHASE-DISCOUNTS-1 | none (+ 3 new manifest entries) |
| 12 | `b76fd581` MEDICAL-COUNTER-1 | `72aaa14c` | medical-store counter on the same POS (Master switch) | `POSController.php` (2 hunks) |
| 13 | `8230eedc` (MEDICAL-COUNTER-KEYS-1 + BILL-BARCODE-1 schema) | `c5ae8856` | shortcut keys, `show_bill_barcode` column | none |
| 14 | `ccc67fa1` (BILL-BARCODE-1 + MEDICAL-COUNTER-KEYS-2, MEDICAL-COUNTER-UX-1, REFUND-SHIFT-1 schema) | `4366b47c` | held-bill scan at billing, bill/KOT barcode (layout switch OFF), `sales_returns.shift_id/terminal_id` | none (+ one Cloud literal re-routed) |

**MERGED_HEAD = `4366b47c`** · **CANONICAL_HEAD_INCLUDED = `ccc67fa1`** (`git merge-base --is-ancestor ccc67fa1 HEAD` = yes; the merge
base of the two branches is now `ccc67fa1`). Canonical is being committed to continuously; anything after `ccc67fa1` is NOT included.

Per chunk: `php -l` on every touched PHP file (0 failures), `tests/Feature/Edge` + `tests/Feature/Pos` + the Blade compile gates
(`EdgeBladeCompileGateTest`, `CompiledBladeSyntaxTest`) + `PosFrontendRegressionTest` + `PosTileSkuRuleTest`: **240 tests green after
every one of the 14 chunks** (4 skipped = pre-existing environment skips). No conflict marker was ever committed or left on disk.

## 3. CONFLICTS_RESOLVED (every conflicted hunk)

| FILE | CANONICAL_INTENT | EDGE_INTENT | RESOLUTION | TEST_PROVING_CANONICAL_BEHAVIOUR | TEST_PROVING_EDGE_BEHAVIOUR |
|---|---|---|---|---|---|
| `resources/views/tenant/pos/index.blade.php` — `#qr-email` (chunk 2) | REPORT-CHANNEL-SETTINGS-1: button reads "Send to owner" with `ti-send` | capability gate `@disabled(! $posRuntime->can('quickReportEmail'))` + runtime hint | both: canonical label + icon, Edge gate kept | `ReportChannelSettingsMySqlTest` ("Email to owner" absent) | `EdgeSharedPosRegressionGateMySqlTest` (quickReportEmail control disabled + hint on Edge), `SharedPosViewRenderTest` |
| `app/Http/Controllers/Tenant/ShiftController.php` — use block (chunk 4) | `use App\Services\Printing\ReminderPauseService;` + `posReminderPause()` + `reminder` badge key | `use App\Services\Sales\CashCountService;` (W4 shared denomination count) | both use lines; both methods auto-merged | `PosReminderPauseMySqlTest` (18), `PosScreenRendersHttpMySqlTest` | `EdgeCashierShiftParityHttpMySqlTest`, `EdgeShift*` (run D) |
| `app/Http/Controllers/Tenant/Finance/ManualJournalController.php` — hunk 1 `reverse()` (chunk 6) | `JournalEntry::whereKey(..)->lockForUpdate()` inside a transaction before `$this->journal->reverse` + inline cash/subledger reversal | ONE official path `ManualJournalService::reverse()` (GL + cash/bank counter-movements + supplier subledger mirror — the same authority the Edge supplier-finance ingestion posts through) | canonical lock ENFORCED (transaction + `lockForUpdate` on the entry) around Edge's `ManualJournalService::reverse`; `use DB` added (fix `12afd313`) | `ManualJournalCashLineGuardMySqlTest::test_reverse_do_bar_dabane_par_ek_hi_reversal`, `::test_purani_ghalat_entry_reverse_hoti_hai…`, `CashBookVoidMySqlTest::test_manual_journal_ka_reverse_bhi_cash_book_par_lautata_hai` (25/25 green) | `SupplierFinanceDirectMySqlTest::test_reversal_gl_aur_subledger_dono_theek_ek_bar_palatta_hai` (32/32), `EdgeSupplierFinance*` (run D) |
| same file — hunk 2 helper block (chunk 6) | new `guardNarration()` + `guardCashLines()` (R1–R3, R5) called from `validateData()`; old inline helpers still present | old inline helpers (`normalizeLines`/`syncCashBankLines`/`reverseCashBankLines`/`nextManualJournalId`) removed (moved into the service) | canonical guards kept and called; the old helpers stay removed; `store()` keeps `ManualJournalService::post` | `ManualJournalCashLineGuardMySqlTest` (R1/R2/R3/R5 HTTP tests) | `EdgeSupplierFinanceHttpMySqlTest` (service path) |
| `app/Http/Controllers/Tenant/POSController.php` — recipe line (chunk 8) | `$this->recipeAvailability(...)` + ONLINE-ORDERING-1 comment + `->filter(fn ($g) => $g->showsOnPos())` | `$recipeAvailability->forProduct(...)` (shared `App\Support\Pos\RecipeAvailability`, W-G1) | Edge line + canonical comment + canonical filter; the same `channel != 'website'` rule added to the Edge menu builder (`EdgeLocalPosController::menuPayload`) and store (`EdgeLocalPosService::resolveModifiers`) | `PosScreenRendersHttpMySqlTest::test_a_website_only_add_on_group_never_reaches_the_till` | `EdgeCashierMenuHttpMySqlTest`, `EdgeSharedPosContractMySqlTest` (run D) |
| `app/Providers/AppServiceProvider.php` — `register()` (chunk 8) | `$this->app->scoped(BillingRates::class)` | `EdgeBackupKeyProvider → ConfigEdgeBackupKeyProvider` binding | both bindings | `BillingRatesScreenMySqlTest` | `EdgeArtifactBootTest`, `EdgeApplianceDependencyClosureTest` |
| `app/Http/Controllers/Tenant/POSController.php` — view array close (chunk 10) | `'kitchenTickets' => $this->kitchenTicketsOn(), ]);` | `])->toViewData($posRuntime));` (closed `PosPageData` contract) | canonical key inside Edge's closed block; `PosPageData::KEYS += kitchenTickets`; Edge twin `EdgeLocalPosController::kitchenTicketsOn()` | `PharmacyDemoMySqlTest` | `PosRuntimeTest::test_pos_controller_index_passes_exactly_the_page_data_keys`, `SharedPosViewRenderTest`, `EdgeSharedPosViewMySqlTest` |
| `app/Http/Controllers/Tenant/POSController.php` — products closure `use (...)` (chunk 12) | `use (…, $unitConversion, $medicalCounter, $batchBalances, $medToday)` | `use (…, $recipeAvailability, $posRuntime)` | Edge vars + canonical medical vars (`$unitConversion` unused on the Edge RecipeAvailability path) | `MedicalCounterMySqlTest` | `EdgeCashierMenuHttpMySqlTest`, `PosRuntimeTest` |
| same file — view array close (chunk 12) | `'medicalCounter' => $medicalCounter, ]);` | closed `PosPageData` block | canonical key inside the block; `PosPageData::KEYS += medicalCounter`; Edge twin passes `false` | `MedicalCounterMySqlTest` | `SharedPosViewRenderTest`, `EdgeSharedPosRegressionGateMySqlTest` |

Auto-merged shared files eyeballed after each chunk: `.env.example`, `.gitignore`, `bootstrap/app.php` (middleware chain: Edge loopback
`trustProxies` + canonical `encryptCookies(except CookieConsent)` + new aliases), `TenantProvisioner` (Edge catalogue-driven Cashier (Counter)
template + canonical permissions/sections + Owner gets every route permission), `tenant/pos/partials/table-board.blade.php`
(Close-when-all-paid), `printing/documents/kot.blade.php` (heading stars + Edge mark-printed URL), `reports/center/print.blade.php`
(PARTNER SHARE block), `SalesReturnService` (PHARMACY-DEMO-1 `batchTheSaleTookFrom` beside the Edge security contract).

## 4. Semantic conflicts — what was done on the Edge side (the shared-view contract)

Owner rule applied throughout: **no Cloud literal in the shared view**; every new endpoint rides `PosRuntime` (Cloud factory: the literal
tenant path, capability ON; Edge factory: `null` + capability OFF + hint, same control in the same slot); page data stays the closed
`PosPageData` contract both runtimes fill.

| Canonical change | Edge-side change in this merge | Handed to |
|---|---|---|
| POS-REMINDER-PAUSE-1 (`#pos-reminder-toggle`, `fetch(url('/api/pos/reminder-pause'))`, `reminder` badge key) | `PosRuntime::ROUTE_KEYS/CAPABILITY_KEYS += reminderPause`; Cloud `/api/pos/reminder-pause`; Edge `null` + off + hint; view `fetch(POS.route('reminderPause'))`, toggle `@disabled` + hint like `#qr-email`, JS guard; `EdgeLocalShiftController::shiftStatus` carries `reminder => null`; `PosRuntimeTest` + gate map | **D2**: build the twin (`/edge/local/api/pos/reminder-pause` via the shared `ReminderPauseService`, `statusFor()` on the badge, capability ON) |
| ONLINE-ORDERING-1 POS inbox (`@include website-orders` behind `OnlineOrdering::enabledForTenant()`, partial with `url('/api/pos/website-orders')`, `url('/api/pos/held-sales')`, `url('/pos')`) | keys `websiteOrders/websiteOrderHold/websiteOrderVerify` + capability `websiteOrders`; Cloud literal paths; Edge `null` + off + hint; the bell slot renders for a plan with Online Ordering on the Cloud and the SAME slot disabled+hint on a runtime without the capability (`$websiteOrdersSlot`); the partial takes every URL from `POS.route()` and stops when the route is null; gate map + fixture attach the `online_ordering` module; artifact excludes the Cloud-only domain | **D4** (decision recorded: Cloud-only; explicit capability, no accidental binding gate) |
| ONLINE-ORDERING-1 `showsOnPos()` | Edge menu + store apply `channel != 'website'` (null = pos) | — done |
| CANCEL-PAID-FREES-TABLE-1 (board Close on all-paid, JS reads `{ok:false,message}`) | Edge close twin answers `ok:true/false` + the Online refusal text; the Edge void path already runs the REAL `KotCancellationService` (running-only release) | — done |
| PARTNER-SHARE-1 (report section; `share_partners`/`share_rates` CLOUD_ONLY → absent on the appliance) | `EdgeQuickReportController::CLOUD_ONLY_SECTIONS = ['partner_share']` — never computed on the appliance (box stays in the modal) | **D6**: bootstrap sections vs hide by capability |
| MANUAL-JOURNAL-CASH-LINE-GUARD-1 | guards stay in the controller (canonical place); lock enforced around the service | **D1**: move R1–R3/narration + lock into `ManualJournalService` so the Edge offline General Journal twin and the ingestion enforce the same rule |
| PHARMACY-DEMO-1 `kitchenTickets` | `PosPageData` key; Edge twin = KOT-capable printer OR a configured dine-in board (the two bootstrap signals the appliance has; it carries no plan-module list) | — done (verify on a medical tenant's appliance later) |
| MEDICAL-COUNTER-1 / -KEYS-1/-2 / -UX-1 (packs per line, batch first-out, Rx check, shortcut keys) | `PosPageData` key `medicalCounter`; Edge twin passes `false`; no new route (pack/batch fields ride the existing sale/held/split payloads — the Edge sale store twin ignores them); held-bill scan literal re-routed to `POS.route('heldList')`; manifest entries for `product_pack_units`, `add_sale_line_batch`, `add_refund_shift_to_sales_returns` | **NEW (MEDICAL-COUNTER Edge twin decision)**: a medical tenant's Branch Server needs TenantFeatures from the Cloud (bootstrap section or heartbeat flag), `product_pack_units` + batch balances as bootstrap/refresh sections, pack/batch fields in the Edge sale/held/return twins and in the sale envelope (v4?), REFUND-SHIFT-1 behaviour mirrored in the Edge return twin |
| PURCHASING-V2-1/2, PURCHASE-RETURN-V2, PURCHASE-DISCOUNTS-1 (Cloud purchasing screens, Master switch) | manifest: `add_purchase_discounts` EDGE_REQUIRED (products bootstrap columns); purchasing screens are Cloud-only (excluded module globs `Purchase*`/`Supplier*`/`GoodsReceipt*` already); Edge supplier-finance twin untouched | **NEW (PURCHASING)**: confirm the next bootstrap refresh carries the new `products` columns (upgrade proven); Edge purchase-return twin vs PURCHASE-RETURN-V2 batch-picked returns — follow-up review |
| REPORT-DELIVERY-V2-1 (`ShiftCloseReportTrigger` on Online shift close) | CLOUD_ONLY migration; the Edge shift twin never sends reports | — none |
| PRODUCT-SCREEN-V2 `generic_name` | `products` bootstrap column (EDGE_REQUIRED, upgrade proven); shared search reads `product.generic` | — done |
| `tenant.pos.pause-reminders` permission | the view carries no `@can` on it (server-side check only) → `EdgePermissionCatalogTest` stayed green | **D3**: catalogue it for new tenants' Cashier (Counter) template or declare Cloud-only |
| SYSTEM-MAP-1 (CLAUDE.md standing rule) | map rebuilt from the `_t20` test DBs after the merge (see §7) | — |

## 5. Tenant-migration manifest (D7) after the merge

- All **33** `PENDING_MERGE_FROM => '99e9365'` markers dropped as each chunk landed its files (chunks 1, 3, 4, 5, 7, 8, 9, 11); the real
  `up()` was compared with the pre-classification every time — one correction (`add_reminder_pause_to_shifts` does not touch
  `model_has_permissions`); **no entry changed class**.
- **6 canonical migrations post-date 99e9365** and were classified by the D7 rules when they landed (`docs/status/edge-tenant-migration-manifest.md` §11):
  `add_send_trigger_to_report_schedules` CLOUD_ONLY; `create_product_pack_units`, `add_purchase_discounts`, `add_sale_line_batch`,
  `add_bill_barcode_toggle`, `add_refund_shift_to_sales_returns` EDGE_REQUIRED — each with an upgrade test.
- Manifest `edge-tenant-migration-manifest@2026-10-20.1`, `meta.merged_canonical = ccc67fa1`; **212 entries** (EDGE_REQUIRED 121,
  EDGE_SCHEMA_SAFE 67, CLOUD_ONLY 24); `EdgeTenantMigrationManifestTest` 13/13 green.
- **`EdgeTenantMigrationUpgradeMySqlTest` on `_t20`: the 10 previously skipped upgrade tests RUN and pass**, plus the 5 new ones and the
  naming gate — 15/15 after chunk 13; 16/16 in run D (see §6).
- Artifact: `config/edge.php` excludes the ONLINE-ORDERING-1 Cloud-only domain (`app/Services/OnlineOrdering`, `Http/Controllers/Website`,
  `Http/Controllers/Tenant/OnlineOrdering`, `OnlineOrdering*` commands, `website` + `online-ordering` views) — required by the manifest's
  `AreaLocator` CLOUD_ONLY dependency; `EdgeArtifactTest`, `EdgeApplianceArtifactBoundaryTest`, `EdgeApplianceDependencyClosureTest`,
  `EdgeArtifactBootTest` green.

## 6. Suites run (isolated DBs `pos_test_master_edgewt_t20` / `pos_test_tenant_edgewt_t20` / `pos_test_edge_local_edgewt_t20`; `_t21` for reproductions)

| Run | Filter / suites | Result |
|---|---|---|
| Per chunk ×14 (SQLite) | `tests/Feature/Edge` + `tests/Feature/Pos` + `CompiledBladeSyntaxTest` + `PosFrontendRegressionTest` + `PosTileSkuRuleTest` | 240 tests, 0 failures each time (4 skipped) |
| A (MySQL, at `c5ae8856`) | `EdgeTenantMigrationManifest\|EdgeTenantMigrationUpgrade` | 15 passed, 0 skipped, 0 failed |
| B (MySQL, at `c5ae8856`) | 40 canonical classes: Billing{MonthlyInvoice,RatesScreen}, CancelFreesTable, CashBookVoid, CateringKitchenSheet{Density,Language,Preview}, CustomerPaymentsModule, CustomerSearchPunctuation, Grn{BillCharges,ChargesVisible,ExtraCharges,NonStock,PickerGuard,Void}, ManualJournalCashLineGuard, MedicalCounter, OnlineOrdering×10, PartnerShare, PharmacyDemo, PosQuickReport, PosReminderPause, PosScreenRendersHttp, PrintAgentDownloadTruth, PrinterFormParity, ProductScreenV2, PurchasingV2, ReportDeliveryV2, SupplierFinanceDirect, SupplierRunningAccount, TableCloseEmpty | 389 run: 376 passed, 13 failed — 4 were merge-induced (missing `use DB` in the chunk-6 resolution → fixed in `12afd313`, re-run green: ManualJournalCashLineGuard + CashBookVoid 25/25, SupplierFinanceDirect 32/32), 9 are the EXPECTED environmental reds below |
| C (MySQL, at `c5ae8856`) | `EdgeSharedPos\|EdgeCashier\|EdgeLocalPos\|EdgeHeld\|EdgeLocalRestaurant\|EdgeReturn\|EdgeShift\|EdgePrint\|EdgeManagerApproval\|EdgeCustomer\|EdgeRecentOrders\|EdgeBootstrap\|EdgeConfigRefresh\|EdgeTenantMigration\|EdgeArtifact\|EdgeAppliance\|EdgeUpdate` | **291 passed, 0 failed, 0 skipped** |
| D (MySQL, at MERGED_HEAD `4366b47c`) | `EdgeTenantMigration\|EdgeReturn\|EdgeCashier\|EdgeSharedPos\|EdgeHeld\|EdgeLocalPos\|EdgeSupplierFinance\|EdgePurchaseReturn\|EdgeShift\|EdgePrint\|BillBarcode\|MedicalCounter\|ManualJournalCashLineGuard\|CashBookVoid\|SupplierFinanceDirect\|PosScreenRendersHttp\|PosReminderPause\|CancelFreesTable\|TableCloseEmpty\|PosQuickReport` | **352 passed, 0 failed, 0 skipped** (incl. EdgeTenantMigrationUpgradeMySqlTest 16/16, ManualJournalCashLineGuard + CashBookVoid + SupplierFinanceDirect green after the fix) |

**Expected reds (not merge-induced, named):**
- `BillingMonthlyInvoiceMySqlTest` (8 errors): `setUp()` does `Tenant::first()` on the master DB — on a fresh isolated master (`_t20`) no
  tenant row exists at that point → `TypeError: Cannot assign null`. Fixture/order dependency of the canonical harness (it passes where the
  shared master DB already holds tenants). Same before/after the merge.
- `CustomerPaymentsModuleMySqlTest::test_the_module_claims_the_route_key_that_the_routes_carry`: `route_catalogs` is populated by
  deploy / Master "Sync" only — empty on the isolated master → "customer-payments ke routes catalog me hone chahiyen". Environmental.
- `EdgePermissionCatalogTest` did NOT go red on `tenant.pos.pause-reminders` (the view has no `@can` on it) — D3 remains a policy decision,
  not a failing test. No pixel/census pins were run (D5: re-capture the Online baselines first — the shared view changed in >12 canonical commits).

Not run (out of scope / long): the full Edge MySQL gate beyond the filters above (e.g. `EdgeBackup*`, `EdgeConnectionPartition`,
`EdgeCleanMachineInstall`), the browser proofs, the pixel pairs, the appliance schema dry-run on a 0.7.0-shaped disposable DB (D7: 39 new
tenant migrations are now pending on the LAB — raw ENUM `ALTER`s on `printers`, `goods_receipts`, `sales_orders`; `ALGORITHM=INSTANT` adds
on the line tables).

## 7. System map (CLAUDE.md standing rule)

`php tools/system-map/build.php --tenant-db=pos_test_tenant_edgewt_t20 --master-db=pos_test_master_edgewt_t20` was run after the merge and
committed with this report (`docs/system-map/system-map.json` + `index.html`). The artifact link in CLAUDE.md was not republished from this
session (no Artifact publish rights assumed); the committed `index.html` is the source of truth.

## 8. Items handed over

| Team | Item |
|---|---|
| D1 | Move `guardNarration()`/`guardCashLines()` (R1–R3, R5) and the reverse `lockForUpdate` from `ManualJournalController` into `ManualJournalService` (Online, Edge offline General Journal twin and the Cloud ingestion enforce ONE rule); extend `EdgeSupplierFinanceHttpMySqlTest` for a refused drawer-on-wrong-line journal. |
| D2 | Reminder pause twin: `/edge/local/api/pos/reminder-pause` → `EdgeLocalShiftController::posReminderPause` via the shared `ReminderPauseService`; `shiftStatus` returns `statusFor()`; `EdgePosRuntimeFactory` capability `reminderPause` ON + route; drop the hint; `EdgeCashierShiftParity` test. The shared print plumbing already honours a paused shift row. |
| D3 | `tenant.pos.pause-reminders` in `PosPermissionCatalog::cashier()` (new tenants' Cashier (Counter) template) or declared Cloud-only. |
| D4 | Website orders: recorded as Cloud-only (`websiteOrders` OFF, same slot disabled + hint, partial stops without a route). Decide whether a standby read-only inbox is ever wanted (website orders need the Cloud). |
| D5 | Re-capture the Online pixel baselines, then the Edge pairs (reminder toggle both states, website-orders slot with/without the module, all-paid board, SKU tiles, `#qr-w-none`, the medical counter OFF page). |
| D6 | Partner share on the appliance: ship `share_partners`/`share_rates` as bootstrap/refresh sections (bumps bootstrap `SCHEMA_VERSION`) or disable the section by capability; the interim `CLOUD_ONLY_SECTIONS` guard in `EdgeQuickReportController` goes away either way. |
| D7 | Appliance schema dry-run + update/rollback verdicts for the next package (39 pending tenant migrations on the LAB); `edgeSchemaVersion()` policy unchanged (still derived from `migrations/edge`). |
| NEW — MEDICAL-COUNTER | Edge twin decision for a medical tenant's Branch Server: TenantFeatures (`medical_counter`, `pack_units`) from the Cloud, `product_pack_units` + batch balances as sections, pack/batch/Rx fields in the Edge sale/held/return twins and in the sale envelope + Cloud ingestion whitelist, REFUND-SHIFT-1 (`sales_returns.shift_id/terminal_id`) mirrored in the Edge return twin. Until then `medicalCounter=false` on every appliance. |
| NEW — PURCHASING-V2 | Cloud-only screens; confirm the Edge purchase-return twin against PURCHASE-RETURN-V2 (returns from the batch picked, bought-in unit); the `products` discount columns reach the appliance by the proven upgrade + the next refresh. |

## 9. Not completed / caveats

- Canonical moved three times during the work (92b1148 → 8230eedc → ccc67fa1); everything up to `ccc67fa1` is included, later commits are not.
- Run B's environmental reds (§6) were not made green — they need a seeded tenant row / route catalog in the isolated master DB, which is
  harness work, not merge work.
- The chunk-6 resolution shipped once without `use Illuminate\Support\Facades\DB;` (`php -l` cannot catch a missing import) — caught by run B,
  fixed in `12afd313`; the three affected canonical suites are green on `_t21` and re-run in D.
- No LAB, production or dev-server database was touched; the dev instances (:8095/:8096/:9704) serve the merged tree.
