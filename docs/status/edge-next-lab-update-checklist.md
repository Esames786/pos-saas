# Next LAB update — checklist (PREPARED ONLY, nothing executed) — 18 Oct 2026

Owner §7 of the "CLOSE PHASE 4, THEN START PHASE 5 PRE-RELEASE" directive. This is the plan for the LAB-only update that follows the
owner's review of the canonical reconciliation report. Every step below is a proposal: the LAB appliance (0.7.0-edge, STANDBY_READY), the
LAB Cloud (:9701, export of 7b8f886) and the LAB data are NOT touched by this document. Production is never in scope.

## 0. Preconditions (release engineering, before any LAB step)

| # | Item | State |
|---|---|---|
| 0.1 | Canonical reconciliation report against pos-saas 70c4e43 reviewed by the owner (file-level matrix + non-mutating merge-tree check) | pending (produced after W15 is green) |
| 0.2 | Owner go for the merge/rebase strategy and for the release build | pending |
| 0.3 | Release build from the reconciled tree — must contain: updater fix e45b8b7, bootstrap v8, Phase 2 shared exact Online POS, Phase 3 cutover + security, Phase 4 offline add-customer, Gap C Recent Orders parity, device version reporting | not built (owner §6) |
| 0.4 | Signed package + `edge:local:update` / rollback dry-runs on a COPY of the dev appliance DB (never the LAB) + pre-install report (§8-style) | not run |
| 0.5 | Full Edge MySQL gate, Feature/Edge, Feature/Pos, both browser suites, same-dataset geometry/pixel pairs — green on the reconciled tree | partly (green on the Edge branch; must be repeated after reconciliation) |

## 1. Order of the LAB update (Cloud FIRST, then the appliance)

The appliance on 0.7.0 reports bootstrap v7. A Cloud on the new build classifies it `software_update_required` and refuses a v8 package
cleanly (the appliance keeps its last applied revision), so the Cloud can go first without breaking the running appliance.

1. **LAB Cloud** (`Start-LabCloud.ps1 -Stop`, then `-Worktree <new export>`; export the reconciled release commit to `cloud\src-<hash>`):
   run the tenant migrations on the LAB master/tenant DBs (`Migrate-LabTenant.php` pattern; includes `2026_10_06_000001` Phase 4
   identity: `phone_normalized`, `address_uuid`, `customer_uuid_aliases`, Gap C mirror fields), confirm the Cloud advertises
   `customer_create` in the heartbeat ACK, confirm `/settings/offline-edge` shows the appliance's reported version.
2. **LAB data corrections (LAB-only, owner §7; NO broad grants, NO "all permissions")** — run through the Online UI or a reviewed SQL file
   on `pos_lab_tenant_edge` only:
   - Approver `LABMF2DE` (user id 2): set a **manager PIN** through `/users/2/manager-pin`; grant exactly `tenant.pos.store`,
     `tenant.held-sales.cancel`, `tenant.sales-returns.store` (keeps `tenant.pos.void-kot-item`).
   - Cashier role / tenant permissions: create the missing permission rows `tenant.shifts.create`, `tenant.shifts.close-form`,
     `tenant.sales-orders.split-bill`, `tenant.pos.customers.quick-store` (the LAB tenant came from `lab-cloud.php`, not
     TenantProvisioner) and grant them to the LAB cashier `LAB2C5D` (user id 1) — nothing else.
   - Verify with `permissions:audit-cashier-roles --tenant=<lab tenant>` (read-only report) before and after.
3. **Config refresh** on the still-0.7.0 appliance is EXPECTED to be refused (`SCHEMA_UNSUPPORTED`, bounded backoff) — record it; the
   appliance keeps working on its last revision. Do not force anything.
4. **Appliance update** (owner-approved, sequencing as in the 0.7.0 update record: A–L, STOP rules): signed package →
   `edge:local:update` (child-process schema upgrade = the e45b8b7 fix; expect the edge migrations `2026_10_04_000001` may_approve_pos and
   `2026_10_06_000001` offline customer origin, plus Gap C mirror columns) → runtime pointer switch → first heartbeat → first accepted v8
   refresh (bootstrap_schema follows the refresh) → `may_approve_pos` arrives for the LAB approver → `customer_create` advertised →
   quick-add turns on in place.
5. **Verification before the second laptop**: `edge:local:status` / health page (version, schema, capabilities), `/edge/local/pos` renders the
   shared view, menu shows Shifts / Sales returns / Health / Log out, finance entries only for permitted users; LAB Cloud device page shows
   the new version WITHOUT re-pairing.

## 2. Second-laptop acceptance (owner), on the SAME dataset

Supervised Local Mode (lease lapse → `edge:local:authority-takeover --confirm --by=`), then: cash sales, hold/recall, dine-in with KOT
rounds, move, merge, split bill, void with the approver (employee code + credential), manual discount with approval, **return** (not
exercised in 0.7.0), **shift close with denomination count** (not exercised in 0.7.0), **add customer + address at the till**, quick
report to the network printer, FakePrinter proof (one delivery per job), controlled handback (`edge:local:authority-handback --by=`),
outbox applied exactly once on the LAB Cloud incl. the new-customer envelope (create-or-match, alias on a phone match), paired
Online/Edge screenshots at the same viewport (pixel + geometry tools), STANDBY_READY restored.

## 3. Rollback rule (unchanged from 0.7.0)

Runtime-pointer rollback is viable only BEFORE the first applied v8 refresh; after it, rollback = restore the pre-update backup and put
the LAB Cloud back on the previous export. Take the backup and record `edge:local:backup` + recovery-key custody before step 4.

## 4. Still open after this update

Physical printer certification (a real LAN printer instead of the FakePrinter), then the P6 pilot decision. AUTO_FAILOVER_ENABLED stays no.
