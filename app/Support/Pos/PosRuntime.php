<?php

namespace App\Support\Pos;

use JsonSerializable;

/**
 * POS-RUNTIME-1 — the ONLY frontend runtime switch of the shared cashier view (`resources/views/tenant/pos/index.blade.php`).
 *
 * The same Blade renders on the Cloud (Online POS) and on a Branch Server (Edge). Everything that legitimately differs between
 * the two runtimes is carried by this value object — endpoint URLs, capability flags, authority labels, the manager-credential
 * type, the asset base and the login redirect — and by NOTHING else: the view never inspects `config('app.role')`, never
 * hard-codes a `url('/…')`, and never carries a second copy of workflow markup. A capability that is off renders the SAME
 * control disabled in the SAME place (owner decision A5: identical geometry in both runtimes).
 *
 * Built by {@see CloudPosRuntimeFactory} (Online) and {@see \App\Services\Edge\EdgePosRuntimeFactory} (Edge); serialised into
 * `window.POS_RUNTIME` by `layouts.pos`. Route templates use `{param}` placeholders resolved by `POS.route()` in
 * `resources/views/tenant/pos/js/pos-runtime.blade.php`. A route whose capability is off is `null`.
 */
final class PosRuntime implements JsonSerializable
{
    public const MODE_CLOUD = 'cloud';
    public const MODE_EDGE = 'edge';

    public const CREDENTIAL_PIN = 'pin';                                 // Online: manager PIN
    public const CREDENTIAL_EMPLOYEE = 'employee_code_and_credential';   // Edge: the manager's own Edge credential

    /** Every route key the shared view may ask for. Factories MUST define all of them (null = capability off). */
    public const ROUTE_KEYS = [
        // page + navigation
        'posIndex', 'logout', 'status', 'serverTime',
        // sale
        'saleStore', 'saleHeldSettle', 'printingRetry',
        // customers
        'customerSearch', 'customerQuickStore', 'customerAddressStore',
        // quick report
        'quickReportOptions', 'quickReportSettings', 'quickReportSave', 'quickReportPrint', 'quickReportEmail', 'quickReportNetwork',
        // tables
        'tableBoardHtml', 'tableSessions', 'tableSessionOpenOrders', 'tableOpen', 'tableBillRequested', 'tableClose',
        'tableBillPreview', 'tableMove', 'tableMerge', 'reservation', 'reserve', 'unreserve', 'manageFloors', 'manageTables',
        // shift
        'shiftStatus', 'shiftOpenPage', 'shiftClosePage', 'shiftOpen', 'shiftClose',
        // totals / approvals / preview
        'totalsQuote', 'promoQuote', 'billPreview', 'managerVerify',
        // held / recent
        'heldList', 'heldShow', 'heldStore', 'heldCancel', 'heldReattach', 'recentSales', 'salesOrderShow', 'splitBillPage',
        // printing
        'printJobsForSale', 'printJobsRecent', 'kotQueue', 'receiptQueue', 'reminderConfirm', 'reminderReprint', 'printRetry', 'printDocument',
        // returns / reports
        'salesReturnCreatePage', 'salesReturnIndexPage', 'reportsCenter',
        // W-B additions (Edge runtime report §2): terminal/session + sync, shared secondary screens, printing extras
        'terminals', 'terminalSelect', 'syncSummary', 'shiftSummary',
        'shiftIndexPage', 'shiftShowPage', 'shiftOpenStore', 'shiftCloseStore', 'shiftCloseBranchPage',
        'salesReturnShowPage', 'salesReturnSearch', 'salesReturnStore', 'splitBillStore',
        'voidReasons', 'printPreferences', 'printMarkPrinted', 'printDismiss', 'heldKot',
        // Phase 3 Stage B (owner §5.1 — Edge entry points): the Branch Server's own operator screens, offered by the Edge menu
        // behind #pos-sidebar-toggle (pos-chrome-edge). Cloud: null (the Cloud sidebar carries its own menu); Edge: null when
        // the operator lacks the permission the Edge route enforces.
        'supplierFinancePage', 'financeJournalPage', 'purchaseReturnsPage',
        // CANONICAL MERGE (D8, 19 Oct 2026) — POS-REMINDER-PAUSE-1 (canonical 4175db4f): the selected terminal's Reminder switch.
        // Cloud: /api/pos/reminder-pause; Edge: null (capability reminderPause off) until team D2 builds the Branch Server twin
        // through the shared ReminderPauseService. The view NEVER hard-codes the Cloud URL (owner: no Cloud literal in the shared view).
        'reminderPause',
        // CANONICAL MERGE (D8) — ONLINE-ORDERING-1 (canonical bd84466d…): the POS website-orders inbox (list / hold / verify).
        // Cloud: tenant.api.pos.website-orders*; Edge: null — a website order needs the Cloud (capability websiteOrders off, D4).
        'websiteOrders', 'websiteOrderHold', 'websiteOrderVerify',
    ];

