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
EDGE_BROWSER=EDGE_BROWSER_PLACEHOLDER
ONLINE_BROWSER=ONLINE_BROWSER_PLACEHOLDER
W15=W15_PLACEHOLDER
REMINDER_PAUSE=REMINDER_PLACEHOLDER
FULL_EDGE_GATE=FULL_GATE_PLACEHOLDER
ONLINE_PIXEL_BASELINE_RECAPTURED=PIXEL_PLACEHOLDER
EDGE_ONLINE_GEOMETRY=GEOMETRY_PLACEHOLDER
ARTIFACT_BOUNDARY=ARTIFACT_PLACEHOLDER
OPEN_BLOCKERS=see §4
READY_FOR_RELEASE_BUILD=READY_PLACEHOLDER
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
| Feature/Edge | FEAT_EDGE_PLACEHOLDER |
| Feature/Pos | FEAT_POS_PLACEHOLDER |
| Unit | UNIT_PLACEHOLDER |
| Full Edge MySQL gate | FULL_GATE_PLACEHOLDER |
| Canonical Online suites relevant to the merged files (billing, cancel-frees-table, cash book void, catering kitchen sheet, customer payments, customer search, GRN ×6, manual journal, medical counter, online ordering ×10, partner share, pharmacy demo, quick report, reminder pause, POS screen renders, print agent, printer form, product screen v2, purchasing v2, report delivery v2, supplier finance, supplier running account, table close, bill barcode) | 406 tests / 2,877 assertions on isolated DBs (_t24, 25 min): 396 green; 8 errors + 1 failure are the known environmental reds (BillingMonthlyInvoiceMySqlTest setUp expects an existing tenant on the master; CustomerPaymentsModuleMySqlTest route-catalog row populated only by deploy) — same before and after the merge; 1 failure was a canonical test pinning the literal reminder-pause URL in the Blade, which the shared view now carries through the runtime contract — the test now asserts the Cloud runtime route + the enabled switch (5 / 24 green) |
| Focused: manager approval security (35 / 187), reminder pause Cloud + Edge (PosReminderPause + EdgeReminderPauseHttp 7), Recent Orders parity (6 + 3), printing/KOT, updater regression (EdgeUpdater + EdgeUpdatePlanHash 7), migration-manifest gate (13 + 5 + 16), artifact boundary, dependency closure, route/endpoint leak, no-external-network, shared-view regression gate (render + static) | all inside the suites above; team-level counts in the D1 / CAP / D7 / merge reports |

## 3. Browser and visual gates (Team FB, docs/status/edge-merged-tree-browser-and-baseline.md)

BROWSER_SECTION_PLACEHOLDER

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

## 5. Disposable infrastructure (left running, LAB untouched)

Dev Edge 8095 (dev seed on the merged schema), paired dev Edge 8096 (clone dataset, merged schema via the manifest, STANDBY, worker stopped,
lease released), dev Cloud clone 9704 (merged code + migrated clone DBs), dev Cloud 9702, LAB stack as before.
