# W-E — POS permission model (Team E report)

Date: 27 Sep 2026 · Branch: `feat/edge-config-refresh-v1` (worktree `pos-saas-edge`) · Not committed (the coordinator commits).
Scope: shared-POS architecture §9 / §11 W-E under **owner decision A6 (approved)**. The work adds a canonical POS cashier
permission catalogue, taken from the permissions the real cashier workflows check. It adds a `Cashier (Counter)` role template
that only NEW tenants receive. For EXISTING tenants there is only a read-only audit: nothing is granted to all, no custom role
is overwritten, and no role is silently expanded. The Edge bootstrap export is unchanged: it still flattens role and direct
permissions into `users[].permissions[]`.

## Files changed

| File | Change |
|---|---|
| `app/Support/Pos/PosPermissionCatalog.php` (new) | `ENTRIES`: 41 permissions. Each entry records its groups, its Edge check site and the Online route or migration that owns it. Also: `cashier()` / `manager()` / `finance()` / `all()` / `has()` / `describe()`, `CASHIER_ROLE_TEMPLATE = 'Cashier (Counter)'`, and `SHARED_VIEW_CLOUD_ONLY_CHECKS`. |
| `app/Services/Tenancy/TenantProvisioner.php` | Three additions only. (1) `$isNewTenant` is computed as "no Owner role yet" before the Owner is created. (2) The seed calls `$this->provisionCashierRoleTemplate($isNewTenant)` after the Owner lines. (3) New public `provisionCashierRoleTemplate(bool)`. It returns null for an existing tenant. It never touches an existing role of that name. Otherwise it runs `Permission::findOrCreate` for each catalogue cashier name (some, such as `tenant.held-sales.reattach-table`, only arrive later through the route catalog), then `Role::create` + `givePermissionTo(PosPermissionCatalog::cashier())`. The role is never assigned to anyone. The Owner's list and grant are unchanged. |
| `app/Console/Commands/PermissionsAuditCashierRolesCommand.php` (new) | `permissions:audit-cashier-roles {--tenant=} {--json}`. For each active tenant, or the one named, every role holding `tenant.pos.index` or `tenant.pos.store` is listed with its user count and the catalogue cashier permissions it lacks. It also lists catalogue names the tenant has no permission row for. It is SELECT-only and has no write path. It is Cloud-only, because it is not on the branch_server `cli_allowlist`. |
| `tests/MySql/Support/EdgeLocalRuntimeFixture.php` | `onlinePosParityPermissions()` now returns `PosPermissionCatalog::cashier()`. The method name is kept and the docblock updated. It replaces the former route-derived list of 10. Added the `revokeEdgePermission($userId, $permission)` helper, the counterpart of `grantEdgePermission`. |
| `tests/Feature/Edge/EdgePermissionCatalogTest.php` (new) | 6 tests, SQLite. See "Tests". |
| `tests/MySql/EdgeCashierPermissionMatrixMySqlTest.php` (new) | 3 tests, MySQL. See "Tests". |
| `tests/MySql/EdgeCashierShellHttpMySqlTest.php` (coordinator request) | Two tests now revoke the permission they study: `revokeEdgePermission` of change-terminal; of sales-returns.store + quick-report-send. |
| `tests/MySql/EdgeCashierRouteGatesHttpMySqlTest.php`, `EdgeCashierScreenRendersHttpMySqlTest.php`, `EdgeCashierReturnHttpMySqlTest.php`, `EdgeLocalRestaurantHttpMySqlTest.php` (coordinator request, 2nd note) | One explicit revoke each, for the permission the restricted-operator case studies: change-terminal (pin) ×2, sales-returns.store, and void-kot-item on the "NOPERM" approver. |
| `tests/MySql/EdgeDevInstanceSeedMySqlTest.php` (coordinator request) | DEVCASH1 is granted the full `PosPermissionCatalog::cashier()` explicitly, so the dev render shows Return / Change / Quick Report. DEVMGR1 is granted `PosPermissionCatalog::all()`, which replaces the hand-written list. |

No other file was touched.

## Catalogue contents (41)

**cashier (29)**: this is the `Cashier (Counter)` template and the fixture set.

