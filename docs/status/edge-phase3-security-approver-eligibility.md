# Phase 3 security — manager-approval eligibility contract (Team S, 4 Oct 2026)

Branch `feat/edge-config-refresh-v1`, worktree `D:\laragon2\www\pos-saas-edge`, base HEAD 6cac0e8. Source + isolated
tests only; nothing committed, nothing touched outside the worktree. Closes W-E Finding 1 / step-7 report item 1
("`tenant.pos.void-kot-item` is both the cashier void permission and the Edge approver marker; no self-approval guard").

## 1. The contract

| # | Rule | Where it is enforced | Test |
|---|------|----------------------|------|
| 1 | **Eligibility is an explicit Cloud-authoritative flag, not a permission.** `users[].may_approve_pos` (bootstrap v8) is `true` iff the user holds an **active `manager_pins` row** (the Online approval mechanism) **and** `users.status = active`. Only the boolean ships; the PIN hash never does. | `EdgeBootstrapService::userSection` (export), `EdgeLocalBootstrapImporter::insertUsers` (store), `EdgeLocalConfigRefreshApplier::upsertUsersAndPermissionGraph` (refresh) → Edge-only column `users.may_approve_pos` | `EdgeBootstrapApproverEligibilityMySqlTest::test_bootstrap_exports_the_flag_only_for_active_users_with_an_active_manager_pin_and_never_a_hash`, `::test_importer_stores_the_flag_and_verify_manager_honours_it`, `EdgeLocalAuthMySqlTest::test_V_…` (real v8 package) |
| 2 | **A PIN change on the Cloud mints a new config revision**, and the next applied refresh rewrites the flag (PIN removed → `verifyManager` refuses; re-enabled → restored). | `EdgeBootstrapService::sourceRevision` (watermark `manager_pins=user:is_active,…`), the applier's `mutable()` UPDATE | `EdgeBootstrapApproverEligibilityMySqlTest::test_a_pin_removed_on_the_cloud_revokes_offline_approval_with_the_next_refresh` |
| 3 | **Edge `verifyManager` requires the flag** (`EdgeUserAuthz::mayApprovePos` = active user AND flag). A cashier holding `tenant.pos.void-kot-item` (the whole `Cashier (Counter)` template) without the flag is refused: 422 `{ok:false, message:"This user is not an approving manager (no active manager PIN on the Cloud)."}` | `EdgeLocalAuthService::verifyManager` step 3 | `EdgeManagerApprovalEligibilityHttpMySqlTest::test_cashier_with_void_permission_but_no_approver_eligibility_is_refused`; `EdgeLocalRestaurantHttpMySqlTest::test_manager_reauth_refusal_matrix_on_pure_local_authority` (NOPERM case rewritten) |
| 4 | **The approver must also hold the permission the approved ACTION needs** — `EdgeLocalPosService::MANAGER_ACTION_PERMISSIONS` is now per action: `manual_discount → tenant.pos.store`, `void_kot_item(s) → tenant.pos.void-kot-item`, `cancel_held_order → tenant.held-sales.cancel`, `sales_return → tenant.sales-returns.store` (no blanket marker; unknown actions still fail closed). 422 `"This user is not authorized to approve that action."` | `EdgeLocalAuthService::verifyManager` step 4 | `EdgeManagerApprovalEligibilityHttpMySqlTest::test_approver_lacking_action_permission_is_refused` |
| 5 | **approver ≠ requester, refused server-side** (Edge: before any credential processing, so no lockout counter moves; the shared creator refuses it a second time for both runtimes). 422 `"You cannot approve your own request. Ask another manager to approve."` | `EdgeLocalAuthService::verifyManager` step 1 (+ defence in depth after verify); `ManagerApprovalService::createApprovalForAuthenticatedManager` | Edge: `EdgeManagerApprovalEligibilityHttpMySqlTest::test_self_approval_is_refused_server_side`; Cloud: `CloudManagerApprovalEligibilityMySqlTest::test_self_approval_is_refused_server_side` |
| 6 | **Wrong credential refused** — the epoch-fenced Edge credential rules are untouched (`verify()` unchanged: generic `Invalid credentials.`, failed_attempts, lockout). | `EdgeLocalAuthService::verify` | `EdgeManagerApprovalEligibilityHttpMySqlTest::test_wrong_credential_is_refused`; Cloud `::test_wrong_credential_is_refused` |
| 7 | **Deactivated / expired approvers fail**: inactive user (generic refusal + `mayApprovePos` false), disabled Edge credential, stale activation epoch. Cloud: inactive user refused by `verifyPin` (existing) **and** by the shared creator (`"This manager account is deactivated and cannot approve."`, new). | `EdgeLocalAuthService::verify`; `ManagerApprovalService::createApprovalForAuthenticatedManager` | `EdgeManagerApprovalEligibilityHttpMySqlTest::test_deactivated_user_is_refused`; Cloud `::test_deactivated_user_is_refused` |
| 8 | **One-time consumption, replay refused** (unchanged) + new defence: an approval can never be consumed by its own approver. | `ManagerApprovalService::consume` | `EdgeManagerApprovalEligibilityHttpMySqlTest::test_consumed_approval_replay_is_refused` (real held-sale settle over HTTP, replay on a second check → 422); Cloud `::test_consumed_approval_replay_is_refused` |
| 9 | Valid manager approves another cashier — unchanged 200 `{ok, approval_id, approval_no, approval_uuid}`; cashier session untouched. | — | `EdgeManagerApprovalEligibilityHttpMySqlTest::test_valid_manager_approves_another_cashier`; Cloud `::test_valid_manager_approves_another_cashier` |

