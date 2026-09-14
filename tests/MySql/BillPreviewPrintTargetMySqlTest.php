<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * BILL-PREVIEW-WRONG-PRINT-1 — jo bill SCREEN par hai, printer par wohi jaye.
 *
 * Owner ka bayan: "bill preview sahi khulta hai, magar send to network karne par jo order
 * background me attach hota hai us ka print jata hai."
 *
 * Sabab: `billPreviewModal` SANJHA hai — table session ka bill bhi is me khulta hai aur cart ka
 * preview bhi. Footer ka "Send to network" dono surton me `currentReprintSaleId()` (yani CART ka
 * order) bhejta tha. Screen par table 9 ka bill, printer par table 5 ki parchi.
 *
 * 31 Aug ko `75dc5cf` ne us button ko card se CHHUPA diya tha — magar wo "card bhara hua hai" wali
 * shikayat ka jawab tha, is bug ka nahi. Kharabi wahin rahi aur POS ke session bar wale button se
 * aaj tak pahunchi ja sakti thi.
 *
 * Doc: docs/plans/bill-preview-wrong-print-2026-09-14.md
 */
class BillPreviewPrintTargetMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $branchId;
    private int $terminalId;
    private int $tableId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'billpv.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'billpv')->delete();
        } catch (\Throwable) {
            // best effort
        }
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SERVER — preview ke sath ye BHI aaye ke bill kin orders ka hai
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Endpoint us session ke UNPAID orders ki id lauta-ye.
     *
     * Yehi wo cheez hai jo pehle nahi thi. Us ke baghair modal ke paas koi sale id hoti hi nahi
     * thi, aur button ke paas cart ke order ke siwa bhejne ko kuch tha hi nahi.
     */
    public function test_preview_ke_sath_us_session_ke_unpaid_orders_ki_id_bhi_aati_hai(): void
    {
        $session = $this->openSession();
        $a = $this->orderOn($session, 'held', 500);
        $b = $this->orderOn($session, 'held', 300);

        $res = $this->preview($session);
        $res->assertOk();

        $ids = collect($res->json('held_sale_ids'))->map(fn ($v) => (int) $v)->sort()->values()->all();

        $this->assertSame([$a, $b], $ids,
            'preview ke sath usi session ke dono unpaid orders ki id aani chahiye');
    }

    /** ADA HO CHUKE order ki parchi dobara nahi bhejni — us ka koi matlab nahi. */
    public function test_ada_ho_chuke_order_ki_id_nahi_aati(): void
    {
        $session = $this->openSession();
        $held    = $this->orderOn($session, 'held', 500);
        $paid    = $this->orderOn($session, 'paid', 900);

        $ids = collect($this->preview($session)->json('held_sale_ids'))->map(fn ($v) => (int) $v)->all();

        $this->assertSame([$held], $ids, 'sirf unpaid order ki id');
        $this->assertNotContains($paid, $ids, 'paid order ki parchi dobara nahi jani chahiye');
    }

    /** Kisi DOOSRI table ka order is bill me na aaye. */
    public function test_doosri_table_ka_order_is_preview_me_nahi_aata(): void
    {
        $mine   = $this->openSession();
        $theirs = $this->openSession(sameTable: false);

        $mineId = $this->orderOn($mine, 'held', 500);
        $this->orderOn($theirs, 'held', 700);

        $ids = collect($this->preview($mine)->json('held_sale_ids'))->map(fn ($v) => (int) $v)->all();

        $this->assertSame([$mineId], $ids, 'sirf isi session ke orders');
    }

    /** Sab kuch ada ho chuka ho to khali list — taake button saaf inkaar kar sake. */
    public function test_sab_ada_ho_chuka_ho_to_khali_list_aati_hai(): void
    {
        $session = $this->openSession();
        $this->orderOn($session, 'paid', 900);

        $this->assertSame([], $this->preview($session)->json('held_sale_ids'),
            'koi unpaid order nahi to khali list — aur button "no unpaid order" keh sake');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAFHA — wiring asli render par mojood ho
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Shipped JS me wo dono nishan aur session wali shaakh mojood hon.
     *
     * ⚠️ Guard-3 ke andaz ka pehra: agar koi `markPreviewMode('cart')` hata de to modal ki pichli
     * haalat CHIPAK jayegi aur wohi bug ulti taraf se wapas aa jayega — cart ka preview khulta
     * aur purani table ki parchi jaati.
     */
    public function test_safhe_par_dono_nishan_aur_session_wali_shaakh_mojood_hai(): void
    {
        $html = $this->pos();

        // ⚠️ Poore safhe par `assertStringContainsString` KAFI NAHI hai. Pehli koshish me maine
        // yehi kiya tha aur guard be-maani nikla: sabotage me satar ko comment kiya, aur wo lafz
        // COMMENT ke andar bhi mojood tha — is liye test pass karta raha jabke feature toot chuka
        // tha. Ab har function ka apna jism nikal kar, comments HATA kar dekha jata hai.
        $cart    = $this->liveCode($this->extractFunction($html, 'billPreview'));
        $session = $this->liveCode($this->extractFunction($html, 'showTableBillPreview'));

        $this->assertStringContainsString("markPreviewMode('session'", $session,
            'table ka preview apne aap ko session ka nishan de');
        $this->assertStringContainsString("markPreviewMode('cart')", $cart,
            'cart ka preview nishan SAAF kare — warna pichli table ka nishan chipak jayega aur '
            . 'us ki parchi chali jayegi');

        $this->assertStringContainsString("dataset.mode === 'session'", $this->liveCode($html),
            'send-to-network session wali soorat alag se sambhale');
        $this->assertStringContainsString('held_sale_ids', $this->liveCode($html),
            'server se aayi ids ka istemal safhe par mojood ho');
    }

    /** JS ka zinda code — `//` wale comments hata kar, taake comment kiya hua code "mojood" na gine. */
    private function liveCode(string $js): string
    {
        return preg_replace('~^\s*//.*$~m', '', $js);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // JS KA BARTAAO — asli shipped code chala kar
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * `markPreviewMode()` ko ASLI safhe se nikal kar chalao.
     *
     * Ye is poore masle ki JARR par pehra hai: modal sanjha hai, aur pichli haalat ka chipak jana
     * hi wo cheez thi jis ne ghalat parchi bhijwayi. Ye guard source parh kar nahi, wohi function
     * CHALA kar dekhta hai jo browser me chalta hai.
     */
    public function test_cart_ka_preview_pichli_table_ka_nishan_saaf_kar_deta_hai(): void
    {
        $src = $this->extractFunction($this->pos(), 'markPreviewMode');

        $node = $this->runJs($src, [
            "markPreviewMode('session', [7, 9]);",
            'out.afterSession = { mode: el.dataset.mode, ids: el.dataset.heldSaleIds };',
            "markPreviewMode('cart');",
            'out.afterCart = { mode: el.dataset.mode, ids: el.dataset.heldSaleIds };',
        ]);

        $this->assertSame('session', $node['afterSession']['mode']);
        $this->assertSame('[7,9]', str_replace(' ', '', $node['afterSession']['ids']),
            'session ke nishan me usi table ke orders');

        $this->assertSame('cart', $node['afterCart']['mode']);
        $this->assertSame('', $node['afterCart']['ids'],
            'cart par aate hi purane orders SAAF hon — warna unhi ki parchi jayegi');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Madadgar
    // ══════════════════════════════════════════════════════════════════════════

    private function pos(): string
    {
        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->get('http://' . $this->host . '/pos');
        $res->assertOk();

        return $res->getContent();
    }

    private function preview(RestaurantTableSession $session)
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->getJson('http://' . $this->host . '/restaurant/table-sessions/' . $session->id . '/bill-preview');
    }

    /** `function X(...) { … }` ka poora source — brace ginn kar. */
    private function extractFunction(string $html, string $name): string
    {
        $start = strpos($html, 'function ' . $name);
        $this->assertNotFalse($start, "[{$name}] safhe par mila");

        $depth = 0;
        for ($i = strpos($html, '{', $start); $i < strlen($html); $i++) {
            if ($html[$i] === '{') { $depth++; }
            elseif ($html[$i] === '}') { $depth--; if ($depth === 0) { return substr($html, $start, $i - $start + 1); } }
        }

        $this->fail("[{$name}] ka ikhtitam nahi mila");
    }

    /** Chhote DOM stub par asli function chalao; `out` JSON me wapas. */
    private function runJs(string $source, array $steps): array
    {
        $node = $this->nodeBinary();
        if (! $node) {
            $this->markTestSkipped('node nahi mila — JS ka bartaao yahan nahi chalaya ja sakta');
        }

        $script = "const el = { dataset: {} };\n"
            . "const document = { getElementById: (id) => (id === 'billPreviewModal' ? el : null) };\n"
            . $source . "\n"
            . "const out = {};\n"
            . implode("\n", $steps) . "\n"
            . "console.log(JSON.stringify(out));\n";

        $file = sys_get_temp_dir() . '/billpv_' . Str::random(8) . '.js';
        file_put_contents($file, $script);
        $raw = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1');
        @unlink($file);

        $decoded = json_decode(trim((string) $raw), true);
        $this->assertIsArray($decoded, 'node ka jawab samajh nahi aaya: ' . $raw);

        return $decoded;
    }

    private function nodeBinary(): ?string
    {
        foreach (['D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe', 'node'] as $c) {
            if ($c === 'node' || is_file($c)) {
                return $c;
            }
        }

        return null;
    }

    private function openSession(bool $sameTable = true): RestaurantTableSession
    {
        $tableId = $sameTable ? $this->tableId : $this->makeTable($this->branchId, ['table_no' => 'X' . random_int(10, 99)]);

        DB::connection('tenant')->table('restaurant_tables')->where('id', $tableId)->update(['status' => 'occupied']);

        $id = DB::connection('tenant')->table('restaurant_table_sessions')->insertGetId([
            'session_no'          => 'TS-' . Str::upper(Str::random(10)),
            'branch_id'           => $this->branchId,
            'restaurant_table_id' => $tableId,
            'status'              => 'open',
            'guest_count'         => 1,
            'opened_by_user_id'   => $this->ownerId,
            'opened_at'           => now(),
            'business_date'       => now()->toDateString(),
            'created_at'          => now(), 'updated_at' => now(),
        ]);

        return RestaurantTableSession::on('tenant')->findOrFail($id);
    }

    private function orderOn(RestaurantTableSession $session, string $status, float $total): int
    {
        $id = $this->makeSale($this->branchId, [
            'status'                      => $status,
            'payment_status'              => $status === 'paid' ? 'paid' : 'unpaid',
            'order_type'                  => 'dine_in',
            'restaurant_table_session_id' => $session->id,
            'restaurant_table_id'         => $session->restaurant_table_id,
            'terminal_id'                 => $this->terminalId,
            'grand_total'                 => $total,
        ]);
        $this->makeSaleLine($id, $this->productId, ['quantity' => 1, 'unit_price' => $total, 'line_total' => $total]);

        return $id;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Seed
    // ══════════════════════════════════════════════════════════════════════════

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'billpv')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'billpv', 'business_name' => 'Bill Preview',
            'owner_name' => 'Owner', 'owner_email' => 'owner@billpv.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'billpv-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'billpv-plan', 'name' => 'Bill Preview', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        // ⚠️ Module MOJOOD ho to plan me daalo — BANAO kabhi nahi. Master DB sanjhi hai; us me naya
        // dawedar module banane se doosre tests ka gate fail-open se fail-CLOSED ho jata hai.
        foreach (['tenant.pos.index', 'tenant.restaurant.table-sessions.bill-preview'] as $routeName) {
            $key = $m->table('route_catalogs')->where('route_name', $routeName)->value('module_key');
            if (! $key) {
                continue;
            }
            $module = Module::forRouteModuleKey($key)->first();
            if (! $module) {
                continue;
            }
            $m->table('plan_modules')->updateOrInsert(
                ['plan_id' => $planId, 'module_id' => $module->id], ['is_enabled' => 1]
            );
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenant(): void
    {
        $this->cleanTenant([
            'sale_payments', 'sales_order_lines', 'sales_orders',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors',
            'model_has_roles', 'users', 'products', 'categories', 'terminals', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        foreach (['tenant.pos.index', 'tenant.restaurant.table-sessions.bill-preview'] as $routeName) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $routeName, 'guard_name' => 'tenant'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'BpOwner', 'email' => 'owner@billpv.test', 'password' => bcrypt('x'),
            'employee_code' => 'BPOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->branchId   = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->tableId    = $this->makeTable($this->branchId, ['table_no' => '9']);
        $this->productId  = $this->makeProduct(
            $this->makeCategory(['name' => 'Food', 'slug' => 'food-' . Str::random(4)]),
            ['product_type' => 'service', 'product_kind' => 'service', 'is_stock_tracked' => 0]
        );

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
