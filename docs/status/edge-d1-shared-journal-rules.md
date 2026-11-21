# D1 — ONE shared manual-journal rule set (Cloud + Edge)

**Date:** 20 Oct 2026 · **Worktree:** `D:\laragon2\www\pos-saas-edge`, branch `feat/edge-config-refresh-v1`, base HEAD `3d21b1c1` (canonical `ccc67fa1` fully merged) · **Status:** source + isolated tests, UNCOMMITTED (owner rule: team D1 does not commit).

**Owner decision D1 (verbatim):** "Move canonical's manual-journal guards and reverse-lock behaviour into the ONE shared journal service. Cloud and Edge must consume the same business rule. Do not maintain separate Cloud/Edge reversal semantics. Add regression tests proving: allowed reversal; refused reversal; already-reversed protection; authority restrictions; replay/idempotency where applicable."

## 1. The rule set and where it lives

| Rule | Text (canonical MANUAL-JOURNAL-CASH-LINE-GUARD-1 `7da4190d`, messages byte-for-byte) | Lives in |
|---|---|---|
| R1 | A chosen drawer only on the line of the GL account it is linked to — "*Drawer* is linked to *code name*. Choose it on the *code* line, not on *label*." / "… is not linked to any account in the chart of accounts …" | `ManualJournalRules::guardCashLines` |
| R2 | A line on an active drawer's GL account needs a drawer: exactly one linked → auto-filled; several → "*label* is used by more than one cash/bank account (…). Choose which one the money went through." | `ManualJournalRules::guardCashLines` |
| R3 | No posting on a group head — "*label* is a group. Choose an account under it, e.g. *first active child*." | `ManualJournalRules::guardCashLines` |
| R5 | Narration ≥ 3 characters with a letter or digit — "Write what this entry is for, in at least 3 characters (e.g. "Owner drawing - cash"). A dot or a dash is not a description." | `ManualJournalRules::guardNarration` (`NARRATION_MESSAGE`) |
| AP | Debit XOR credit per line; every Accounts Payable line names its supplier (SUPPLIER-FINANCE-DIRECT-1) | `ManualJournalService::assertApLinesNameTheirSupplier` (unchanged) |
| Reverse | Row lock for the whole transaction (two tabs serialise); only a POSTED `manual_journal` that is not itself a reversal; an entry that already has a reversal → **refused, nothing posted** (B3: "*JE* was already reversed by *JE* — nothing was posted again.") | `ManualJournalService::reverse` → `ManualJournalReversalRefused` {NOT_MANUAL_JOURNAL, NOT_POSTED, IS_REVERSAL, ALREADY_REVERSED} |

**Why a `ManualJournalRules` class and not only `ManualJournalService`:** `app/Services/Finance/ManualJournalService.php` is a Cloud-only package sentinel (config `edge.artifact`, `EdgePackageBuilder::CLOUD_ONLY_SENTINELS`, `EdgeArtifactTest`) — it posts the GL and is physically absent from the appliance. The rules therefore live in a pure, chart-agnostic class (`ManualJournalRules` + `ManualJournalChart`, no models, no connection) that ships to the appliance; `ManualJournalService` is their **single Cloud enforcement point** (`guard()` inside `post()`), and the Branch Server runs the identical class against its replica. One rule object, one message text, three consumers:

| Consumer | Path | How it consumes |
|---|---|---|
| Online form | `ManualJournalController@store` → `ManualJournalService::post()` | thin caller: Laravel shape validation only; `post()` runs `guard()` (AP → R5 → R1–R3) inside the transaction before any write; `ValidationException` propagates field-keyed exactly as before |
| Online reverse | `ManualJournalController@reverse` → `ManualJournalService::reverse()` | translates `ManualJournalReversalRefused`: NOT_MANUAL_JOURNAL → 404, NOT_POSTED / IS_REVERSAL → 422, ALREADY_REVERSED → canonical B3 redirect to the existing reversal (Cash Book return honoured) |
| Edge till (offline General Journal) | `EdgeLocalSupplierFinanceService::postApJournal` | after the freshness gate: `ManualJournalRules::guardNarration` + `applyCanonicalCashLineRules()` (chart from `edge_supplier_finance_accounts` incl. `parent_cloud_account_id`, `edge_supplier_finance_cash_bank_accounts` incl. `coa_account_id`); error keys translated to the till's `cloud_*` field names; the R2 auto-filled drawer lands in the envelope and the local cash effect |
| Cloud ingestion (`/api/edge/sync/supplier-finance`, `SUPPLIER_AP_JOURNAL_ADJUSTMENT`) | `EdgeInboundSupplierFinanceIngestionService::applyApJournal` → `ManualJournalService::guard()` then `post()` | a violation → `IngestionRefusal('JOURNAL_RULE_REFUSED', <canonical message>)` → ack `refused` (HTTP 422), registry row `refused`, **terminal** in `EdgeIngestionVerdicts` (appliance parks the outbox row `failed_permanent` for a supervisor) |

