# Next Edge release — MERGED-TREE RECONCILIATION REPORT (owner directive "CANONICAL RECONCILIATION / PHASE 5 PRE-RELEASE", final section)

Date: 20 Oct 2026. Branch `feat/edge-config-refresh-v1`. Safety: source, isolated tests and the disposable dev pair only. LAB appliance (0.7.0 / 7b8f886),
LAB Cloud (:9701), LAB data (`pos_lab_tenant_edge.sales_orders` 5), production, Khatri, Kashif, Tawakal untouched; no LAB migration, permission, PIN,
Local Mode, takeover or deploy; AUTO_FAILOVER_ENABLED=no; P6 not started. Local Mode happened ONLY on the disposable dev appliance 8096 against the
disposable clone 9704 for the W15 proof, ending in a handback and a released lease. No release built or signed.

```
MERGED_HEAD=768ac71 (merge code at 4366b47; then 3d21b1c merge report + system map, 00b075c D1, 768ac71 D2/D3/D4/D6; this report follows)
CANONICAL_HEAD_INCLUDED=ccc67fa1 (canonical feat/14d-2-plan-upgrade-requests at merge time = its current head; merge base is now ccc67fa1; the Edge branch was NOT merged into canonical)
PRE_MUTATION=EDGE_HEAD 63748f8 · MERGE_BASE b529c95 · CANONICAL_REPO_HEAD moved 99e9365 → 92b1148 → 8230eed → ccc67fa1 during the work (re-checked before every chunk) · COMMITS_CANONICAL_AFTER_70c4e43 = 31 first-parent / 87 total
CONFLICTS_RESOLVED=9 hunks in 5 files (index.blade.php #qr-email; ShiftController uses; ManualJournalController reverse + helpers; POSController recipe line / closure vars / PosPageData keys; AppServiceProvider register) — each recorded in docs/status/edge-canonical-merge-report.md §3 with CANONICAL_INTENT / EDGE_INTENT / RESOLUTION / TEST_PROVING_CANONICAL / TEST_PROVING_EDGE; no wholesale ours/theirs; resources/views/edge/pos/** stayed deleted
MIGRATIONS_EDGE_REQUIRED=121
MIGRATIONS_EDGE_SCHEMA_SAFE=67
MIGRATIONS_CLOUD_ONLY=24
UNCLASSIFIED_MIGRATIONS=0 (manifest edge-tenant-migration-manifest@2026-10-20.1, 212 entries; CI gate EdgeTenantMigrationManifestTest; 16 upgrade tests for the canonical RUN set green; the paired dev appliance upgraded through the manifest: 16 RUN / 24 SKIP_CLOUD_ONLY, plan hash sha256:127412ad… identical in dry-run and apply)
EDGE_BROWSER=17/17 — W01–W17 (+ W03M) all PASS on dev Edge 8095 (fresh merged-schema seed, Local Mode, 16 min, evidence\phase5\proof-edge; W15 = EXPECTED-DIFFERENCE there because the never-paired 8095 has no Cloud-advertised customer_create: #qa-save disabled with hint + direct POST 422 CLOUD_CAPABILITY_NOT_ADVERTISED; W11 retry refusal now carries Online's exact text); PLUS the paired appliance 8096: STANDBY W01/W15/W18 (both STANDBY 422 negatives, nothing written) and the Local-Mode WRITER run W01/W02/W18/W15/W16 all PASS with 0 HTTP errors
ONLINE_BROWSER=17/17 — W01–W17 (+ W03M modifier sale) all PASS on the disposable clone 9704 running the MERGED Online POS (evidence\phase5\proof-online: 18 PASS / 0 FAIL); the new W18 reminder-pause workflow PASS (proof-online-w18)
W15=PASS end to end on the disposable pair (8096 Local Mode → clone 9704) with the exact Phase 4 sequence: CUSTOMER_CREATE_NEW PASS (till +92 300 5550101 → local 1000000003, phone_normalized 03005550101, customer_uuid 01M5C6C3P3NHT1RAX5YEW423YD + address_uuid; Cloud: exactly ONE customer under the same uuid, address once, sale → that customer) · CUSTOMER_PHONE_MATCH PASS (existing phone reused on the till; a Cloud-only phone created Online after the last refresh matched by phone_normalized at ingestion, 1 customer per key, name kept) · CUSTOMER_ALIAS PASS (customer_uuid_aliases row source phone_match, device-stamped, sale repointed) · CUSTOMER_REPLAY_IDEMPOTENT PASS (both v3 envelopes re-POSTed byte-identically → 200 already_applied with the first ingestion_uuid / official_sale_no, counts unchanged) · CUSTOMER_REFRESH_RECONCILIATION PASS (standby refresh 21→23 after the handback: local duplicate merged into the Cloud customer, 0 duplicate phone keys, 0 deletions) · envelopes v3 new_customer ×2 + v1 customer ×1, shift block identity-only (no reminder field); lease handed back and released afterwards
REMINDER_PAUSE=PASS on both runtimes in a real browser — Online W18 (pause → resume on the merged POS, Cloud shift) and Branch Server W18 inside the W15 writer run on the paired appliance 8096 in Local Mode (the same #pos-reminder-toggle, local shift row, no Cloud call); HTTP/MySQL suites PosReminderPause + EdgeReminderPauseHttp green
FULL_EDGE_GATE=GREEN — 717 tests / 9,744 assertions, 1 skipped (guarded dev seed), 0 failures, 0 errors on 768ac71 (58 min, shared _edgewt DBs; log C:\Users\Dell\BingooEdgeLab\evidence\phase5\edge-mysql-gate-768ac71.log)
ONLINE_PIXEL_BASELINE_RECAPTURED=YES — new authority = the MERGED Online POS captured on the clone 9704: evidence\phase5\online-baseline-merged (12 states × 1366×768 and 1024×768); Edge same-dataset capture evidence\phase5\edge-samedata-merged; diff-merged-1366: 9 states 0.000 %, 11-review-pay 0.084 %, 08-quick-report 0.279 %, 06-recent-orders 7.092 % — classification in §3
EDGE_ONLINE_GEOMETRY=NONE — geometry-compare on the same dataset (8096 STANDBY vs clone 9704, both no open shift, config revision 23): 998 boxes over 12 states × 2 viewports, 0 geometry / 0 data / 0 presence differences, max deviation 0 px (tolerance 1 px); 07-recent-prints skipped identically on both runtimes as in every run since Phase 3 (evidence\phase5\geometry-merged\compare.json)
ARTIFACT_BOUNDARY=HELD — the distributed appliance artifact physically excludes the Cloud-only domains the merge brought in (Catering, manufacturing, purchasing/AP, suppliers, SaaS billing, ONLINE-ORDERING-1 website + inbox + delivery areas + WhatsApp, Cloud AP posting) and the 24 CLOUD_ONLY migrations; Cloud chrome views may ship but no edge.local route renders them; fonts/CSS self-hosted with no remote reference; the package builder refuses untracked files — proven by EdgeApplianceArtifactBoundaryTest, EdgeApplianceDependencyClosureTest, EdgeArtifactBootTest, EdgeLocalRuntimeBoundaryTest, EdgeRuntimeBoundaryTest and the manifest gate inside the green Feature/Edge and full MySQL gates; no-external-network and route/endpoint-leak tests green
OPEN_BLOCKERS=see §4
READY_FOR_RELEASE_BUILD=NO — HELD FOR THE OWNER, not for the code: every source gate is green (full Edge MySQL gate, Feature/Edge, Feature/Pos, canonical suites minus the two known environmental reds, 17/17 browser on BOTH runtimes, W15 e2e, reminder pause on both, pixel drift none unintentional, geometry NONE, 0 unclassified migrations, artifact boundary held). The build may start only after the owner (a) answers D7-1…D7-5 — D7-1 fixes the approved RUN set the release upgrader ships, so a build before it would carry an unapproved plan hash; (b) accepts §4 items 2 (medical counter / purchasing V2 stay capability-OFF in this release), 6 (06-recent-orders DATA difference = the appliance's own local sale numbers and undelivered print state, successor of Gap C) and 7 (LAB precondition for the Reminder switch: permission grant + Reminder rule as checklist items, D3 forbids a silent grant); and (c) confirms the pre-install report flow of docs/status/edge-next-lab-update-checklist.md. Nothing was built or signed.
```

