<?php

namespace App\Support\Pos;

/**
 * W-E (owner decision A6, next-release programme §9) — the CANONICAL POS permission catalogue.
 *
 * WHY: the LAB cashier on the 0.7.0 appliance was seeded from a ROUTE-DERIVED guess of what a cashier needs, so
 * workflows the Edge actually gates (void a sent KOT line, move / merge a table, returns, Quick Report, terminal
 * switch…) answered 403 for a real Online cashier. This catalogue is instead derived from the permission CHECKS the
 * Edge runtime performs (denyUnlessCan / ->can / abort_unless(can) / PERM_* constants in app/Http/Controllers/Edge,
 * app/Services/Edge, the shared KotCancellationService / UserDataScope / AmountVisibility, resources/views/edge) plus
 * the cashier-facing @can gates of the ONE shared POS view (resources/views/tenant/pos) that Edge renders from the
 * next release. tests/Feature/Edge/EdgePermissionCatalogTest scans those paths and fails when a checked permission is
 * missing here, so the catalogue cannot drift from the code again.
 *
 * Every entry documents:
 *   - `groups`  cashier | manager | finance (a permission may sit in more than one group);
 *
 * Phase 3 (approver eligibility): offline manager-approval ELIGIBILITY is NOT a permission in this catalogue. It is the
 * Cloud-authoritative bootstrap flag users[].may_approve_pos (edge-bootstrap-v8: active manager PIN AND active user),
 * checked by EdgeLocalAuthService::verifyManager together with the permission the approved ACTION needs
 * (EdgeLocalPosService::MANAGER_ACTION_PERMISSIONS). tenant.pos.void-kot-item is no longer an approver marker.
 *   - `edge`    the Edge check site(s) that enforce it;
 *   - `online`  the Online route (or the synthetic-permission migration) that owns the name.
 *
 * USES: `cashier()` is the `Cashier (Counter)` role template TenantProvisioner creates for NEW tenants only, the set
 * the Edge MySQL fixtures seed (EdgeLocalRuntimeFixture::onlinePosParityPermissions) and the reference the read-only
 * `permissions:audit-cashier-roles` command compares real roles against. NOTHING here ever grants a permission to an
 * existing tenant's role or user (A6: no grant-all, no overwrite of custom roles, no silent expansion). The Edge
 * bootstrap keeps exporting each user's EFFECTIVE set (role + direct permissions flattened into users[].permissions[]).
 */
final class PosPermissionCatalog
{
    public const CASHIER_ROLE_TEMPLATE = 'Cashier (Counter)';

    public const GUARD = 'tenant';

    public const GROUP_CASHIER = 'cashier';
    public const GROUP_MANAGER = 'manager';
    public const GROUP_FINANCE = 'finance';

