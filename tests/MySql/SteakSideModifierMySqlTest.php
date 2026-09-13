<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Printing\PrintJobService;
use App\Services\Reports\SalesReportEngine;
use App\Services\Sales\ShiftService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * STEAK-SIDE-MODIFIER-1 — steak ke sath side MUFT hai, sirf steak ka rate lagta hai.
 *
 * Client ki zaroorat bilkul saada hai: Rs 2,650 ka steak liya to bill Rs 2,650 hi banega — chahe
 * sath French Fries len, Vegetable Rice len, dono len, ya kuch na len. Side steak ke sath SHAMIL
 * hai, us ka alag paisa nahi.
 *
 * Isi liye owner ne dono side products ka rate 0 karwaya tha, aur isi liye modifier ka `price_delta`
 * bhi 0 hai.
 *
 * ⚠️ Meri PEHLI koshish ULTI thi. Ye dono products pehle 300 aur 400 par alag bikte thay, to maine
 * 300/400 ko "extra charge" samajh liya aur modifier me wohi rate daal diye. Ye client ki zaroorat
 * ke BILKUL khilaf tha: har steak par chup-chaap 300 ya 400 chad jata. Prod par us waqt 0 sale
 * lines me modifier tha (restaurant band tha), is liye kisi customer se ghalat paisa nahi gaya —
 * magar ye guards us ghalti ko dobara hone se rokte hain.
 *
 * To phir modifier kyun, jab paisa hi nahi lena?
 *   - KOT par kitchen ko pata chalta hai ke is steak ke sath fries hain ya rice
 *   - Cashier ko yaad karke alag item daalna nahi parta
 *   - Chunaav `sales_order_lines.modifiers` JSON me MEHFOOZ rehta hai, to baad me report banayi
 *     ja sakti hai: "kitne steak fries ke sath gaye, kitne rice ke sath" — aur wo report PURANI
 *     sales bhi ginn legi, kyunke data pehle din se darj ho raha hai.
 */
class SteakSideModifierMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const STEAK_PRICE = 2650.0;   // Tarragon Steak (Beef) — YEHI kul rate hai

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $branchId;
    private int $terminalId;
    private int $shiftId;
    private int $steakId;
    private int $friesProductId;
    private int $riceProductId;
    private int $groupId;
    private int $friesModifierId;
    private int $riceModifierId;
    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'steakmod.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'steakmod')->delete();
        } catch (\Throwable) {
            // best effort
        }
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PAISA — client ki poori zaroorat teen guards me
    // ══════════════════════════════════════════════════════════════════════════

    /** Side ke sath bhi bill sirf steak ka. YEHI wo cheez hai jo client ne maangi. */
    public function test_side_lene_par_bhi_sirf_steak_ka_rate_lagta_hai(): void
    {
        $res = $this->punchAndPay([$this->friesModifierId => 'French Fries (Steak Side)']);

        $this->assertContains($res->getStatusCode(), [200, 201],
            'sale ban'."'".'ni chahiye; mila ' . $res->getStatusCode() . ' — ' . $this->kyun($res));

        $this->assertSame(
            number_format(self::STEAK_PRICE, 2, '.', ''),
            number_format((float) SalesOrder::on('tenant')->latest('id')->first()->grand_total, 2, '.', ''),
            'side MUFT hai — bill sirf steak ka banna chahiye, ek rupya ziyada nahi'
        );
    }

    /** Dono sides ek sath bhi — phir bhi sirf steak ka rate. */
    public function test_dono_sides_lene_par_bhi_sirf_steak_ka_rate_lagta_hai(): void
    {
        $this->punchAndPay([
            $this->friesModifierId => 'French Fries (Steak Side)',
            $this->riceModifierId  => 'Vegetable Rice (Steak Side)',
        ]);

        $this->assertSame(
            number_format(self::STEAK_PRICE, 2, '.', ''),
            number_format((float) SalesOrder::on('tenant')->latest('id')->first()->grand_total, 2, '.', ''),
            'dono sides bhi muft hain — bill phir bhi sirf steak ka'
        );
    }

    /** Aur koi side na lein tab bhi wohi rate — yani teenon surat ka jawab EK hai. */
    public function test_side_na_lein_to_bhi_wohi_rate_lagta_hai(): void
    {
        $this->punchAndPay([]);

        $this->assertSame(
            number_format(self::STEAK_PRICE, 2, '.', ''),
            number_format((float) SalesOrder::on('tenant')->latest('id')->first()->grand_total, 2, '.', ''),
            'bina side ke bhi wohi rate'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CONFIG — us ghalti par pehra jo maine ek bar ki
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Kisi bhi option par rate NA laga ho.
     *
     * Ye us ghalti ka seedha pehra hai jo maine ki thi: purane product ke rate (300/400) ko
     * `price_delta` me daal dena. Agar koi dobara aisa kare — ya UI se ghalti se rate bhar de —
     * to har steak par chup-chaap paisa chad jayega aur client ki poori zaroorat toot jayegi.
     */
    public function test_kisi_side_par_koi_rate_nahi_laga_hua(): void
    {
        foreach (DB::connection('tenant')->table('modifiers')->where('modifier_group_id', $this->groupId)->get() as $m) {
            $this->assertSame('0.00', number_format((float) $m->price_delta, 2, '.', ''),
                "\"{$m->name}\" par rate nahi hona chahiye — side steak ke sath SHAMIL hai, extra nahi");
        }
    }

    /** Side lazmi na ho, aur koi side khud-ba-khud cart me na gire. */
    public function test_side_lazmi_nahi_aur_khud_ba_khud_nahi_chunta(): void
    {
        $group = DB::connection('tenant')->table('modifier_groups')->find($this->groupId);

        $this->assertSame(0, (int) $group->is_required, 'side lazmi na ho');
        $this->assertSame(0, (int) $group->min_select, 'min_select 0 rahe, warna POS aage nahi jane dega');
        $this->assertSame(2, (int) $group->max_select, 'dono sides ek sath lena mumkin rahe');

        $this->assertSame(0,
            DB::connection('tenant')->table('modifiers')
                ->where('modifier_group_id', $this->groupId)->where('is_default', 1)->count(),
            'koi side default na ho — cart me wohi jaye jo cashier ne chuna');
    }

    /**
     * Side ka product chhupa hai magar MITA nahi.
     *
     * Yehi cheez wapasi ko aasan rakhti hai, aur us ki purani bikri (Kashif Food par Rs 16,400,
     * 31 Aug se 12 Sep) reports me qaayam rehti hai.
     */
    public function test_side_ka_product_chhupa_hai_magar_mita_nahi(): void
    {
        foreach ([$this->friesProductId, $this->riceProductId] as $id) {
            $p = DB::connection('tenant')->table('products')->find($id);

            $this->assertSame('active', $p->status, 'product zinda rehna chahiye');
            $this->assertSame(0, (int) $p->is_pos_visible, 'POS par alag se na bike');
            $this->assertSame('0.00', number_format((float) $p->default_selling_price, 2, '.', ''),
                'rate 0 — ghalti se bik gaya to bhi paisa na lage');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // KITCHEN aur CUSTOMER — paisa nahi, magar KHABAR zaroori
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * KOT par side ka naam aana LAZMI hai.
     *
     * Paisa bhale na lag raha ho, kitchen ko pata hona chahiye ke is steak ke sath fries banani
     * hain ya rice. Yehi is poore kaam ka asal faida hai — warna modifier ka koi maqsad hi nahi.
     */
    public function test_kot_par_side_ka_naam_chhapta_hai(): void
    {
        $this->punchAndPay([$this->friesModifierId => 'French Fries (Steak Side)']);
        $sale = SalesOrder::on('tenant')->latest('id')->first();

        $jobs = app(PrintJobService::class)->queueKot($sale);
        $this->assertNotEmpty($jobs, 'KOT queue honi chahiye');
        $payload = collect($jobs)->map(fn ($j) => (string) $j->raw_payload)->implode("\n");

        $this->assertStringContainsString('French Fries', $payload,
            'kitchen ko side ka naam dikhna chahiye, warna wo banayegi hi nahi');

        // ⚠️ Thermal KOT par ITEM ka naam UPPERCASE chhapta hai (modifier ki sub-row nahi).
        // Mixed-case needle yahan jhoota "nahi mila" deta hai — ye jaal pehle bhugta hai.
        $this->assertMatchesRegularExpression('/tarragon steak/i', $payload,
            'item ka naam bhi KOT par hona chahiye');
    }

    /** Receipt par side ka naam aaye, magar us ke saamne koi extra raqam NA ho. */
    public function test_receipt_par_side_ka_naam_aata_hai_magar_koi_extra_raqam_nahi(): void
    {
        $this->punchAndPay([$this->friesModifierId => 'French Fries (Steak Side)']);
        $sale = SalesOrder::on('tenant')->latest('id')->first();

        $payload = (string) app(PrintJobService::class)->queueReceipt($sale)->raw_payload;

        $this->assertStringContainsString('French Fries', $payload, 'receipt par side ka naam');

        // ⚠️ Poore payload me "300" dhoondhna bhadda hai — us me random payment code, tareekh, waqt
        // aur ESC/POS ke control bytes bhi hain, to wo kisi bhi wajah se match kar sakta hai.
        // Dekhna USI SATAR par hai jahan side ka naam hai: us par koi raqam nahi honi chahiye.
        $sideRow = collect(preg_split('/\R/', $payload))
            ->first(fn ($row) => str_contains($row, 'French Fries'));

        $this->assertNotNull($sideRow, 'side ki satar milni chahiye');
        $this->assertDoesNotMatchRegularExpression('/\d/', $sideRow,
            "side ki satar par koi raqam nahi honi chahiye, mila: [{$sideRow}] — "
            . 'warna customer samjhega ke us se extra liya gaya');

        // Aur kul raqam sirf steak ka rate ho.
        $this->assertStringContainsString('2,650', $payload, 'total sirf steak ka rate');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // AAGE KI REPORT KA SAMAAN
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Chunaav line par MEHFOOZ rahe — is se hi wo report banegi jo client baad me maangega
     * ("kitne steak fries ke sath gaye?"). Data pehle din se darj ho raha hai, is liye wo report
     * jab bhi bane, PURANI sales bhi ginn legi.
     */
    public function test_kaunsa_side_chuna_gaya_ye_line_par_mehfooz_rehta_hai(): void
    {
        $this->punchAndPay([$this->riceModifierId => 'Vegetable Rice (Steak Side)']);

        $mods = json_decode(
            DB::connection('tenant')->table('sales_order_lines')->latest('id')->first()->modifiers ?? '[]',
            true
        );

        $this->assertCount(1, $mods, 'line par theek ek side darj hona chahiye');
        $this->assertSame('Vegetable Rice (Steak Side)', $mods[0]['name']);
        $this->assertSame($this->riceModifierId, (int) $mods[0]['modifier_id'],
            'modifier_id bhi mehfooz ho — report isi se ginegi');
    }

    /** Report me sirf steak ka rate — aur side ka apna product report me aata hi nahi. */
    public function test_report_me_sirf_steak_ka_rate_aata_hai(): void
    {
        $this->punchAndPay([$this->friesModifierId => 'French Fries (Steak Side)']);

        $rows  = app(SalesReportEngine::class)->byItem($this->reportFilters());
        $steak = collect($rows)->first(fn ($r) => (int) $r->product_id === $this->steakId);
        $fries = collect($rows)->first(fn ($r) => (int) $r->product_id === $this->friesProductId);

        $this->assertNotNull($steak, 'steak report me hona chahiye');
        $this->assertSame(
            number_format(self::STEAK_PRICE, 2, '.', ''),
            number_format((float) $steak->net, 2, '.', ''),
            'report me bhi sirf steak ka rate'
        );

        $this->assertNull($fries,
            'side ka product report me nahi aata — wo koi line nahi banata. '
            . 'Ginti ki report baad me modifiers JSON se banegi.');
    }

    /** Modifier inventory ko bilkul na chhue. */
    public function test_modifier_stock_ko_nahi_chhoota(): void
    {
        foreach (DB::connection('tenant')->table('modifiers')->where('modifier_group_id', $this->groupId)->get() as $m) {
            $this->assertSame(0, (int) $m->consume_stock,
                "\"{$m->name}\" par consume_stock band rehna chahiye — ye sides stock-tracked nahi hain");
        }

        $this->punchAndPay([$this->friesModifierId => 'French Fries (Steak Side)']);

        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count(),
            'modifier wali sale se ek bhi stock ledger row nahi ban'."'".'ni chahiye');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Madadgar
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * POS jaisa hi payload.
     *
     * ⚠️ `unit_price` HAMESHA steak ka apna rate hai — chahe kitne hi sides chune jayen. POS ka JS
     * `price_delta` jorRta hai, aur wo sab 0 hain, is liye jama wohi rehta hai.
     *
     * ⚠️ `modifiers` ki validation `nullable|string` hai — POS JSON STRING bhejta hai, array nahi.
     */
    private function punchAndPay(array $modifiers)
    {
        $line = [
            'product_id' => $this->steakId,
            'quantity'   => 1,
            'unit_price' => self::STEAK_PRICE,
        ];

        if ($modifiers !== []) {
            $line['modifiers'] = json_encode(collect($modifiers)->map(fn ($name, $id) => [
                'modifier_group_id'   => $this->groupId,
                'modifier_group_name' => 'Steak Side',
                'modifier_id'         => $id,
                'name'                => $name,
                'price_delta'         => 0,
            ])->values()->all());
        }

        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->postJson('http://' . $this->host . '/sales-orders', [
                'branch_id'     => $this->branchId,
                'terminal_id'   => $this->terminalId,
                'order_type'    => 'takeaway',
                'discount_type' => 'none',
                'lines'         => [$line],
                'payments'      => [[
                    'payment_method_id' => $this->paymentMethodId,
                    'amount'            => self::STEAK_PRICE,
                    'tendered_amount'   => self::STEAK_PRICE,
                ]],
            ]);
    }

    private function reportFilters(): array
    {
        // ⚠️ `now()->toDateString()` yahan GHALAT hai. Business date raat ko roll hoti hai, is liye
        // subah 4 baje chalne wale test ki sale KAL ki business date par jaati hai aur report khali
        // aati hai. Shift ki apni business_date hi wahid sach hai.
        $businessDate = DB::connection('tenant')->table('shifts')
            ->where('id', $this->shiftId)->value('business_date');

        return app(SalesReportEngine::class)->normalizeFilters([
            'date_from'  => $businessDate,
            'date_to'    => $businessDate,
            'branch_ids' => [$this->branchId],
        ]);
    }

    private function kyun($res): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($res->getContent()))), 300);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Seed
    // ══════════════════════════════════════════════════════════════════════════

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'steakmod')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'steakmod', 'business_name' => 'Steak Mod',
            'owner_name' => 'Owner', 'owner_email' => 'owner@steakmod.test',
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

        $planId = $m->table('plans')->where('code', 'steakmod-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'steakmod-plan', 'name' => 'Steak Mod', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        foreach (['tenant.pos.index', 'tenant.sales-orders.store'] as $routeName) {
            $key = $m->table('route_catalogs')->where('route_name', $routeName)->value('module_key');
            if (! $key) {
                continue;
            }
            $module = Module::forRouteModuleKey($key)->first();
            $this->assertNotNull($module,
                "route [{$routeName}] [{$key}] se juda hai magar koi module us ka dawedar nahi");
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
            'stock_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'product_modifier_group', 'modifiers', 'modifier_groups',
            'print_jobs', 'printers', 'payment_methods',
            'model_has_roles', 'users', 'products', 'categories', 'shifts', 'terminals', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        foreach (['tenant.pos.index', 'tenant.sales-orders.store'] as $routeName) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $routeName, 'guard_name' => 'tenant'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'SteakOwner', 'email' => 'owner@steakmod.test', 'password' => bcrypt('x'),
            'employee_code' => 'STKOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->branchId        = $this->makeBranch();
        $this->terminalId      = $this->makeTerminal($this->branchId);
        $this->paymentMethodId = $this->makePaymentMethod();

        $steaks = $this->makeCategory(['name' => 'Steaks', 'slug' => 'steaks-' . Str::random(4)]);

        // ⚠️ Asli Kashif Food ke steaks SERVICE hain, stock-tracked nahi (POS par "Service" badge).
        // Fixture ka default stock-tracked tha aur sale "Insufficient stock" par ruk gayi thi — wo
        // code ki kharabi nahi thi, meri fixture haqeeqat se alag thi.
        $this->steakId = $this->makeProduct($steaks, [
            'name'                  => 'Tarragon Steak (Beef)',
            'default_selling_price' => self::STEAK_PRICE,
            'product_type'          => 'service',
            'product_kind'          => 'service',
            'is_stock_tracked'      => 0,
        ]);

        // Prod jaisi shakl: rate 0, POS se chhupe, magar ZINDA.
        $sideShape = [
            'default_selling_price' => 0,
            'is_pos_visible'        => 0,
            'is_sellable'           => 1,
            'is_stock_tracked'      => 0,
            'status'                => 'active',
            'product_type'          => 'service',
            'product_kind'          => 'service',
        ];
        $this->friesProductId = $this->makeProduct($steaks, array_merge($sideShape, ['name' => 'French Fries (Steak Side)']));
        $this->riceProductId  = $this->makeProduct($steaks, array_merge($sideShape, ['name' => 'Vegetable Rice (Steak Side)']));

        $this->groupId = $c->table('modifier_groups')->insertGetId([
            'branch_id'   => $this->branchId,
            'name'        => 'Steak Side',
            'min_select'  => 0,
            'max_select'  => 2,
            'is_required' => 0,
            'sort_order'  => 0,
            'status'      => 'active',
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        // ⚠️ price_delta 0 — side steak ke sath SHAMIL hai. Yahan 300/400 daalna wohi ghalti hai
        // jo maine ek bar ki: purane product ke rate ko "extra charge" samajh lena.
        $this->friesModifierId = $this->makeModifier('French Fries (Steak Side)', $this->friesProductId, 0);
        $this->riceModifierId  = $this->makeModifier('Vegetable Rice (Steak Side)', $this->riceProductId, 1);

        $c->table('product_modifier_group')->insert([
            'product_id' => $this->steakId, 'modifier_group_id' => $this->groupId,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->shiftId = app(ShiftService::class)->open(
            Branch::on('tenant')->find($this->branchId),
            Terminal::on('tenant')->find($this->terminalId),
            $this->ownerId,
            0.0
        )->id;

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeModifier(string $name, int $linkedProductId, int $sort): int
    {
        return DB::connection('tenant')->table('modifiers')->insertGetId([
            'modifier_group_id' => $this->groupId,
            'name'              => $name,
            'price_delta'       => 0,
            'linked_product_id' => $linkedProductId,
            'consume_stock'     => 0,
            'is_default'        => 0,
            'sort_order'        => $sort,
            'status'            => 'active',
            'created_at'        => now(), 'updated_at' => now(),
        ]);
    }
}