| Permission | Edge check site | Online owner |
|---|---|---|
| tenant.pos.index | EdgeLocalPosController@screen (abort_unless); post-login landing | GET /pos POSController@index |
| tenant.pos.store | ResolvesEdgePosContext::denyUnlessMayCompleteSale (sale / held settle) | POST /pos SalesOrderController@store |
| tenant.pos.change-terminal | ResolvesEdgePosContext::denyUnlessMayOperateTerminal via UserDataScope::CHANGE_TERMINAL_PERMISSION | synthetic (migration 2026_08_30_000002) |
| tenant.pos.customers.quick-store | W-D EdgeLocalCustomerController (not yet on Edge) | POST /pos/customers/quick-store CustomerController@quickStore |
| tenant.pos.quick-report-send | EdgeQuickReportController::PERMISSION | synthetic (migration 2026_08_27_000001) |
| tenant.pos.void-kot-item | KotCancellationService::assertCancellationPermission (the requester); also the manager marker | synthetic (migration 2026_08_03_000001) |
| tenant.held-sales.store / .cancel / .reattach-table | EdgeLocalHeldSalesController (denyUnlessCan) | POST /held-sales, …/{id}/cancel, …/{id}/reattach-table |
| tenant.sales-orders.split-bill | shared view table-board `@can` (Split Bill button), rendered on Edge from W-B | GET /sales-orders/{id}/split-bill SplitBillController@create |
| tenant.sales-orders.split-bill.store | EdgeLocalHeldSalesController@splitHeldSale | POST /sales-orders/{id}/split-bill |
| tenant.api.manager-approvals.verify | EdgeLocalManagerApprovalController (the requesting cashier) | POST /api/manager-approvals/verify |
| tenant.restaurant.table-sessions.open / close / show / move / merge / bill-preview / bill-requested | EdgeLocalRestaurantController (denyUnlessCan; open also gates reserve / unreserve / reservation) | RestaurantTableSessionController routes |
| tenant.shifts.store / close / index / show | EdgeLocalShiftController (denyUnlessCan / abort_unless) | ShiftController routes |
| tenant.shifts.create / close-form | W-B shared shift pages (`tenant/shifts/index` @can "Open Shift"; `tenant/shifts/show` @can "Close Shift"). W-B's `openPage` / `closePage` gate `shifts.store` / `shifts.close` today; they should gate these names to match Online. | GET /shifts/open ShiftController@create; GET /shifts/{shift}/close ShiftController@closeForm |
| tenant.sales-returns.index / show | EdgeLocalReturnController list / detail screens (abort_unless) | SalesReturnController@index / @show |
| tenant.sales-returns.store | EdgeLocalReturnController::denyUnlessMayReturn (search / returnable / post / show) | POST /sales-returns |
| tenant.sales-returns.create | shared view `@can` (Return button + modal), rendered on Edge from W-B | GET /sales-returns/create |

**manager (2)**: `tenant.pos.void-kot-item` is the Edge offline approval marker (`EdgeLocalPosService::MANAGER_ACTION_PERMISSIONS`). `tenant.shifts.view-amounts` is checked by `AmountVisibility` in the Edge shift screens and controls whether amounts are hidden from a blind-count cashier.

**finance (11)**: `tenant.suppliers.ledger`, `tenant.supplier-payments.store/index/show`,
`tenant.finance.manual-journals.store/index/show`, `tenant.purchase-returns.store/post/index/show`. These are checked by
the Edge supplier-finance and purchase-return controllers and services (PERM_* constants, requireAny, abort_unless) and by the
Cloud ingestion actor checks.

**Declared Cloud-only (not catalogued)**: `@can` gates in the shared views that are §7 Cloud surfaces:
- `tenant.reports.center.index`, `tenant.restaurant.floors.index` and `tenant.restaurant.tables.index` open the Report
  Center and the Manage Floors / Manage Tables iframes.
- `tenant.sales-orders.show` is the sales-returns index/show link to the Online sales-order page, which has no Edge route.
  W-B should hide that link on Edge.

The catalogue test scans the shared POS view directory plus every Online `tenant.*` view that an Edge controller renders
(`view('tenant.…')`, found automatically), so W-B's new shared shift and returns pages are covered too.

### Deviations from the brief (deliberate, flagged)