    /** @var array<string, array{groups: list<string>, edge: string, online: string}> */
    public const ENTRIES = [
        // ── CASHIER — every permission a counter cashier's Edge workflows check at runtime ──────────────────────────
        'tenant.pos.index' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalPosController@screen (abort_unless) + EdgeLocalAuthController post-login landing',
            'online' => 'GET /pos — POSController@index',
        ],
        'tenant.pos.store' => [
            'groups' => ['cashier'],
            'edge' => 'ResolvesEdgePosContext::denyUnlessMayCompleteSale (EdgeLocalPosController@storeSale, held settle) + canCompleteSale page flag',
            'online' => 'POST /pos — SalesOrderController@store',
        ],
        'tenant.pos.change-terminal' => [
            'groups' => ['cashier'],
            'edge' => 'ResolvesEdgePosContext::denyUnlessMayOperateTerminal via UserDataScope::CHANGE_TERMINAL_PERMISSION (terminal/select + every selectedTerminal use)',
            'online' => 'synthetic — UserDataScope pin (migration 2026_08_30_000002_add_pos_change_terminal_permission)',
        ],
        'tenant.pos.customers.quick-store' => [
            'groups' => ['cashier'],
            'edge' => 'W-D offline add-customer (EdgeLocalCustomerController, §8) — not yet on the appliance',
            'online' => 'POST /pos/customers/quick-store — CustomerController@quickStore',
        ],
        'tenant.pos.quick-report-send' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeQuickReportController::PERMISSION (guard → denyUnlessCan {message, permission} 403 on every JSON quick-report action; HTML 403 on the thermal page) + canQuickReport page flag',
            'online' => 'synthetic — PosQuickReportController (migration 2026_08_27_000001_add_pos_quick_report_permission)',
        ],
        'tenant.pos.void-kot-item' => [
            'groups' => ['cashier'],
            'edge' => 'KotCancellationService::assertCancellationPermission (the REQUESTING cashier, held revise with void_items / cancel) + EdgeLocalPosService::MANAGER_ACTION_PERMISSIONS (the approving manager must hold it too for void_kot_item(s) — eligibility itself is users.may_approve_pos, Phase 3)',
            'online' => 'synthetic — KotCancellationService (migration 2026_08_03_000001_add_kot_cancellation_controls)',
        ],
        'tenant.held-sales.store' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalHeldSalesController@storeHeldSale (denyUnlessCan) + canChangeOrderDetails page flag',
            'online' => 'POST /held-sales — HeldSaleController@store',
        ],
        'tenant.held-sales.cancel' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalHeldSalesController@cancelHeldSale (denyUnlessCan)',
            'online' => 'POST /held-sales/{salesOrder}/cancel — HeldSaleController@cancel',
        ],
        'tenant.held-sales.reattach-table' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalHeldSalesController@reattachTable (denyUnlessCan)',
            'online' => 'POST /held-sales/{salesOrder}/reattach-table — HeldSaleController@reattachTable',
        ],
        'tenant.sales-orders.split-bill' => [
            'groups' => ['cashier'],
            'edge' => 'shared POS view resources/views/tenant/pos/partials/table-board.blade.php @can (Split Bill button) — rendered on Edge from W-B',
            'online' => 'GET /sales-orders/{salesOrder}/split-bill — SplitBillController@create',
        ],
        'tenant.sales-orders.split-bill.store' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalHeldSalesController@splitHeldSale (denyUnlessCan)',
            'online' => 'POST /sales-orders/{salesOrder}/split-bill — SplitBillController@store',
        ],
        'tenant.api.manager-approvals.verify' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalManagerApprovalController@verifyManagerApproval (denyUnlessCan — the requesting cashier)',
            'online' => 'POST /api/manager-approvals/verify — ManagerApprovalController@verify',
        ],
        'tenant.restaurant.table-sessions.open' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalRestaurantController@openTable + RESERVE_PERMISSION (reserve / unreserve / reservation)',
            'online' => 'POST /restaurant/tables/{restaurantTable}/open — RestaurantTableSessionController@open',
        ],
        'tenant.restaurant.table-sessions.close' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalRestaurantController@closeTableSession (denyUnlessCan)',
            'online' => 'POST /restaurant/table-sessions/{restaurantTableSession}/close — RestaurantTableSessionController@close',
        ],
        'tenant.restaurant.table-sessions.show' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalRestaurantController@showSession (denyUnlessCan)',
            'online' => 'GET /restaurant/table-sessions/{restaurantTableSession} — RestaurantTableSessionController@show',
        ],
        'tenant.restaurant.table-sessions.move' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalRestaurantController@moveSession (denyUnlessCan)',
            'online' => 'POST /restaurant/table-sessions/{restaurantTableSession}/move — RestaurantTableSessionController@move',
        ],
        'tenant.restaurant.table-sessions.merge' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalRestaurantController@mergeSessions (denyUnlessCan)',
            'online' => 'POST /restaurant/table-sessions/{restaurantTableSession}/merge — RestaurantTableSessionController@merge',
        ],
        'tenant.restaurant.table-sessions.bill-preview' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalRestaurantController@sessionBillPreview (denyUnlessCan)',
            'online' => 'GET /restaurant/table-sessions/{restaurantTableSession}/bill-preview — RestaurantTableSessionController@billPreview',
        ],
        'tenant.restaurant.table-sessions.bill-requested' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalRestaurantController@requestBill (denyUnlessCan)',
            'online' => 'POST /restaurant/table-sessions/{restaurantTableSession}/bill-requested — RestaurantTableSessionController@billRequested',
        ],
        'tenant.shifts.store' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalShiftController@openShift (denyUnlessCan)',
            'online' => 'POST /shifts/open — ShiftController@store',
        ],
        'tenant.shifts.close' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalShiftController@closeShift (denyUnlessCan)',
            'online' => 'POST /shifts/{shift}/close — ShiftController@close',
        ],
        'tenant.shifts.create' => [
            'groups' => ['cashier'],
            'edge' => 'W-B shared shift pages (EdgeLocalShiftController@openPage renders tenant/shifts/open; tenant/shifts/index @can "Open Shift")',
            'online' => 'GET /shifts/open — ShiftController@create',
        ],
        'tenant.shifts.close-form' => [
            'groups' => ['cashier'],
            'edge' => 'W-B shared shift pages (tenant/shifts/show @can "Close Shift" → the close form)',
            'online' => 'GET /shifts/{shift}/close — ShiftController@closeForm',
        ],
        'tenant.shifts.index' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalShiftController@historyScreen (abort_unless)',
            'online' => 'GET /shifts — ShiftController@index',
        ],
        'tenant.shifts.show' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalShiftController@showScreen (abort_unless)',
            'online' => 'GET /shifts/{shift} — ShiftController@show',
        ],
        'tenant.sales-returns.index' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalReturnController@listScreen (abort_unless) + can_view_list flag',
            'online' => 'GET /sales-returns — SalesReturnController@index',
        ],
        'tenant.sales-returns.show' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalReturnController@detailScreen (abort_unless) + detail_url',
            'online' => 'GET /sales-returns/{salesReturn} — SalesReturnController@show',
        ],
        'tenant.sales-returns.store' => [
            'groups' => ['cashier'],
            'edge' => 'EdgeLocalReturnController::denyUnlessMayReturn (returns search / returnable sale / post / show) + canSalesReturn page flag',
            'online' => 'POST /sales-returns — SalesReturnController@store',
        ],
        'tenant.sales-returns.create' => [
            'groups' => ['cashier'],
            'edge' => 'shared POS view resources/views/tenant/pos/index.blade.php @can (Return button + modal) — rendered on Edge from W-B',
            'online' => 'GET /sales-returns/create — SalesReturnController@create',
        ],

        // ── MANAGER — approval / supervision markers the Edge checks on the APPROVER or for amount visibility ──────────
        'tenant.shifts.view-amounts' => [
            'groups' => ['manager'],
            'edge' => 'AmountVisibility::maySeeAmounts (EdgeLocalShiftController shift status / summary / screens, blind count)',
            'online' => 'synthetic — HIDE-AMOUNTS-1 (migration 2026_09_01_000004_add_shifts_view_amounts_permission)',
        ],

        // ── FINANCE — supplier finance / manual journal / purchase return offline workflows ─────────────────────────
        'tenant.suppliers.ledger' => [
            'groups' => ['finance'],
            'edge' => 'EdgeLocalSupplierFinanceService::PERM_LEDGER (suppliers screen / options / ledger) + EdgeSupplierFinanceProjectionService::PERMISSIONS',
            'online' => 'GET /suppliers/{supplier}/ledger — SupplierController@ledger',
        ],
        'tenant.supplier-payments.store' => [
            'groups' => ['finance'],
            'edge' => 'EdgeLocalSupplierFinanceService::PERM_PAYMENT (record payment) + EdgeInboundSupplierFinanceIngestionService actor check',
            'online' => 'POST /supplier-payments — SupplierPaymentController@store',
        ],
        'tenant.supplier-payments.index' => [
            'groups' => ['finance'],
            'edge' => 'EdgeLocalSupplierFinanceController@paymentsIndex (requireAny)',
            'online' => 'GET /supplier-payments — SupplierPaymentController@index',
        ],
        'tenant.supplier-payments.show' => [
            'groups' => ['finance'],
            'edge' => 'EdgeLocalSupplierFinanceController@paymentShow (requireAny)',
            'online' => 'GET /supplier-payments/{supplierPayment} — SupplierPaymentController@show',
        ],
        'tenant.finance.manual-journals.store' => [
            'groups' => ['finance'],
            'edge' => 'EdgeLocalSupplierFinanceService::PERM_JOURNAL (journal screen / options / post) + EdgeInboundSupplierFinanceIngestionService actor check',
            'online' => 'POST /finance/manual-journals — ManualJournalController@store',
        ],
        'tenant.finance.manual-journals.index' => [
            'groups' => ['finance'],
            'edge' => 'EdgeLocalSupplierFinanceController@journalsIndex (requireAny)',
            'online' => 'GET /finance/manual-journals — ManualJournalController@index',
        ],
        'tenant.finance.manual-journals.show' => [
            'groups' => ['finance'],
            'edge' => 'EdgeLocalSupplierFinanceController@journalShow (requireAny)',
            'online' => 'GET /finance/manual-journals/{manualJournal} — ManualJournalController@show',
        ],
        'tenant.purchase-returns.store' => [
            'groups' => ['finance'],
            'edge' => 'EdgeLocalPurchaseReturnService::PERM_STORE + EdgePurchaseReturnProjectionService::PERMISSIONS + EdgeInboundPurchaseReturnIngestionService actor check',
            'online' => 'POST /purchase-returns — PurchaseReturnController@store',
        ],
        'tenant.purchase-returns.post' => [
            'groups' => ['finance'],
            'edge' => 'EdgeLocalPurchaseReturnService::PERM_POST + EdgePurchaseReturnProjectionService::PERMISSIONS + EdgeInboundPurchaseReturnIngestionService actor check',
            'online' => 'POST /purchase-returns/{purchaseReturn}/post — PurchaseReturnController@post',
        ],
        'tenant.purchase-returns.index' => [
            'groups' => ['finance'],
            'edge' => 'EdgeLocalPurchaseReturnController@listScreen (abort_unless)',
            'online' => 'GET /purchase-returns — PurchaseReturnController@index',
        ],
        'tenant.purchase-returns.show' => [
            'groups' => ['finance'],
            'edge' => 'EdgeLocalPurchaseReturnController@detailScreen (abort_unless)',
            'online' => 'GET /purchase-returns/{purchaseReturn} — PurchaseReturnController@show',
        ],
    ];

    /**
     * @can gates of the shared POS view that are deliberately NOT catalogued: Cloud management / reporting surfaces
     * (§7 cloud-only capability matrix) — Edge renders a capability slot instead, never the Cloud iframe.
     */
    public const SHARED_VIEW_CLOUD_ONLY_CHECKS = [
        'tenant.reports.center.index' => 'POS Report link → Cloud Sales Report Center iframe (Edge: Quick Report instead)',
        'tenant.restaurant.floors.index' => 'Manage Floors → Cloud restaurant management iframe',
        'tenant.restaurant.tables.index' => 'Manage Tables → Cloud restaurant management iframe',
        'tenant.sales-orders.show' => 'sales-returns index/show link to the Online sales-order detail page — no Edge route (Edge hides the link)',
    ];

    /** @return list<string> the `Cashier (Counter)` template. */
    public static function cashier(): array
    {
        return self::group(self::GROUP_CASHIER);
    }

    /** @return list<string> supervisor-only markers (NOT approver eligibility — that is the bootstrap flag may_approve_pos, Phase 3). */
    public static function manager(): array
    {
        return self::group(self::GROUP_MANAGER);
    }

    /** @return list<string> supplier finance / manual journal / purchase return permissions Edge checks. */
    public static function finance(): array
    {
        return self::group(self::GROUP_FINANCE);
    }

    /** @return list<string> the union of every group (each name once). */
    public static function all(): array
    {
        return array_keys(self::ENTRIES);
    }

    public static function has(string $permission): bool
    {
        return isset(self::ENTRIES[$permission]);
    }

    /** @return array{groups: list<string>, edge: string, online: string}|null */
    public static function describe(string $permission): ?array
    {
        return self::ENTRIES[$permission] ?? null;
    }

    /** @return list<string> */
    private static function group(string $group): array
    {
        $names = [];
        foreach (self::ENTRIES as $name => $entry) {
            if (in_array($group, $entry['groups'], true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
