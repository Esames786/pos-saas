# Next Edge release — Phase 2 step 7 report (workflow migration on the shared cashier view)

Date: 3 Oct 2026. Branch `feat/edge-config-refresh-v1`, HEAD **`7f8794d`** (checkpoint was `68fd04b`). For the owner, before the Phase 3 cutover.
Safety: source + isolated automated tests + disposable dev instances only. The installed 0.7.0 LAB appliance, the LAB Cloud (:9701), the LAB tenant
data (sales_orders 5 before and after), production and P6 were not touched; no Local Mode, no takeover, no deploy, no new artifact. The old Edge
page (`edge.local.pos.screen`, `resources/views/edge/pos/**`) is still in place as the fallback.

```
WORKFLOWS_PROVEN_EDGE=17/17 in substance on the shared view in a real browser (Playwright/msedge, 1366x768) — 13 PASS by the tool's own
                     verdict, 4 PASS-in-substance where the tool's check misread the page (W06 no settle step after merge — same on Online;
                     W07 cart-row heuristic; W13 board check — the screenshot shows the reserved tile; W16 refused by the SHARED ShiftService
                     rule "settle all open work" because the run leaves held orders — same rule on Online), 1 EXPECTED-DIFFERENCE (W15 quick-add
                     disabled with the capability hint, owner A5/A6).
WORKFLOWS_PROVEN_ONLINE=14/17 on the disposable clone of the LAB tenant — 3 FAIL are the clone's (and the LAB tenant's) missing permission
                     rows tenant.shifts.create / tenant.shifts.close-form / tenant.sales-orders.split-bill (403 on the shift open page, shift
                     close page and split-bill page; the shift still opened/closed through the POST). Not a code gap: TenantProvisioner seeds
                     them for real tenants; the LAB tenant came from lab-cloud.php.
CONTRACT_GAPS_FOUND=4 (+1 view deviation) — all fixed with regression tests (W-G3): G1 JSON-string line modifiers refused (blocked every sale
                     and hold from the shared view), G2 hold response without client_line_key (second KOT round refused), G3 customer without
                     canonical customer_uuid refused (seed data; Cloud export now generates-once, envelope rule kept), G4 reservation on a
                     stricter authority gate than every other mutation; E3 quick-add / add-address not rendered disabled-with-hint.
BACKEND_GAPS_CLOSED=4 (W-G1) — held-order settle prints like Online (same intents, same printing block, exactly-once on replay), recipe
                     "makeable" preview through ONE shared class (Online moved verbatim, Edge computes over the accepted baseline), quick-report
                     JSON 403 shape, Cloud Offline Edge page shows the appliance's reported build.
ONLINE_PIXEL_REGRESSION=NONE — Online after Phase 2 (68fd04b) vs Online after step 7 (7f8794d), same LAB dataset (clone), 1366x768, status slot
                     masked: main/category/context/quick-report/cart/review-pay/modifier-entry ≤ 0.44 % and every changed pixel is DATA (shift
                     badge text "Shift Open · Business date …" vs "No Open Shift", stock counters after the proof sales); customer modal /
                     held / recent / table workspace differ by DATASET STATE only (customers, held orders, occupied tables created by the
                     proof); qty-entry 4.7 % was a capture caught mid fade-in (re-capture: see §4).
LAYOUT_DIFFERENCES_EDGE_VS_ONLINE=NONE geometric (both proof runs; the only visual deltas are dataset, permission and the shared status slot).
TEST_STATUS=OK — Edge MySQL gate on 7f8794d (test-mysql.sh --filter Edge, shared _edgewt DBs, run concurrently with the browser proofs):
                     587 tests / 7,652 assertions, 1 skipped (guarded dev seed), 0 failures, 0 errors, 37 min 12 s
                     (log: evidence\phase2\edge-mysql-gate-7f8794d.log). Team gates on isolated DBs: Feature/Edge 164 / 36,131,
                     Feature/Pos 25 / 445, W-G1 filter 99 / 2,065, W-G3 filter 211 (209 pass, 1 skipped, census re-pinned).
COMMITS=cabe940 W-G1 · 5a39317 W-G2 (proof tool + report) · 7f8794d W-G3 · (this report)
```

## 1. What step 7 did

1. **Backend contract gaps the checkpoint listed** (Team G1, `docs/status/edge-w-g1-contract-gaps-report.md`): held settle printing parity,
   `RecipeAvailability` shared class, quick-report 403 shape, Offline Edge device page with version/schema/capabilities/reported-at.