**How can the till even produce a violating envelope?** It cannot: the identical rules run at the till before the envelope is built (R2 auto-fill included, so the envelope always carries the drawer). The ingestion refusal is defence in depth for an appliance on an older release (pre-D1 tills allowed a cash GL line without a drawer and a group-head line) or a hand-made envelope. Mixed-release note: an older appliance's envelope with a cash GL line and no drawer is now posted exactly as the Online form would post it (single drawer auto-filled → a Cash Book movement; the finance verifier is given the guarded lines so it agrees); a group-head / wrong-line-drawer envelope is refused `JOURNAL_RULE_REFUSED`.

**Till ordering (deliberate):** R5/R1–R3 run after the freshness gate so a stale chart answers "post this journal on the Online POS", never a rule verdict read from stale data (keeps `EdgeSupplierFinanceSyncHttpMySqlTest::test_a_stale_projection…` semantics).

**Reverse on the appliance:** not offered (no route, no envelope type — `EdgeLocalSupplierFinanceController@journalShow` "Reverse: owner-dependent, not offered"); `ManualJournalService::post()/reverse()` additionally fail closed on a Branch Server before touching the database. Reversal of an Edge-originated journal happens Online through the same service.

## 2. Files changed

| File | Change |
|---|---|
| `app/Services/Finance/ManualJournalRules.php` | **NEW** — R1–R3, R5 (pure; ships to the appliance) |
| `app/Services/Finance/ManualJournalChart.php` | **NEW** — chart snapshot (accounts with parent, drawers with linked GL) the rules read |
| `app/Services/Finance/ManualJournalReversalRefused.php` | **NEW** — typed reverse refusal with reason + the existing reversal |
| `app/Services/Finance/ManualJournalService.php` | `guard()` (AP → R5 → R1–R3, returns auto-filled lines), `chart()`, `post()` runs `guard()` first; `reverse()` owns the lock + rules + ALREADY_REVERSED; `reversalOf()`; `assertCloud()` fail-closed |
| `app/Http/Controllers/Tenant/Finance/ManualJournalController.php` | thin caller — `guardNarration()`/`guardCashLines()` and the AP call removed from `validateData()`; `store()` lets `ValidationException` through; `reverse()` translates the typed refusal; `use DB` dropped |
| `app/Services/Edge/EdgeInboundSupplierFinanceIngestionService.php` | `JOURNAL_RULE_REFUSED` constant; `guard()` before `post()`; `ValidationException` → that code; verifier fed the guarded lines |
| `app/Services/Edge/EdgeIngestionVerdicts.php` | `JOURNAL_RULE_REFUSED` added to `TERMINAL_FAILURE_CODES` |
| `app/Services/Edge/EdgeLocalSupplierFinanceService.php` | till runs `ManualJournalRules` (narration + `applyCanonicalCashLineRules()`); former Edge-only R1 wording replaced by the canonical message (the Edge-only "active Cash/Bank" and "supplier only on an AP line" checks stay) |
| `tests/MySql/ManualJournalSharedRulesOnlineMySqlTest.php` | **NEW** (5 tests) |
| `tests/MySql/EdgeSharedJournalRulesMySqlTest.php` | **NEW** (4 tests) |
| `tests/MySql/EdgeSupplierFinanceAuthorityMySqlTest.php` | one needle: `'mapped to chart account'` → the canonical R1 message (wording changed by design) |
| `docs/status/edge-d1-shared-journal-rules.md` | this note |

Not touched: team CAP's files (EdgePosRuntimeFactory, PosRuntime, CloudPosRuntimeFactory, EdgeLocalShiftController, ReminderPauseService, PosPermissionCatalog, TenantProvisioner, permissions audit command, EdgeQuickReportController, index.blade.php, routes/edge_runtime.php, config/edge.php). `ManualJournalCashLineGuardMySqlTest` and `CashBookVoidMySqlTest` unchanged.

## 3. Owner case → test

