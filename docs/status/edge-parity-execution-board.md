# Offline Edge — FULL cashier parity implementation: execution board

Owner directive 20 Sep 2026 (after the 149-record audit, commit b678568). Baseline = master report
`docs/status/edge-online-vs-edge-screen-audit-2026-09-20.md` + appendices `docs/status/audit-2026-09-20/`. The Online POS is the
UI **and** functional reference. No re-audit. Every record is backlog. Owner-dependent items (§7 of the directive) are isolated
below and are NOT started.

Standing safety state: `YELLOW_CABLE=connected · LOCAL_MODE=inactive · AUTO_FAILOVER_ENABLED=no · P6_STARTED=no · LIVE_TENANT_MUTATIONS=none ·
LAB_APPLIANCE=unchanged (0.6.0-edge, no reinstall/reset/re-pair)`.

## Board (20 Sep 2026)

| WORKSTREAM | OWNER/AGENT | FILES_OWNED | DEPENDENCIES | IMPLEMENTATION_STATUS | TEST_STATUS | BROWSER_PROOF_STATUS | BLOCKER |
|---|---|---|---|---|---|---|---|
| **W0 page decomposition + control census** | coordinator (this session) | `resources/views/edge/pos/{index,partials/*,js/*}.blade.php`, `EdgeLocalPosController@screen` (vm), `tests/Fixtures/edge/online-pos-control-census.json`, `tests/MySql/EdgeCashierControlCensusHttpMySqlTest.php` | — | **DONE** — commit f7e977a. 19 fragments, one owning team each; runtime unchanged (same IIFE); stable ids on action buttons + header context | **GREEN** — census 4/4 (278 Online controls: present 11 / equivalent 113 / partial 36 / planned 101 / online_required 17), render 3/3, node --check of the composed script OK, route census (Feature) 4/4 unchanged | not applicable (no visual change) — harness ready (see W7) | none |
| **W0b safety-critical gates** (independent, owner-free) | coordinator | `EdgeLocalPosController` (gates + terminal authority + hidden-amounts), `EdgeLocalReturnService::search` (data scope), `tests/MySql/EdgeCashierRouteGatesHttpMySqlTest.php`, `tests/MySql/Support/EdgeLocalRuntimeFixture.php` (Online cashier permission set), `EdgeCashierPermissionHttpMySqlTest` | W0 | **DONE** — Online route permissions enforced server-side: `tenant.pos.index`, `held-sales.store/cancel`, `split-bill.store`, `table-sessions.open/close`, `shifts.store/close`, `api.manager-approvals.verify`; terminal pin + terminal assignments enforced on select AND on every use; `GET /shift` strips amounts by the shared AmountVisibility rule; returns search + Recent Prints follow UserDataScope | **GREEN** — gates 4/4 (77 assertions), permission 1/1; broad Edge regression 98 tests / 1171 assertions: 1 failure = stale in-memory permission relation in the shared fixture (not a product defect), fixed, suite re-run green | pending (LAB update is owner-gated); dev Edge instance being prepared for the harness | LAB appliance web backends are DOWN (see LAB note) |
| **W1 shell / navigation / states / responsive** | Team 1 (to spawn) | `edge/pos/partials/{styles,header,banners,shell}`, `js/{core,sync,boot}`, view-model flags | W0 | NOT STARTED | — | — | none |
| **W2 real menu + sale workflows** | Team 2 | `partials/{grid,cart}`, `js/{catalog,cart,commercial,payment}`, `EdgeLocalPosService` line resolution/validation, `EdgeLocalPosController@sale/preview` | W0; W6 for envelope fields (modifiers, tips, line discounts, notes) | NOT STARTED | — | — | owner decision for non-cash tenders (isolated) |
| **W3 tables + order lifecycle** | Team 3 | `js/{held,tables}`, restaurant/held controllers + services, new `edge.local.pos.*` routes (route census must be extended deliberately) | W0; W6 only for the combo-void reconcile | NOT STARTED | — | — | none |
| **W4 shifts / permissions / finance** | Team 4 | `js/{shift,returns,reports}`, shift/returns/quick-report controllers, finance views, `edge-build-manifest.json` capabilities | W0, W0b (done); W6 for new events | NOT STARTED (W0b covered the verified permission gaps + hidden-amounts leak) | — | — | owner decisions: Close Branch / Daily Closing, journal reversal, PR drafts (isolated) |
| **W5 kitchen printing** | Team 5 | `js/printing`, print-jobs controller, print worker, KOT/reminder services | W0 | NOT STARTED | — | — | none |
| **W6 Cloud/Edge contract + reconcile + release** | Team 6 | `EdgeSaleEnvelopeBuilder`, Cloud `EdgeInboundSaleIngestionService`, canonical reconcile (243e01d incl. MANAGER-APPROVAL-COMBO-VOID-1), release build | — | NOT STARTED | — | — | none (starts with the reconcile assessment) |
| **W7 continuous verification** | Team 7 / coordinator | `tests/MySql/EdgeCashier*`, census fixture, `tools/edge-browser-proof/` (Playwright on the installed Edge/Chrome, no download) | every lane | **HARNESS READY** — `tools/edge-browser-proof/edge-pos-proof.mjs`: login, DOM census per dialog, paired-viewport screenshots, report with denominators; Playwright package install in progress | census gate is the regression gate | needs a target: dev Edge instance (next) or the LAB after the owner-approved update | no dev Edge instance yet; LAB backends down |