    /** Every capability flag the shared view may ask for. Factories MUST define all of them. */
    public const CAPABILITY_KEYS = [
        'reports', 'quickReport', 'quickReportEmail', 'quickReportNetwork', 'manageFloorsTables', 'branchSelect',
        'nonCashTender', 'tipOnPaidSale', 'customerCreate', 'customerAddressCreate', 'changeRider', 'splitBill',
        'salesReturn', 'shiftPages', 'promotions', 'tips', 'tableMerge', 'reservations', 'deadSessionRecovery', 'printHere',
        // POS-REMINDER-PAUSE-1 — pause / resume Reminder slips from the counter. Cloud ON; Edge ON since D2 (20 Oct 2026): the
        // Branch Server twin stamps the LOCAL open shift through the shared ReminderPauseService (no Cloud call).
        'reminderPause',
        // CANONICAL MERGE (D8): ONLINE-ORDERING-1 — the website-orders inbox is a Cloud capability (D4: Cloud-only, same slot on Edge).
        'websiteOrders',
        // D6 (20 Oct 2026): PARTNER-SHARE-1 — the partner-share report section. Cloud ON; Edge OFF (share_partners / share_rates are
        // CLOUD_ONLY tables, bootstrap stays v8). SERVER-SIDE capability: no cashier control binds to it on either runtime (the
        // quick-report modal has no Partner Share box — Online PosQuickReportController::SECTIONS excludes it); the Edge quick
        // report strips the section by this flag (EdgeQuickReportController::CAPABILITY_SECTIONS).
        'partnerShare',
        // CANONICAL 025728d8 (22 Oct 2026): RETURN-NO-BILL-1 — a return without a bill (Master switch). Cloud ON; Edge OFF: the Branch
        // Server has no processNoBillReturn twin, so the shared return screen renders the "Without bill" way only where this is ON.
        // SERVER-SIDE class: no control on the cashier page binds to it (the site is the shared sales-returns/create screen).
        'returnWithoutBill',
    ];

    /**
     * @param  array<string,string|null>  $routes        route key → URL template ('/edge/local/pos/held-sales/{sale}/cancel')
     * @param  array<string,bool>         $capabilities  capability key → enabled
     * @param  array<string,mixed>        $identity      { branch_id, branch_name, branch_selectable, terminal_selection: per_request|session }
     * @param  array<string,mixed>        $authority     { state, label, sub_label, can_mutate, pending_sync, tone: ok|warn|danger }
     * @param  array<string,string>       $assets        { base, storage }  (absolute-path prefixes)
     * @param  array<string,string>       $transport     { csrf_header, body, unauthenticated_redirect }
     * @param  array<string,string|null>  $labels        free-text labels the view shows verbatim (hints for disabled controls)
     * @param  string|null                $chromeView    W-A (additive, optional): the Blade view `layouts.pos` renders in its chrome
     *                                                   slot — Cloud: 'tenant.pos.partials.pos-chrome-cloud' (the hidden Online
     *                                                   header/sidebar); Edge: its own view. Server-side only, never serialised.
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $routes,
        public readonly array $capabilities,
        public readonly array $identity,
        public readonly array $authority,
        public readonly array $assets,
        public readonly array $transport,
        public readonly string $managerCredential,
        public readonly array $labels = [],
        public readonly ?string $chromeView = null,
    ) {
        foreach (self::ROUTE_KEYS as $key) {
            if (! array_key_exists($key, $this->routes)) {
                throw new \InvalidArgumentException("PosRuntime: route [{$key}] is not defined for mode [{$mode}].");
            }
        }
        foreach (self::CAPABILITY_KEYS as $key) {
            if (! array_key_exists($key, $this->capabilities)) {
                throw new \InvalidArgumentException("PosRuntime: capability [{$key}] is not defined for mode [{$mode}].");
            }
        }
        if (! in_array($mode, [self::MODE_CLOUD, self::MODE_EDGE], true)) {
            throw new \InvalidArgumentException("PosRuntime: unknown mode [{$mode}].");
        }
    }

    public function isEdge(): bool
    {
        return $this->mode === self::MODE_EDGE;
    }

    public function can(string $capability): bool
    {
        return (bool) ($this->capabilities[$capability] ?? false);
    }

    /** Resolve a route template; `null` when the capability behind it is off. */
    public function route(string $key, array $params = []): ?string
    {
        $template = $this->routes[$key] ?? null;
        if ($template === null) {
            return null;
        }
        foreach ($params as $name => $value) {
            $template = str_replace('{' . $name . '}', rawurlencode((string) $value), $template);
        }

        return $template;
    }

    /** Tooltip text for a capability-off control; a runtime label (`capability.<key>`, then `capabilityOff`) wins. */
    public function capabilityHint(string $capability): string
    {
        return (string) ($this->labels['capability.' . $capability] ?? $this->labels['capabilityOff'] ?? 'Not available in this mode');
    }

    /** Asset URL for a path under public/ (e.g. 'assets/css/style.css'). */
    public function asset(string $path): string
    {
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'assets/')) {
            return rtrim($this->assets['base'], '/') . '/' . substr($path, strlen('assets/'));
        }
        if (str_starts_with($path, 'storage/')) {
            return rtrim($this->assets['storage'], '/') . '/' . substr($path, strlen('storage/'));
        }

        return '/' . $path;
    }

    public function jsonSerialize(): array
    {
        return [
            'mode' => $this->mode,
            'routes' => $this->routes,
            'capabilities' => $this->capabilities,
            'identity' => $this->identity,
            'authority' => $this->authority,
            'assets' => $this->assets,
            'transport' => $this->transport,
            'managerCredential' => $this->managerCredential,
            'labels' => $this->labels,
        ];
    }
}
