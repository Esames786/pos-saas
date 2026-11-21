# Next Edge release — Phase 3 output (shared cashier view cutover)

Date: 6 Oct 2026. Branch `feat/edge-config-refresh-v1`. For the owner (directive "OWNER GO — PHASE 3 SHARED POS CUTOVER", §10 fields).
Safety: source + isolated automated tests + disposable dev instances only. The installed 0.7.0 LAB appliance, the LAB Cloud (:9701), the
LAB tenant data (`pos_lab_tenant_edge.sales_orders` 5 before and after, LAB lease untouched), production and P6 were not touched; no Local
Mode, no takeover, no deploy, no signed artifact. Two API session-limit cuts (4 and 5 Oct) interrupted five teams mid-run; every piece was
verified again by the coordinator before it was committed.

```
CURRENT_HEAD=2601ece (last source/tooling commit; this report is the documentation-only commit directly on top of it — same pattern as 6cac0e8 on 7f8794d)
ROUTE_CUTOVER_COMMIT=80d04a6 (Stage A: edge.local.pos.screen → the shared view; old page unreachable) · 180af6d (Stage B: old tree deleted)
SECURITY_FIX_COMMIT=fd8f495 (eligibility contract, bootstrap v8, Edge + Cloud self-approval / deactivated refusal) · e4fdd6d (Cloud also requires the action's permission, ONE map)
ONLINE_CLONE_17_17=YES — W01–W17 all PASS on the disposable clone: full run 16/18 (proof-final\online) + W08 PASS after the clone approver received the action permissions the §1 rule requires (proof-final\online-w08-w16) + W16 PASS with earlier runs' leftover held order / table cleared through the UI first (proof-final\online-w16-clearall, --clear-all-open-work)
EDGE_17_17=YES — 17/17 PASS by real verdicts (HTTP outcome + DOM confirmation) + W15 EXPECTED-DIFFERENCE (customer quick-add disabled with the capability hint — the approved Phase 2 state, Phase 4 removes it); evidence\phase3\proof-final\edge
ONLINE_17_17=YES (see ONLINE_CLONE_17_17)
OLD_EDGE_POS_FILES_DELETED=25 (resources/views/edge/pos/** 22 · EdgeCashierControlCensusHttpMySqlTest · online-pos-control-census.json · edge-pos-proof.mjs); EdgeLocalPosController::screen() removed
OLD_EDGE_POS_REFERENCES=0 (no view()/@include/@extends of edge.pos.* under app/ or routes/, no edge.local.* action renders one; asserted by EdgeSharedPosRegressionStaticGateTest + the render gate, strict-only)
CONTROL_CENSUS_REPLACEMENT=EdgeSharedPosRegressionGateMySqlTest (render half, both runtimes in one run) + EdgeSharedPosRegressionStaticGateTest; the old id inventory lives on as tests/Fixtures/edge/shared-pos-required-ids.json (276 ids, no view hash); category mapping in docs/status/edge-phase3-census-replacement.md §2
ONLINE_PIXEL_RESULT=NONE — Online after Phase 2 (reference) vs Online after Stage B (clone, 1366×768, status slot masked): 01-main 0.453 %, 02 0.434 %, 04 0.382 %, 08 0.016 %, 10 0.453 %, 11 0.000 %, 12 0.373 %, 13 0.233 % (all shift-badge / stock text); 03 / 05 / 06 / 09 differ by the records the proof runs created on the clone (customer list length, held orders, recent orders, occupied tables) — list content, no box moved; evidence\phase3\cp-diff-online-stageB-1366
EDGE_ONLINE_GEOMETRY_RESULT=NONE — same dataset (paired instance 8096 bootstrapped from the clone): 998 boxes, 13 states × 2 viewports, 0 geometry differences, max deviation 0 px; evidence\phase3\geometry-samedata
ARTIFACT_BOUNDARY=GREEN — EdgeApplianceArtifactBoundaryTest; EdgeArtifactTest manifest now requires the shared view, layouts/pos, tenant/pos partials + js, the shift / sales-return / split-bill screens, edge/auth/login, edge/health, and asserts the old page is not in the plan
DEPENDENCY_CLOSURE=GREEN — EdgeApplianceDependencyClosureTest (scans the shared views), Blade compile gate widened to tenant/pos/** + layouts/pos (old entry dropped), no-external-network scan incl. linked CSS, route manifest / allowlist + URI census, endpoint leak test — all in Feature/Edge 168 / 36,906
FULL_TEST_RESULT=GREEN — full Edge MySQL gate on 180af6d (test-mysql.sh --filter Edge, shared _edgewt DBs, run while the browser proofs were idle): 603 tests / 8,376 assertions, 1 skipped (guarded dev seed), 0 failures, 0 errors, 27 min 36 s (log: evidence\phase3dge-mysql-gate-180af6d.log); Feature/Edge 168 / 36,906; Feature/Pos 26 / 456
OPEN_FINDINGS=see §3
NEXT_PHASE=Phase 4 — offline add-customer (owner §7 contract), then Phase 5 (second-laptop paired acceptance, signed release with the updater fix + bootstrap v8 + LAB approver PIN, owner-approved LAB update)
```