## Regression (W0 + W0b)

Filter: `EdgeCashier* | EdgeLocalPosHttp | EdgeLocalRestaurantHttp | EdgePurchaseReturnHttp | EdgeSupplierFinanceHttp | EdgeLocalPosMySqlTest | EdgeLocalPosRaceTest | EdgeReturnSyncHttp`
— 98 tests, 1171 assertions, 3m08s. One failure (`EdgeCashierReturnHttpMySqlTest` 403 on returns search): the test granted the
return permission AFTER the new gates had already lazily loaded the acting user's permission relation; a real request re-resolves
the user, so the shared fixture now drops the stale relation on grant. Re-run of that suite: 3/3 green. Route census (Feature) 4/4.

## LAB note (read-only observation, 20 Sep 2026 ~13:30 PKT)

The LAB appliance's nginx gateway still listens on 8443/8081, but both PHP web backends (127.0.0.1:8090/8091) do not answer, so
`/edge/local/health` through the gateway times out. The FakePrinter (9100) and the LAB Cloud (9701) are up. This pre-dates W0
(observed before any change; nothing in W0/W0b touches the appliance). Restarting the backends is a LAB *operation*
(no reboot, no elevation, no cable, no router) — **awaiting the owner's go-ahead before running the existing start script**; it is
needed only for the acceptance lane, not for W0–W6 coding.

## Owner-dependent items (isolated; NOT started, NOT excluded)

| Item | Records | Needs |
|---|---|---|
| Offline bank-transfer / cheque / other tenders and refunds | A21, R2.1, R3.4 | envelope + ingestion contract (W6), owner approval |
| Close Branch / Daily Closing financial posting offline | R1.10 | Cloud posting contract, owner approval |
| Manual journal reversal offline | R8.6 | new finance event, owner approval |
| Purchase-return drafts offline | R9.2 | draft lifecycle event, owner approval |
| Customer / printer administration on Edge | R7.2, D-16, D-17, A13 | owner ruling (admin vs operator) |
| Cloud-only reporting scope (Reports Center) | A44, R5.6 | unchanged by this directive |

## Acceptance (second Windows cashier laptop) — not yet reached

Before the LAB update: changed files + release commit, restricted-artifact audit, signed-update verification, migrations/rollback,
LAB outbox/backup state, any Administrator-needing step, exact update/rollback procedure → **owner approval**. After: every
in-scope screen/workflow from the second laptop, paired Online/Edge screenshots at the same viewport, browser-executable
evidence (`tools/edge-browser-proof`). No live-tenant transactions. No P6.
