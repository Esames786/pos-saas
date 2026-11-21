# OFFLINE EDGE — STATUS FOR GPT (18 Oct 2026)

Worktree `pos-saas-edge`, branch `feat/edge-config-refresh-v1`, pushed head **718057e**; 37 Phase 4 files UNCOMMITTED in the
working tree (see §4). Canonical `pos-saas` = PROD at **70c4e43** (17 Oct); the Edge branch is NOT merged into it. No production,
Khatri, Kashif or Tawakal state was touched by any Edge work. Nothing deployed. AUTO_FAILOVER_ENABLED=no. P6 not started.

## 1. Installed / running

- LAB Branch Server: **0.7.0-edge** (7b8f886, bootstrap v7), STANDBY_READY since the supervised Local Mode acceptance and
  controlled handback of 26 Sep (owner cashier acceptance on the second laptop; FakePrinter 33 jobs ↔ 33 docs; outbox 5/5
  acked exactly-once). Not updated since.
- LAB Cloud :9701 (export of 7b8f886), 7/7 workers, FakePrinter — untouched by Phases 2–4.
- Known 0.7.0 defect fixed in source only: the updater applied no migrations (schema upgrade ran in the old runtime) → commit
  e45b8b7, mandatory in the next release.

## 2. Owner decisions already executed (26 Sep → 6 Oct)

FINAL product decision: the Edge cashier POS = the EXACT Online POS (one shared cashier Blade + runtime adapter, no separately
styled Edge copy) + offline add-customer. Phase 2 approvals A1–A6 (A5 modified: one shared status slot, identical geometry).
Phase 3 go with corrections: security fix required before cutover; Online clone 17/17; two-stage retirement; census converted to
a shared-view gate; small mismatches normalised and "No terminal" fixed as a bug; offline add-customer after the cutover; device
version reporting proven; required gates and §10 output fields.

## 3. What is built (commit ladder, all pushed)

| Commit | Content | Status |
|---|---|---|
| 599c5d0 | Phase 1 dependency inventory / architecture report | done |
| 0726272 … 885fae8 | PHASE 2: `layouts.pos` + `PosRuntime` (same 22 theme assets, no CDN, local Nunito), Edge runtime factory, JSON contract normalisation, A2 discount at payment, shared shift / return / split screens, permission catalogue + Cashier (Counter) template + audit command, heartbeat build report | done; Online pixel-identical (0.000 % outside the slot) |
| cabe940 · 5a39317 · 7f8794d · 6cac0e8 | Step 7: held-settle printing parity, shared recipe preview, quick-report 403 shape, device page; browser harness; four browser-found contract gaps fixed; step-7 report | done |
| 7facd78 | §6: lenient Edge totals quote, Online retry wording, reservation refusal shape, `#ctx-terminal-name` bug fixed on BOTH runtimes, `idempotent_replay`; §8 device version update proven without re-pairing | done |
| fd8f495 · e4fdd6d | §1 SECURITY: `may_approve_pos` from the Cloud manager-PIN state (bootstrap v7 → v8, Edge column); verifyManager = self-approval → credential → eligibility → action permission; Cloud refuses self-approval, deactivated approvers and approvers without the action's permission (ONE map `ManagerApprovalService::ACTION_PERMISSIONS`); one-time consumption / replay unchanged; the seven owner cases as Cloud + Edge tests | done |
| 80d04a6 | STAGE A: `/edge/local/pos` renders the shared view; Edge held settle requires the print intents (Online 422 shape); 21 MySQL test classes migrated (145 re-targeted / 214 kept / 90 retired with reasons) | done |
| 7c40923 · 40ce17a | census → `EdgeSharedPosRegressionGateMySqlTest` (both runtimes in one run) + static gate; `geometry-compare.mjs`; a second dev Edge PAIRED to the Cloud clone through the product flow → same dataset on both sides | done |
| 180af6d | STAGE B: old Edge POS tree + census test/fixture deleted (25 files, 0 references); `/edge/local/pos/shared` = 301; artifact manifest ships the shared view; combo `category_id` carried by bootstrap/refresh; empty-query customer list like Online; Edge menu (health / finance / logout) behind the SAME `#pos-sidebar-toggle` Online uses; Edge Direct Pay requires print intents | done |
| 2601ece · 718057e | harness v2 (verdict = HTTP outcome + DOM confirmation) + Phase 3 output report | done |

Phase 3 output (`docs/status/edge-phase3-cutover-report.md`):

