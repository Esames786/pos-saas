<?php

namespace Tests\Feature\Pos;

use App\Support\Pos\CloudPosRuntimeFactory;
use App\Support\Pos\PosPageData;
use App\Support\Pos\PosRuntime;
use App\Support\TenantClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * W-A — the shared cashier view `tenant.pos.index` renders through `layouts.pos` from a PosRuntime + PosPageData alone:
 * no `url('/…')` endpoint literal is left, every POS.route()/POS.api()/$posRuntime->route() key is a contract key,
 * window.POS_RUNTIME carries every route key, the layout loads the same theme files in the same order as layouts.app,
 * the A5 status slot renders, capability-off controls stay in place (disabled), and every inline script parses.
 */
class SharedPosViewRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The view reads the tenant connection once (active void reasons). Point it at an in-memory SQLite.
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('tenant');
        Schema::connection('tenant')->create('void_reasons', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('reason_type')->nullable();
            $t->boolean('requires_manager_approval')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        $clock = \Mockery::mock(TenantClock::class);
        $clock->shouldReceive('now')->andReturn(Carbon::parse('2026-09-27 12:00:00'));
        $clock->shouldReceive('currentBusinessDate')->andReturn('2026-09-27');
        $this->app->instance(TenantClock::class, $clock);

        view()->share('errors', new ViewErrorBag());
    }

    private function pageData(): PosPageData
    {
        $branch = (object) [
            'id' => 1, 'name' => 'Main', 'allow_negative_stock' => 0, 'default_delivery_charge' => 0, 'delivery_charge_locked' => 0,
            'held_kot_cancellation_approval_mode' => 'manager_required', 'held_kot_line_cancellation_approval_mode' => null,
            'manual_discount_approval_mode' => 'manager_required',
        ];

        return PosPageData::fromArray([
            'deadSession' => null,
            'branches' => collect([$branch]),
            'selectedBranchId' => 1,
            'terminals' => collect([(object) ['id' => 3, 'name' => 'Till 1', 'branch_id' => 1, 'branch' => (object) ['name' => 'Main']]]),
            'categories' => collect(),
            'pillCategoryIds' => [],
            'contentCategoryIds' => [],
            'hasUncategorizedCombos' => false,
            'productsPayload' => [],
            'combosPayload' => collect(),
            'paymentMethods' => collect([
                (object) ['id' => 1, 'name' => 'Cash', 'method_type' => 'cash'],
                (object) ['id' => 2, 'name' => 'Card', 'method_type' => 'card'],
            ]),
            'floors' => collect(),
            'waiters' => collect([(object) ['id' => 9, 'name' => 'Ali']]),
            'quickReportBranches' => collect([(object) ['id' => 1, 'name' => 'Main']]),
            'quickReportPrinters' => collect(),
            'deliveryChannels' => collect(),
            'deliveryRiders' => collect(),
            'allowedOrderTypes' => ['dine_in', 'takeaway', 'quick_sale', 'delivery'],
            'tableSession' => null,
            'heldSale' => null,
            'receiptLayouts' => collect(),
            'terminalPrintConfig' => collect(),
            'activeMode' => 'takeaway',
        ]);
    }

    /** The Cloud runtime without its chrome view (the Cloud header/sidebar need a live tenant + user, outside this test). */
    private function runtime(array $overrides = []): PosRuntime
    {
        $c = app(CloudPosRuntimeFactory::class)->make(1, 'Main');
        $a = array_merge([
            'mode' => $c->mode, 'routes' => $c->routes, 'capabilities' => $c->capabilities, 'identity' => $c->identity,
            'authority' => $c->authority, 'assets' => $c->assets, 'transport' => $c->transport,
            'managerCredential' => $c->managerCredential, 'labels' => $c->labels, 'chromeView' => null,
        ], $overrides);

        return new PosRuntime(...$a);
    }

    private function render(PosRuntime $rt, bool $allPermissions = true): string
    {
        if ($allPermissions) {
            Gate::before(fn ($user = null) => true);
        }

        return view('tenant.pos.index', $this->pageData()->toViewData($rt))->render();
    }

    public function test_the_page_extends_the_shared_layout_and_keeps_no_url_literal(): void
    {
        $src = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));
        $this->assertStringStartsWith("@extends('layouts.pos')", $src);
        $this->assertStringNotContainsString('url(', $src, 'every endpoint must come from the runtime map');
        $this->assertStringNotContainsString('asset(', $src, 'every asset must come from $posRuntime->asset()');
        $board = file_get_contents(resource_path('views/tenant/pos/partials/table-board.blade.php'));
        $this->assertStringNotContainsString('url(', $board);
    }

    public function test_every_route_key_the_view_asks_for_is_a_contract_key(): void
    {
        $src = file_get_contents(resource_path('views/tenant/pos/index.blade.php'))
            . file_get_contents(resource_path('views/tenant/pos/partials/table-board.blade.php'))
            . file_get_contents(resource_path('views/tenant/pos/partials/pos-clock.blade.php'));
        preg_match_all("/POS\.(?:route|api)\('([A-Za-z]+)'/", $src, $js);
        preg_match_all("/->route\('([A-Za-z]+)'/", $src, $blade);
        $keys = array_unique(array_merge($js[1], $blade[1]));
        $this->assertGreaterThan(40, count($keys), 'the page should resolve ~50 distinct endpoints through the map');
        foreach ($keys as $key) {
            $this->assertContains($key, PosRuntime::ROUTE_KEYS, "unknown route key [{$key}] used by the view");
        }
        preg_match_all("/(?:POS\.can|->can)\('([A-Za-z]+)'\)/", $src, $caps);
        foreach (array_unique($caps[1]) as $cap) {
            $this->assertContains($cap, PosRuntime::CAPABILITY_KEYS, "unknown capability [{$cap}] used by the view");
        }
    }

    public function test_the_cloud_page_renders_through_layouts_pos_with_the_runtime_map(): void
    {
        $html = $this->render($this->runtime());

        // window.POS_RUNTIME carries EVERY route key
        $this->assertSame(1, preg_match('/window\.POS_RUNTIME = (\{.*?\});<\/script>/s', $html, $m));
        $runtime = json_decode($m[1], true);
        $this->assertIsArray($runtime);
        $this->assertEqualsCanonicalizing(PosRuntime::ROUTE_KEYS, array_keys($runtime['routes']));
        $this->assertSame('cloud', $runtime['mode']);
        $this->assertLessThan(strpos($html, 'const products'), strpos($html, 'window.POS = {'), 'POS must exist before the page scripts');

        // layout chrome rules, CSRF meta, the shared overlay, the status slot
        $this->assertStringContainsString('<meta name="csrf-token"', $html);
        $this->assertMatchesRegularExpression('/<body class="pos-workspace nosidebar">/', $html);
        $this->assertStringContainsString('body.pos-workspace .page-wrapper { margin: 0; padding-top: 0; }', $html);
        $this->assertStringContainsString('id="pos-runtime-overlay"', $html);
        $this->assertMatchesRegularExpression('/id="pos-runtime-state">ONLINE<\/span>/', $html);
        $this->assertStringContainsString('· CLOUD', $html);
        $this->assertMatchesRegularExpression('/class="badge bg-warning text-dark d-none" id="pos-runtime-pending"/', $html);

        // endpoints are the runtime's root-relative paths — no absolute url() output for an endpoint
        $appUrl = rtrim((string) config('app.url'), '/');
        foreach (['/pos', '/api/', '/printing/', '/restaurant/', '/held-sales', '/ajax/', '/sales-orders', '/shifts', '/reports/'] as $p) {
            $this->assertStringNotContainsString($appUrl . $p, $html, "absolute url() endpoint {$p} left in the page");
        }
        $this->assertStringContainsString('action="/pos"', $html);
        $this->assertStringContainsString('data-board-url="/api/pos/table-board"', $html);
        $this->assertStringContainsString('data-report-url="/reports/center?date_from=2026-09-27', $html);
        $this->assertStringContainsString('href="/shifts/open" id="pos-shift-open-link"', $html);

        // Online: every capability on → nothing Cloud-only is disabled
        foreach (['pos-report-btn', 'pos-quick-report-btn', 'qr-email', 'qr-network', 'pos-return-btn'] as $id) {
            $this->assertDoesNotMatchRegularExpression('/id="' . $id . '"[^>]*\sdisabled/s', $html, "{$id} must be enabled Online");
        }
        $this->assertDoesNotMatchRegularExpression('/<select id="branch_id"[^>]*\sdisabled/s', $html);
        $this->assertStringNotContainsString('<input type="hidden" name="branch_id"', $html);
    }

    public function test_the_layout_loads_the_same_theme_files_in_the_same_order_as_layouts_app(): void
    {
        $app = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $pos = file_get_contents(resource_path('views/layouts/pos.blade.php'));
        preg_match_all("/\basset\('(assets\/[^']+)'\)/", $app, $a);
        preg_match_all("/\\\$posRuntime->asset\('(assets\/[^']+)'\)/", $pos, $b);
        // A3 (W-C): the self-hosted font sheet sits immediately before style.css; layouts.app gets the same link from its
        // owner. Compare the theme list without it, and pin its position in the POS layout.
        $font = 'assets/css/fonts-local.css';
        $appList = array_values(array_diff($a[1], [$font]));
        $posList = array_values(array_diff($b[1], [$font]));
        $this->assertCount(22, $appList);
        $this->assertSame($appList, $posList);
        $i = array_search($font, $b[1], true);
        $this->assertNotFalse($i, 'layouts.pos must link the self-hosted font sheet');
        $this->assertSame('assets/css/style.css', $b[1][$i + 1]);
        $this->assertStringNotContainsString('asset(\'', str_replace('$posRuntime->asset(\'', '', $pos));
    }

    public function test_capability_off_controls_render_disabled_in_place_and_edge_credential_prompt(): void
    {
        $caps = array_fill_keys(PosRuntime::CAPABILITY_KEYS, true);
        foreach (['reports', 'quickReportEmail', 'manageFloorsTables', 'branchSelect', 'nonCashTender', 'tipOnPaidSale'] as $off) {
            $caps[$off] = false;
        }
        $html = $this->render($this->runtime([
            'mode' => PosRuntime::MODE_EDGE,
            'capabilities' => $caps,
            'authority' => ['state' => 'standby', 'label' => 'STANDBY', 'sub_label' => 'CLOUD AUTHORITY', 'can_mutate' => false, 'pending_sync' => 3, 'tone' => 'warn'],
            'managerCredential' => PosRuntime::CREDENTIAL_EMPLOYEE,
            'labels' => ['capability.reports' => 'Reports run on the Online POS'],
        ]));

        $this->assertMatchesRegularExpression('/id="pos-report-btn".*?\sdisabled\s.*?title="Reports run on the Online POS"/s', $html);
        $this->assertMatchesRegularExpression('/id="qr-email"\s+disabled/s', $html);
        $this->assertDoesNotMatchRegularExpression('/id="qr-network"\s+disabled/s', $html);
        $this->assertMatchesRegularExpression('/<select id="branch_id"[^>]*\sdisabled/s', $html);
        $this->assertStringContainsString('<input type="hidden" name="branch_id" value="1">', $html);
        $this->assertMatchesRegularExpression('/data-type="card"\s[^>]*disabled>/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-type="cash"\s[^>]*disabled>/', $html);
        $this->assertSame(4, preg_match_all('/tip-btn" disabled/', $html));
        $this->assertMatchesRegularExpression('/id="pos-runtime-state">STANDBY<\/span>/', $html);
        $this->assertStringContainsString('· CLOUD AUTHORITY', $html);
        $this->assertMatchesRegularExpression('/class="badge bg-warning text-dark " id="pos-runtime-pending">3 pending sync/', $html);
        $this->assertStringContainsString('"managerCredential":"employee_code_and_credential"', $html);
    }

    /**
     * layouts.pos also hosts the SECONDARY shared screens on Edge (shift, returns, split bill — Team B): a plain
     * @section('content') page with its own @push('styles'/'scripts') renders, and ?embed=1 behaves as in layouts.app.
     */
    public function test_a_secondary_page_renders_through_the_shared_layout_including_embed_mode(): void
    {
        $page = <<<'BLADE'
@extends('layouts.pos')
@section('title', 'Open Shift')
@push('styles')<style>.secondary-probe{color:red}</style>@endpush
@section('content')<div id="secondary-probe">Shift page</div>@endsection
@push('scripts')<script>window.__secondaryProbe = 1;</script>@endpush
BLADE;
        $html = \Illuminate\Support\Facades\Blade::render($page, ['posRuntime' => $this->runtime()]);
        $this->assertStringContainsString('<title>' . config('app.name') . ' - Open Shift</title>', $html);
        $this->assertStringContainsString('<div id="secondary-probe">Shift page</div>', $html);
        $this->assertStringContainsString('.secondary-probe{color:red}', $html);
        $this->assertLessThan(strpos($html, 'window.__secondaryProbe'), strpos($html, 'sweetalert2.all.min.js'), 'pushed scripts run after the theme scripts');
        $this->assertStringContainsString('<body class="pos-workspace nosidebar">', $html);
        $this->assertStringNotContainsString('id="pos-runtime-slot"', $html, 'the status slot belongs to the POS page, not the layout');

        $this->app['request']->query->set('embed', '1');
        $embedded = \Illuminate\Support\Facades\Blade::render($page, ['posRuntime' => $this->runtime()]);
        $this->assertStringContainsString('<body class="pos-workspace nosidebar embedded-workspace">', $embedded);
        $this->assertStringContainsString("document.body.classList.add('embedded-workspace')", $embedded);
    }

    /** `php -l` proves the Blade; the browser runs the JS — every inline script of the rendered page must parse. */
    public function test_every_inline_script_of_the_rendered_page_parses_as_javascript(): void
    {
        $candidates = glob('D:/laragon2/bin/nodejs/*/node.exe') ?: [];
        rsort($candidates);
        $node = getenv('EDGE_NODE_BIN') ?: ($candidates ? reset($candidates) : ((new \Symfony\Component\Process\ExecutableFinder())->find('node') ?: null));
        if (! $node) {
            $this->markTestSkipped('no Node runtime available');
        }

        $html = $this->render($this->runtime());
        preg_match_all('/<script>(.*?)<\/script>/s', $html, $m);
        $this->assertGreaterThan(8, count($m[1]));
        foreach ($m[1] as $i => $js) {
            $tmp = tempnam(sys_get_temp_dir(), 'pos_js_') . '.js';
            file_put_contents($tmp, $js);
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            @unlink($tmp);
            $this->assertSame(0, $code, "inline script #{$i} does not parse:\n" . implode("\n", $out) . "\n" . substr($js, 0, 300));
        }
    }
}