## 1. Owner decisions applied (D1–D8)

| Decision | Implementation | Commit |
|---|---|---|
| D1 journal guards into ONE service | `ManualJournalRules` / `ManualJournalChart` / `ManualJournalReversalRefused`; `ManualJournalService::guard()` + `reverse()` own R1–R3/R5, the reverse lock and the already-reversed refusal; Online controller thin; the till and the Cloud ingestion (JOURNAL_RULE_REFUSED, terminal) consume the same rules; five owner cases tested on each path | 00b075c |
| D2 reminder-pause Edge twin | `POST /edge/local/pos/reminder-pause` on the LOCAL shift with Online's contract; same shared control enabled on Edge; STANDBY refuses; no Cloud call; the pause ends with the appliance shift and a handback is blocked while a shift is open, so the Cloud shift starts clean (no envelope field) | 768ac71 |
| D3 `tenant.pos.pause-reminders` | catalogue + Cashier (Counter) template for new tenants; existing tenants not granted; audit report lists missing roles/users | 768ac71 |
| D4 website orders CLOUD_ONLY | same component, capability-disabled with hint on Edge, no Cloud endpoint reachable; proven in the regression gate | merge + 768ac71 |
| D5 baseline recapture | see §3 | — |
| D6 partner share | `partnerShare` capability (Cloud ON, Edge OFF, server-side: the Online quick-report modal has no partner-share box); Edge quick report never computes it; bootstrap stays v8 | 768ac71 |
| D7 migration manifest | see the MIGRATIONS fields; enforcement in the upgrader, db-init, artifact builder, package audit, update installer (plan hash) | 63748f8 (+ 6 classifications during the merge) |
| D8 merge order / LAB safety | 14 chunks at canonical's merge points, actual head re-checked each time, per-conflict record, LAB untouched | f1793bc0 … 4366b47c |