2. **Real-browser workflow proof of the shared view in BOTH runtimes** (Team G2, `tools/edge-browser-proof/shared-pos-workflows.mjs`,
   `docs/status/edge-w-g2-workflow-proof-report.md`): W01 login/slot · W02 shift open page · W03 cash sale + print panel · W03M modifier
   item · W04 customer sale · W05 hold/recall/settle · W06 dine-in (open, KOT round 1, KOT round 2, workspace, bill preview, bill requested,
   move, second table, merge) · W07 void sent item with approver · W08 manual discount % / fixed + hold-with-discount refusal · W09 return
   inside the POS iframe + direct returns screen · W10 reprint · W11 print retry · W12 quick report · W13 reservation · W14 split bill ·
   W15 customer quick-add · W16 shift close page (blind count) · W17 logout.
3. **Fixes for what the browser found** (Team G3, `docs/status/edge-w-g3-gap-fixes-report.md`): G1–G4, E3, X1 (split-bill page script guard;
   zero-geometry theme hook in the Edge chrome so theme-script writes the same `data-*` attributes as on Online), X2 (Edge terminal list follows
   `UserDataScope::terminalsForPos` like Online).
4. **Final proof runs on the fixed tree** (no request rewriting): Edge `evidence\phase2\step7-workflows-final\edge\`, Online
   `…\step7-workflows-final\online\` (report.json + a screenshot per step; every POS request/response status and `ok/code/message` logged).

## 2. Workflow verdicts (final runs)

| W | Workflow | Edge shared view | Online POS (clone) | Note |
|---|---|---|---|---|
| W01 | login, slot, terminal/shift badge | PASS (`LOCAL MODE · MANUAL SWITCH`) | PASS (`ONLINE · CLOUD`) | |
| W02 | shift open via the separate page | PASS | 403 page (missing permission row) → opened via POST | clone/LAB data |
| W03 | cash sale, receipt + KOT jobs | PASS | PASS | |
| W03M | modifier item sale | PASS | PASS | G1 fixed (was 422) |
| W04 | customer sale | PASS | PASS | G3 fixed (was 422) |
| W05 | hold → recall → settle | PASS | PASS | settle now prints (W-G1) |
| W06 | dine-in lifecycle incl. KOT round 2, move, merge | PASS in substance (tool: no settle step after merge) | same | G2 fixed (round 2 was 422) |
| W07 | void sent item + approver | PASS in substance (verify 200, re-hold 200 with void_print_jobs) | same | |
| W08 | discounts %, fixed; hold-with-discount refused | PASS; 422 identical text on both | PASS | A2 parity |
| W09 | return in iframe + returns screen | PASS | PASS | |
| W10 | reprint | PASS | PASS | |
| W11 | print retry rule | PASS (no failed job → no Retry; 422 on queued) | PASS (same rule) | message wording differs (cosmetic) |
| W12 | quick report view/network; email | PASS (email disabled + hint) | PASS (email 422 no owner email) | expected difference |
| W13 | reserve → details → unreserve | PASS in substance (screenshot shows F4 Reserved · Proof Guest) | PASS | G4 fixed (was 422) |
| W14 | split bill | PASS | 403 page (missing permission row) | clone/LAB data |
| W15 | customer quick-add | EXPECTED-DIFFERENCE: disabled + hint | PASS (created + attached) | E3 fixed |
| W16 | shift close page (blind) | refused by shared rule (held orders left by the run) | 403 page (missing permission row) → closed via POST | sequencing |
| W17 | logout | PASS | PASS | |

Row counts after everything: `pos_lab_tenant_edge.sales_orders` **5** (unchanged); clone `pos_devonline_tenant_edge` 16; dev Edge
`bingoo_edge_devtest_local` 12 (+1 return, 1 open shift); dev Edge customers with NULL `customer_uuid` 0.

## 3. Online-vs-Edge mismatches noticed and deliberately NOT changed (Online is the specification)

- Edge settle keeps the two print intents optional (old fallback page + existing tests post without them; the shared page always sends both;
  Online requires both) — one-line change at cutover.
- Edge `totals/quote` validates required modifier groups (422 while the modifier modal is still open; the page ignores it); Online's quote does not.
- Retry refusal text: Edge "Only a terminally-failed local delivery can be retried." vs Online "Only failed or cancelled jobs can be retried."
- Reservation refusal shape: Online 422 `errors` bag vs Edge `{ok:false, message}` (the view reads `message`).
- Recipe preview ignores `applicable_order_types` on both sides (moved verbatim).
- `#ctx-terminal-name` shows "No terminal" on BOTH runtimes while terminal 1 is selected (shared-view nit, identical).
- Edge Direct Pay `storeSale` still omits `idempotent_replay` (toast wording only).

