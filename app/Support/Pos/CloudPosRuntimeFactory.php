<?php

namespace App\Support\Pos;

/**
 * W-A — builds the ONLINE (Cloud) {@see PosRuntime} for the shared cashier view `tenant.pos.index`.
 *
 * Every route template is the literal tenant path the page hard-coded as `url('/…')` before the shared-view
 * refactor (Phase 2 step 3), so the Online POS talks to exactly the same endpoints as before. Templates are
 * root-relative ('/pos', '/printing/jobs/kot/{sale}'), prefixed with the application's base path when the app is
 * served from a sub-directory, so the result is equivalent to `url()` on every deployment shape.
 *
 * Capabilities: all ON. The page keeps its own `@can` gates unchanged — a capability flag never replaces a
 * permission check; it only exists so the Edge runtime can render a Cloud-only control disabled in the same place.
 *
 * A route that the Cloud does not expose as a separate endpoint is `null` (documented per key below); the page
 * never asks for those on the Online path.
 */
final class CloudPosRuntimeFactory
{
    /**
     * @param  int|null     $branchId    the branch the page renders for (identity only; the page still posts its own branch_id)
     * @param  string|null  $branchName
     */
    public function make(?int $branchId = null, ?string $branchName = null): PosRuntime
    {
        $root = $this->basePath();
        $path = static fn (?string $p): ?string => $p === null ? null : $root . $p;

        $routes = array_map($path, [
            // page + navigation
            'posIndex' => '/pos',
            'logout' => '/logout',
            'status' => null,                                  // Edge-only status page; the Cloud has none
            'serverTime' => '/api/server-time',
            // sale
            'saleStore' => '/pos',
            'saleHeldSettle' => '/pos',                        // Online settles a held sale through POST /pos + held_sale_id
            'printingRetry' => '/pos/{sale}/printing/retry',
            // customers
            'customerSearch' => '/ajax/customers',
            'customerQuickStore' => '/pos/customers/quick-store',
            'customerAddressStore' => '/pos/customers/{customer}/addresses',
            // quick report
            'quickReportOptions' => null,                      // Online embeds the pickers server-side (no JSON twin yet)
            'quickReportSettings' => '/pos/quick-report/settings',
            'quickReportSave' => '/pos/quick-report/save-settings',
            'quickReportPrint' => '/pos/quick-report/print',
            'quickReportEmail' => '/pos/quick-report/email',
            'quickReportNetwork' => '/pos/quick-report/send-to-network',
            // tables
            'tableBoardHtml' => '/api/pos/table-board',
            'tableSessions' => '/api/pos/table-sessions',
            'tableSessionOpenOrders' => '/api/pos/table-sessions/{session}/open-orders',
            'tableOpen' => '/restaurant/tables/{table}/open',
            'tableBillRequested' => '/restaurant/table-sessions/{session}/bill-requested',
            'tableClose' => '/restaurant/table-sessions/{session}/close',
            'tableBillPreview' => '/restaurant/table-sessions/{session}/bill-preview',
            'tableMove' => '/restaurant/table-sessions/{session}/move',
            'tableMerge' => '/restaurant/table-sessions/{session}/merge',
            'reservation' => '/restaurant/tables/{table}/reservation',
            'reserve' => '/restaurant/tables/{table}/reserve',
            'unreserve' => '/restaurant/tables/{table}/unreserve',
            'manageFloors' => '/restaurant/floors',
            'manageTables' => '/restaurant/tables',
            // shift
            'shiftStatus' => '/api/pos/shift-status',
            'shiftOpenPage' => '/shifts/open',
            'shiftClosePage' => '/shifts/{shift}/close',
            'shiftOpen' => '/shifts/open',
            'shiftClose' => '/shifts/{shift}/close',
            // totals / approvals / preview
            'totalsQuote' => '/api/pos/totals/quote',
            'promoQuote' => '/api/pos/promotions/quote',
            'billPreview' => '/api/pos/bill-preview',
            'managerVerify' => '/api/manager-approvals/verify',
            // held / recent
            'heldList' => '/api/pos/held-sales',
            'heldShow' => null,                                // Online embeds lines[] in the list; recall = posIndex?held_sale_id=
            'heldStore' => '/held-sales',
            'heldCancel' => '/held-sales/{sale}/cancel',
            'heldReattach' => '/held-sales/{sale}/reattach-table',
            'recentSales' => '/api/pos/recent-sales',
            'salesOrderShow' => '/sales-orders/{sale}',
            'splitBillPage' => '/sales-orders/{sale}/split-bill',
            // printing
            'printJobsForSale' => '/api/pos/print-jobs/{sale}',
            'printJobsRecent' => null,                         // no JSON "recent jobs" endpoint on the Cloud (page uses printJobsForSale)
            'kotQueue' => '/printing/jobs/kot/{sale}',
            'receiptQueue' => '/printing/jobs/receipt/{sale}',
            'reminderConfirm' => '/printing/jobs/reminder/{sale}/confirm',
            'reminderReprint' => '/printing/jobs/{job}/reminder-reprint',
            'printRetry' => '/printing/jobs/{job}/retry',
            'printDocument' => '/printing/documents/{job}/preview',
            // returns / reports
            'salesReturnCreatePage' => '/sales-returns/create',
            'salesReturnIndexPage' => '/sales-returns',
            'reportsCenter' => '/reports/center',
            // W-B additions — Cloud values (null = the Cloud has no separate endpoint; the Online page embeds the data)
            'terminals' => null,                               // terminals are rendered into the page
            'terminalSelect' => null,                          // Online selects the terminal per request (identity.terminal_selection)
            'syncSummary' => null,                             // Edge sync outbox summary; nothing to sync on the Cloud
            'shiftSummary' => null,                            // shift figures come from shift-status / the shift pages
            'shiftIndexPage' => '/shifts',
            'shiftShowPage' => '/shifts/{shift}',
            'shiftOpenStore' => '/shifts/open',
            'shiftCloseStore' => '/shifts/{shift}/close',
            'shiftCloseBranchPage' => '/shifts-close-branch',
            'salesReturnShowPage' => '/sales-returns/{salesReturn}',
            'salesReturnSearch' => '/ajax/sales',
            'salesReturnStore' => '/sales-returns',
            'splitBillStore' => '/sales-orders/{sale}/split-bill',
            'voidReasons' => null,                             // active void reasons are rendered into the page
            'printPreferences' => null,                        // Online auto-print toggles are per device (localStorage)
            'printMarkPrinted' => '/printing/jobs/{job}/mark-printed',
            'printDismiss' => '/printing/jobs/{job}/dismiss',
            'heldKot' => null,                                 // Online queues a held order's KOT through kotQueue
            // Phase 3 Stage B — Edge-only operator screens (the Edge menu); the Cloud sidebar carries its own menu.
            'supplierFinancePage' => null,
            'financeJournalPage' => null,
            'purchaseReturnsPage' => null,
        ]);

        return new PosRuntime(
            mode: PosRuntime::MODE_CLOUD,
            routes: $routes,
            capabilities: array_fill_keys(PosRuntime::CAPABILITY_KEYS, true),
            identity: [
                'branch_id' => $branchId,
                'branch_name' => $branchName,
                'branch_selectable' => true,
                'terminal_selection' => 'per_request',
            ],
            authority: [
                'state' => 'cloud',
                'label' => 'ONLINE',
                'sub_label' => 'CLOUD',
                'can_mutate' => true,
                'pending_sync' => 0,
                'tone' => 'ok',
            ],
            assets: [
                'base' => $this->assetBase('assets', $root),
                'storage' => $this->assetBase('storage', $root),
            ],
            transport: [
                'csrf_header' => 'X-CSRF-TOKEN',
                'body' => 'json',
                'unauthenticated_redirect' => $root . '/login',
            ],
            managerCredential: PosRuntime::CREDENTIAL_PIN,
            labels: [],
            chromeView: 'tenant.pos.partials.pos-chrome-cloud',
        );
    }

    /** '' on a domain-root deployment (every tenant today); '/sub' when the app is served from a sub-directory. */
    private function basePath(): string
    {
        $path = (string) (parse_url(url('/'), PHP_URL_PATH) ?? '');

        return rtrim($path, '/');
    }

    /** Root-relative '/assets' unless an ASSET_URL (CDN) is configured, in which case `asset()` decides — as today. */
    private function assetBase(string $dir, string $root): string
    {
        if (config('app.asset_url')) {
            return rtrim(asset($dir), '/');
        }

        return $root . '/' . $dir;
    }
}