1. **Additions to the cashier set beyond the list in the brief**:
   - `tenant.sales-orders.split-bill`: the shared POS view gates the Split Bill button on it (table-board). Without it, a
     template cashier holding `split-bill.store` would not see the button once Edge renders the shared view.
   - `tenant.shifts.create` and `tenant.shifts.close-form`, added at the coordinator's request (Team B). The Online
     open-shift and close-shift pages check them, and the Edge shared shift pages must use the same names.
   - The manager set also carries **`tenant.shifts.view-amounts`**. The Edge checks it through `AmountVisibility`, so under
     the "every checked permission" rule it has to be catalogued. It is deliberately **not** in the cashier template, so a
     counter cashier stays subject to blind count.

   If the owner wants the exact list from the brief, remove `tenant.sales-orders.split-bill` from `ENTRIES`. Two things then
   fail on purpose: `EdgePermissionCatalogTest::test_shared_pos_view_gates_are_catalogued_or_declared_cloud_only` (it would
   need a Cloud-only declaration instead) and the matrix exemption list.
2. **"Every catalogue string exists in TenantProvisioner's permission list"** cannot hold literally. Four catalogue names are
   not in `$tenantPermissions` today:
   - `tenant.pos.change-terminal`, `tenant.pos.quick-report-send` and `tenant.shifts.view-amounts` are synthetic permissions
     seeded by tenant migrations.
   - `tenant.held-sales.reattach-table` is a route name that arrives through `route_catalogs` / deploy.sh.

   The brief forbids changing the Owner's list, so the test checks each name against the three real provisioning sources:
   the provisioner list, tenant migrations that seed `permissions`, and Online route names in `routes/tenant.php`. The template
   runs `findOrCreate` on every cashier name before granting, so it cannot throw `PermissionDoesNotExist` on a fresh tenant.

## Tests

- `vendor/bin/phpunit tests/Feature/Edge/EdgePermissionCatalogTest.php`: **OK (6 tests, 319 assertions)**. This is the final
  run, after adding shifts.create / close-form and the scan of the views Edge renders.
  - The scanner covers `app/Http/Controllers/Edge`, `app/Services/Edge`, `KotCancellationService`, `UserDataScope`,
    `AmountVisibility` and `resources/views/edge`.
  - It picks up checks in several forms: `->can('…')`, `denyUnlessCan('…')`, `requireAny([...])`, `@can`, `PERM_*` and
    `*PERMISSION*` constants, and class-constant references resolved by reflection (private ones included).
  - As a catch-all, it also tokenizes every `'tenant.*'` string literal, skipping Blade view names and one declared
    non-permission (`OfflineEdgeEntitlementService::ROUTE_KEY`).
  - The scanner currently finds **36** checked permissions, and all of them are catalogued. Every failure message names the
    permission and the file(s).
  - Also asserted:
    - every shared-view `@can` is catalogued or declared Cloud-only;
    - every catalogue name is a known tenant permission;
    - entries are documented and the groups are consistent (finance never leaks into the cashier set; view-amounts is not
      in the cashier set);
    - the provisioner wires the template from the catalogue, with no `assignRole` / `syncRoles` / `syncPermissions`;
    - the MySQL fixture returns exactly `PosPermissionCatalog::cashier()`.
- `EdgeCashierPermissionMatrixMySqlTest` (t3 DBs): **3/3 pass**. Combined with the clean-machine run below:
  `DB_DATABASE=pos_test_master_edgewt_t3 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t3 EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t3 php vendor/bin/phpunit -c phpunit.mysql.xml --filter 'EdgeCashierPermissionMatrixMySqlTest|EdgeCleanMachineInstallMySqlTest'`
  → **OK (4 tests, 347 assertions)** in 20:36. That was under heavy concurrent load from other teams' suites. The matrix alone
  took about 8½ min, including the one-off master + tenant + edge migrations, which account for most of it.
  1. A cashier holding **exactly** the template (asserted against `getAllPermissions()`) completes every workflow over the
     real branch_server routes with 2xx:
     - POS page; terminal switch; shift open, status, history, detail and close;
     - cash sale; receipt and KOT print queue; recent prints;
     - draft, recall list, recall one and recent orders; cancel order;
     - sent-line KOT void with a manager approval, where the approver holds only the template;
     - table open, bill preview, request bill, session detail, move, merge, split, settle ×3 and close; reattach after a
       dead session;
     - customer search;
     - returns search, returnable sale, cash return, return document, and the list / detail screens;
     - Quick Report options.
  2. The matrix is table-driven over the template. For 24 permissions, the cashier is refused while that single permission is
     revoked:
     - the endpoints that use `denyUnlessCan` return 403 with `permission` = that name;
     - the `abort_unless` screens and `denyUnlessMayCompleteSale` return a bare 403;
     - `tenant.pos.void-kot-item` returns the shared-service **422 with `errors.permission`** (this is the Online
       KotCancellationService contract, not a 403).

     Once the permission is granted back, each endpoint answers 2xx or a business 422, never 403. Five names have no Edge
     endpoint check today, and the test asserts this list exactly:
     - `pos.customers.quick-store` (pending W-D);
     - `sales-orders.split-bill` and `sales-returns.create` (gated only in the shared view);
     - `shifts.create` and `shifts.close-form` (the W-B shared shift pages gate `shifts.store` / `shifts.close` today).
  3. Provisioner template: an existing tenant gets nothing. A new tenant gets exactly `cashier()`, assigned to nobody. A
     trimmed role is never re-expanded. The audit command (`--json` and text) reports the gaps for the template role and for
     a custom "Counter Staff" role, and ignores a non-cashier role. Before-and-after row counts of `role_has_permissions`,
     `model_has_permissions`, `model_has_roles` and `permissions` are identical, which shows it is read-only.