## 1. What landed (commits on top of 6cac0e8)

| Commit | Content |
|---|---|
| 7facd78 | §6 B–F normalisation (lenient Edge totals quote like Online, Online retry wording, reservation refusal shape, `#ctx-terminal-name` bug fixed on both runtimes, `idempotent_replay` on Edge Direct Pay) + §8 device-version page update proven without re-pairing |
| fd8f495 | §1 security: `may_approve_pos` eligibility from the Cloud's manager-PIN state (bootstrap v7 → v8, Edge column + migration), verifyManager = self-approval → credential → eligibility → action permission; Cloud creator refuses self-approval + deactivated; one-time consumption / replay unchanged; the owner's seven cases as Cloud + Edge tests |
| e4fdd6d | §1 Cloud side: the approver must hold the action's permission on the Online POS too — ONE map `ManagerApprovalService::ACTION_PERMISSIONS` |
| 80d04a6 | Stage A route swap; Edge held settle requires the print intents (Online 422 shape); 21 MySQL classes migrated (145 re-targeted / 214 kept / 90 retired with reasons) |
| 7c40923 | §5 census replacement gate (render + static) + `geometry-compare.mjs` |
| 40ce17a | same-dataset comparison: paired dev instance (`serve-clone.sh`), geometry 0 differences, three product gaps found |
| 180af6d | Stage B: old tree + census deleted, `/edge/local/pos/shared` 301, artifact manifest, gaps A (combo `category_id` in bootstrap/refresh) + B (empty-query customer lookup) fixed, Edge menu (health / finance / logout) behind the shared `#pos-sidebar-toggle`, Edge Direct Pay requires the print intents |
| 2601ece | harness v2 (verdict = HTTP + DOM; W06 settle after merge; W16 clears open work through the UI; `--clear-all-open-work` for an instance that cannot be reset), README rewritten |

## 2. Browser proofs (final runs, one harness version, both runtimes)