Order of checks in `verifyManager`: self-approval (by employee code) → Edge credential (`verify()`, unchanged) →
eligibility flag → action permission → `E_MGR_OK` audit. Every refusal writes an `E_MGR_FAIL` audit with `detail` =
`self_approval | not_approver | missing_permission | <verify() reason>`.

HTTP contract of `POST /edge/local/pos/manager-approvals/verify` is unchanged for the shared cashier view: 200
`{ok:true, approval_id, approval_no, approval_uuid}`; every business refusal is 422 `{ok:false, message}` (the modal
shows `message`); the requester-permission gate stays the 403 `{message, permission}` shape; throttle 10/min unchanged.

## 2. The bootstrap field (schema decision)

* Canonical name: **`may_approve_pos`** (boolean) on every `users[]` record of the bootstrap / config-refresh
  package. `has_manager_pin` was considered and NOT added — one flag, one meaning ("may approve POS actions offline");
  the Cloud derivation (active PIN AND active user) is documented at the export site.
* **Bootstrap schema bumped `edge-bootstrap-v7 → edge-bootstrap-v8`**, the way v7 was done: constant +
  comment in `EdgeBootstrapService`, watermark carries the schema, exact-match importer + applier
  (`SCHEMA_UNSUPPORTED`), `EdgeCompatibilityService` classifies a v6/v7 appliance as `software_update_required`,
  `config/edge.php` comment, Feature tests (`EdgeCompatibilityContractTest`, `EdgeBuildInfoTest`) and
  `EdgeBootstrapV7MySqlTest` (literals → the constant; v6 stays the "foreign generation" sample).
  **Why unavoidable**: the appliance stores the flag in a new Edge-only column
  (`database/migrations/edge/2026_10_04_000001_add_may_approve_pos_to_users_on_edge.php`, `users.may_approve_pos`
  boolean default false, hasColumn-guarded, forward-only upgrader safe). A 0.7.0 (v7) appliance has no such column, so
  a v8 package would not be "accepted with an unknown key ignored" — the users INSERT/UPDATE would fail inside the
  import/refresh transaction. The bump turns that into the clean, already-handled refusal (`SCHEMA_UNSUPPORTED`,
  retried with bounded backoff; health/compat report `update_required`). `CONFIG_SCHEMA_VERSION` stays `edge-config-v1`.
* Importer/applier: `may_approve_pos` absent or false → stored `0` (fail closed). No Cloud column exists; on the
  Cloud the flag is derived at export time from `manager_pins`.

## 3. Files changed

Source
* `app/Services/Edge/EdgeBootstrapService.php` — `SCHEMA_VERSION = 'edge-bootstrap-v8'` (+ rationale), watermark
  `manager_pins=…`, `userSection()` exports `may_approve_pos`.
* `app/Services/Edge/EdgeLocalBootstrapImporter.php` — `insertUsers()` stores the flag.
* `app/Services/Edge/EdgeLocalConfigRefreshApplier.php` — `upsertUsersAndPermissionGraph()` refreshes the flag.
* `app/Support/EdgeUserAuthz.php` — `mayApprovePos()`.
* `app/Services/Edge/EdgeLocalAuthService.php` — `verifyManager(code, credential, requiredPermission, ?requestingUserId, ?ip)`:
  self-approval, eligibility, action permission; `verify()` untouched.