## 4. Online pixel re-check (same dataset, status slot masked)

`evidence\phase2\cp-online-step7\1366x768` vs `cp-online-after\1366x768`, diffs in `cp-diff-online-step7-1366` (red = changed, blue = mask):
01-main 0.415 % (shift badge text + stock counters), 02-category 0.407 %, 04-context 0.441 %, 08-quick-report 0.015 %, 10-cart 0.415 %,
11-review-pay 0.000 %, 13-modifier-entry 0.211 %; 03-customer-modal / 05-held / 06-recent / 09-table-workspace differ by the records the proof
created; 12-qty-entry 4.742 % = Bootstrap fade-in caught mid-transition (translucent, 20 px above its resting place). Re-capture with a longer settle (`cp-online-step7b`, diffs `cp-diff-online-step7b-1366`): 12-qty-entry **0.355 %** (shift badge + stock text only), every other geometric state unchanged (01 0.415, 02 0.407, 04 0.355, 08 0.015, 10 0.415, 11 0.000, 13 0.211 %).

## 5. Items for the owner (data / decisions), none blocking the code

1. **SECURITY (unchanged, still awaiting your decision):** `tenant.pos.void-kot-item` is both the cashier void permission and the Edge approver
   marker; no self-approval guard. Proposed: `has_manager_pin` flag in the bootstrap + `verifyManager` requires it + approver ≠ requester.
2. **LAB tenant permission rows** `tenant.shifts.create`, `tenant.shifts.close-form`, `tenant.sales-orders.split-bill` do not exist on the LAB tenant
   (seeded by `lab-cloud.php`, not by `TenantProvisioner`) → Online shift open/close pages and split-bill page answer 403 there; real tenants have
   them. Pre-update checklist item for the next LAB release (and the SQL the owner can run on the disposable clone to finish W02/W14/W16 Online).
3. **LAB tenant has no manager PIN row** → Online approvals impossible on the LAB tenant as seeded (clone got one for the proof).
4. Dev Edge dataset has 0 denominations and blind count on; Online clone has no owner e-mail for the quick report (expected 422).
5. Phase 4 (offline add-customer) and Phase 5 (same-dataset paired pixel acceptance on the second laptop, signed release with the updater fix,
   owner-approved LAB update) remain as planned.

## 6. Phase 3 cutover — proposal (NOT executed; needs your go)

1. Swap `edge.local.pos.screen` to render the shared view (`tenant.pos.index`) and make the two print intents required on the Edge settle.
2. Delete `resources/views/edge/pos/**` and the old-page-only JSON twins that nothing else uses; retire `EdgeCashierControlCensusHttpMySqlTest` +
   `tests/Fixtures/edge/online-pos-control-census.json` (the shared view IS the census).
3. Re-run: Feature/Edge, Feature/Pos, the full Edge MySQL gate, both browser proofs, the Online pixel pair; artifact boundary + dependency closure
   gates (the old views leave the plan).
4. Then (Phase 5) the paired same-dataset pixel acceptance on the second laptop and the signed release.

## 7. Disposable infrastructure used (LAB machine)

Dev Edge instance `tools/edge-dev-instance/serve.sh` (127.0.0.1:8095, DB `bingoo_edge_devtest_local`, re-seeded by G3); disposable Online clone
`pos_devonline_master_edge` + `pos_devonline_tenant_edge` (mysqldump copies of the LAB DBs; `tenant_databases` repointed; inherited authority lease
released — LESSON: a cloned tenant carries its lease and the Cloud fences the clone with 409 BRANCH_LOCAL_EDGE_ACTIVE until it is released),
served by `Start-DevCloud.ps1 -Port 9704 -MasterDb pos_devonline_master_edge` (new `-MasterDb` parameter); FakePrinter (127.0.0.1:9100) found
stopped and restarted (LAB Cloud :9701, 7/7 workers, appliance untouched; no reboot since 22 Sep).