```
EDGE_BROWSER=17/17
ONLINE_BROWSER=17/17 (disposable clone of the LAB tenant)
ONLINE_PIXEL_REGRESSION=NONE
EDGE_VS_ONLINE_GEOMETRY=NONE (998 boxes, same dataset, 0 differences)
SELF_APPROVAL_REFUSED=YES
OLD_EDGE_POS_RUNTIME_REFERENCES=0
CLOUD_ENDPOINT_LEAKS_EDGE=0
EXTERNAL_NETWORK_DEPENDENCIES=0
FULL_EDGE_GATE=GREEN (603 tests / 8,376 assertions, 1 skipped)
Feature/Edge 172 · Feature/Pos 26 green (with Phase 4 in the tree)
```

## 4. PHASE 4 — offline add-customer: IMPLEMENTED, gates green, NOT COMMITTED

Contract as approved: canonical `customer_uuid` (never the local numeric id); ONE phone normaliser (Cloud + appliance,
`phone_normalized` indexed and backfilled); Edge quick-store / add-address endpoints with Online's request/response (the shared
modal is unchanged, it simply turns on); local duplicate lookup by normalised phone; local ids from a reserved range so Cloud ids
never collide; addresses with `address_uuid`; customer usable at once in the sale; sale envelope **v3** carries a `new_customer`
intent ONLY while the Cloud has advertised `customer_create` in the heartbeat ACK (old Cloud → SCHEMA_UNSUPPORTED, outbox row
parked, applies once after the upgrade; nothing is ever emitted unsupported); Cloud create-or-match = uuid → alias → phone match
(row lock, alias `customer_uuid_aliases` offline→canonical, no duplicate) → create with the offline uuid as canonical; replay
idempotent; refresh/bootstrap match customers by uuid (local id kept, local-origin rows never deactivated, id held by another
identity → whole refresh refused); the alias book ships to the appliance and merges the local row. Bootstrap schema stays v8
(additive, unreleased).

Tests: EdgeCustomer{QuickStoreHttp,Ingestion,Refresh}MySqlTest (23) + normaliser + regression-gate and heartbeat updates; MySQL
filter 359 tests / 5,665 assertions green; Feature/Edge 172 / 36,978 and Feature/Pos 26 / 456 re-verified on 18 Oct. Harness W15
now expects PASS on Edge when the capability is advertised (the browser run has not been repeated yet).

**Blocker:** the coding tool's permission classifier refused the `git commit` twice on 18 Oct. The commit message is prepared.
Owner action: allow the commit, or run `git add -A && git commit` in the worktree — it is approved, gated work.

## 5. Owner decisions / data pending

1. Gap C: a STANDBY appliance's Recent Orders list is the returnable-sale mirror (no cancelled sales, no table label).
   Options: mirror `restaurant_table_id` + Online's list rule (recommended if it should read like Online) / table id only / leave.
2. LAB tenant data before the next LAB update: the approver needs a manager PIN + `tenant.pos.store`, `tenant.held-sales.cancel`,
   `tenant.sales-returns.store`, `tenant.pos.void-kot-item`; the LAB tenant lacks `tenant.shifts.create`, `tenant.shifts.close-form`,
   `tenant.sales-orders.split-bill`, `tenant.pos.customers.quick-store` (lab-cloud.php seed; real tenants have them); then a refresh.
3. Online behaviour changes to acknowledge: a PIN holder without the action's permission can no longer approve; self-approval is
   refused server-side; the quick-store phone match is spelling-insensitive.

## 6. What is left before a release (Phase 5)

1. Commit Phase 4; repeat the Edge browser run for W15 PASS.
2. Merge/rebase assessment of the Edge branch against canonical 70c4e43 (shared files: POS view, layouts, SalesOrderController,
   printing, permissions, migrations) — canonical moved a lot (partner share, billing, online ordering, kitchen sheet, print agent).
3. Signed release package (updater fix e45b8b7 + bootstrap v8 + Phases 2–4), update/rollback dry-runs, pre-install report.
4. Owner-approved LAB update: Cloud first (advertises `customer_create`, tenant migrations, LAB data items of §5.2), then the
   appliance; then second-laptop paired acceptance on the same dataset (pixel + workflows); re-test return, shift denomination
   count, add-customer at the till, and the handback.
5. Only then: physical printer certification and P6 remain as before (not started).

## 7. Disposable dev infrastructure (LAB machine, leave as is)

Dev Cloud 9702 (live worktree), dev Cloud clone 9704 (`pos_devonline_*`, a disposable copy of the LAB tenant; its lease row must
stay released), dev Edge 8095 (dev seed), paired dev Edge 8096 (clone dataset, STANDBY). Evidence under
`C:\Users\Dell\BingooEdgeLab\evidence\phase2\` and `evidence\phase3\` (proof-final, geometry-samedata, pixel-samedata, gate logs).
