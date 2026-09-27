<?php

namespace Tests\Feature\Pos;

use App\Support\Pos\CloudPosRuntimeFactory;
use App\Support\Pos\PosPageData;
use App\Support\Pos\PosRuntime;
use Tests\TestCase;

/**
 * W-A — the runtime contract of the shared cashier view: the Cloud factory defines every route key and capability,
 * reproduces the literal Online endpoints the page hard-coded before the refactor, and the value object resolves
 * templates/assets exactly as the view and POS.route() expect.
 */
class PosRuntimeTest extends TestCase
{
    public function test_cloud_factory_defines_every_route_key_and_capability(): void
    {
        $rt = app(CloudPosRuntimeFactory::class)->make(7, 'Main');

        $this->assertSame(PosRuntime::MODE_CLOUD, $rt->mode);
        $this->assertFalse($rt->isEdge());
        $this->assertEqualsCanonicalizing(PosRuntime::ROUTE_KEYS, array_keys($rt->routes));
        $this->assertEqualsCanonicalizing(PosRuntime::CAPABILITY_KEYS, array_keys($rt->capabilities));
        foreach (PosRuntime::CAPABILITY_KEYS as $cap) {
            $this->assertTrue($rt->can($cap), "Online must keep every control enabled ({$cap})");
        }
        $this->assertSame(['branch_id' => 7, 'branch_name' => 'Main', 'branch_selectable' => true, 'terminal_selection' => 'per_request'], $rt->identity);
        $this->assertSame(['state' => 'cloud', 'label' => 'ONLINE', 'sub_label' => 'CLOUD', 'can_mutate' => true, 'pending_sync' => 0, 'tone' => 'ok'], $rt->authority);
        $this->assertSame(PosRuntime::CREDENTIAL_PIN, $rt->managerCredential);
        $this->assertSame('tenant.pos.partials.pos-chrome-cloud', $rt->chromeView);
        $this->assertSame(['csrf_header' => 'X-CSRF-TOKEN', 'body' => 'json', 'unauthenticated_redirect' => '/login'], $rt->transport);
    }

    /** Each key maps to the literal tenant path the Online page used (routes/tenant.php) — no endpoint moved. */
    public function test_cloud_routes_are_the_literal_online_paths(): void
    {
        $rt = app(CloudPosRuntimeFactory::class)->make();

        $expected = [
            'posIndex' => '/pos', 'saleStore' => '/pos', 'saleHeldSettle' => '/pos', 'serverTime' => '/api/server-time',
            'printingRetry' => '/pos/{sale}/printing/retry', 'customerSearch' => '/ajax/customers',
            'customerQuickStore' => '/pos/customers/quick-store', 'customerAddressStore' => '/pos/customers/{customer}/addresses',
            'quickReportSettings' => '/pos/quick-report/settings', 'quickReportSave' => '/pos/quick-report/save-settings',
            'quickReportPrint' => '/pos/quick-report/print', 'quickReportEmail' => '/pos/quick-report/email',
            'quickReportNetwork' => '/pos/quick-report/send-to-network', 'tableBoardHtml' => '/api/pos/table-board',
            'tableSessions' => '/api/pos/table-sessions', 'tableSessionOpenOrders' => '/api/pos/table-sessions/{session}/open-orders',
            'tableOpen' => '/restaurant/tables/{table}/open', 'tableBillRequested' => '/restaurant/table-sessions/{session}/bill-requested',
            'tableClose' => '/restaurant/table-sessions/{session}/close', 'tableBillPreview' => '/restaurant/table-sessions/{session}/bill-preview',
            'tableMove' => '/restaurant/table-sessions/{session}/move', 'reservation' => '/restaurant/tables/{table}/reservation',
            'reserve' => '/restaurant/tables/{table}/reserve', 'unreserve' => '/restaurant/tables/{table}/unreserve',
            'manageFloors' => '/restaurant/floors', 'manageTables' => '/restaurant/tables', 'shiftStatus' => '/api/pos/shift-status',
            'shiftOpenPage' => '/shifts/open', 'totalsQuote' => '/api/pos/totals/quote', 'promoQuote' => '/api/pos/promotions/quote',
            'billPreview' => '/api/pos/bill-preview', 'managerVerify' => '/api/manager-approvals/verify', 'heldList' => '/api/pos/held-sales',
            'heldStore' => '/held-sales', 'heldCancel' => '/held-sales/{sale}/cancel', 'heldReattach' => '/held-sales/{sale}/reattach-table',
            'recentSales' => '/api/pos/recent-sales', 'salesOrderShow' => '/sales-orders/{sale}', 'splitBillPage' => '/sales-orders/{sale}/split-bill',
            'printJobsForSale' => '/api/pos/print-jobs/{sale}', 'kotQueue' => '/printing/jobs/kot/{sale}', 'receiptQueue' => '/printing/jobs/receipt/{sale}',
            'reminderConfirm' => '/printing/jobs/reminder/{sale}/confirm', 'reminderReprint' => '/printing/jobs/{job}/reminder-reprint',
            'printRetry' => '/printing/jobs/{job}/retry', 'printDocument' => '/printing/documents/{job}/preview',
            'salesReturnCreatePage' => '/sales-returns/create', 'reportsCenter' => '/reports/center',
        ];
        foreach ($expected as $key => $path) {
            $this->assertSame($path, $rt->routes[$key], "route [{$key}]");
        }
        // Keys the Cloud does not expose as a separate endpoint are explicitly null (documented in the factory).
        foreach (['status', 'quickReportOptions', 'heldShow', 'printJobsRecent'] as $key) {
            $this->assertNull($rt->routes[$key], "route [{$key}] must be null on the Cloud");
            $this->assertNull($rt->route($key));
        }
    }

