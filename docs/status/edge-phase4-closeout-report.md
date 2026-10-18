# Next Edge release — Phase 4 close-out (owner directive "CLOSE PHASE 4, THEN START PHASE 5 PRE-RELEASE", §8)

Date: 19 Oct 2026. Branch `feat/edge-config-refresh-v1`. Safety: source, isolated tests and the disposable dev pair only. The installed LAB
0.7.0 appliance, the LAB Cloud (:9701), the LAB data (`pos_lab_tenant_edge.sales_orders` 5 before and after), production, Khatri, Kashif and
Tawakal were not touched; no release built or deployed; AUTO_FAILOVER_ENABLED=no; P6 not started. The ONE Local Mode + takeover of this
phase happened on the DISPOSABLE dev appliance (8096) against the DISPOSABLE Cloud clone (9704) — the W15 proof needs a writer — and
ended with a handback and a released lease; never the LAB.

```
PHASE4_COMMIT=41650b4 (37 files, the approved offline add-customer implementation; recorded before commit: HEAD 718057e, status 28 M + 15 ??, diff --stat 28 files +794/−42 tracked)
  + f6ed8ba Shifts / Sales returns menu entries · 50d5bff Gap C · 7999a50 F1 identity-adoption fix · 87f496a W15 proof + harness · 4b0de4f F2 Online add-address permission · 738bf3c reconciliation doc · (this report)
WORKTREE_CLEAN=YES (git status empty after each commit; everything pushed to origin)
GAP_C_STATUS=DONE — ONE shared Recent Orders rule (app/Support/Pos/RecentOrders.php) for POSController::recentSales and the Edge twin; the mirror (returnable package generation 2) carries every listed sale (cancelled included) with restaurant_table_id, channel, rider, payment_status, returnable flag; table label resolved by identity like Online; refresh updates in place; returns stay returnable-only; owner tests: dine-in with table, quick sale without table, cancelled sale (listed, not returnable), refresh/update, shared POS rendering twin, return refusal — 6 + 3 static-rule tests green (50d5bff)
W15_EDGE_BROWSER=PASS — paired dev appliance 8096 in LOCAL_ACTIVE after a supervised takeover on the dev clone: quick-add "+92 300 7654321" → 200, local id 1000000002, phone_normalized 03007654321, 26-char customer_uuid; attached at once; address through the same modal (address_uuid); CASH sale 201 with that customer; STANDBY negative (422 STANDBY) and nothing-advertised negative (8095: disabled + hint, 422 CLOUD_CAPABILITY_NOT_ADVERTISED) also in the browser; evidence BingooEdgeLab\evidence\phase4\w15\{edge-writer-2, edge-standby, edge-8095-nothing-advertised, online-cloud-only-customer}
CUSTOMER_CREATE_NEW=PASS — clone: exactly one customer (id 11) with the offline uuid as canonical, phone key once, address once by address_uuid, sale linked, ingestion applied (envelope v3 kind new_customer)
CUSTOMER_PHONE_MATCH=PASS — "0092 300 1110001" reused the synced Lab Customer One at the till (v1 customer envelope); a Cloud-only phone typed as "+92 300 2349813" was created locally and matched on the Cloud by phone_normalized — no duplicate, name kept
CUSTOMER_ALIAS=PASS — customer_uuid_aliases: one row offline uuid → canonical customer 10 (source phone_match, device uuid recorded)
CUSTOMER_REPLAY_IDEMPOTENT=PASS — the sender resends nothing once acked; a byte-identical replay of the two outbox envelopes answered 200 already_applied with the SAME ingestion uuids and sale numbers; counts unchanged (11 customers / 1 alias / 4 addresses / 39 sales / 9 ingestions)
CUSTOMER_REFRESH_RECONCILIATION=PASS — after handback the refresh (18→20) kept the local ids under the Cloud uuids, inserted the matched Cloud customer locally, repointed the sale, merged the alias (local 1000000001 → canonical 10), deactivated nothing, 0 duplicate phone keys; and the F1 fix was verified healing the skewed appliance on its first refresh (17→18, three Cloud-owned addresses adopted the Cloud identities)
OLD_CLOUD_COMPATIBILITY=PASS — never-paired appliance: quick-add disabled with the hint, endpoint 422 CLOUD_CAPABILITY_NOT_ADVERTISED; tests: capability withdrawn → a sale with a local customer fails closed (no sale, no envelope); old Cloud answers SCHEMA_UNSUPPORTED → the sender parks the row under the bounded backoff and the identical bytes apply once after the upgrade (2 tests / 22 assertions)
MYSQL_GATE=GREEN — full Edge MySQL gate on 4b0de4f (test-mysql.sh --filter Edge, shared _edgewt DBs): 642 tests / 8,915 assertions, 1 skipped (guarded dev seed), 0 failures, 0 errors, 37 min 13 s (log: BingooEdgeLabvidence\phase4dge-mysql-gate-4b0de4f.log)
FEATURE_EDGE=GREEN — 176 tests / 37,035 assertions
FEATURE_POS=GREEN — 31 tests / 475 assertions
CANONICAL_ASSESSMENT_READY=YES — docs/status/edge-canonical-70c4e43-reconciliation.md (738bf3c): merge base b529c95; Edge 153 commits / 513 files, canonical 334 / 581; 13 overlapping files, 5 textual conflicts (6 hunks: ManualJournalController ×2, POSController, ShiftController, AppServiceProvider, tenant/pos/index.blade.php), 6 semantic conflicts, 0 migration table+column collisions; merge-tree run non-mutating (exit 1, tree recorded); shared-view impact list; strategy: merge canonical INTO Edge (no rebase), 70c4e43 first, then canonical's two newer commits (a9499a1 PHARMACY-DEMO-1 already re-touches POSController / index.blade / SalesReturnService); 8 owner decisions D1–D8
OPEN_BLOCKERS=see §3
```

