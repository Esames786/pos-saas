# Offline Edge — W6 (Team 6) wave 2 report: canonical reconcile, Cloud/Edge contract code, 0.7.0-edge release prep

Date 25 Sep 2026. Worktree `D:\laragon2\www\pos-saas-edge`, branch `feat/edge-config-refresh-v1`. Design source =
`docs/status/edge-w6-contract-and-reconcile-plan.md` (wave 1). Nothing was pushed, built, signed or installed; the custody keystore,
`C:\Users\Dell\BingooEdgeLab`, every `pos_tenant_*` / `pos_saas_*` database, production and the dev instance DB were not touched; the
LAB Cloud (down since the 22 Sep reboot) was NOT started. Tests ran only on `pos_test_*_edgewt_t6`.

```
START_HEAD        30105df (= origin/feat/edge-config-refresh-v1, clean)
CANONICAL         origin/feat/14d-2-plan-upgrade-requests @ b529c95 (unchanged since the wave-1 assessment)
MERGE             1baa34c  git merge --no-ff --no-edit origin/feat/14d-2-plan-upgrade-requests — 0 conflicts (merge-tree exit 0 first)
W6 COMMITS        b6b11ea (reconcile follow-ups) · 06bb53b (contract code) · docs commit (tip); NOT pushed
```

## PART A — canonical reconcile