    /** Every non-null Cloud template is a real tenant route (method-agnostic URI match against routes/tenant.php). */
    public function test_every_cloud_template_matches_a_registered_tenant_route(): void
    {
        $uris = [];
        foreach (app('router')->getRoutes() as $route) {
            $uris['/' . ltrim($route->uri(), '/')] = true;
        }
        $rt = app(CloudPosRuntimeFactory::class)->make();
        foreach ($rt->routes as $key => $template) {
            if ($template === null) {
                continue;
            }
            // normalise the template placeholders to "{x}" and the registered uri placeholders likewise
            $norm = preg_replace('/\{[^}]+\}/', '{x}', $template);
            $found = false;
            foreach (array_keys($uris) as $uri) {
                if (preg_replace('/\{[^}]+\}/', '{x}', $uri) === $norm) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "route [{$key}] = {$template} is not a registered route");
        }
    }

    public function test_route_resolution_assets_and_serialisation(): void
    {
        $rt = app(CloudPosRuntimeFactory::class)->make();

        $this->assertSame('/printing/jobs/kot/42', $rt->route('kotQueue', ['sale' => 42]));
        $this->assertSame('/restaurant/tables/a%2Fb/open', $rt->route('tableOpen', ['table' => 'a/b']));
        $this->assertSame('/assets/css/style.css', $rt->asset('assets/css/style.css'));
        $this->assertSame('/storage/products/x.webp', $rt->asset('/storage/products/x.webp'));
        $this->assertSame('/favicon.ico', $rt->asset('favicon.ico'));
        $this->assertSame('Not available in this mode', $rt->capabilityHint('reports'));

        $json = json_decode(json_encode($rt), true);
        $this->assertSame(['mode', 'routes', 'capabilities', 'identity', 'authority', 'assets', 'transport', 'managerCredential', 'labels'], array_keys($json));
        $this->assertArrayNotHasKey('chromeView', $json, 'the chrome view is server-side only');
    }

    public function test_the_runtime_refuses_an_incomplete_route_map(): void
    {
        $rt = app(CloudPosRuntimeFactory::class)->make();
        $routes = $rt->routes;
        unset($routes['kotQueue']);

        $this->expectException(\InvalidArgumentException::class);
        new PosRuntime(PosRuntime::MODE_CLOUD, $routes, $rt->capabilities, $rt->identity, $rt->authority, $rt->assets, $rt->transport, $rt->managerCredential);
    }

    public function test_page_data_contract_is_closed(): void
    {
        $data = array_fill_keys(array_keys(PosPageData::KEYS), null);
        $page = PosPageData::fromArray($data);
        $view = $page->toViewData(app(CloudPosRuntimeFactory::class)->make());
        $this->assertArrayHasKey('posRuntime', $view);
        $this->assertCount(count(PosPageData::KEYS) + 1, $view);

        try {
            PosPageData::fromArray(array_slice($data, 1, null, true));
            $this->fail('a missing key must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('deadSession', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        PosPageData::fromArray($data + ['surprise' => 1]);
    }

    /** The controller hands the view exactly the PosPageData keys — the two lists cannot drift. */
    public function test_pos_controller_index_passes_exactly_the_page_data_keys(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Tenant/POSController.php'));
        $start = strpos($src, 'PosPageData::fromArray([');
        $end = strpos($src, '])->toViewData($posRuntime)', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $block = substr($src, $start, $end - $start);
        // top-level keys only: 12-space indentation inside the array
        preg_match_all("/^ {12}'([A-Za-z]+)'\s*=>/m", $block, $m);
        $this->assertEqualsCanonicalizing(array_keys(PosPageData::KEYS), $m[1]);
    }
}
