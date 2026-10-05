<?php

namespace App\Services\Edge;

use App\Models\Tenant\Branch;
use App\Support\Pos\PosRuntime;
use Illuminate\Http\Request;
use Throwable;

/**
 * POS-RUNTIME-1 (W-B) — the Edge adapter of the ONE shared cashier view (`tenant.pos.index`).
 *
 * Builds the {@see PosRuntime} for a Branch Server: every route template is an `edge.local.*` path (never a Cloud path —
 * the Cloud controllers are not routed on an appliance), every capability follows the §7 Cloud-only matrix of
 * docs/status/edge-next-release-shared-pos-architecture.md (a capability that is off → its route is `null` and the page
 * renders the SAME control disabled in the SAME place), the identity is the BOUND branch (never selectable; terminal
 * chosen per session), and the authority block carries the P0 lease / Q state-machine labels the shared status slot
 * shows (owner decision A5: no Edge-only layout delta — the Edge status lives in the shared slot).
 *
 * The factory adds no authority of its own: every endpoint re-checks the Online route permission, the bound branch, the
 * selected terminal and the lease fence server-side. `can_mutate` is informational for the page (disable, explain).
 */
class EdgePosRuntimeFactory
{
    /** THE cashier page (Phase 3 Stage A: `edge.local.pos.screen` renders the shared view; `/edge/local/pos/shared` is a Phase 2 alias). */
    public const SHARED_PAGE = '/edge/local/pos';

    public const ASSET_BASE = '/edge/local/assets';

    /** W-C's local product-image streamer (`edge.local.storage`, storage/app/public). */
    public const STORAGE_BASE = '/edge/local/storage';

    public const LOGIN = '/edge/local/login';

    public const CHROME_VIEW = 'tenant.pos.partials.pos-chrome-edge';

    /** §7 — the capability matrix on a Branch Server (the ONLY allowed functional differences from Online). */
    public const CAPABILITIES = [
        'reports' => false,              // Reports Centre = accepted Cloud-only reporting scope
        'quickReport' => true,
        'quickReportEmail' => false,     // needs the Internet
        'quickReportNetwork' => true,
        'manageFloorsTables' => false,   // Cloud configuration (admin CRUD iframes)
        'branchSelect' => false,         // one bound branch per appliance
        'nonCashTender' => false,        // card/provider = ONLINE_REQUIRED; manual methods = owner decision
        'tipOnPaidSale' => false,        // the sync contract does not carry a tip yet
        'customerCreate' => false,       // Team D (§8) switches this on with the Cloud advertisement
        'customerAddressCreate' => false,
        'changeRider' => false,          // no Edge sales-order page (gap noted in §14)
        'splitBill' => true,
        'salesReturn' => true,
        'shiftPages' => true,            // owner A4: the SAME shift pages, scoped to the bound branch/terminal
        'promotions' => true,
        'tips' => true,                  // tips are QUOTED (held/preview); a paid sale refuses one (tipOnPaidSale)
        'tableMerge' => true,
        'reservations' => true,
        'deadSessionRecovery' => true,
        'printHere' => true,
    ];