| Owner case | Online | Edge |
|---|---|---|
| Allowed reversal | `ManualJournalSharedRulesOnlineMySqlTest::test_online_allowed_reversal_posts_the_gl_reversal_and_the_cash_counter_movement_once` (+ canonical `…test_purani_ghalat_entry_reverse_hoti_hai…`, `CashBookVoidMySqlTest::test_manual_journal_ka_reverse_bhi_cash_book_par_lautata_hai`) | `EdgeSharedJournalRulesMySqlTest::test_an_ingested_journal_applies_once_on_replay_and_is_reversed_online_exactly_once` (ingested journal reversed Online through the one service: GL + cash counter-movement once) |
| Refused reversal | `…Online…::test_refused_reversal_not_a_manual_journal_not_posted_or_itself_a_reversal` (service reasons + route 404/422) | `…Edge…::test_a_standby_appliance_and_an_operator_without_the_permission_are_refused_and_the_appliance_never_reverses` (Branch Server fails closed; no Edge reverse route) |
| Refused posting (R1–R3/R5 guards) | `…Online…::test_the_service_enforces_r1_r2_r3_r5_without_the_controller` (+ canonical R1/R2/R3/R5 HTTP tests) | `…Edge…::test_the_till_refuses_r1_r2_r3_r5_with_the_online_messages_and_autofills_the_single_drawer`; `…Edge…::test_the_cloud_ingestion_refuses_a_rule_violating_envelope_with_an_explicit_terminal_code` (R3, R1, R5 → `JOURNAL_RULE_REFUSED`, terminal, nothing posted) |
| Already-reversed protection | `…Online…::test_already_reversed_a_second_reverse_posts_nothing_on_the_route_and_in_the_service` (B3 kept + service ALREADY_REVERSED) + canonical `test_reverse_do_bar_dabane_par_ek_hi_reversal` | `…Edge…::test_an_ingested_journal_applies_once_on_replay…` (second reverse → ALREADY_REVERSED, counts stay 1) |
| Authority restrictions | `…Online…::test_store_and_reverse_require_their_route_permissions` (403 on store and reverse; read still allowed) | `…Edge…::test_a_standby_appliance_and_an_operator…` (no PERM_JOURNAL → refused; STANDBY → "Local Mode is not active"; local_active posts again); `…Edge…::test_the_cloud_ingestion_refuses…` (`ACTOR_UNAUTHORIZED`) |
| Replay / idempotency | service reverse replay (above) | `…Edge…::test_an_ingested_journal_applies_once_on_replay…` (same envelope twice → `already_applied`, one journal, one movement, one registry `applied` row) + existing `EdgeSupplierFinanceSyncHttpMySqlTest` lost-ack/replay/conflict |

## 4. Ingestion refusal code

`JOURNAL_RULE_REFUSED` (`EdgeInboundSupplierFinanceIngestionService::JOURNAL_RULE_REFUSED`) — message = the canonical rule message; ack `status: refused` (HTTP 422 at `/api/edge/sync/supplier-finance`); registry `edge_inbound_supplier_finance_ingestions.status = refused`, `failure_code = JOURNAL_RULE_REFUSED`; terminal (`EdgeIngestionVerdicts::isTerminal` true → outbox `failed_permanent`, supervisor attention, blocks handback like every permanent failure). Existing codes unchanged (`AP_SUPPLIER_REQUIRED` keeps its own code because its explicit pre-check runs first).

## 5. Asar (system-map impact) — no schema change, no rebuild needed

Tables whose **writes** this change governs (no new columns, no migration): `journal_entries`, `journal_lines` (manual journals + reversals), `cash_bank_account_transactions` + `cash_bank_accounts.current_balance` (R1/R2 decide whether and on which drawer a Cash Book movement is made), `supplier_ledgers` (AP mirror of the posted/reversed journal), `edge_inbound_supplier_finance_ingestions` (new `failure_code` value), appliance: `edge_local_supplier_finance_events`, `edge_local_supplier_finance_effects`, `edge_sync_outbox` (R2 auto-filled drawer now travels in the envelope); **read** on the appliance: `edge_supplier_finance_accounts`, `edge_supplier_finance_cash_bank_accounts`. Screens: Online Finance → Manual Journals (create/store/show/reverse), Cash Book (void = reverse), Edge → Finance → General Journal (`/edge/local/pos/finance/journal`), Edge Manual Journals list/show. Per CLAUDE.md the system map is rebuilt only after a schema/route change — neither happened here, so no rebuild.

## 6. Gate counts (_t22 / Feature)

| Gate | Result |
|---|---|
| Canonical trio `ManualJournalCashLineGuard|CashBookVoid|SupplierFinanceDirect` (_t22) | **57/57, 375 assertions** — green, test files unchanged |
| New classes `ManualJournalSharedRulesOnline` (5) + `EdgeSharedJournalRules` (4) (_t22) | **9/9, 126 assertions** |
| MySQL filter `ManualJournal|CashBook|SupplierFinance|EdgeSupplierFinance|EdgeInboundSupplierFinance|EdgeW6ContractEnvelope|EdgeLocalFinance` (_t22) | **95/95, 968 assertions, 0 failures** (1 PHPUnit deprecation, pre-existing) |
| `tests/Feature/Edge` + `tests/Feature/Pos` (SQLite, EDGE_NODE_BIN set) | **221/221, 42,018 assertions** — incl. EdgeArtifactTest / EdgePackageBuilderTest (sentinels hold; the new Finance classes ship) |

Runs were made on the shared working tree while team CAP's uncommitted edits were present; all green.

## 7. Not done / notes for the owner

- No concurrency test with two independent PDO connections for the reverse lock (the canonical double-click test covers the request-level behaviour; the lock is now inside the service transaction).
- The Edge General Journal page (`resources/views/edge/finance/journal.blade.php`) already filters the drawer picker by the chosen GL account (R4 parity) — unchanged; it shows the first error message only, which is now the canonical text.
- `ManualJournalReversalRefused`, `ManualJournalRules`, `ManualJournalChart` ship in the appliance package (not in the Cloud-only sentinel lists) — intended for the latter two; the exception class is harmless there.
