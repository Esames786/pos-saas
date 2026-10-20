# Next Edge release — owner decisions D2 / D3 / D4 / D6 (Team CAP output)

Date: 20 Oct 2026. Branch `feat/edge-config-refresh-v1` on top of 3d21b1c (canonical ccc67fa1 fully merged). Source + isolated automated
tests only (`_edgewt_t23` DBs); nothing committed by Team CAP; the LAB appliance, the canonical checkout, production and the dev
servers' databases were not touched. Team D1's concurrent finance work (ManualJournal*, EdgeInboundSupplierFinanceIngestionService,
EdgeIngestionVerdicts, EdgeLocalSupplierFinanceService and the finance tests) was left alone.

```
D2  reminder pause = LOCAL on the Branch Server: POST /edge/local/pos/reminder-pause (edge.local.pos.reminder.pause) → EdgeLocalShiftController::posReminderPause
    → shared ReminderPauseService::setPaused on the LOCAL open shift row; shift-status carries the real `reminder` block; capability reminderPause ON; same control, same state, same permission
D3  tenant.pos.pause-reminders in PosPermissionCatalog (cashier group) → new tenants' Cashier (Counter) template; existing tenants NOT granted; audit reports it separately (CASHIER_TEMPLATE_ADDITIONS)
D4  websiteOrders stays CLOUD_ONLY: same component, disabled + hint on Edge, every websiteOrders* route null, no Cloud literal in the Edge render — gated (test_c3), documented (edge-capability-differences.md)
D6  partnerShare = a proper PosRuntime capability (Cloud ON / Edge OFF, server-side); the interim CLOUD_ONLY_SECTIONS guard is gone; bootstrap stays v8
RECONCILIATION  the pause is shift-scoped; handback is BLOCKED while any appliance shift is open (OPEN_SHIFTS) → no paused shift can be live when the Cloud is the writer again; the Cloud's own next shift starts ON (Online rule); the envelope's shift block carries identity only
GATES  see §7
```

## 1. D2 — the Branch Server reminder-pause twin