Capability differences on the Branch Server are documented in `docs/status/edge-capability-differences.md` (reports, quick-report e-mail,
floors/tables admin, branch select, non-cash tenders, tip, change rider, website orders, partner share, medical counter; customer create and
reminder pause are ON).

## 2. Suites on the final tree (768ac71)

| Gate | Result |
|---|---|
| Feature/Edge | 190 tests / 41,529 assertions green |
| Feature/Pos | 31 / 489 green |
| Unit | 119 tests / 542 assertions: 116 green, 3 red = canonical PRE-EXISTING static-test debt (needle absent in canonical ccc67fa1 own files, verified with git show): CateringClientFeedbackUiRegressionTest sidebar-collapsed hook, EscPosReportPayloadTest two-decimal money (the shared service prints whole rupees), DeliveryRiderReassignmentRegressionTest rider key in POSController; two Edge-induced static tests updated to the runtime form (42bee1a) |
| Full Edge MySQL gate | 717 / 9,744, 1 skipped, 0 failures (58 min) |
| Canonical Online suites relevant to the merged files (billing, cancel-frees-table, cash book void, catering kitchen sheet, customer payments, customer search, GRN ×6, manual journal, medical counter, online ordering ×10, partner share, pharmacy demo, quick report, reminder pause, POS screen renders, print agent, printer form, product screen v2, purchasing v2, report delivery v2, supplier finance, supplier running account, table close, bill barcode) | 406 tests / 2,877 assertions on isolated DBs (_t24, 25 min): 396 green; 8 errors + 1 failure are the known environmental reds (BillingMonthlyInvoiceMySqlTest setUp expects an existing tenant on the master; CustomerPaymentsModuleMySqlTest route-catalog row populated only by deploy) — same before and after the merge; 1 failure was a canonical test pinning the literal reminder-pause URL in the Blade, which the shared view now carries through the runtime contract — the test now asserts the Cloud runtime route + the enabled switch (5 / 24 green) |
| Focused: manager approval security (35 / 187), reminder pause Cloud + Edge (PosReminderPause + EdgeReminderPauseHttp 7), Recent Orders parity (6 + 3), printing/KOT, updater regression (EdgeUpdater + EdgeUpdatePlanHash 7), migration-manifest gate (13 + 5 + 16), artifact boundary, dependency closure, route/endpoint leak, no-external-network, shared-view regression gate (render + static) | all inside the suites above; team-level counts in the D1 / CAP / D7 / merge reports |

