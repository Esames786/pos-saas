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
 * STEAK-SIDE-MODIFIER-1 — modifier ka PEHLA asli istemal, prod par lagane se pehle.
 *
 * Kashif Food par "French Fries (Steak Side)" 300 aur "Vegetable Rice (Steak Side)" 400 abhi ALAG
 * products hain jo cashier ko yaad karke daalne parte hain. Owner chahta hai ke ye steak ke andar
 * modifier ban jayen: rate 0 + POS se chhupe, aur steak punch karte hi option saamne aaye.
 *
 * ⚠️ Ye nizam aaj tak KISI tenant par istemal nahi hua — 38,381 sale lines jaanchin, sab `[]`.
 * Code mojood hai, magar prod par pehli bar chalega. Owner ne prod par jhooti sale se mana kiya
 * hai (theek hi kiya), is liye yehi guard wahid imandaar tareeqa hai ye dekhne ka ke:
 *
 *     paisa theek gaya? KOT par naam aaya? receipt par rate aaya? report ka hisab theek raha?
 *
 * Mojooda `ComboModifierKotIntegrityMySqlTest` sirf KOT tak dekhta hai, aur line seedhi banata hai —
 * asli HTTP, paisa aur report us me nahi. Ye file wohi khala bharti hai.
 */
class SteakSideModifierMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const STEAK_PRICE = 2650.0;   // Tarragon Steak (Beef)
    private const SIDE_DELTA  = 300.0;    // French Fries (Steak Side)
    private const RICE_DELTA  = 400.0;    // Vegetable Rice (Steak Side)

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $branchId;
    private int $terminalId;
    private int $shiftId;
    private int $steakId;
    private int $sideProductId;
    private int $groupId;
    private int $modifierId;
    private int $riceModifierId;
    private int $riceProductId;
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
    // PAISA — sab se ahem
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Modifier ka 300 customer se WAQAI wasool hota hai.
     *
     * POS ka JS `price_delta` ko `unit_price` me jorR kar bhejta hai, aur server us qeemat ko
     * `SalePricingService::resolveSellingPrice()` me `if ($submittedPrice !== null) return ...` se
     * qabool karta hai. Agar wo kabhi badal kar catalog se rate dobara hal karne lage, to modifier
     * ka paisa CHUP-CHAAP gir jayega aur restaurant har steak par 300 ka nuqsan uthayega. Ye guard
     * theek us soorat par girta hai.
     */
    public function test_modifier_ka_paisa_customer_se_wasool_hota_hai(): void
    {
        $res = $this->punchAndPay();

        $this->assertContains($res->getStatusCode(), [200, 201],
            'sale ban'."'".'ni chahiye; mila ' . $res->getStatusCode() . ' — ' . $this->kyun($res));

        $sale = SalesOrder::on('tenant')->latest('id')->first();

        $this->assertSame(
            number_format(self::STEAK_PRICE + self::SIDE_DELTA, 2, '.', ''),
            number_format((float) $sale->grand_total, 2, '.', ''),
            'sale ka total steak + modifier hona chahiye'
        );
    }

    /**
     * ⚠️ SAB SE AHEM ROZMARRA SOORAT — modifier liya hi NAHI.
     *
     * Zyada tar customer sirf steak lete hain. Un se steak ka apna rate hi lena chahiye, ek rupya
     * ziyada nahi. Agar kabhi kisi option par `is_default` on ho jaye, ya POS ka JS sum galat kare,
     * to har saada steak par chup-chaap 300 chad jayega aur customer se ziyada wasool hoga.
     *
     * Ye guard mere pehle set me NAHI tha — owner ne poocha "modifier na lein to?" aur tab pata
     * chala ke maine sirf modifier WALA case test kiya tha. Yehi wo sawal hai jo rozana sab se
     * ziyada bar chalega.
     */
    public function test_modifier_na_lein_to_steak_ka_apna_rate_hi_lagta_hai(): void
    {
        $res = $this->punchAndPay(withModifier: false);

        $this->assertContains($res->getStatusCode(), [200, 201],
            'saada steak ki sale ban'."'".'ni chahiye; mila ' . $res->getStatusCode() . ' — ' . $this->kyun($res));

        $sale = SalesOrder::on('tenant')->latest('id')->first();

        $this->assertSame(
            number_format(self::STEAK_PRICE, 2, '.', ''),
            number_format((float) $sale->grand_total, 2, '.', ''),
            'modifier ke baghair sirf steak ka rate lena chahiye — ek rupya ziyada nahi'
        );

        $line = DB::connection('tenant')->table('sales_order_lines')->latest('id')->first();
        $this->assertSame([], json_decode($line->modifiers ?? '[]', true),
            'koi modifier chuna hi nahi, to line par bhi koi na ho');
    }

    /**
     * Dono sides ek sath — group `max_select = 2` hai, is liye ye jaiz hai aur dono ka paisa lagna
     * chahiye. (Aaj bhi ye alag products hain, to customer dono le sakta hai; wo azadi na chhine.)
     */
    public function test_dono_sides_ek_sath_lein_to_dono_ka_paisa_lagta_hai(): void
    {
        $total = self::STEAK_PRICE + self::SIDE_DELTA + self::RICE_DELTA;

        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->postJson('http://' . $this->host . '/sales-orders', [
                'branch_id'     => $this->branchId,
                'terminal_id'   => $this->terminalId,
                'order_type'    => 'takeaway',
                'discount_type' => 'none',
                'lines'         => [[
                    'product_id' => $this->steakId,
                    'quantity'   => 1,
                    'unit_price' => $total,
                    'modifiers'  => json_encode([
                        [
                            'modifier_group_id'   => $this->groupId,
                            'modifier_group_name' => 'Steak Side',
                            'modifier_id'         => $this->modifierId,
                            'name'                => 'French Fries (Steak Side)',
                            'price_delta'         => self::SIDE_DELTA,
                        ],
                        [
                            'modifier_group_id'   => $this->groupId,
                            'modifier_group_name' => 'Steak Side',
                            'modifier_id'         => $this->riceModifierId,
                            'name'                => 'Vegetable Rice (Steak Side)',
                            'price_delta'         => self::RICE_DELTA,
                        ],
                    ]),
                ]],
                'payments' => [[
                    'payment_method_id' => $this->paymentMethodId,
                    'amount'            => $total,
                    'tendered_amount'   => $total,
                ]],
            ]);

        $this->assertContains($res->getStatusCode(), [200, 201],
            'dono sides ke sath sale ban'."'".'ni chahiye; mila ' . $res->getStatusCode() . ' — ' . $this->kyun($res));

        $sale = SalesOrder::on('tenant')->latest('id')->first();
        $this->assertSame(
            number_format($total, 2, '.', ''),
            number_format((float) $sale->grand_total, 2, '.', ''),
            'steak + 300 + 400 = 3350 lena chahiye'
        );
    }

    /**
     * Koi bhi option `is_default` na ho.
     *
     * Agar `is_default` on ho to POS us side ko KHUD chun kar cart me daal deta hai, aur jo customer
     * sirf steak chahta tha us se bhi 300 wasool ho jata. Ye wohi soorat hai jo test-1 rokta hai —
     * ye us ki jarr par pehra deta hai.
     */
    public function test_koi_side_khud_ba_khud_nahi_chunta(): void
    {
        $defaults = DB::connection('tenant')->table('modifiers')
            ->where('modifier_group_id', $this->groupId)->where('is_default', 1)->count();

        $this->assertSame(0, $defaults,
            'koi bhi side default na ho — warna saada steak lene wale se bhi extra charge ho jayega');

        $group = DB::connection('tenant')->table('modifier_groups')->find($this->groupId);
        $this->assertSame(0, (int) $group->is_required,
            'side lazmi na ho — customer sirf steak bhi le sakta hai');
        $this->assertSame(0, (int) $group->min_select,
            'min_select 0 rahe, warna POS side chune baghair aage nahi jane dega');
    }

    /** Modifier ka snapshot line par mehfooz rahe — report baad me isi se banayi ja sakti hai. */
    public function test_modifier_line_par_mehfooz_rehta_hai(): void
    {
        $this->punchAndPay();

        $line = DB::connection('tenant')->table('sales_order_lines')->latest('id')->first();
        $mods = json_decode($line->modifiers ?? '[]', true);

        $this->assertCount(1, $mods, 'line par theek ek modifier hona chahiye');
        $this->assertSame('French Fries (Steak Side)', $mods[0]['name']);
        $this->assertSame(self::SIDE_DELTA, (float) $mods[0]['price_delta'],
            'rate bhi snapshot me hona chahiye — warna baad ki report andha hogi');
        $this->assertSame($this->modifierId, (int) $mods[0]['modifier_id']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRINT — kitchen aur customer dono ko nazar aaye
    // ══════════════════════════════════════════════════════════════════════════

    /** KOT par side ka naam aana LAZMI hai — warna kitchen fries banayegi hi nahi. */
    public function test_kot_par_side_ka_naam_chhapta_hai(): void
    {
        $this->punchAndPay();
        $sale = SalesOrder::on('tenant')->latest('id')->first();

        $jobs = app(PrintJobService::class)->queueKot($sale);

        $this->assertNotEmpty($jobs, 'KOT queue honi chahiye');
        $payload = collect($jobs)->map(fn ($j) => (string) $j->raw_payload)->implode("\n");

        $this->assertStringContainsString('French Fries', $payload,
            'kitchen ko side ka naam dikhna chahiye, warna wo banayegi hi nahi');

        // ⚠️ Thermal KOT par ITEM ka naam UPPERCASE chhapta hai (modifier ki sub-row nahi).
        // Mixed-case needle yahan jhoota "nahi mila" deta hai — ye jaal maine pehle bhi bhugta hai.
        $this->assertMatchesRegularExpression('/tarragon steak/i', $payload,
            'item ka naam bhi KOT par hona chahiye');
    }

    /** Receipt par side ka naam AUR uska rate — customer ko pata chale 300 kis cheez ka hai. */
    public function test_receipt_par_side_ka_naam_aur_rate_dono_chhapte_hain(): void
    {
        $this->punchAndPay();
        $sale = SalesOrder::on('tenant')->latest('id')->first();

        $job = app(PrintJobService::class)->queueReceipt($sale);
        $payload = (string) $job->raw_payload;

        $this->assertStringContainsString('French Fries', $payload, 'receipt par side ka naam');
        $this->assertStringContainsString('300', $payload,
            'receipt par uska rate bhi — warna customer ko 300 ka sabab nazar nahi aayega');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // REPORT — wo nateeja jis par owner ka faisla tha
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Kul sale bilkul theek rehti hai — modifier ka paisa steak ki row ke andar aata hai.
     *
     * Aur yahi is tabdeeli ki QEEMAT hai, jo owner ko batayi gayi thi: side ka apna product report
     * me AATA HI NAHI, kyunke wo ab koi line nahi banata. `byItem()` `sales_order_lines` ki rows
     * ginta hai; modifier row nahi, parent line ka JSON hai. Ye guard us faisle ko likhit rakhta hai
     * taake koi baad me ise "bug" samajh kar chup-chaap na badle.
     */
    public function test_report_me_paisa_steak_ki_row_me_aata_hai_side_ki_apni_row_nahi_banti(): void
    {
        $this->punchAndPay();

        $rows = app(SalesReportEngine::class)->byItem($this->reportFilters());

        $steak = collect($rows)->first(fn ($r) => (int) $r->product_id === $this->steakId);
        $side  = collect($rows)->first(fn ($r) => (int) $r->product_id === $this->sideProductId);

        $this->assertNotNull($steak, 'steak report me hona chahiye');
        $this->assertSame(
            number_format(self::STEAK_PRICE + self::SIDE_DELTA, 2, '.', ''),
            number_format((float) $steak->net, 2, '.', ''),
            'steak ki row me modifier ka paisa shamil hona chahiye — kul sale kabhi kam na dikhe'
        );

        $this->assertNull($side,
            'side ka product report me NAHI aata — ye jaana-boojha nateeja hai, bug nahi. '
            . 'Ginti wapas chahiye to modifiers JSON se alag report banani paregi.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CONFIG — wo shakl jo prod par lagegi
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Side ka product rate 0 + POS se chhupa hua ho, MAGAR mita hua nahi.
     *
     * Yehi cheez is kaam ko wapas-palatne layaq banati hai: ghalat lage to ek field badal kar 10
     * second me bahal. Aur us ki purani bikri (Kashif Food par Rs 16,400) reports me qaayam rehti hai.
     */
    public function test_side_ka_product_chhupa_hai_magar_mita_nahi(): void
    {
        $p = DB::connection('tenant')->table('products')->find($this->sideProductId);

        $this->assertSame('active', $p->status, 'product zinda rehna chahiye — mitana wapasi ka raasta band kar deta hai');
        $this->assertSame(0, (int) $p->is_pos_visible, 'POS par alag se nazar na aaye');
        $this->assertSame('0.00', number_format((float) $p->default_selling_price, 2, '.', ''),
            'rate 0 — warna ghalti se bik gaya to dohra charge');
    }

    /**
     * Modifier INVENTORY ko bilkul na chhue.
     *
     * `consume_stock` on karne ke liye linked product ka stock-tracked hona lazmi hai; ye side
     * products stock-tracked hain hi nahi. Agar koi baad me ye switch on kar de to har steak ki sale
     * inventory ke raaste par chali jayegi — jo abhi maqsad nahi.
     */
    public function test_modifier_stock_ko_nahi_chhoota(): void
    {
        $m = DB::connection('tenant')->table('modifiers')->find($this->modifierId);

        $this->assertSame(0, (int) $m->consume_stock,
            'consume_stock band rehna chahiye — ye side products stock-tracked nahi hain');

        $this->punchAndPay();

        $this->assertSame(0,
            DB::connection('tenant')->table('stock_ledgers')->count(),
            'modifier wali sale se stock ka ek bhi ledger row nahi ban'."'".'na chahiye');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Madadgar
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * POS jaisa hi payload.
     *
     * `withModifier = false` wo rozmarra soorat hai jahan customer sirf steak leta hai: POS ka JS
     * tab koi delta nahi jorRta, is liye `unit_price` steak ka apna rate hota hai aur `modifiers`
     * khali jata hai.
     */
    private function punchAndPay(bool $withModifier = true)
    {
        $price = $withModifier ? self::STEAK_PRICE + self::SIDE_DELTA : self::STEAK_PRICE;

        $line = [
            'product_id' => $this->steakId,
            'quantity'   => 1,
            // POS ka JS delta ko unit_price me jorR kar bhejta hai — bilkul yehi shakl.
            'unit_price' => $price,
        ];

        if ($withModifier) {
            // ⚠️ Validation `nullable|string` hai — POS modifiers ko JSON STRING bhejta hai, array
            // nahi. Pehli koshish me array bheja aur 422 mila:
            // "The lines.0.modifiers field must be a string."
            $line['modifiers'] = json_encode([[
                'modifier_group_id'   => $this->groupId,
                'modifier_group_name' => 'Steak Side',
                'modifier_id'         => $this->modifierId,
                'name'                => 'French Fries (Steak Side)',
                'price_delta'         => self::SIDE_DELTA,
            ]]);
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
                    'amount'            => $price,
                    'tendered_amount'   => $price,
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

        // ⚠️ Asli Kashif Food ke steaks SERVICE hain, stock-tracked nahi (POS par "Service" ka badge).
        // Fixture ka default stock-tracked tha aur sale "Insufficient stock" par ruk gayi — wo code ki
        // kharabi nahi thi, meri fixture haqeeqat se alag thi.
        $this->steakId = $this->makeProduct($steaks, [
            'name'                  => 'Tarragon Steak (Beef)',
            'default_selling_price' => self::STEAK_PRICE,
            'product_type'          => 'service',
            'product_kind'          => 'service',
            'is_stock_tracked'      => 0,
        ]);

        // Prod par lagne wali shakl: rate 0, POS se chhupa, magar ZINDA (wapasi ka raasta khula).
        $this->sideProductId = $this->makeProduct($steaks, [
            'name'                  => 'French Fries (Steak Side)',
            'default_selling_price' => 0,
            'is_pos_visible'        => 0,
            'is_sellable'           => 1,
            'is_stock_tracked'      => 0,
            'status'                => 'active',
        ]);

        $this->groupId = $c->table('modifier_groups')->insertGetId([
            'branch_id' => null, 'name' => 'Steak Side',
            'min_select' => 0, 'max_select' => 1, 'is_required' => 0,
            'sort_order' => 0, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->modifierId = $c->table('modifiers')->insertGetId([
            'modifier_group_id' => $this->groupId,
            'name'              => 'French Fries (Steak Side)',
            'price_delta'       => self::SIDE_DELTA,
            'linked_product_id' => $this->sideProductId,
            'consume_stock'     => 0,     // stock ko chhuna maqsad nahi
            'is_default'        => 0,
            'sort_order'        => 0,
            'status'            => 'active',
            'created_at'        => now(), 'updated_at' => now(),
        ]);

        // Doosra option — prod par bhi group me dono hain (max_select = 2).
        $this->riceProductId = $this->makeProduct($steaks, [
            'name'                  => 'Vegetable Rice (Steak Side)',
            'default_selling_price' => 0,
            'is_pos_visible'        => 0,
            'is_sellable'           => 1,
            'is_stock_tracked'      => 0,
            'status'                => 'active',
        ]);

        $this->riceModifierId = $c->table('modifiers')->insertGetId([
            'modifier_group_id' => $this->groupId,
            'name'              => 'Vegetable Rice (Steak Side)',
            'price_delta'       => self::RICE_DELTA,
            'linked_product_id' => $this->riceProductId,
            'consume_stock'     => 0,
            'is_default'        => 0,
            'sort_order'        => 1,
            'status'            => 'active',
            'created_at'        => now(), 'updated_at' => now(),
        ]);

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
}