- `EdgeCleanMachineInstallMySqlTest` (uses the fixture): **green**. It passed in the combined run above, and again alone
  with the final 29-permission cashier set: **OK (1 test, 160 assertions)**, 5:13.
- `vendor/bin/phpunit tests/Feature/Edge`: **162 tests, 36 098 assertions, 1 failure, not W-E's.** The failure is
  `EdgeApplianceArtifactBoundaryTest::test_cloud_chrome_views_may_ship_but_no_edge_local_route_renders_them`. The cause is
  W-B's in-progress `EdgeLocalShiftController`: +257 lines in the working tree, now rendering `tenant.shifts.open`, which
  extends `layouts.app` / Cloud chrome. W-E touched no controller. `EdgePermissionCatalogTest` stays green against W-B's
  current controller edits.
- **Sweep of every MySQL class that consumes the fixture** (52 classes / 200 tests; the partition tests were excluded
  because they depend on wall-clock timing; t3 DBs; 22 min): **15 failures, 2 skipped**. Classified:
  - **4 caused by the W-E fixture change**: see Finding 2. All four are now fixed and re-verified on t3.
  - **8 caused by W-B's manager-approval verify 201 → 200** (`ok` + 200): DealsDiscounts ×1, OrderLifecycle ×2,
    ReturnParity ×2, ComboVoidReconcile ×2, LocalRestaurant `test_reducing_kitchen_sent_line…`.
  - **2 caused by a held-sale conflict now answered 409 instead of 422**: DineIn
    `test_hidden_product_on_open_bill…` and LocalRestaurant `test_full_dine_in_lifecycle…`. `EdgeLocalHeldSalesController`
    and `EdgeLocalPosService` are being modified by other teams.
  - **1 caused by the Online POS view census SHA**: ControlCensus. The shared view is being changed by W-A, and the census
    is W-G's to retire.
  - The two `EdgeCashierShellHttpMySqlTest` tests the coordinator asked me to fix **pass** in this sweep (the whole class is
    green).

## Findings the coordinator / owner must see

1. **SECURITY: with this template, every cashier is also an offline approver.**
   - The Edge treats `tenant.pos.void-kot-item` as the manager-approval marker
     (`EdgeLocalPosService::MANAGER_ACTION_PERMISSIONS`: manual_discount, void_kot_item(s), cancel_held_order,
     sales_return).
   - `EdgeLocalAuthService::verifyManager` checks only that permission, and `ManagerApprovalService::createApprovalForAuthenticatedManager`
     has **no self-approval guard**.
   - The A6 template must include `void-kot-item`, because the requester check in KotCancellationService demands it. As a
     result, a `Cashier (Counter)` user on the appliance can approve discounts, voids, cancellations and returns, **including
     their own**, by entering their own employee code and Edge credential.
   - On Online the approver is a manager-PIN holder, so holding the permission alone is not enough there.

   This was not fixed here, because it is outside W-E's file ownership. Recommended: give the Edge a distinct approver
   marker, for example a manager-PIN-holder flag in the bootstrap or a dedicated permission, and/or refuse
   `approved_by_user_id == requested_by_user_id`. **Owner decision needed before the template reaches a live tenant.**