## 3. Browser and visual gates (Team FB, docs/status/edge-merged-tree-browser-and-baseline.md — the full evidence)

Team FB ran the whole browser programme on the merged tree (768ac71) against the disposable instances only: the Online clone 9704 (merged
schema, 212 migrations), the paired dev appliance 8096 (clone dataset, D7-upgraded) and dev Edge 8095 (re-seeded on the merged schema).
LAB `pos_lab_tenant_edge.sales_orders` stayed 5 throughout.

| Gate | Result | Evidence |
|---|---|---|
| ONLINE W01–W17 (+ W03M) | 18 PASS / 0 FAIL on the merged Online POS; expected 4xx only (cashier /dashboard 403, hold-with-unconsumed-discount 422, retry-on-queued 422, quick-report e-mail 422 "No owner email is configured"); `POS_RUNTIME.capabilities` carries reminderPause / websiteOrders / partnerShare true on the Cloud; 15/15 tiles show the new SKU line; `#web-orders-btn` absent (clone plan without Online Ordering) | `evidence\phase5\proof-online`, `proof-online-w18` |
| EDGE W01–W17 (+ W03M) | 18 PASS on 8095 (W15 EXPECTED-DIFFERENCE: capability not advertised to a never-paired appliance; W18 PASS by probe — permission held, no Reminder rule in the dev seed so the shared rule hides the switch) | `evidence\phase5\proof-edge` |
| Paired 8096 | STANDBY: slot `STANDBY · CLOUD AUTHORITY`, disabled-with-hint census = web-orders bell, quick-report e-mail, branch select (+2 ordinary tooltips); W15 and W18 → 422 STANDBY before any write. LOCAL MODE writer run W01/W02/W18/W15/W16 PASS, 0 HTTP errors, slot `SYNCHRONIZING · LOCAL MODE` | `evidence\phase5\w15\edge-standby`, `w15\edge-writer` |
| W15 e2e (Phase 4 sequence) | CREATE_NEW / PHONE_MATCH / ALIAS / REPLAY_IDEMPOTENT / REFRESH_RECONCILIATION all PASS (field block above); worker drained 3 → acknowledged 3; handback assess `ready` → handed back; lease released; clone counts customers 14 / addresses 7 / aliases 2 / sales 55 / ingestions 12; 8096 15 customers, 0 duplicate phone keys | FB doc §3 |
| REMINDER_PAUSE (D2) | Online W18 PASS (click → Swal → POST 200 paused:true → "Reminder OFF" → 200 paused:false → "Reminder ON"); Edge 8096 Local Mode W18 PASS through the SAME control against the D2 twin, shift block in the envelope identity-only; 8096 STANDBY 422 STANDBY with the control rendered enabled by the server | FB doc §4 |
| D5 Online baseline | recaptured on the merged clone (12 states × 1366 and 1024) = the new authority; Edge same-dataset capture; both sides STANDBY/Cloud, no open shift, config revision 23 | `online-baseline-merged`, `edge-samedata-merged` |
| Pixels 1366 / 1024 | 9/12 and 8/12 states 0.000 %; 08-quick-report 0.279 / 0.374 % STATE (Send-to-owner capability), 11-review-pay 0.084 / 0.112 % STATE (non-cash tenders off), 13-modifier-entry 0 / 0.164 % STATE-TIMING (1024 slot text past the mask), 06-recent-orders 7.092 / 7.897 % DATA (the appliance's three own sales show local `SO-1-1-…` numbers and "Printing needs attention"/Resume because no print worker ran on 8096, vs the Cloud's official numbers — same boxes). Phase 3 gaps A (combos without category) and B (empty customer lookup) are gone. **Unintentional drift: none** | `diff-merged-1366`, `diff-merged-1024` |
| Geometry | NONE — 998 boxes, 0 geometry / 0 data / 0 presence, max 0 px, tolerance 1 px; 07-recent-prints skipped identically on both | `geometry-merged\compare.json` |