* `app/Services/Edge/EdgeLocalPosService.php` — `MANAGER_ACTION_PERMISSIONS` per action; `verifyManagerApproval()`
  passes the requester (two hunks, local to the approval path; other teams' hunks untouched).
* `app/Services/Sales/ManagerApprovalService.php` — shared creator refuses self-approval + inactive approver;
  `consume()` refuses consumption by the approver.
* `app/Support/Pos/PosPermissionCatalog.php` — `void-kot-item` groups `['cashier']` (no longer "manager"), check-site
  texts, class doc on the non-permission approver contract. `EdgePermissionCatalogTest` updated accordingly.
* `database/migrations/edge/2026_10_04_000001_add_may_approve_pos_to_users_on_edge.php` — new (Edge-only).
* `config/edge.php` — comment.

Tests
* New: `tests/MySql/EdgeManagerApprovalEligibilityHttpMySqlTest.php` (7 owner cases, real HTTP, master dead),
  `tests/MySql/CloudManagerApprovalEligibilityMySqlTest.php` (7 owner cases on the Cloud PIN path),
  `tests/MySql/EdgeBootstrapApproverEligibilityMySqlTest.php` (export / import / refresh).
* `tests/MySql/Support/EdgeLocalRuntimeFixture.php` — `markPosApprover($userId, $eligible = true)`; fixture doc.
* Approver fixtures now carry the flag (and no longer rely on `void-kot-item` as the marker):
  `EdgeCashierDealsDiscountsHttp`, `EdgeCashierDineInHttp`, `EdgeCashierOrderLifecycleHttp`, `EdgeCashierPermissionMatrix`,
  `EdgeCashierReturnParityHttp`, `EdgeComboVoidReconcileHttp`, `EdgeDiscountFlow`, `EdgeLocalRestaurantHttp`
  (refusal matrix: template-without-flag, flag-without-action-permission, self-approval), `EdgeReturnAuthority`,
  `EdgeSharedPosContract`, `EdgeDevInstanceSeed` (DEVMGR1 eligible; DEVCASH1 not), `EdgeLocalAuthMySqlTest` (the Cloud
  source gives EMP1 an active manager PIN → the real v8 export flags them; asserts no PIN hash on the appliance).
* Version literals: `EdgeBootstrapV7MySqlTest`, `tests/Feature/Edge/EdgeCompatibilityContractTest.php`,
  `tests/Feature/Edge/EdgeBuildInfoTest.php`.

## 4. Online vs Edge differences noticed

1. **Online does not require the approver to hold the action's permission** — any active manager-PIN holder approves any
   action (original Cloud semantics, unchanged here; the coordinator's brief asked for self-approval + deactivated only
   on the Cloud). Edge now requires both. Pinned as a documented difference in
   `CloudManagerApprovalEligibilityMySqlTest::test_approver_lacking_action_permission_is_accepted_online_but_refused_on_edge_documented_difference`
   — tightening Online is an owner decision.
2. Online identifies the approver by the PIN alone (the requesting cashier never enters an employee code), so "wrong
   credential" and "no eligibility" collapse into one generic `Invalid manager PIN.`; Edge distinguishes them in the
   audit `detail` but keeps the generic message for credential failures.
3. The `tenant.users.manager-pin` screen has no "remove PIN" action (only set/replace with `is_active = true`); a PIN is
   disabled only by deactivating the user or a DB change. The refresh test covers `is_active = 0` directly.

## 5. LAB impact

* The installed **0.7.0 LAB appliance is NOT updated** by this work (LAB untouched, stack down). It reports
  `edge-bootstrap-v7`; once the LAB Cloud runs this code it will classify the appliance `software_update_required`
  and refuse a v8 package cleanly — the appliance keeps working on its last applied revision.
* **The next LAB update must carry**:
  1. the new appliance build (edge migration `2026_10_04_000001` applied by `edge:local:schema-upgrade` — additive,
     forward-only; the first applied v8 revision records `bootstrap_schema = edge-bootstrap-v8` per
     EDGE-BOOTSTRAP-SCHEMA-FOLLOWS-REFRESH-1);
  2. **a manager PIN for the LAB approver on the LAB Cloud tenant** — the LAB tenant has no `manager_pins` row today
     (step-7 report item 3), so without it NO user on the LAB appliance can approve a discount / void / cancel / return
     after the update (`may_approve_pos` false for everyone). Set it through the Online user screen
     (`/users/{id}/manager-pin`) for the approver; the approver must also keep the action permissions
     (`tenant.pos.store`, `tenant.pos.void-kot-item`, `tenant.held-sales.cancel`, `tenant.sales-returns.store`).
  3. a config refresh after the PIN is set (the watermark moves on `manager_pins`, so the next revision carries it).
* Dev instance: `EdgeDevInstanceSeedMySqlTest` marks DEVMGR1 eligible; the dev DB must be re-seeded by the coordinator
  (`tools/edge-dev-instance/seed.sh`) before the browser proof runs again — W07/W08 approvals are entered as DEVMGR1.

## 6. Not done / open

* Online HTTP-level test of `ManagerApprovalController@verify` (tenant-host harness) — the Cloud cases exercise the
  service the controller wraps (422 `{ok:false, message}` for every exception, unchanged).
* No UI change: the shared cashier view keeps showing `message` from the 422; the Edge "Manager Approval" prompt does
  not pre-hide the cashier's own code (the server rule is the authority, as required).
* Owner decision pending: whether Online should also require the approver to hold the action's permission (§4.1).