## 1. Findings fixed during the close-out (both would have broken the next LAB update)

- **F1 (blocker)**: the Phase 4 tenant migration minted `address_uuid` per row on the Cloud AND on an already-bootstrapped appliance, so the
  first refresh after the upgrade conflicted forever (appliance stuck one revision behind, takeover refused). Fixed in 7999a50: the appliance
  never mints identities for Cloud-owned rows; the refresh adopts the Cloud uuid for Cloud-origin rows (NULL or locally minted); a conflict
  remains only for a local-origin clash; customers protected symmetrically; reservations repointed; the real skew is reproduced in a test and
  the real schema upgrader is exercised. LAB impact: nothing manual — the first accepted v8 refresh adopts the identities. The LAB MASTER must
  run its migrations too (edge_devices version columns) — added to the checklist.
- **F2 (Online bug)**: the POS add-address route was gated by an ungrantable route name, so no cashier could save an address from the shared
  modal on the Online POS. Fixed in 4b0de4f with a route-name → permission alias (add-address accepts quick-store); no grant, migration or
  permission row needed; every quick-store holder gains it on the Cloud deploy.

## 2. Prepared only (owner §7) — not executed

`docs/status/edge-next-lab-update-checklist.md`: Cloud first (master + tenant migrations, capability advertised, device page), the LAB-only
data corrections (approver manager PIN + `tenant.pos.store`, `tenant.held-sales.cancel`, `tenant.sales-returns.store`, keeps
`tenant.pos.void-kot-item`; cashier rows `tenant.shifts.create`, `tenant.shifts.close-form`, `tenant.sales-orders.split-bill`,
`tenant.pos.customers.quick-store` — nothing broad), expected SCHEMA_UNSUPPORTED on the 0.7.0 appliance until updated, the appliance update
sequence, the identity adoption on the first refresh, verification, second-laptop acceptance scope, rollback rule.

## 3. Open blockers / decisions before the release build (owner §5–§6)

1. The reconciliation's eight decisions D1–D8 (manual-journal guards into the shared service; reminder-pause twin or capability-off; the
   `tenant.pos.pause-reminders` catalogue entry; website-orders inbox as an explicit Cloud-only capability slot; re-capturing the Online pixel
   baselines after the merge; partner share as bootstrap sections (schema v9) or hidden on Edge; schema-version policy for the 32 canonical
   tenant migrations the appliance will run; merge order 70c4e43 then a9499a1).
2. After the merge: the full test plan in the reconciliation doc (Edge gates + the canonical suites the merged code relies on + both browser
   proofs + pixel/geometry pairs re-captured + schema-upgrade dry-run with the 32 pending tenant migrations).
3. Then the release build (owner §6 contents: updater fix e45b8b7, bootstrap v8, Phases 2–4, Gap C, device version reporting), dry-runs,
   pre-install report, owner-approved LAB update, second-laptop acceptance. Physical printer certification and P6 remain afterwards.

## 4. Disposable pair — final state

8096: STANDBY, worker stopped, outbox 4 acknowledged / 0 unacked, config revision 20, no open shift. Clone: lease holder cloud / standby,
released, not fenced; sales_operating_mode cloud; the Online clone answers sales. Dev Edge 8095 (dev seed, manual Local Mode) untouched by
the proof except the capability-off probe. LAB stack untouched.