| Piece | File : lines |
|---|---|
| Route | `routes/edge_runtime.php` — `POST /edge/local/pos/reminder-pause` → `EdgeLocalShiftController@posReminderPause`, name `edge.local.pos.reminder.pause` (inside the `edge.auth` + `edge.branch` POS group, next to `shift.close`) |
| Allowlist + capability token | `config/edge.php` — `route_allowlist` += `edge.local.pos.reminder.pause`; `capabilities` += `local_reminder_pause` (heartbeat build report declares it) |
| URI census | `tests/Feature/Edge/EdgeLocalPosRouteCensusTest.php` (branch_server: exists, auth-gated), `EdgeLocalPosRouteCensusCloudTest.php` (absent on Cloud), `EdgeBranchServerRegistrationTest.php` (`edge/local/pos/reminder-pause` deliberately approved) |
| Controller | `app/Http/Controllers/Edge/EdgeLocalShiftController.php` — imports `ReminderPauseService`, `EdgeAuthorityService`; `shiftStatus()` now answers `'reminder' => ReminderPauseService::statusFor($terminal, user)` (and `statusFor(null, user)` on the no-terminal branch, exactly Online's posStatus); new `posReminderPause(Request, ReminderPauseService, EdgeAuthorityService)` |
| Runtime | `app/Services/Edge/EdgePosRuntimeFactory.php` — `CAPABILITIES['reminderPause'] = true`; route `'reminderPause' => '/edge/local/pos/reminder-pause'`; the stale "runs on the Online POS for now" hint removed from `LABELS` |
| Shared view | `resources/views/tenant/pos/index.blade.php` — **no markup change**; `#pos-reminder-toggle` was already `@disabled(! $posRuntime->can('reminderPause'))` and the JS already posts to `POS.route('reminderPause')` (comments refreshed only) |
| PosRuntime | `app/Support/Pos/PosRuntime.php` — comment on `reminderPause` (Cloud ON / Edge ON since D2) |

**Contract = Online's** (`ShiftController::posReminderPause`, `ReminderPauseService`): request `{terminal_id: int, paused: bool}`; response
`{reminder: {available, paused, paused_by, paused_at (H:i in the shift tz), can_toggle}}`. Gates in order:

1. validation (422 `errors`);
2. permission `tenant.pos.pause-reminders` (`denyUnlessCan(ReminderPauseService::PERMISSION)` → 403 `{message: 'You are not allowed to pause Reminder slips.', permission}`);
3. the selected terminal — W-B: the page's `terminal_id` is adopted (`adoptRequestedTerminal`), a terminal of another branch / inactive → 422 `{ok:false, code: INVALID_TERMINAL}`, pinned/assigned-terminal authority → 403;
4. the branch-authority lease (`EdgeAuthorityService::assertLocalMutationAllowed` — the SAME gate every Edge mutation uses): STANDBY → 422 `{ok:false, code: STANDBY, message}`; without lease mode (manual Local Mode) a no-op;
5. `ReminderPauseService::setPaused` inside its `lockForUpdate` transaction on the LOCAL open shift (no open shift → 422 `{message}` with Online's `ShiftException` text).

**Local reminder plan.** No new reminder logic exists on the appliance: `EdgeLocalPrintKotService::queueKot` (the shared view's `kotQueue`
route `POST /edge/local/pos/sales/{sale}/kot`) → `PrintJobService::planRemindersForKotJobs` → `ReminderPauseService::isPausedForTerminal($sale->terminal_id)`
reads the LOCAL shift row (the `Shift` model is on the `tenant` connection = the appliance DB); the same service guards
`queueCancellationReminders` (a correction slip is still owed where a Reminder is already on paper) and the Review & Pay
`DirectPayPrintOrchestrator` (`EdgeLocalPrintDirectPayService`). Fail-open as on Online (an unreadable pause prints as usual).

## 2. Reconciliation rule for the pause state (D2 — "state reconciles safely after reconnect/handback")

The pause is **a stamp on the appliance's OPEN shift** (`shifts.reminders_paused_at`, `reminders_paused_by_user_id`) and ends with that shift
(Online's own rule: closing the shift ends the pause; the next shift starts ON). Three facts make the handback safe without carrying
anything in the envelope or the handback payload:

1. **A handback is blocked while any appliance shift is open** — `EdgeHandbackOrchestrator::assess()` → `OPEN_SHIFTS` ("close them — the
   shift's cash belongs to this branch server"). So by the time the Cloud becomes the writer again, the appliance has **no open shift and
   therefore no live pause**; the closed row keeps the stamp for audit only (nothing reads a closed shift's pause).
2. **The Cloud's shift is a different row.** During Local Mode the Cloud POS is fenced (standby) and opens no shift for the branch; after
   the handback the Online cashier opens the Cloud's own shift, which starts with Reminder ON by Online's "new shift starts clean" rule
   (`PosReminderPauseMySqlTest::test_a_new_shift_starts_with_reminder_on`). The Online badge is therefore correct by construction — it
   reads `ReminderPauseService::statusFor` over the Cloud's open shift.
3. **The sale envelope's `shift` block is identity only** (`shift_uuid, business_date, opened_at, terminal_id, opened_by_user_id` —
   `EdgeSaleEnvelopeBuilder`); no `reminders_paused_*` field rides it, on purpose: the pause is operational state of one terminal's shift,
   never money or inventory, and a stale pause must never be replayed onto a Cloud shift.

Standby: the mutation refuses (`STANDBY`), the badge keeps reading the local row (a read is not a mutation). While standby the appliance
has no open shift of its own writer role anyway (handback drained it); a cashier on the Online POS pauses the Cloud shift there.

Tested in `EdgeReminderPauseHttpMySqlTest::test_the_pause_ends_with_the_shift_a_handback_is_blocked_while_it_is_open_and_the_envelope_carries_no_pause`.

## 3. D3 — the permission

| Piece | File |
|---|---|
| Catalogue | `app/Support/Pos/PosPermissionCatalog.php` — `ENTRIES['tenant.pos.pause-reminders']` (group cashier; edge check site = the twin's `denyUnlessCan(ReminderPauseService::PERMISSION)` + `statusFor().can_toggle`; online = `POST /api/pos/reminder-pause`, `tenant.api.pos.reminder-pause`, seeded by migration `2026_10_05_000002_add_reminder_pause_to_shifts`); new `CASHIER_TEMPLATE_ADDITIONS` (since 2026-10-20, decision D3, note) |
| New tenants | `TenantProvisioner::provisionCashierRoleTemplate` is unchanged — it grants `PosPermissionCatalog::cashier()`, which now carries the permission (the `Cashier (Counter)` template for NEW tenants only; an existing role of that name is never touched) |
| Existing tenants | **nothing is granted** by this work. (The Cloud migration `2026_10_05_000002` had already granted it once, additively, to Owner and to every role holding `tenant.held-sales.store` — a role created or trimmed since must be granted by the tenant.) |
| Audit | `app/Console/Commands/PermissionsAuditCashierRolesCommand.php` — per role `missing_recent_additions` (the subset of `missing` that is a template addition); per tenant `recent_additions[]` = `{permission, since, decision, note, known_to_tenant, roles_missing[{role, users}], users_affected}`; text output marks each such line "(newly catalogued — not granted to existing tenants)" and prints `newly catalogued since 2026-10-20 (D3): tenant.pos.pause-reminders — missing in N role(s) / M user(s): "…" (k)`. Still read-only (no write path). |
| Bootstrap | unchanged — keeps exporting each user's EFFECTIVE permission set (`users[].permissions[]`, v8) |
| Dev seed | `tests/MySql/EdgeDevInstanceSeedMySqlTest.php` grants `PosPermissionCatalog::cashier()` to DEVCASH1 (now incl. the permission) — asserted |

## 4. D4 — website orders (confirmed CLOUD_ONLY)

Already a capability after the canonical merge (`websiteOrders` OFF on Edge, routes null, same slot disabled + hint, the partial's JS stops
without `POS.route('websiteOrders')`). This work adds the proof and the document: `EdgeSharedPosRegressionGateMySqlTest::test_c3…` asserts
Cloud ON / Edge OFF, the three routes null on Edge and set on Cloud, the SAME component (`#web-orders-btn`, `#webOrdersModal`,
`#web-orders-body`) present once in both renders, the bell disabled with `LABELS['websiteOrders']` as its title on Edge and enabled on
Cloud, and **no `/api/pos/website-orders` literal in the Edge render**. `docs/status/edge-capability-differences.md` records it as an
intentional capability difference, not a layout difference.

## 5. D6 — partner share as a capability

| Piece | File |
|---|---|
| Capability | `app/Support/Pos/PosRuntime.php` — `CAPABILITY_KEYS += 'partnerShare'` (Cloud factory fills ON); `EdgePosRuntimeFactory::CAPABILITIES['partnerShare'] = false` + `LABELS['partnerShare']` (server refusal hint) |
| Quick report | `app/Http/Controllers/Edge/EdgeQuickReportController.php` — `CLOUD_ONLY_SECTIONS` **removed**; `CAPABILITY_SECTIONS = ['partner_share' => 'partnerShare']` + pure `sectionsAllowed(array $sections, array $capabilities)`; `context()` strips every section whose capability is off in `EdgePosRuntimeFactory::capabilities()` (falls back to the full allowed list, never an empty report) |
| Modal | **no change on either runtime** — the Online quick-report modal has no Partner Share box (`PosQuickReportController::SECTIONS` excludes `partner_share`; the section belongs to the Reports Centre, capability `reports`). The brief assumed a checkbox; adding one would have changed Online's geometry, so the capability is enforced server-side and the gates got a `SERVER_ONLY_CAPABILITIES` class (`EdgeSharedPosRegressionGateMySqlTest`, `EdgeSharedPosRegressionStaticGateTest`) that asserts Cloud ON / Edge OFF / no view site / the server rule. |
| Bootstrap | **stays v8** — no `share_partners` / `share_rates` sections shipped |

## 6. Tests

| Test | What it proves |
|---|---|
| `tests/MySql/EdgeReminderPauseHttpMySqlTest.php` (new, 7 tests) | pause/resume over the real routes answer Online's shape and stamp the local shift; the badge carries the identical block (`===` the shared service's `statusFor`); the local reminder plan prints the KOT but no Reminder while paused, another counter keeps its slips, the next round after resume prints; STANDBY 422 while the badge still reads; named 403 without the permission (state shown, `can_toggle=false`); validation / INVALID_TERMINAL / no-open-shift refusals; the switch shows only where a Reminder rule fires + the no-terminal answer; handback reconciliation (envelope shift block identity-only, OPEN_SHIFTS blocks, closing ends the pause, the next shift is ON and prints) |
| `tests/MySql/PosReminderPauseMySqlTest.php` | Online — unchanged |
| `tests/MySql/EdgeCashierPermissionMatrixMySqlTest.php` | the template workflow pauses + resumes (2xx); the per-permission matrix probes `tenant.pos.pause-reminders` (403 naming it, 2xx with it back); the audit test asserts `missing_recent_additions`, `recent_additions` and the new text line; the fresh template holds the permission, the custom role lacks it, nothing is granted |
| `tests/Feature/Edge/EdgePermissionCatalogTest.php` | the required cashier set includes the permission; new `test_pause_reminders_is_a_cashier_template_addition_checked_by_the_edge_twin` (scanner sees the twin's check site; catalogue documents the Online owner + migration; the audit has no write path) |
| `tests/MySql/EdgeSharedPosRegressionGateMySqlTest.php` | `SERVER_ONLY_CAPABILITIES`; test_c handles it; new `test_c3_reminder_pause_is_local_website_orders_and_partner_share_are_cloud_only_capabilities` |
| `tests/Feature/Edge/EdgeSharedPosRegressionStaticGateTest.php` | an OFF capability must be gated in the view OR declared server-only (with a hint); reminderPause is ON and keeps its Blade gate |
| `tests/MySql/EdgeCashierQuickReportParityHttpMySqlTest.php` | new `test_partner_share_is_a_cloud_only_capability_the_edge_quick_report_never_computes` |
| `tests/MySql/EdgeDevInstanceSeedMySqlTest.php` | DEVCASH1 holds the permission |
| URI census ×3 | the new route exists on branch_server, is auth-gated, absent on Cloud, deliberately approved |

## 7. Gate counts

| Suite | Result |
|---|---|
| `vendor/bin/phpunit tests/Feature/Edge tests/Feature/Pos` (SQLite, EDGE_NODE_BIN set) | **OK — 221 tests, 42,018 assertions** (3:47 first run; 1:14 re-run after the view-comment fix) |
| MySQL `_edgewt_t23` filter `EdgeReminderPause\|PosReminderPause\|EdgeSharedPos\|EdgeCashierPermission\|EdgeCashierShift\|EdgeCashierQuickReport\|EdgeCashierPrinting\|EdgeDevInstanceSeed\|EdgeLocalPrint` | **101 tests, 2,715 assertions, 0 failures, 0 errors**, 1 skipped (`EdgeDevInstanceSeedMySqlTest` — skips by design unless `EDGE_DEV_SEED=1` against `bingoo_edge_devtest_local`, which this team may not touch), 1 PHPUnit deprecation (pre-existing); 4:31 |
| of which new / changed | `EdgeReminderPauseHttpMySqlTest` 7/7; `PosReminderPauseMySqlTest` 11/11 unchanged; `EdgeSharedPosRegressionGateMySqlTest` 7/7 (incl. test_c3); `EdgeCashierPermissionMatrixMySqlTest` 3/3; `EdgeCashierQuickReportParityHttpMySqlTest` 3/3 |

Two intermediate reds were mine and are fixed: the gate's allowlist lookup must run before `renderBoth()` boots the Cloud (route table), the
envelope key comparison must be order-insensitive (canonical JSON sorts keys), the matrix workflow probe on a terminal without a Reminder rule
asserts the row stamp (Online's `statusFor` answers `available=false` there), and a JS comment of mine carried the Cloud path literal
(reworded — the shared view has no Cloud literal).

## 8. Asar (system-map impact) + system map

Tables moved: `shifts` (`reminders_paused_at`, `reminders_paused_by_user_id` — existing columns, now written on the appliance by the twin),
`print_jobs` (reminder rows not created while paused), `permissions` / `role_has_permissions` (new tenants' Cashier (Counter) template),
`edge_sync_outbox` (unchanged shape — asserted), `edge_local_meta` (read for STANDBY).
Screens: the ONE shared cashier page (`tenant.pos.index`) on the Branch Server — `#pos-reminder-toggle` enabled; the Edge shift-status badge;
the Quick Report modal (no change) + thermal view (section stripped); the website-orders slot (no change, documented).
Routes: `edge.local.pos.reminder.pause` (new, allowlisted). No schema change; no bootstrap change (v8).

System map rebuilt after the MySQL run had migrated the isolated DBs (CLAUDE.md standing rule; no other run was using them):
`php tools/system-map/build.php --tenant-db=pos_test_tenant_edgewt_t23 --master-db=pos_test_master_edgewt_t23` →
`{"tables":265,"foreign_keys":429,"inferred_links":301,"screens":730}`; regenerated files `docs/system-map/system-map.json` and
`docs/system-map/index.html` (the new `reminder-pause` route is in the screens index). Not committed (team rule); the published artifact
copy was not republished by this team.

## 9. Not done / caveats

- Pixel baselines (D5) not re-captured here — the reminder toggle now renders ENABLED on the Branch Server (same as Online), so the Edge
  pair for "reminder toggle both states" needs its new baseline.
- The interim partner-share guard is gone; if a later canonical adds a Partner Share box to the Online quick-report modal, gate it with
  `@disabled(! $posRuntime->can('partnerShare'))` and move `partnerShare` from `SERVER_ONLY_CAPABILITIES` to `CAPABILITY_CONTROLS`.
- `local_reminder_pause` is declared in `config/edge.php` capabilities (heartbeat build report); it is NOT in
  `EdgeCompatibilityService::CLOUD_OFFLINE_FEATURES` (a Cloud classifier change belongs to Team 6 / the coordinator, as for the W4 tokens).