Data preconditions Team FB applied on the DISPOSABLE clone only (P3 precedent, product models, helper guarded to `devonline` DBs): the clone
cashier was granted `tenant.pos.pause-reminders` and one `print_role=reminder` category-printer mapping was added, both flowing to 8096 through
the ordinary config refresh so the pair stayed one dataset. No LAB or production row was touched. The three disposable PHP servers were found
down at ~13:50 and restarted by the team (O4); databases unaffected.

Harness: `shared-pos-workflows.mjs` gains the opt-in W18 reminder-pause workflow (runs only when listed in `--only`; the 17-workflow counts are
unchanged) and W01 `facts.merged_controls` (reminder switch, website-orders bell, Send to owner, SKU-line tiles, medical chrome).

## 4. Open blockers and owner items

1. D7 sub-decisions (docs/status/edge-tenant-migration-manifest.md §7): D7-1 approve the 4 post-baseline OWNER_REVIEW_REQUIRED migrations
   (Phase 4 identity backfill — proven on the pair; three CLOUD_ONLY ones that never run on the appliance); D7-2 the 5 baseline Cloud-side
   tables kept as EDGE_SCHEMA_SAFE vs reclassified CLOUD_ONLY; D7-3 the mixed online-ordering migration (accepted as EDGE_REQUIRED: products /
   combos website price columns are bootstrap-section columns); D7-4 run the sales_orders ENUM/index migration only while handed back;
   D7-5 accept the baseline rule.
2. NEW from canonical's recent work: the medical-store counter (packs, batches, prescriptions, batch first-out) runs on the same shared POS and
   is capability-OFF on the Branch Server in this release; a later release needs the Edge twin decision (TenantFeatures from the Cloud,
   pack_units / batches sections, pack/batch/Rx fields in the Edge sale/held/return twins and the envelope, REFUND-SHIFT-1 mirror).
   Purchasing V2 changed purchase returns to batch-picked returns; the Edge purchase-return twin follows the old flow — owner decision.
3. `local_reminder_pause` capability is declared by the appliance build report but not yet in the Cloud compatibility classifier's
   offline-feature list (informational; no authority semantics).
4. Expected environmental reds in the canonical suites on an isolated master: BillingMonthlyInvoiceMySqlTest (fixture expects an existing
   tenant), CustomerPaymentsModuleMySqlTest route-catalog case (populated only by deploy) — same before and after the merge.
5. LAB: the prepared checklist (docs/status/edge-next-lab-update-checklist.md) stays checklist-only until the release build passes all source
   gates and a new pre-install report (with the D7 plan hash from a dry-run on a copy of the LAB appliance DB) is approved.
6. 06-recent-orders pixel difference (DATA, 7.1 % at 1366): the appliance's recent-orders mirror shows its own local sale numbers and the
   undelivered print state of its own jobs; the boxes are identical (geometry NONE). Successor of Gap C — owner acceptance.
7. F-FB1 (D2/D3 precondition, not a defect): on a tenant upgraded to the merged tree the Reminder switch appears only where a
   `print_role=reminder` mapping routes the terminal AND the cashier holds `tenant.pos.pause-reminders`; the LAB copy has neither, so the
   LAB update checklist needs the grant (owner approval — D3 forbids a silent grant) and, if the switch is wanted, a Reminder rule.
8. O1 — CLOSED 21 Oct 2026 (owner §4-8): reproduced on the disposable pair, verdict PURELY_IDEMPOTENT (two ticks racing one cutover; the
   loser wrote nothing); focused follow-up b3648abd reports `already_current` + attribution + regression test — docs/status/edge-o1-cutover-race.md.

## 5. Disposable infrastructure (left running, LAB untouched)

Dev Edge 8095 (re-seeded on the merged schema after the browser gate, 0 open shifts), paired dev Edge 8096 (clone dataset at config revision 23,
STANDBY, connection online, outbox 7 acknowledged / 0 unacked, 0 open shifts, worker NOT running), dev Cloud clone 9704 (lease holder cloud,
released 08:30:49 UTC, branch mode cloud, 0 open shifts), dev Cloud 9702, LAB stack as before (LAB Cloud from the 7b8f886 export). The three
disposable servers were restarted by Team FB after being found down at ~13:50 local.