| Item | Result |
|---|---|
| Merge | `1baa34c`, clean auto-merge (59 canonical commits; `receipt.blade.php` merged two disjoint hunks). No Edge-owned file changed by the merge. |
| Packaging excludes | `config/edge.php` `artifact.exclude` += `app/Jobs/Catering`, `app/Support/Catering` (SendCateringCustomerMailJob, CourseOrder would otherwise ship). `EdgeArtifactTest::test_*_physically_absent` list extended with both files. `app/Services/Tenant/CustomerDirectory.php` left shipping (framework-safe, unreferenced; plan R-A4). |
| KotCancellationService (R26 / D-25) | Canonical MANAGER-APPROVAL-COMBO-VOID-1 present (`KotCancellationService.php` decides by the approval's own `action_type`, `void_kot_items` accepted for one row). Team 3's page already asks ONE grouped `void_kot_items` approval for every deal line (`js/held.blade.php` `approveVoids`: `single = items.length === 1 && !items[0].deal`). **New** `tests/MySql/EdgeComboVoidReconcileHttpMySqlTest` (2 tests, real HTTP): a one-component deal 2→1 (resolves to ONE sent row) with a grouped approval is ACCEPTED (1 cancellation row, 1 cancel KOT, approval consumed); the consumed approval cannot be reused; a singular `void_kot_item` approval over a two-row deal void is still REFUSED ("One manager approval is required for this grouped cancellation."), the grouped one then passes. |
| receipt.blade `@isset($tableBill)` (R14 / D-10) | Team 3's `EdgeLocalTableOperationsService::renderTableBillReceipt` already passes `tableBill` (comment updated — no longer "dormant"). `EdgeCashierTablesWorkspaceHttpMySqlTest::test_session_detail_bill_preview_document_and_table_sessions_picker` now asserts the RENDERED document carries `Previously paid (1):`, the paid round's sale number and `Already settled:`. |
| Census | The merge changed `resources/views/tenant/pos/index.blade.php` (TABLE-WORKSPACE-WIDTH-1 CSS/class, BILL-PREVIEW-WRONG-PRINT-1 `markPreviewMode` + session send-to-network, TABLE-BILL-PREVIEW-PARITY-1 iframe). Id inventory re-run: **0 unregistered, 0 stale** (the only id in the new code, `bill-preview-frame`, was already registered). **Fixture change (the only one): `online_view_sha1` 5ff44838b41f9b7b4beff58dec54de5e68016213 → 6d4061de7820522ebdc809bf1e177aea8f410857.** No row re-classified. Coordinator note: the `billPreviewModal` partial rows were "partial — R14 print block awaits reconcile"; the block now renders (test above) — flipping them is the coordinator's call. |
| Tenant migration from canonical | `database/migrations/tenant/2026_09_17_000001_add_customer_email_switch_to_catering_settings.php` (additive, `hasColumn`-guarded) — runs on the appliance DB at update and must run on the LAB Cloud tenant DB (owner-gated, see Part C). |

## PART B — contract code (per item)

### (1) Tips + line-only discounts — guards lifted
- **Changed:** `EdgeSaleEnvelopeBuilder::assertSupported` — tip guard removed (a NEGATIVE tip is still refused); the discount guard now
  accepts discount money explained by `Σ lines.discount_amount` (as well as a type or promotion). `EdgeLocalPosService::TIPS_SYNC_CONTRACT_READY = true`;
  `assertEnvelopeCanCarryLineDiscounts` no longer refuses (kept as an empty call site with the history comment). Page flag `tipsSyncable`
  flips automatically (Team 2's `#tip-hint` hides). Held checks still carry no tip (settle path unchanged, Online-identical).
- **Tests:** `EdgeW6ContractEnvelopeHttpMySqlTest::test_tip_line_only_discount_and_the_note_ride_the_envelope_on_direct_pay_and_on_a_settled_held_check`
  (Direct Pay 2×250 − 30 line discount + 20 tip = 490; envelope v1 carries `totals.tip_amount` 20, `discount_type` none, `discount_amount` 30,
  `lines[0].discount_amount` 30); Cloud `EdgeW6ContractIngestionMySqlTest::test_a_tipped_envelope_applies_and_the_tip_is_credited_to_the_tips_account`
  (applied, `tip_amount` projected, Cr 4140 = 20, GL balanced, till +220) and `::test_a_line_only_discount_envelope_applies_with_a_balanced_gl`;
  replay: `::test_v1_keeps_…exactly_once` + the pre-existing `EdgeInboundSaleIngestionMySqlTest` replay/conflict cases. Team 2 tests flipped to the
  new contract: `EdgeCashierMenuHttpMySqlTest::test_line_discounts_ride_the_shared_totals_and_the_sync_envelope`,
  `EdgeCashierPaymentHttpMySqlTest::test_preview_answers_the_promo_like_online_and_a_quoted_tip_rides_the_paid_sale_and_envelope`,
  `::test_the_payment_method_list_follows_online_and_only_cash_is_taken` (`tipsSyncable` true).
- **Compatibility:** v1, no key added. Old Cloud → it already projected `tip_amount` and line `discount_amount` and posts the same GL
  (`JournalPostingService` grosses revenue up by the header discount, credits 4140) — identical money. Old appliance → never emits either.

### (2) Held-sale / Direct-Pay `notes`
- **Changed:** builder emits top-level `notes` (trimmed, ≤1000) ONLY when non-empty, so every note-less sale keeps its exact pre-W6 byte shape;
  `EdgeInboundSaleIngestionService::projectSale` maps `notes` (absent → NULL). (Persisting the note locally was already done by Team 2.)
- **Tests:** envelope test above (Direct Pay note + a held check whose note survives a revise that omits it and rides the SETTLED envelope;
  exactly one outbox row per paid sale); `::test_price_only_options_…` asserts a plain sale has NO `notes` key; Cloud
  `::test_the_note_is_projected_and_an_envelope_without_it_keeps_notes_null`.
- **Compatibility:** additive optional v1 key — an old Cloud hashes the whole envelope (unknown keys included) and ignores it: same money/stock,
  only the text is lost. Old appliance: no key → NULL.

### (3) Line modifiers
- **v1 carries ids/names/deltas end to end — confirmed:** `::test_price_only_options_ride_envelope_v1_with_ids_names_and_deltas_and_a_plain_sale_keeps_the_v1_shape`
  (server-priced unit 250 + 50; `modifier_group_id`, `modifier_group_name` "Extras", `modifier_id`, `name`, `price_delta`; content hash self-consistent).
- **Envelope v2 modifier linked-stock consumption — IMPLEMENTED (additive, safe):**
  - `SalesService::consumeLineModifiers` made **public** (visibility only; the one shared rule; Cloud POS behaviour unchanged — docblock records the two callers).
  - Builder: `edge-sale-envelope-v2` ONLY when a line carries a selected modifier whose synced config has `consume_stock=1` and `linked_quantity>0`;
    everything else stays v1.
  - Ingestion: accepts `[v1, v2]` (`supportedEnvelopeSchemas()`, a protected method so a test can stand in an older Cloud); for **v2 only**, after the
    product FEFO/recipe of each non-header line it calls the shared `consumeLineModifiers` inside the ingestion transaction and adds the cost to the line
    COGS exactly like Cloud finalize. Any failure → `IngestionRefusal('MODIFIER_STOCK_FAILED')` (NOT terminal) → the whole ingestion rolls back and answers a
    retryable `exception`. **v1 semantics are frozen** (a v1 envelope never consumes modifier stock, as before).
  - Sender routing: v2 falls to the default sale URL (no config change).
- **Tests:** Edge `::test_a_stock_consuming_option_emits_envelope_v2_and_only_then` (v2 + the appliance's own operational consumption; flipping
  `consume_stock` off returns to v1); Cloud `::test_v1_keeps_modifier_semantics_frozen_and_v2_posts_official_modifier_stock_exactly_once` (v1 → 0
  `modifier_consumption` ledgers; v2 → 1 ledger, qty 4 (2 × line qty 2), cost 12 on top of product COGS 80 = 92, GL balanced, registry records v2; replay =
  `already_applied`, zero further movements) and `::test_a_misconfigured_stock_modifier_rolls_the_whole_v2_ingestion_back_and_applies_after_the_fix`.
- **Compatibility:** old appliance (v1) → new Cloud: unchanged. New appliance → old Cloud: price-only sales go v1 (correct); consume-stock sales go v2 →
  old Cloud answers `SCHEMA_UNSUPPORTED` → item (4) keeps the row pending with backoff until the Cloud is upgraded.
- **Remaining / not proven:** F1 return of a v2 (modifier-stock) sale — after v2 ingestion the Cloud sale is shaped exactly like a Cloud-POS sale
  (same `modifier_consumption` ledgers), so a Cloud-side return behaves as Online does; an **Edge-local** return of such a sale was not re-proven here
  (F1 envelope unchanged, no F1 code touched). Stock semantics = Cloud's CURRENT modifier book at ingest (recipe precedent).

### (4) Outbox: `SCHEMA_UNSUPPORTED` retryable with bounded backoff (coordinator-approved)
- **Changed:** `EdgeSyncSender` — a `refused` ACK with `SCHEMA_UNSUPPORTED` (identity verified as before) no longer parks the row: it is **deferred**
  via the new `EdgeSyncOutboxService::deferLease()` (row stays `leased` under a `backoff:<ulid>` token until `now + delay`; only the current owner may
  defer; no worker token can acknowledge it; `lease()` reclaims it like an expired lease, so other rows keep flowing). Delay = `base·2^(attempts−1)`
  capped (`edge.sync.schema_retry_base_seconds` 60 / `…_max_seconds` 900, env `EDGE_SYNC_SCHEMA_RETRY_BASE_SECONDS` / `…_MAX_SECONDS`). The shared
  `EdgeIngestionVerdicts` list is unchanged; every other terminal code still → `failed_permanent`. Envelope bytes/identity never change. No migration.
- **Test:** `EdgeW6ContractIngestionMySqlTest::test_schema_unsupported_is_deferred_with_backoff_and_the_same_row_applies_once_after_the_cloud_upgrades`
  — REAL sender + REAL ingestion behind `Http::fake`: an old (v1-only) Cloud refuses the v2 row → row `leased` with a backoff token, expiry ≈ +60 s,
  Cloud registry `refused`, no sale; during the backoff the sender is `idle`; bounded (attempt 2 → 120 s, attempt 50 → 900 s cap); Cloud "upgrades" +
  backoff elapses → the SAME row is re-sent → `acknowledged`, same content hash, attempts 2, exactly ONE official sale, registry `applied`, one
  modifier ledger; a later duplicate delivery is `already_applied`, still one sale.
- **Operational note:** a deferred row counts as `leased` in `edge:local:sync-status` (it is genuinely undelivered). Rows already parked
  `failed_permanent` with `SCHEMA_UNSUPPORTED` before this build stay parked (the supervisor requeue still refuses that class) — the LAB has 0.

### (5) `effectiveIntent` (local duplicate-retry fingerprint)
- **Found:** tip, both print intents and per-line discounts were already hashed (Team 2). Missing: the note and kitchen notes (the SHARED
  `SaleIdempotencyService::canonicalSalePayload` drops unknown keys and is not changed — changing it would re-key every Cloud retry).
- **Changed:** `EdgeLocalPosService::edgeCanonicalIntent()` = shared canonical form + `edge_notes` + sorted `edge_kitchen_notes`, each appended
  ONLY when non-empty (every pre-W6 retry key keeps its exact hash).
- **Test:** `EdgeW6ContractEnvelopeHttpMySqlTest::test_the_local_retry_fingerprint_covers_tip_line_discounts_notes_kitchen_notes_and_print_intents`
  (same request replays the first sale; a changed tip / note / line discount / kitchen note / KOT intent / receipt intent under the same
  `client_uuid` → 409; one sale, one outbox row).
- **Compatibility:** local only; the Cloud uses the envelope `content_hash`.

### (6) Bootstrap v7
- **Changed:** `EdgeBootstrapService::SCHEMA_VERSION = 'edge-bootstrap-v7'`. Export adds `product_modifier_group` (sellable products × shipped groups),
  GLOBAL modifier groups (+ their modifiers) beside the branch's, every modifier of a shipped group whose linked product exists (the linked product ships
  as a bare config row even when not POS-visible; its unit + the modifier's `linked_unit_id` join the units section), `currencies` +
  `currency_denominations`, every ACTIVE payment method (non-cash rows display-only; `restrictions.allowed_payment_types` still `['cash']`; every
  posting path still cash-only). Watermark (`sourceRevision`) now covers `product_modifier_group`, `currencies`, `currency_denominations`, all modifier
  groups, and the literal `bootstrap_schema=<version>` — so the schema bump mints a NEW config revision (an already-bootstrapped appliance applies it
  as a newer revision, never a same-revision content conflict).
  Importer PLAN += `currencies`, `currency_denominations` (after units), `product_modifier_group` (after modifiers); refresh tombstones: pivot = delete,
  currencies/denominations = deactivate (`cash_count_lines` FK-cascade from denominations — never deleted).
  Team 4 C-4: new Edge migration `database/migrations/edge/2026_09_25_000001_add_tenant_business_name_to_edge_local_meta.php` (nullable, guarded);
  importer + refresh applier persist `tenant.business_name`; `EdgeQuickReportController::businessName()` reads it (falls back to the branch name).
  `EdgeCompatibilityService` needs no code change (exact-match classifier) — proven: v6 → `software_update_required` for every feature, v7 → compatible.
- **Tests:** `EdgeBootstrapV7MySqlTest` (3; real buildSections → real importer on a fresh Edge-local DB): sections carry the pivot, the global group
  (never branch B's), the linked non-visible product + its inactive unit, PKR + 2 denominations, cash + card (inactive bank excluded), tenant name,
  unchanged cash-only restriction; a new pivot row changes the watermark; a v6 package is refused (`SCHEMA_UNSUPPORTED`, nothing imported); v7 imports
  coherently and persists the business name; a revision-2 refresh deactivates a removed denomination, deletes a removed pivot row, deactivates a retired
  card method and updates the business name. Feature: `EdgeCompatibilityContractTest::test_a_v6_appliance_is_update_required_and_a_v7_appliance_is_compatible`,
  `EdgeBuildInfoTest` (v7 supported, v6 refused).
- **Compatibility:** NOT backward compatible by design (exact-match importer, v5→v6 precedent): a 0.6.0 appliance refuses a v7 export and the Cloud
  classifies it `software_update_required`. → sequencing warning in Part C.
- **Not done (not in this wave's list; recorded for the next contract pass):** R19 `restaurant_tables.reserved_*` → `edge_local_table_reservations`
  (Team 3), `pos_quick_report_settings` seed (plan B9), variant/unit snapshots `lines[].variant_name/unit_code` (Team 2 #5), product images.

### (7) Print intents never enter the envelope
- **Test:** `EdgeW6ContractEnvelopeHttpMySqlTest::test_direct_pay_print_intents_never_enter_the_envelope` — intents persisted locally
  (`direct_pay_print_state`), and no `kot_print_intent` / `receipt_print_intent` / `direct_pay_print_state` / `print_intents` / `printing` key at any depth
  of the envelope (raw stored bytes contain no `print_intent`). The builder reads explicit fields only; class doc now states the rule.
- **W5 note W6-1 (Direct-Pay KOT after the envelope):** no contract change — Online has the same timing and `kot_events` is audit-only.

### Owner-dependent — NOT started (design stays in the wave-1 plan)
Shift-close event / CASH-SHORTAGE voucher (C-1/O-1), Close Branch / Daily Closing (C-2), non-cash tenders & refunds, journal reversal, purchase-return
drafts, kitchen-note capture (no Online writer).

## Gates run (all on `pos_test_*_edgewt_t6`)

```
Feature (SQLite)  vendor/bin/phpunit tests/Feature/Edge                               OK 137 tests / 32,883 assertions
                  (route census, artifact boundary incl. Catering job/support absent, dependency closure, Blade gate,
                   compatibility v6/v7, build info v7, log hygiene)
Unit              CancellationPolicyRegressionTest + TableBillDecimalRegressionTest  OK 3 / 12
MySQL targeted    EdgeW6ContractEnvelopeHttp (5) + EdgeW6ContractIngestion (6) + EdgeBootstrapV7 (3) + EdgeComboVoidReconcileHttp (2)
                  + Menu/Payment/TablesWorkspace/ControlCensus (EDGE_NODE_BIN set; node --check of the composed script)  all OK
MySQL regression  --filter 'Edge|ManagerApprovalComboVoid|CancelFreesTable|ReceiptProformaVsFinal|BillPreviewPrintTarget|
                  ComboModifierKotIntegrity|SteakSideModifier|RecipeConsumptionReference|CloudManagerApproval'
                  589 tests / 6,246 assertions, 1 skipped, 1 error = EdgeBackupRecoveryAuthorityMySqlTest::test_a_dead_appliance_is_replaced…
                  -> PRE-EXISTING test-order isolation defect, NOT W6: green alone (4/4); fails only when CloudManagerApprovalMySqlTest or
                  DeliveryChargeMySqlTest ran before it in the same process (both files, EdgeRestoreService and the harness are
                  unchanged since 30105df). Side effect found: that test leaves a master tenant_databases row ('edgerecov') for the
                  shared test tenant DB, which makes the canonical BillPreviewPrintTargetMySqlTest fail with a 1062 if it runs later
                  against the same DB (row removed by hand on _edgewt_t6). Coordinator: harness fix, not a product defect.
Dry artifact      php artisan edge:build-package <scratch> --no-sign --allow-dirty --vendor-junction=vendor  (dev, UNSIGNED, git_commit
                  06bb53b, source_dirty false): boundary_audit ok, forbidden_hits [], cloud_only_present [], edge_runtime_missing [],
                  marker branch_server; edge:audit-package -> PACKAGE OK (2,068 files); app/Jobs/Catering, app/Support/Catering,
                  app/Services/Catering, EdgeInboundSaleIngestionService physically absent; the new edge migration is present.
                  Scratch package deleted (junction unlinked first). No release build, no signing, custody keystore untouched.
Not run here      full `phpunit --testsuite Feature,Unit` and the full MySQL suite (C10.2 — coordinator's release gate), release-mode
                  EdgeCleanMachineInstallMySqlTest with EDGE_PROOF_VENDOR_FROM (needs the no-dev closure of the release commit).
```


## Commits (not pushed)

```
1baa34c  merge origin/feat/14d-2-plan-upgrade-requests (b529c95), 0 conflicts
b6b11ea  EDGE W6 reconcile follow-ups (excludes, combo-void + table-bill proofs, census sha)
06bb53b  EDGE W6 contract (envelope v1 additive keys, v2 modifier stock, SCHEMA_UNSUPPORTED backoff, bootstrap v7)
<tip>    EDGE W6 docs: this report + plan Part C (C0/C5a/C6a/C10a)
```

Release candidate: the branch tip after the docs commit (app tree identical to 06bb53b). LAB pre-update steps: plan Part C §C6a.
