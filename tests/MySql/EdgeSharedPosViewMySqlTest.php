<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Support\Pos\PosPageData;
use App\Support\Pos\PosRuntime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W-B (next release, ONE shared cashier view) — the Branch Server renders THE Online page `tenant.pos.index` through the
 * shared `layouts.pos` with the Edge runtime adapter (EdgePosRuntimeFactory), and the SAME separate screens (shift open /
 * close / list / detail, sales-return create / list / detail, split bill) from the SAME tenant views.
 *
 * Proven over the REAL branch_server routes:
 *   - GET /edge/local/pos/shared = 200 `tenant.pos.index`, POS_RUNTIME.mode = edge, the closed W-A page-data contract;
 *   - the page carries NO Cloud path (/pos, /api/pos, /printing, /restaurant, /held-sales, /shifts, /sales-returns, …) —
 *     only /edge/local/… — and NO Internet asset (fonts.googleapis / http(s):// src|href);
 *   - every non-null POS_RUNTIME.routes value resolves to a registered, ALLOWLISTED edge.local.* route;
 *   - Online deep links (?held_sale_id / ?table_session_id / ?mode) behave as Online; tenant.pos.index gates the page;
 *   - each separate screen renders its tenant view through layouts.pos (never the Cloud header/sidebar), with ?embed=1,
 *     and its form posts land on the Edge routes with Online's outcome (redirect / top-window breakout).
 */
class EdgeSharedPosViewMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $tableId;
    private int $table2Id;
    private int $karahi;
    private int $naan;
    private int $cashMethodId;
    private int $cardMethodId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');

        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations',
            'sales_return_lines', 'sales_returns',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'printers', 'terminal_printer_settings',
            'manager_approvals', 'model_has_permissions', 'permissions', 'cash_count_lines',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'customer_addresses', 'customers', 'delivery_riders', 'delivery_channels',
            'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'combo_components', 'combos', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['name' => 'Shared View Branch', 'allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'manual_discount_approval_mode' => 'auto_approve']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'SHV' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter 1']);
        $floorId = (int) DB::connection('tenant')->table('restaurant_floors')->insertGetId(['branch_id' => $this->branchId, 'name' => 'Ground', 'sort_order' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available', 'restaurant_floor_id' => $floorId]);
        $this->table2Id = $this->makeTable($this->branchId, ['table_no' => 'T2', 'status' => 'available', 'restaurant_floor_id' => $floorId]);
        $this->makeWaiter($this->branchId, ['name' => 'Waiter Ali']);
        $categoryId = $this->makeCategory(['name' => 'Karahi']);
        $this->karahi = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->naan = $this->makeProduct($categoryId, ['name' => 'Roghni Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $comboId = (int) DB::connection('tenant')->table('combos')->insertGetId(['branch_id' => $this->branchId, 'code' => 'FAM', 'name' => 'Family Deal', 'price' => 220, 'sort_order' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('combo_components')->insert([
            ['combo_id' => $comboId, 'product_id' => $this->karahi, 'quantity' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['combo_id' => $comboId, 'product_id' => $this->naan, 'quantity' => 2, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash', 'name' => 'Cash']);
        $this->cardMethodId = $this->makePaymentMethod(['method_type' => 'card', 'name' => 'Card']);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->karahi, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->naan, 'product_variant_id' => null, 'quantity' => 50],
        ]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function openShift(): int
    {
        return (int) $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201)->json('shift_id');
    }

    /** Decode window.POS_RUNTIME from the rendered page. */
    private function runtimeFrom(string $html): array
    {
        $this->assertSame(1, preg_match('/window\.POS_RUNTIME = (\{.*?\});<\/script>/s', $html, $m), 'the page must inject window.POS_RUNTIME');
        $rt = json_decode($m[1], true);
        $this->assertIsArray($rt, 'POS_RUNTIME must be valid JSON');

        return $rt;
    }

    /** Cloud paths that must never appear on an Edge page (quoted / attribute / url( context, JSON-unescaped). */
    private function cloudPathsIn(string $html): array
    {
        $text = str_replace('\/', '/', $html);
        preg_match_all('#(?<=["\'=(])/(pos|api/pos|api|printing|restaurant|held-sales|shifts|shifts-close-branch|sales-returns|sales-orders|ajax|reports)(?=[/?"\'\s)]|$)[^"\'\s<>)]*#', $text, $m);

        return array_values(array_unique($m[0]));
    }

    /** The registered, allowlisted edge.local.* route (any verb) whose URI template matches $template. */
    private function allowlistedRouteFor(string $template): ?string
    {
        $path = ltrim((string) parse_url(preg_replace('/\{[^}]+\}/', 'X', $template), PHP_URL_PATH), '/');
        $want = preg_replace('#/X(?=/|$)#', '/{}', $path);
        $allow = (array) config('edge.route_allowlist');
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = preg_replace('/\{[^}]+\}/', '{}', $route->uri());
            $name = (string) $route->getName();
            if ($uri === $want && str_starts_with($name, 'edge.local.') && in_array($name, $allow, true)) {
                return $name;
            }
        }

        return null;
    }

    public function test_the_shared_page_renders_tenant_pos_index_with_the_edge_runtime_and_no_cloud_or_internet_url(): void
    {
        $this->openShift();
        $response = $this->get('/edge/local/pos/shared')->assertOk()->assertViewIs('tenant.pos.index');
        $html = $response->getContent();

        $rt = $this->runtimeFrom($html);
        $this->assertSame(PosRuntime::MODE_EDGE, $rt['mode']);
        $this->assertSame(PosRuntime::CREDENTIAL_EMPLOYEE, $rt['managerCredential']);
        $this->assertSame('/edge/local/assets', $rt['assets']['base']);
        $this->assertSame('/edge/local/storage', $rt['assets']['storage']);
        $this->assertSame('/edge/local/login', $rt['transport']['unauthenticated_redirect']);
        $this->assertSame($this->branchId, $rt['identity']['branch_id']);
        $this->assertFalse($rt['identity']['branch_selectable']);
        $this->assertSame('session', $rt['identity']['terminal_selection']);
        foreach (['state', 'label', 'sub_label', 'can_mutate', 'pending_sync', 'tone'] as $k) {
            $this->assertArrayHasKey($k, $rt['authority'], "authority.{$k}");
        }
        foreach (PosRuntime::ROUTE_KEYS as $k) {
            $this->assertArrayHasKey($k, $rt['routes'], "route key {$k}");
        }
        foreach (PosRuntime::CAPABILITY_KEYS as $k) {
            $this->assertArrayHasKey($k, $rt['capabilities'], "capability {$k}");
        }
        // §7 — capability off ⇒ route null.
        foreach (['reportsCenter' => 'reports', 'quickReportEmail' => 'quickReportEmail', 'manageFloors' => 'manageFloorsTables',
            'manageTables' => 'manageFloorsTables', 'customerQuickStore' => 'customerCreate', 'customerAddressStore' => 'customerAddressCreate',
            'salesOrderShow' => 'changeRider'] as $route => $capability) {
            $this->assertFalse($rt['capabilities'][$capability], "{$capability} is Cloud-only on a Branch Server");
            $this->assertNull($rt['routes'][$route], "{$route} must be null while {$capability} is off");
        }
        foreach (['splitBill', 'salesReturn', 'shiftPages', 'promotions', 'tips', 'tableMerge', 'reservations', 'deadSessionRecovery', 'printHere', 'quickReport', 'quickReportNetwork'] as $on) {
            $this->assertTrue($rt['capabilities'][$on], "{$on} is available on a Branch Server");
        }

        // Every non-null runtime route is a registered, ALLOWLISTED edge.local.* route (Edge paths only).
        $unresolved = [];
        foreach ($rt['routes'] as $key => $template) {
            if ($template === null) {
                continue;
            }
            $this->assertStringStartsWith('/edge/local/', $template, "route {$key} must be an Edge-local path");
            if ($this->allowlistedRouteFor($template) === null) {
                $unresolved[] = "{$key} => {$template}";
            }
        }
        $this->assertSame([], $unresolved, "runtime routes that do not resolve to an allowlisted edge.local.* route:\n" . implode("\n", $unresolved));

        // No Cloud path, no Internet asset.
        $this->assertSame([], $this->cloudPathsIn($html), 'the Edge page must carry no Cloud path — only /edge/local/…');
        $this->assertStringNotContainsString('fonts.googleapis', $html);
        $this->assertSame(0, preg_match_all('#(?:src|href|action)\s*=\s*["\']https?://#i', $html), 'no absolute http(s):// src/href/action');
        $this->assertSame(0, preg_match_all('#url\(\s*["\']?https?://#i', $html), 'no absolute http(s):// url() in inline CSS');
        preg_match_all('#<(?:link|script)[^>]+(?:href|src)="([^"]+)"#i', $html, $assets);
        foreach ($assets[1] as $url) {
            $this->assertStringStartsWith('/edge/local/assets/', $url, "asset {$url} must be served locally");
        }

        // The Edge chrome slot is present (hidden, no reflow); the Cloud header/sidebar never render on Edge.
        $this->assertStringContainsString('id="pos-edge-chrome"', $html);
        $this->assertStringContainsString('id="pos-runtime-slot"', $html);
        $this->assertDoesNotMatchRegularExpression('#<div class="header[\s"]#', $html, 'the Cloud header must not render on Edge');
        $this->assertDoesNotMatchRegularExpression('#<div class="sidebar[\s"]#', $html, 'the Cloud sidebar must not render on Edge');
    }

    public function test_the_page_receives_exactly_the_online_variable_set_from_the_local_database(): void
    {
        $this->openShift();
        $this->grantEdgePermission($this->userId, 'tenant.pos.quick-report-send');
        DB::connection('tenant')->table('edge_local_table_reservations')->insert([
            'reservation_uuid' => (string) Str::ulid(), 'branch_id' => $this->branchId, 'restaurant_table_id' => $this->table2Id, 'customer_name' => 'Mrs Ahmed',
            'customer_phone' => '0300-1', 'reserved_for' => now()->addHour(), 'note' => 'window seat', 'status' => 'active',
            'reserved_by_user_id' => $this->userId, 'reserved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->get('/edge/local/pos/shared')->assertOk();
        foreach (array_keys(PosPageData::KEYS) as $key) {
            $response->assertViewHas($key);
        }
        $response->assertViewHas('posRuntime', fn ($rt) => $rt instanceof PosRuntime && $rt->isEdge());
        $response->assertViewHas('selectedBranchId', $this->branchId);
        $response->assertViewHas('branches', fn ($b) => $b->count() === 1 && (int) $b->first()->id === $this->branchId);

        // Online productsPayload shape, priced + stocked from the Edge truth.
        $response->assertViewHas('productsPayload', function ($products) {
            $karahi = collect($products)->firstWhere('id', $this->karahi);
            foreach (['id', 'name', 'sku', 'image_url', 'category_id', 'category_name', 'unit_code', 'unit_type', 'allow_decimal_qty',
                'quantity_step', 'price', 'is_stock_tracked', 'pos_grid_visible', 'is_taxable', 'tax_rate_percent', 'barcodes',
                'branch_prices', 'modifier_groups', 'variants', 'stock_by_branch', 'is_recipe', 'makeable_by_branch'] as $k) {
                if (! array_key_exists($k, $karahi)) {
                    return false;
                }
            }

            return $karahi['price'] === 100.0 && $karahi['is_stock_tracked'] === true && (float) $karahi['stock_by_branch'][$this->branchId] === 50.0;
        });
        $response->assertViewHas('combosPayload', fn ($combos) => collect($combos)->firstWhere('name', 'Family Deal')['header_product_id'] === $this->karahi);
        // Every active tender is listed (cash first); non-cash rides flagged display-only.
        $response->assertViewHas('paymentMethods', fn ($m) => $m->count() === 2 && $m->first()->method_type === 'cash'
            && $m->firstWhere('id', $this->cardMethodId)->display_only === true && $m->first()->display_only === false);
        // The board: an ACTIVE Edge reservation is projected onto the table as Online's reserved_* attributes.
        $response->assertViewHas('floors', function ($floors) {
            $t2 = $floors->flatMap(fn ($f) => $f->tables)->firstWhere('id', $this->table2Id);

            return $t2->status === 'reserved' && $t2->reserved_name === 'Mrs Ahmed' && ! $t2->isDirty();
        });
        $response->assertViewHas('quickReportBranches', fn ($b) => $b->count() === 1);
        $this->assertStringContainsString('Mrs Ahmed', $response->getContent(), 'the shared table-board partial renders the reserved tile');
    }

    public function test_online_deep_links_and_the_page_permission(): void
    {
        $this->openShift();
        $sessionId = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(201)->json('session_id');
        $heldId = (int) $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->karahi, 'quantity' => 1]]])->assertStatus(201)->json('sale_id');

        $this->get("/edge/local/pos/shared?held_sale_id={$heldId}")->assertOk()
            ->assertViewHas('heldSale', fn ($s) => (int) $s?->id === $heldId)
            ->assertViewHas('tableSession', fn ($s) => (int) $s?->id === $sessionId)
            ->assertViewHas('activeMode', 'dine_in')
            ->assertViewHas('deadSession', null);
        $this->get("/edge/local/pos/shared?table_session_id={$sessionId}")->assertOk()->assertViewHas('activeMode', 'dine_in');
        $this->get('/edge/local/pos/shared?mode=takeaway')->assertOk()->assertViewHas('activeMode', 'takeaway')->assertViewHas('heldSale', null);

        // a held bill whose session died → the dead-session facts in Online's display format
        DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $sessionId)->update(['status' => 'closed', 'closed_at' => now()]);
        $this->get("/edge/local/pos/shared?held_sale_id={$heldId}")->assertOk()
            ->assertViewHas('deadSession', fn ($d) => is_array($d) && $d['sale_id'] === $heldId && $d['table_no'] === 'T1' && $d['can_reopen'] === true);

        $this->revokeEdgePermission($this->userId, 'tenant.pos.index');
        $this->get('/edge/local/pos/shared')->assertForbidden();
    }

    public function test_the_separate_screens_render_the_same_tenant_views_through_the_shared_layout(): void
    {
        $shiftId = $this->openShift();
        $sessionId = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(201)->json('session_id');
        $heldId = (int) $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->karahi, 'quantity' => 2], ['product_id' => $this->naan, 'quantity' => 2]]])->assertStatus(201)->json('sale_id');
        $paid = $this->postJson('/edge/local/pos/sales', ['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->naan, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 50, 'tendered_amount' => 50]]])->assertStatus(201)->json('sale_id');

        $pages = [
            "/edge/local/pos/shifts/open" => 'tenant.shifts.open',
            "/edge/local/pos/shifts/{$shiftId}/close" => 'tenant.shifts.close',
            '/edge/local/pos/shared/shifts' => 'tenant.shifts.index',
            "/edge/local/pos/shared/shifts/{$shiftId}" => 'tenant.shifts.show',
            '/edge/local/pos/sales-returns/create' => 'tenant.sales-returns.create',
            "/edge/local/pos/sales-returns/create?sales_order_id={$paid}" => 'tenant.sales-returns.create',
            '/edge/local/pos/shared/sales-returns' => 'tenant.sales-returns.index',
            "/edge/local/pos/held-sales/{$heldId}/split-bill" => 'tenant.sales-orders.split-bill',
        ];
        foreach ($pages as $url => $view) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $r = $this->get($url . $sep . 'embed=1');
            $this->assertSame(200, $r->getStatusCode(), "{$url} → " . $r->getStatusCode() . ' ' . Str::limit(strip_tags((string) $r->getContent()), 300));
            $r->assertViewIs($view)->assertViewHas('posRuntime', fn ($rt) => $rt instanceof PosRuntime && $rt->isEdge());
            $html = (string) $r->getContent();
            $this->assertStringContainsString('embedded-workspace', $html, "{$url}: ?embed=1 renders chrome-less like Online");
            $this->assertStringContainsString('window.POS_RUNTIME', $html, "{$url}: rendered through layouts.pos");
            $this->assertDoesNotMatchRegularExpression('#<div class="header[\s"]#', $html, "{$url}: no Cloud header");
            $this->assertDoesNotMatchRegularExpression('#<div class="sidebar[\s"]#', $html, "{$url}: no Cloud sidebar");
            preg_match_all('#<(?:link|script)[^>]+(?:href|src)="([^"]+)"#i', $html, $assets);
            foreach ($assets[1] as $asset) {
                $this->assertStringStartsWith('/edge/local/assets/', $asset, "{$url}: asset {$asset} must be local");
            }
        }
        // The chosen sale's return grid is the Online one (the sale + its line).
        $this->assertStringContainsString('Roghni Naan', $this->get("/edge/local/pos/sales-returns/create?sales_order_id={$paid}")->getContent());
        // The split page lists the check's lines.
        $this->assertStringContainsString('Chicken Karahi', $this->get("/edge/local/pos/held-sales/{$heldId}/split-bill")->getContent());

        // Permission gates (Online route permission of each screen).
        $this->revokeEdgePermission($this->userId, 'tenant.sales-returns.create');
        $this->get('/edge/local/pos/sales-returns/create')->assertForbidden();
        $this->revokeEdgePermission($this->userId, 'tenant.shifts.index');
        $this->get('/edge/local/pos/shared/shifts')->assertForbidden();
    }

    public function test_the_separate_screen_forms_post_to_the_edge_routes_with_the_online_outcome(): void
    {
        // Shift open page → Online store semantics: opens the terminal's shift, redirects to the shift list with the flash.
        $this->post('/edge/local/pos/shifts/open', ['branch_id' => $this->branchId, 'terminal_ids' => [$this->terminalId], 'opening_cash' => 500, 'opening_notes' => 'float'])
            ->assertRedirect('/edge/local/pos/shared/shifts')->assertSessionHas('status');
        $shiftId = (int) DB::connection('tenant')->table('shifts')->where('terminal_id', $this->terminalId)->where('status', 'open')->value('id');
        $this->assertGreaterThan(0, $shiftId);
        $this->assertEquals(500.0, (float) DB::connection('tenant')->table('shifts')->where('id', $shiftId)->value('opening_cash'));
        // Re-opening the same terminal is skipped → back with the error (never a second open shift).
        $this->from('/edge/local/pos/shifts/open')->post('/edge/local/pos/shifts/open', ['terminal_ids' => [$this->terminalId], 'opening_cash' => 0])
            ->assertRedirect('/edge/local/pos/shifts/open')->assertSessionHasErrors('terminal_ids');
        // Another branch is refused — by the edge.branch binding itself (a request branch id can never override it).
        $this->from('/edge/local/pos/shifts/open')->post('/edge/local/pos/shifts/open', ['branch_id' => $this->branchId + 999, 'terminal_ids' => [$this->terminalId], 'opening_cash' => 0])
            ->assertForbidden();

        // Split page → EdgeLocalPosService::splitHeldSale, then Online's top-window breakout to the shared POS on the table.
        $sessionId = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(201)->json('session_id');
        $held = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->karahi, 'quantity' => 2]]])->assertStatus(201);
        $heldId = (int) $held->json('sale_id');
        $lineId = (int) $held->json('lines.0.id');
        $split = $this->post("/edge/local/pos/held-sales/{$heldId}/split-bill", ['lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1]]])->assertOk();
        $this->assertStringContainsString('window.top.location.href="/edge/local/pos/shared?held_sale_id=' . $heldId, $split->getContent());
        $this->assertSame(2, DB::connection('tenant')->table('sales_orders')->where('restaurant_table_session_id', $sessionId)->where('status', 'held')->count());

        // Pay both checks so the shift can close, then the close page → the SHARED close, redirect to the shift detail.
        foreach (DB::connection('tenant')->table('sales_orders')->where('restaurant_table_session_id', $sessionId)->where('status', 'held')->get() as $check) {
            $this->postJson("/edge/local/pos/held-sales/{$check->id}/settle", ['client_uuid' => (string) Str::uuid(),
                'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => (float) $check->grand_total, 'tendered_amount' => (float) $check->grand_total]]])->assertOk();
        }
        $paid = (int) DB::connection('tenant')->table('sales_orders')->where('restaurant_table_session_id', $sessionId)->where('status', 'paid')->value('id');

        // Returns create page form → EdgeLocalReturnService::processReturn → the return's detail page.
        $line = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $paid)->first();
        $this->post('/edge/local/pos/sales-returns', ['sales_order_id' => $paid, 'refund_method' => 'cash', 'reason' => 'cold',
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => 1]]])
            ->assertRedirect()->assertSessionHas('status', 'Sales return posted.');
        $returnId = (int) DB::connection('tenant')->table('sales_returns')->where('sales_order_id', $paid)->value('id');
        $this->assertGreaterThan(0, $returnId);
        $this->get("/edge/local/pos/shared/sales-returns/{$returnId}?embed=1")->assertOk()->assertViewIs('tenant.sales-returns.show');
        // Nothing selected → back with Online's message.
        $this->from('/edge/local/pos/sales-returns/create')->post('/edge/local/pos/sales-returns', ['sales_order_id' => $paid, 'refund_method' => 'cash',
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => 0]]])->assertSessionHasErrors('return');

        $expected = (float) DB::connection('tenant')->table('shifts')->where('id', $shiftId)->value('expected_cash');
        $this->post("/edge/local/pos/shifts/{$shiftId}/close", ['counted_cash' => $expected, 'closing_notes' => 'ok'])
            ->assertRedirect("/edge/local/pos/shared/shifts/{$shiftId}")->assertSessionHas('status');
        $this->assertSame('closed', DB::connection('tenant')->table('shifts')->where('id', $shiftId)->value('status'));
        $this->get("/edge/local/pos/shifts/{$shiftId}/close")->assertNotFound(); // a closed shift has no close page (Online abort 404)
    }
}
