<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * MOVE-TABLE-CART-1 — a car's quick sale ended up on table 32.
 *
 * 22 Oct 2026, Kashif Food, one counter, one browser tab:
 *
 *     22:17   table 30 opened, its bill held
 *     22:28   quick sale for car "h1" held. The POS keeps a held bill loaded in the cart.
 *     22:29   table board -> Move table 30 to 32. The move handler stepped into table 32 WITHOUT
 *             clearing the cart (every other way into a table clears it first).
 *     22:29   cashier added Plain Rice and pressed Hold -> POST /held-sales carried the quick sale's
 *             id + table 32's session -> the server re-homed the car's bill onto table 32.
 *
 * Two fixes, two kinds of guard:
 *   - server: /held-sales refuses to save a held bill onto a table session that is not its own.
 *     That is the real safety net and these tests run it over HTTP;
 *   - POS: Move clears a cart that is not the moved table's. That code runs in the browser, so the
 *     guard here can only prove the page carries it and every inline script still parses.
 */
class MoveTableCartMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const ROUTES = ['tenant.pos.index', 'tenant.held-sales.store', 'tenant.restaurant.table-sessions.move'];

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $branchId;
    private int $terminalId;
    private int $table30;
    private int $table32;
    private int $productId;
    private int $waiterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'movecart.' . config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedSubscription();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'movecart')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /** The 22 Oct case, step by step through the real routes. */
    public function test_after_a_move_a_held_quick_sale_cannot_be_saved_onto_the_table(): void
    {
        $session = $this->openSession($this->table30);
        $tableBill = $this->holdOk(['restaurant_table_session_id' => $session]);
        $car = $this->holdOk(['order_type' => 'quick_sale', 'vehicle_number' => 'h1', 'restaurant_waiter_id' => $this->waiterId]);

        $move = $this->owner()->postJson($this->url("/restaurant/table-sessions/{$session}/move"), ['target_table_id' => $this->table32]);
        $this->assertSame(200, $move->getStatusCode(), 'the move itself is legitimate and must work — ' . $this->why($move));
        $this->assertSame($this->table32, (int) $this->order($tableBill)->restaurant_table_id, 'the table\'s own bill follows the move');

        $carBefore = $this->snapshot($car);

        // The stale cart: the car's bill id, table 32's session, the extra rice.
        $res = $this->hold([
            'held_sale_id' => $car,
            'restaurant_table_session_id' => $session,
            'lines' => [$this->line(), $this->line()],
        ]);

        $this->assertSame(422, $res->getStatusCode(), 'a quick sale must not be saved onto a table — ' . $this->why($res));
        $message = (string) $res->json('message');
        $this->assertStringContainsString($this->order($car)->sale_no, $message, 'the cashier must see WHICH bill was refused');
        $this->assertStringContainsString('Quick Sale', $message);
        $this->assertStringContainsString('Table 32', $message);
        $this->assertStringContainsString('Add Round', $message, 'the message names the button that fixes it');

        $this->assertSame($carBefore, $this->snapshot($car), 'the car\'s bill must be exactly as it was: type, vehicle, table, lines');
        $this->assertSame([$tableBill], $this->billsOn($session), 'table 32 keeps only its own bill');
    }

    /** Same hole from the other side: table 30's bill saved with table 32's session. */
    public function test_another_tables_bill_cannot_be_saved_onto_this_table(): void
    {
        $s30 = $this->openSession($this->table30);
        $s32 = $this->openSession($this->table32);
        $bill30 = $this->holdOk(['restaurant_table_session_id' => $s30]);
        $bill32 = $this->holdOk(['restaurant_table_session_id' => $s32]);

        $res = $this->hold(['held_sale_id' => $bill30, 'restaurant_table_session_id' => $s32]);

        $this->assertSame(422, $res->getStatusCode(), $this->why($res));
        $this->assertStringContainsString('belongs to Table 30', (string) $res->json('message'));
        $this->assertSame([$bill30], $this->billsOn($s30));
        $this->assertSame([$bill32], $this->billsOn($s32));
    }

    /** Control: the guard must not stop the everyday Add Round on the bill's own table. */
    public function test_the_tables_own_bill_still_saves(): void
    {
        $session = $this->openSession($this->table30);
        $bill = $this->holdOk(['restaurant_table_session_id' => $session]);

        $res = $this->hold(['held_sale_id' => $bill, 'restaurant_table_session_id' => $session, 'lines' => [$this->line(), $this->line()]]);

        $this->assertSame(200, $res->getStatusCode(), $this->why($res));
        $this->assertSame(2, DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $bill)->count());
    }

    /** Control: a move keeps the session id, so the moved table's own bill keeps working. */
    public function test_after_a_move_the_tables_own_bill_still_saves(): void
    {
        $session = $this->openSession($this->table30);
        $bill = $this->holdOk(['restaurant_table_session_id' => $session]);
        $this->owner()->postJson($this->url("/restaurant/table-sessions/{$session}/move"), ['target_table_id' => $this->table32])->assertOk();

        $res = $this->hold(['held_sale_id' => $bill, 'restaurant_table_session_id' => $session, 'lines' => [$this->line(), $this->line()]]);

        $this->assertSame(200, $res->getStatusCode(), $this->why($res));
        $this->assertSame($this->table32, (int) $this->order($bill)->restaurant_table_id);
    }

    /** Control: a held quick sale still saves as a quick sale (no table involved). */
    public function test_a_held_quick_sale_still_saves_on_its_own(): void
    {
        $car = $this->holdOk(['order_type' => 'quick_sale', 'vehicle_number' => 'h1', 'restaurant_waiter_id' => $this->waiterId]);

        $res = $this->hold(['held_sale_id' => $car, 'order_type' => 'quick_sale', 'vehicle_number' => 'h1',
            'restaurant_waiter_id' => $this->waiterId, 'lines' => [$this->line(), $this->line()]]);

        $this->assertSame(200, $res->getStatusCode(), $this->why($res));
        $this->assertSame('quick_sale', $this->order($car)->order_type);
    }

    /**
     * The POS half. It runs in the browser, so this cannot click it. It proves the rendered page
     * routes Move through the clearing step, that step clears before it steps in, and the page parses.
     */
    public function test_the_pos_move_button_clears_a_cart_that_is_not_the_moved_tables(): void
    {
        $res = $this->owner()->get($this->url('/pos'));
        $this->assertSame(200, $res->getStatusCode(), $this->why($res));
        $html = $res->getContent();

        $this->assertMatchesRegularExpression(
            '/function enterMovedTableSession\(session\) \{.*?String\(current\) !== String\(session\.id\)\) clearCart\(\);\s*applyTableSession\(session\);/s',
            $html, 'Move must clear a cart that belongs elsewhere, BEFORE stepping into the moved table'
        );
        $this->assertMatchesRegularExpression(
            "#/move', \{ target_table_id: button\.dataset\.moveTarget \}, function \(data\) \{ enterMovedTableSession\(data\.session\);#",
            $html, 'the Move button must go through enterMovedTableSession, not straight to applyTableSession'
        );
        $this->assertScriptsParse($html);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function owner()
    {
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant');
    }

    private function url(string $path): string
    {
        return 'http://' . $this->host . $path;
    }

    private function line(): array
    {
        return ['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 170];
    }

    private function hold(array $extra = [])
    {
        return $this->owner()->postJson($this->url('/held-sales'), array_merge([
            'branch_id' => $this->branchId,
            'terminal_id' => $this->terminalId,
            'order_type' => 'dine_in',
            'discount_type' => 'none',
            'lines' => [$this->line()],
        ], $extra));
    }

    private function holdOk(array $extra): int
    {
        $res = $this->hold($extra);
        $this->assertSame(200, $res->getStatusCode(), 'setup hold failed — ' . $this->why($res));

        return (int) $res->json('sale_id');
    }

    private function openSession(int $tableId): int
    {
        $c = DB::connection('tenant');
        $c->table('restaurant_tables')->where('id', $tableId)->update(['status' => 'occupied']);

        return $c->table('restaurant_table_sessions')->insertGetId([
            'session_no' => 'TS-' . Str::upper(Str::random(10)), 'branch_id' => $this->branchId,
            'restaurant_table_id' => $tableId, 'status' => 'open', 'guest_count' => 1,
            'opened_by_user_id' => $this->ownerId, 'opened_at' => now(), 'business_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(int $id): object
    {
        return DB::connection('tenant')->table('sales_orders')->where('id', $id)->first();
    }

    private function billsOn(int $sessionId): array
    {
        return DB::connection('tenant')->table('sales_orders')->where('restaurant_table_session_id', $sessionId)
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function snapshot(int $id): array
    {
        $o = $this->order($id);

        return [
            'order_type' => $o->order_type, 'vehicle' => $o->vehicle_number, 'session' => $o->restaurant_table_session_id,
            'table' => $o->restaurant_table_id, 'total' => (string) $o->grand_total, 'updated_at' => (string) $o->updated_at,
            'lines' => DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $id)->orderBy('id')
                ->get(['id', 'product_id', 'quantity'])->map(fn ($l) => (array) $l)->all(),
        ];
    }

    private function why($res): string
    {
        $json = json_decode($res->getContent(), true);
        if (is_array($json) && isset($json['message'])) {
            return $res->getStatusCode() . ' ' . $json['message'];
        }

        return $res->getStatusCode() . ' ' . Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($res->getContent()))), 300);
    }

    /** Every inline script on the page must parse — a broken one leaves the counter dead with no error. */
    private function assertScriptsParse(string $html): void
    {
        $node = getenv('NODE_BINARY') ?: 'D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe';
        if (! is_file($node)) {
            $this->addToAssertionCount(1);

            return;
        }
        preg_match_all('#<script(?![^>]*\bsrc=)([^>]*)>(.*?)</script>#si', $html, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as $i => $s) {
            if (preg_match('/type\s*=\s*["\'](?!text\/javascript|module)[^"\']+/i', $s[1])) {
                continue;
            }
            $file = tempnam(sys_get_temp_dir(), 'pos') . '.js';
            file_put_contents($file, $s[2]);
            exec('"' . $node . '" --check "' . $file . '" 2>&1', $out, $code);
            @unlink($file);
            $this->assertSame(0, $code, "inline script #{$i} does not parse:\n" . implode("\n", array_slice($out, 0, 8)));
            $out = [];
        }
    }

    // ── seed ────────────────────────────────────────────────────────────────

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenants')->where('tenant_code', 'movecart')->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'movecart', 'business_name' => 'Move Cart', 'owner_name' => 'Owner',
            'owner_email' => 'owner@movecart.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'), 'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb, 'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');
        $planId = $m->table('plans')->where('code', 'movecart-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'movecart-plan', 'name' => 'Move Cart', 'price' => 0, 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        // Every route this test calls must have its module on — otherwise the gate 403s first and the
        // guard proves nothing. Unmapped route = fail-open; never create modules in the shared master DB.
        foreach (self::ROUTES as $routeName) {
            $key = $m->table('route_catalogs')->where('route_name', $routeName)->value('module_key');
            $module = $key ? Module::forRouteModuleKey($key)->first() : null;
            if ($module) {
                $m->table('plan_modules')->updateOrInsert(['plan_id' => $planId, 'module_id' => $module->id], ['is_enabled' => 1]);
            }
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenant(): void
    {
        // permissions/roles come from a tenant MIGRATION — never truncate them.
        $this->cleanTenant([
            'sales_order_lines', 'sales_orders', 'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors',
            'restaurant_waiters', 'shifts', 'model_has_roles', 'users', 'products', 'categories', 'terminals', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        foreach (self::ROUTES as $routeName) {
            $c->table('permissions')->updateOrInsert(['name' => $routeName, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'MoveOwner', 'email' => 'owner@movecart.test', 'password' => bcrypt('x'),
            'employee_code' => 'MVOWN', 'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId]);

        $this->branchId = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->table30 = $this->makeTable($this->branchId, ['table_no' => '30', 'status' => 'available']);
        $this->table32 = $this->makeTable($this->branchId, ['table_no' => '32', 'status' => 'available']);
        $this->waiterId = $this->makeWaiter($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(4)]), ['name' => 'Plain Rice']);

        // Nothing on the POS works without an open shift (mandatory-open-shift).
        app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->ownerId, 0.0);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