| W | Edge (dev instance, fresh seed) | Online (disposable clone) |
|---|---|---|
| W01 login / slot / badge | PASS | PASS |
| W02 shift open page | PASS | PASS |
| W03 cash sale + print panel | PASS | PASS |
| W03M modifier item | PASS | PASS |
| W04 customer sale | PASS | PASS |
| W05 hold → recall → settle | PASS | PASS |
| W06 dine-in lifecycle incl. KOT round 2, move, merge, settle | PASS | PASS |
| W07 void sent item + approver | PASS | PASS |
| W08 discounts % / fixed, hold-with-discount refused | PASS | PASS (after the clone approver received the action permissions the new §1 rule requires — fixture, see §3.2) |
| W09 return in iframe + returns screen | PASS | PASS |
| W10 reprint | PASS | PASS |
| W11 print retry rule | PASS | PASS |
| W12 quick report | PASS | PASS |
| W13 reservation | PASS | PASS |
| W14 split bill | PASS | PASS |
| W15 customer quick-add | EXPECTED-DIFFERENCE (disabled + hint) | PASS |
| W16 shift close page (blind), open work cleared through the UI first | PASS | PASS (leftovers of earlier runs cleared through the UI first; shift closed, POS shows No Open Shift) |
| W17 logout | PASS | PASS |

Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\phase3\proof-final\{edge,online,online-w08-w16,online-w16-clearall}\` (report.json + one PNG per
step; every POS request/response status, `ok/code/message`, latency, toasts, console errors).

## 3. Open findings

1. **Gap C (owner call):** on a STANDBY appliance the Recent Orders list is the returnable-sale mirror — no cancelled sales, no table label
   (the Cloud projection sends `restaurant_waiter_id` but not `restaurant_table_id`; statuses limited to the returnable ones). Options in
   `docs/status/edge-phase3-stage-b-cleanup.md` §4: (1) mirror `restaurant_table_id` + Online's "every non-held sale" rule, (2) table id
   only, (3) leave as a return-only window. Recommendation: (1) if a standby's Recent Orders should read like Online's.
2. **LAB data consequences of §1 (pre-update checklist for the next LAB release):** the LAB tenant has no `manager_pins` row and its
   approver holds only `tenant.pos.void-kot-item`; after the update NO user can approve a discount / void / cancel / return until the LAB
   approver gets a manager PIN (Online user screen) AND the action permissions (`tenant.pos.store`, `tenant.held-sales.cancel`,
   `tenant.sales-returns.store`, `tenant.pos.void-kot-item`), followed by a config refresh (the watermark moves on `manager_pins`). The
   LAB tenant also lacks `tenant.shifts.create` / `tenant.shifts.close-form` / `tenant.sales-orders.split-bill` (seeded by lab-cloud.php, not
   TenantProvisioner). Real tenants provisioned by TenantProvisioner have all of these; managers hold the action permissions by role.
3. **Online behaviour change from §1 (deliberate):** a manager-PIN holder without the action's permission can no longer approve that action
   on the Online POS (`This user is not authorized to approve that action.`); a manager approving their own request is refused server-side.
4. `EdgeCleanMachineInstallMySqlTest` (needs the appliance toolchain) was updated for the shared page but not run in Phase 3; it runs in
   the release gate.
5. The appliance's Recent Prints state (`07-recent-prints`) never appears within the capture window on EITHER runtime (a modal that needs a
   printed job) — skipped in every pixel/geometry run, identical on both sides; not a parity fact.
6. Harness note: the single-threaded dev servers share one MySQL with the test gates; under load a page can take > 60 s — every request's
   latency is recorded, so a slow run stays attributable to the box.

## 4. Gates run for Phase 3 (owner §9)

| Gate | Result |
|---|---|
| Feature/Edge | 168 tests / 36,906 assertions green (normal; strict cutover is the only mode) |
| Feature/Pos | 26 / 456 green |
| Focused manager-approval suites (Cloud + Edge) | 35 tests / 187 assertions green (`ManagerApproval` filter, _t9) |
| Full Edge MySQL gate | 603 tests / 8,376 assertions, 1 skipped, 0 failures (27 min) |
| Edge real-browser | 17/17 (+ W15 expected difference) |
| Online disposable-clone real-browser | 17/17 (full run + two re-runs after fixture fixes, see §2) |
| Online before/after pixel regression | NONE (geometric states ≤ 0.45 %; list-content states differ by the proof's records) |
| Edge-vs-Online paired geometry | NONE (998 boxes, 0 differences, same dataset) |
| Artifact boundary · dependency closure · Blade compile gate (tenant POS view) · no-external-network incl. linked CSS · route manifest / allowlist · endpoint leak · shared-view regression gate | all green inside Feature/Edge + the MySQL gate |

## 5. Disposable infrastructure (LAB machine) left running

Dev Edge 127.0.0.1:8095 (dev seed, re-seeded for the final run), paired dev Edge 127.0.0.1:8096 (clone dataset, STANDBY, no worker; its
clone lease released), dev Online clone http://edgehomelab.localhost:9704 (`Start-DevCloud.ps1 -Port 9704 -MasterDb pos_devonline_master_edge`),
dev Cloud 9702 (live worktree), LAB Cloud 9701 + 7/7 workers + FakePrinter (LAB stack, untouched by Phase 3).