    /** Hint texts for the controls a capability turns off (the existing Edge wording). */
    public const LABELS = [
        'reports' => 'Reports run on the Online POS.',
        'quickReportEmail' => 'Email needs the Internet — use View / Print here or Send to network on the Branch Server.',
        'manageFloorsTables' => 'Floors, tables and waiters are Cloud configuration — manage them on the Online POS; changes reach this Branch Server with the next configuration refresh.',
        'branchSelect' => 'This Branch Server is bound to its branch — a different branch is served by its own Branch Server.',
        'nonCashTender' => 'Cash is taken on the Branch Server. Card / provider payments run on the Online POS; bank transfer, cheque and other manual methods await the owner decision.',
        'nonCashTenderShort' => 'Only cash can be taken on the Branch Server.',
        'tipOnPaidSale' => 'A tip cannot be recorded on a Branch Server sale until the Cloud sync contract carries tips — choose No Tip to complete this sale.',
        'customerCreate' => 'No exact match in the synced customer book. Adding a new customer needs the Online POS.',
        'customerAddressCreate' => 'Saving another address for this customer needs the Online POS — the order can still go to a saved address.',
        'changeRider' => 'Changing the rider of a completed delivery needs the Online POS.',
        'reservedOnline' => 'Reserved on the Online POS — guest details did not reach this Branch Server.',
        'refundOnlineRequired' => 'Refunding it the same way needs the Online POS; a cash refund from this till is possible where the business allows it.',
        'shiftBranchWide' => 'Opening shifts for several terminals at once runs on the Online POS — this Branch Server opens the shift of its own terminal.',
        'shortageVoucher' => 'The shortage is recorded on this shift. The Branch Server does not raise the finance draft expense voucher; finance settles it from the shift record.',
        'modeChip' => null,
        'connection' => null,
    ];

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeAuthorityService $authority,
        private readonly EdgeLocalReadiness $readiness,
    ) {
    }

    /**
     * W-B canonical contract (§3.2) — the shared page has ONE error path: a missing / stale / unauthorised-terminal refusal
     * from the per-session terminal resolver (ResolvesEdgePosContext::selectedTerminal, 422) carries Online's
     * `code: INVALID_TERMINAL` (RestaurantTableSessionController). A 403 (terminal pin / assignment) keeps its
     * `{message, permission}` shape. Anything else passes through untouched.
     */
    public static function terminalOrCoded(mixed $resolved): mixed
    {
        if ($resolved instanceof \Illuminate\Http\JsonResponse && $resolved->getStatusCode() === 422) {
            $body = (array) $resolved->getData(true);
            $resolved->setData(['ok' => false, 'code' => 'INVALID_TERMINAL'] + $body);
        }

        return $resolved;
    }

    /**
     * W-B — the shared page picks the terminal client-side (Online semantics) and sends it as `terminal_id` with its requests
     * (sale form, shift-status query). A Branch Server keeps ONE per-session selection, so a request-named terminal is
     * ADOPTED into the session when it is an active terminal of the bound branch that this operator may operate (the SAME
     * checks as POST /terminal/select); otherwise the request is refused (422 INVALID_TERMINAL / 403 terminal authority).
     * No `terminal_id` → nothing changes (the old page selects through /terminal/select).
     *
     * @param  callable(\App\Models\Tenant\Terminal): ?\Illuminate\Http\JsonResponse  $mayOperate  the controller's terminal-authority gate
     */
    public static function adoptRequestedTerminal(Request $request, int $branchId, callable $mayOperate): ?\Illuminate\Http\JsonResponse
    {
        $requested = (int) $request->input('terminal_id', 0);
        if ($requested <= 0 || ! $request->hasSession()) {
            return null;
        }
        $key = \App\Http\Controllers\Edge\EdgeLocalPosController::TERMINAL_SESSION_KEY;
        if ((int) $request->session()->get($key, 0) === $requested) {
            return null;
        }
        $terminal = \App\Models\Tenant\Terminal::on('tenant')->where('id', $requested)->where('branch_id', $branchId)->where('status', 'active')->first();
        if (! $terminal) {
            return response()->json(['ok' => false, 'code' => 'INVALID_TERMINAL', 'message' => 'Select an active terminal on this branch.'], 422);
        }
        if ($denied = $mayOperate($terminal)) {
            return $denied;
        }
        $request->session()->put($key, (int) $terminal->id);

        return null;
    }

    /** Online's `code: NO_OPEN_SHIFT` refusal (HeldSaleController / RestaurantTableSessionController) for a ShiftException. */
    public static function noOpenShift(\Throwable $e): \Illuminate\Http\JsonResponse
    {
        return response()->json(['ok' => false, 'code' => 'NO_OPEN_SHIFT', 'message' => $e->getMessage()], 422);
    }

    public function make(?Request $request = null): PosRuntime
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $branch = Branch::on('tenant')->find($branchId);
        $request ??= request();
        $selectedTerminal = $request && $request->hasSession()
            ? (int) $request->session()->get(\App\Http\Controllers\Edge\EdgeLocalPosController::TERMINAL_SESSION_KEY, 0)
            : 0;

        $authority = $this->authorityBlock();
        $labels = self::LABELS;
        $labels['modeChip'] = $authority['label'];
        $labels['connection'] = $authority['connection_label'];
        // PosRuntime::capabilityHint() reads `capability.<key>` (then `capabilityOff`) for a capability-off control's tooltip.
        foreach (self::CAPABILITIES as $capability => $on) {
            if (! $on && isset(self::LABELS[$capability])) {
                $labels['capability.' . $capability] = self::LABELS[$capability];
            }
        }
        $labels['capabilityOff'] = 'Not available on the Branch Server — use the Online POS.';

        return new PosRuntime(
            mode: PosRuntime::MODE_EDGE,
            routes: $this->routes(),
            capabilities: self::CAPABILITIES,
            identity: [
                'branch_id' => $branchId,
                'branch_name' => $branch?->name,
                'branch_selectable' => false,
                'terminal_selection' => 'session',
                'selected_terminal_id' => $selectedTerminal > 0 ? $selectedTerminal : null,
            ],
            authority: $authority,
            assets: ['base' => self::ASSET_BASE, 'storage' => self::STORAGE_BASE],
            transport: [
                'csrf_header' => 'X-CSRF-TOKEN',
                'body' => 'json',
                'unauthenticated_redirect' => self::LOGIN,
            ],
            managerCredential: PosRuntime::CREDENTIAL_EMPLOYEE,
            labels: $labels,
            chromeView: self::CHROME_VIEW,
        );
    }

    /**
     * Every PosRuntime::ROUTE_KEYS entry (null = capability off) + the Edge-only keys the shared page needs because the
     * terminal is chosen per SESSION on a Branch Server (terminals / terminalSelect) and a few Edge twins with no Online
     * key yet (see the W-B report — requested additions to PosRuntime::ROUTE_KEYS).
     *
     * Phase 3 Stage B (owner §5.1): the three Edge operator-screen keys are per OPERATOR — null unless the authenticated
     * Edge user holds the permission the Edge route itself enforces (the screen still refuses server-side; the menu only
     * stops offering an entry that would 403). Same "null = not available" convention as a capability-off route.
     *
     * @return array<string, string|null>
     */
    public function routes(): array
    {
        $p = '/edge/local/pos';
        $user = auth('tenant')->user();
        $may = fn (array $permissions): bool => $user !== null && collect($permissions)->contains(fn (string $perm) => $user->can($perm));

        return [
            // page + navigation
            'posIndex' => self::SHARED_PAGE,
            'logout' => '/edge/local/logout',
            'status' => $p . '/health',
            'serverTime' => $p . '/server-time',
            // sale
            'saleStore' => $p . '/sales',
            'saleHeldSettle' => $p . '/held-sales/{sale}/settle',
            'printingRetry' => $p . '/sales/{sale}/printing/retry',
            // customers
            'customerSearch' => $p . '/customers',
            'customerQuickStore' => null,        // capability customerCreate (Team D)
            'customerAddressStore' => null,      // capability customerAddressCreate (Team D)
            // quick report
            'quickReportOptions' => $p . '/quick-report/options',
            'quickReportSettings' => $p . '/quick-report/settings',
            'quickReportSave' => $p . '/quick-report/save-settings',
            'quickReportPrint' => $p . '/quick-report/view',
            'quickReportEmail' => null,          // capability quickReportEmail (Internet)
            'quickReportNetwork' => $p . '/quick-report/network',
            // tables
            'tableBoardHtml' => $p . '/restaurant/board/html',
            'tableSessions' => $p . '/restaurant/table-sessions',
            'tableSessionOpenOrders' => $p . '/restaurant/table-sessions/{session}/open-orders',
            'tableOpen' => $p . '/restaurant/tables/{table}/open',
            'tableBillRequested' => $p . '/restaurant/table-sessions/{session}/bill-requested',
            'tableClose' => $p . '/restaurant/table-sessions/{session}/close',
            'tableBillPreview' => $p . '/restaurant/table-sessions/{session}/bill-preview',
            'tableMove' => $p . '/restaurant/table-sessions/{session}/move',
            'tableMerge' => $p . '/restaurant/table-sessions/{session}/merge',
            'reservation' => $p . '/restaurant/tables/{table}/reservation',
            'reserve' => $p . '/restaurant/tables/{table}/reserve',
            'unreserve' => $p . '/restaurant/tables/{table}/unreserve',
            'manageFloors' => null,              // capability manageFloorsTables
            'manageTables' => null,
            // shift
            'shiftStatus' => $p . '/shift',
            'shiftOpenPage' => $p . '/shifts/open',
            'shiftClosePage' => $p . '/shifts/{shift}/close',
            'shiftOpen' => $p . '/shift/open',
            'shiftClose' => $p . '/shift/close',
            // totals / approvals / preview
            'totalsQuote' => $p . '/totals/quote',
            'promoQuote' => $p . '/promotions/quote',
            'billPreview' => $p . '/bill-preview/document',
            'managerVerify' => $p . '/manager-approvals/verify',
            // held / recent
            'heldList' => $p . '/held-sales',
            'heldShow' => $p . '/held-sales/{sale}',
            'heldStore' => $p . '/held-sales',
            'heldCancel' => $p . '/held-sales/{sale}/cancel',
            'heldReattach' => $p . '/held-sales/{sale}/reattach-table',
            'recentSales' => $p . '/recent-sales',
            'salesOrderShow' => null,            // capability changeRider
            'splitBillPage' => $p . '/held-sales/{sale}/split-bill',
            // printing
            'printJobsForSale' => $p . '/print-jobs?sale_id={sale}',
            'printJobsRecent' => $p . '/print-jobs',
            'kotQueue' => $p . '/sales/{sale}/kot',
            'receiptQueue' => $p . '/sales/{sale}/receipt',
            'reminderConfirm' => $p . '/sales/{sale}/reminders/confirm',
            'reminderReprint' => $p . '/print-jobs/{job}/reminder-reprint',
            'printRetry' => $p . '/print-jobs/{job}/retry',
            'printDocument' => $p . '/print-jobs/{job}/document',
            // returns / reports
            'salesReturnCreatePage' => $p . '/sales-returns/create',
            'salesReturnIndexPage' => $p . '/shared/sales-returns',
            'reportsCenter' => null,             // capability reports

            // ── Edge-only keys (not in PosRuntime::ROUTE_KEYS yet; requested in the W-B report) ──
            'terminals' => $p . '/terminals',
            'terminalSelect' => $p . '/terminal/select',
            'syncSummary' => $p . '/sync/summary',
            'shiftSummary' => $p . '/shift/summary',
            'shiftIndexPage' => $p . '/shared/shifts',
            'shiftShowPage' => $p . '/shared/shifts/{shift}',
            'shiftOpenStore' => $p . '/shifts/open',
            'shiftCloseStore' => $p . '/shifts/{shift}/close',
            'shiftCloseBranchPage' => null,       // Cloud-only (branch-wide close; owner A4)
            'salesReturnShowPage' => $p . '/shared/sales-returns/{salesReturn}',
            'salesReturnSearch' => $p . '/returns/search',
            'salesReturnStore' => $p . '/sales-returns',
            'splitBillStore' => $p . '/held-sales/{sale}/split-bill',
            'voidReasons' => $p . '/void-reasons',
            'printPreferences' => $p . '/print-preferences',
            'printMarkPrinted' => $p . '/print-jobs/{job}/printed',
            'printDismiss' => $p . '/print-jobs/{job}/dismiss',
            'heldKot' => $p . '/held-sales/{sale}/kot',

            // ── Stage B (owner §5.1) — the Branch Server's own operator screens, offered by the Edge menu behind #pos-sidebar-toggle
            //    (pos-chrome-edge). Gated on the SAME permission each Edge route enforces (EdgeLocalSupplierFinanceController::requireAny,
            //    EdgeLocalPurchaseReturnController::requireView); Cloud: null. ──
            'supplierFinancePage' => $may([EdgeLocalSupplierFinanceService::PERM_LEDGER, EdgeLocalSupplierFinanceService::PERM_PAYMENT]) ? $p . '/suppliers' : null,
            'financeJournalPage' => $may([EdgeLocalSupplierFinanceService::PERM_JOURNAL]) ? $p . '/finance/journal' : null,
            'purchaseReturnsPage' => $may([EdgeLocalPurchaseReturnService::PERM_STORE, EdgeLocalPurchaseReturnService::PERM_POST]) ? $p . '/purchase-returns' : null,
        ];
    }

    /**
     * The authority block for the shared status slot. Business words only (never a lease id, hash, epoch or timestamp).
     * The slot is a fixed 260px box (W-A pos-status-slot): label + sub_label stay short enough to fit (≈36 characters).
     *
     * @return array{state:string, label:string, sub_label:string, can_mutate:bool, pending_sync:int, needs_attention:int, tone:string, connection:string, connection_label:string, lease_mode:bool}
     */
    public function authorityBlock(): array
    {
        $pending = 0;
        $attention = 0;
        try {
            $outbox = app(EdgeSyncStatusService::class)->snapshot()['outbox'] ?? [];
            $pending = (int) ($outbox['pending'] ?? 0) + (int) ($outbox['leased'] ?? 0);
            $attention = (int) ($outbox['failed_permanent'] ?? 0);
        } catch (Throwable $e) {
            report($e); // the status slot is informational — never a reason to refuse the page
        }

        $leaseMode = $this->authority->leaseModeEnabled();
        $state = $this->authority->state();
        $connection = EdgeConnectionStateMachine::ONLINE;
        if ($leaseMode) {
            try {
                $connection = (string) (app(EdgeConnectionStateMachine::class)->derive()['state'] ?? EdgeConnectionStateMachine::ONLINE);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $connectionLabel = EdgeConnectionStateMachine::label($connection);

        if (! $leaseMode) {
            // Manual Local Mode (no lease provisioned): the hand-to-server switch governs and the appliance is the writer.
            $block = ['state' => 'local_manual', 'label' => 'LOCAL MODE', 'sub_label' => 'MANUAL SWITCH', 'can_mutate' => true, 'tone' => 'ok'];
        } elseif ($state === EdgeAuthorityService::LOCAL_ACTIVE) {
            $block = ['state' => 'local_active', 'label' => $connectionLabel === 'ONLINE' ? 'LOCAL MODE ACTIVE' : $connectionLabel,
                'sub_label' => $connectionLabel === 'ONLINE' || $connection === EdgeConnectionStateMachine::LOCAL_ACTIVE ? 'BRANCH SERVER' : 'LOCAL MODE', 'can_mutate' => true,
                'tone' => $connection === EdgeConnectionStateMachine::LOCAL_ACTIVE ? 'danger' : 'warn'];
        } elseif ($state === EdgeAuthorityService::HANDING_BACK) {
            $block = ['state' => 'handing_back', 'label' => 'RETURNING TO ONLINE', 'sub_label' => 'HANDBACK', 'can_mutate' => false, 'tone' => 'warn'];
        } else {
            // STANDBY — the Cloud (Online POS) is the writer; the appliance is a warm standby and refuses mutations.
            $troubled = in_array($connection, [EdgeConnectionStateMachine::CONNECTION_UNSTABLE, EdgeConnectionStateMachine::CONNECTION_LOST, EdgeConnectionStateMachine::PREPARING_LOCAL], true);
            $block = ['state' => 'standby', 'label' => $troubled ? $connectionLabel : 'STANDBY', 'sub_label' => $troubled ? null : 'CLOUD AUTHORITY',
                'can_mutate' => false, 'tone' => $troubled ? 'danger' : 'warn'];
        }

        if ($attention > 0 && $block['tone'] === 'ok') {
            $block['tone'] = 'warn';
        }

        return $block + [
            'pending_sync' => $pending,
            'needs_attention' => $attention,
            'connection' => $connection,
            'connection_label' => $connectionLabel,
            'lease_mode' => $leaseMode,
            'local_database_ready' => $this->readiness->localDatabaseReady(),
        ];
    }
}