2. **Existing MySQL tests that assumed the old 10-permission fixture set.** A seeded cashier now holds the full template,
   and the fixture's rule is that "a test that models a restricted operator revokes the one it studies". These tests assert
   the absence of a template permission without revoking it, so they are expected to fail until their owners add one
   `revokeEdgePermission(...)` line. They are outside W-E's file ownership.

   **Fixed at the coordinator's request** (the tests are now explicit; the catalogue stays the seeded default):
   `tests/MySql/EdgeCashierShellHttpMySqlTest.php`:
   - `test_branch_and_terminal_context_dialog_and_change_gate` now starts with
     `revokeEdgePermission($this->userId, UserDataScope::CHANGE_TERMINAL_PERMISSION)`.
   - `test_return_and_quick_report_buttons_follow_the_online_permission` now revokes `tenant.sales-returns.store` and
     `tenant.pos.quick-report-send` first.

   Both **pass** on t3.

   **Also fixed, at the coordinator's second request.** These are the four the t3 sweep confirmed; each got one explicit
   revoke:
   | Test | Why it failed | Fix |
   |---|---|---|
   | `EdgeCashierRouteGatesHttpMySqlTest::test_terminal_pin_and_assignments_are_enforced_server_side` | The seeded cashier held change-terminal, so it was not pinned | `$this->revoke('tenant.pos.change-terminal');` after the default-terminal update |
   | `EdgeCashierScreenRendersHttpMySqlTest::test_pinned_operator_is_offered_only_his_assigned_terminal` | Same | `revokeEdgePermission($this->userId, 'tenant.pos.change-terminal')` |
   | `EdgeCashierReturnHttpMySqlTest::test_a_cashier_without_the_return_permission_is_refused` | The seeded cashier held `sales-returns.store` | `revokeEdgePermission($this->userId, 'tenant.sales-returns.store')` |
   | `EdgeLocalRestaurantHttpMySqlTest::test_manager_reauth_refusal_matrix_on_pure_local_authority` | The "NOPERM" approver held `void-kot-item`, **so it could approve** (Finding 1 in test form) | `revokeEdgePermission($noPermId, 'tenant.pos.void-kot-item')` |

   **t3 run of the affected classes**, with the filter
   `EdgeCashierShellHttpMySqlTest|EdgeCashierReturnHttpMySqlTest|EdgeCashierRouteGatesHttpMySqlTest|EdgeCashierScreenRendersHttpMySqlTest|EdgeLocalRestaurantHttpMySqlTest|EdgeCashierPermissionMatrixMySqlTest`:
   **29 tests, 612 assertions, 1 failure, not W-E's.** Every W-E fix passes, and so does the matrix.
   - The one failure is the same LocalRestaurant manager-matrix test. It now gets past the NOPERM step (422 as intended) and
     then fails at l.362 `$ok->assertStatus(201)`.
   - That is W-B's approval-verify change to `ok` + 200. W-B is updating these assertions in the same file: its working-tree
     diff already changed l.157 (→409) and l.283 (→200), but not l.362.
3. W-B changed the Edge manager-approval verify response from 201 to `ok` + 200 in the working tree. The matrix test accepts
   either status. Eight existing tests still assert 201 and now fail (see the sweep above). They belong to W-B.
4. `tenant.pos.customers.quick-store` is catalogued and templated, but no Edge endpoint checks it yet. When W-D lands
   `EdgeLocalCustomerController`, add a probe to the matrix: move the name from `NO_EDGE_ENDPOINT` to `$probes`.
5. The Owner list in `TenantProvisioner` still lacks four catalogue names (see Deviation 2). A new tenant's Owner only
   receives them when deploy.sh grants the route catalog and the synthetic-permission migrations run. This was already true
   before W-E; the brief forbade changing it.

## Not done / constraints respected

No commit or push. Nothing under `C:\Users\Dell\BingooEdgeLab` was touched. No Local Mode, no production.

Outside W-E ownership, only the test files the coordinator named were edited:
- the Shell test (2 tests);
- RouteGates, ScreenRenders, ReturnHttp and LocalRestaurant (one revoke each);
- EdgeDevInstanceSeedMySqlTest (grants).

`EdgeDevInstanceSeedMySqlTest` was linted but **not run**. It runs only with EDGE_DEV_SEED=1 against the shared dev database,
and re-seeding it would disturb the dev server other teams are using. Re-seed with `tools/edge-dev-instance/seed.sh` when
convenient.

The self-approval security issue (Finding 1) is not fixed, because it is outside W-E's files.
