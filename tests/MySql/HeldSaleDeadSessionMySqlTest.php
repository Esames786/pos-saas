<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\Branch;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Tenant\Terminal;
use App\Services\Sales\ShiftService;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * HELD-SALE-DEAD-SESSION-1 — band table par bill hold ho jata hai, phir kabhi pay nahi hota.
 *
 * 12 Sep 2026, Kashif Food, table 9 (First Floor):
 *
 *     22:07:33   session 2147 khuli
 *     22:08:03   session 2147 BAND kar di gayi
 *     22:08:28   cashier ne Hold dabaya -> bill us BAND session par likh diya gaya
 *
 * Bill `HS-20260912170828-631` (Rs 2,465) us ke baad na pay ho saka na table board par dikha.
 * Cashier ko ye mila: "No query results for model [RestaurantTableSession] 2147".
 *
 * Sabab: Hold aur Pay ALAG shartein lagate hain.
 *
 *     HeldSaleController:419-423   session id di gayi ho   -> koi status check NAHI
 *     HeldSaleController:424-430   table se khud dhoondhe  -> open/bill_requested
 *     SalesOrderController:303-308 Pay                     -> open/bill_requested
 *
 * Yani band session par bill BAN sakta hai magar PAY kabhi nahi. Wo 6 satar ka farq hi poora marz hai.
 *
 * ⚠️ Validation is ko nahi rok sakti: rule `exists:restaurant_table_sessions,id` hai — band session
 * bhi "mojood" hai, is liye wo aaram se guzar jati hai. Rokna controller me hi parega.
 *
 * Doc: docs/plans/held-sale-dead-session-2026-09-12.md
 */
class HeldSaleDeadSessionMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $branchId;
    private int $terminalId;
    private int $tableId;
    private int $productId;
    private int $shiftId;
    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'deadsession.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'deadsession')->delete();
        } catch (\Throwable) {
            // best effort; asli nateeja kabhi na chhupe
        }
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // P1 — band session par HOLD rad ho
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Asal guard. Ye wo lamha hai jahan 12 Sep ko system ne "haan" kaha jahan "nahi" kehna tha.
     *
     * ⚠️ Sirf status code dekhna KAFI NAHI. Agar bill ban jaye aur controller baad me kisi aur wajah
     * se 422 de, to status-only assertion pass karti rahegi aur anaath bill roz banta rahega.
     * Is liye `sales_orders` ki GINTI pehle aur baad me barabar honi chahiye.
     */
    public function test_band_session_par_hold_rad_hota_hai_aur_koi_bill_nahi_banta(): void
    {
        $session = $this->openSession();
        $this->closeSession($session);

        $pehle = DB::connection('tenant')->table('sales_orders')->count();

        $res = $this->hold(['restaurant_table_session_id' => $session->id]);

        $this->assertSame(422, $res->getStatusCode(),
            'band session par hold rad hona chahiye; mila ' . $res->getStatusCode() . ' — ' . $this->kyunRada($res));

        $baad = DB::connection('tenant')->table('sales_orders')->count();
        $this->assertSame($pehle, $baad,
            'band session par koi sales_order BANNA hi nahi chahiye — warna wo bill hamesha ke liye phans jata hai');
    }

    /** Us paighaam me cashier ko agla qadam nazar aana chahiye, warna wo phone uthayega. */
    public function test_hold_ke_inkaar_me_agla_qadam_likha_hota_hai(): void
    {
        $session = $this->openSession();
        $this->closeSession($session);

        $res = $this->hold(['restaurant_table_session_id' => $session->id]);
        $matn = $res->getContent();

        $this->assertMatchesRegularExpression('/closed|band/i', $matn,
            'paighaam me batana chahiye ke table band ho chuki hai');
        $this->assertMatchesRegularExpression('/open|khol/i', $matn,
            'paighaam me agla qadam ho — table dobara kholein');
    }

    /** P1 kisi JAIZ hold ko na roke. Ye guard 1 ko be-maani hone se bachata hai. */
    public function test_khuli_session_par_hold_pehle_jaisa_chalta_hai(): void
    {
        $session = $this->openSession();

        $res = $this->hold(['restaurant_table_session_id' => $session->id]);

        $this->assertContains($res->getStatusCode(), [200, 201, 302],
            'khuli session par hold chalna chahiye; mila ' . $res->getStatusCode() . ' — ' . $this->kyunRada($res));
        $this->assertSame(1,
            DB::connection('tenant')->table('sales_orders')->where('restaurant_table_session_id', $session->id)->count(),
            'khuli session par bill banna chahiye');
    }

    /**
     * `bill_requested` bhi ZINDA haalat hai — mehmaan ne bill manga hai, table band nahi hui.
     * Agar shart sirf `open` par lagayi to bill mangne ke baad aakhri round add karna namumkin ho jata.
     */
    public function test_bill_requested_session_par_bhi_hold_chalta_hai(): void
    {
        $session = $this->openSession();
        DB::connection('tenant')->table('restaurant_table_sessions')
            ->where('id', $session->id)->update(['status' => 'bill_requested']);

        $res = $this->hold(['restaurant_table_session_id' => $session->id]);

        $this->assertContains($res->getStatusCode(), [200, 201, 302],
            'bill_requested zinda haalat hai, hold chalna chahiye; mila ' . $res->getStatusCode()
            . ' — ' . $this->kyunRada($res));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // P2 — band session par PAY ka paighaam insani ho
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Jo cashier ne 12 Sep ko screen par dekha:
     *
     *     No query results for model [App\Models\Tenant\RestaurantTableSession] 2147
     *
     * Ye `findOrFail` ka raw exception hai. Usi function me `:323` par ek theek paighaam mojood hai
     * magar wo sirf doosre branch me chalta hai.
     */
    public function test_band_session_par_pay_raw_exception_nahi_deta(): void
    {
        $session = $this->openSession();
        $saleId  = $this->punchHeld($session);
        $this->closeSession($session, force: true);

        $res = $this->pay($session->id, $saleId);
        $matn = $res->getContent();

        $this->assertStringNotContainsString('No query results for model', $matn,
            'cashier ko Laravel ka raw exception nahi dikhna chahiye');
        $this->assertStringNotContainsString('RestaurantTableSession', $matn,
            'model ka class naam cashier ki screen par nahi jana chahiye');
        $this->assertMatchesRegularExpression('/closed|band/i', $matn,
            'paighaam batana chahiye ke table band ho chuki hai');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // P4 — table board sach bole
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * `openSession()` `latestOfMany()` istemal karta hai. Us ka andruni `MAX(id)` subquery UPAR wali
     * `whereIn('status')` shart ko NAHI ginta. Yani wo "sab se nayi KHULI session" nahi dhoondta,
     * balki "sab se nayi session" utha kar phir poochta hai ke khuli hai ya nahi.
     *
     * Nateeja: ek khuli session ke OOPAR agar koi naye id wali BAND session pari ho, to board us table
     * ko KHALI dikhata hai — halanke bill wahin mojood hota hai. Ye maine 12 Sep ki raat live dekha:
     * bachaya hua bill table 20 par bheja aur board ne table khali dikhai.
     *
     * ⚠️ Is guard ke liye id ki tarteeb JAAN-BOOJH kar ulti banani parti hai (pehle khuli, phir band)
     * — warna ye kabhi RED nahi hoga.
     */
    public function test_nayi_band_session_purani_khuli_wali_ko_nahi_chhupati(): void
    {
        $khuli = $this->openSession();                       // chhota id, ZINDA
        $band  = $this->openSession(sameTable: true);        // bara id
        $this->closeSession($band, force: true);             // ...magar band

        $table = RestaurantTable::on('tenant')->with('openSession')->find($this->tableId);

        $this->assertNotNull($table->openSession,
            'board ko khuli session milni chahiye — warna table par bill hote hue bhi wo "available" dikhega');
        $this->assertSame((int) $khuli->id, (int) $table->openSession->id,
            'openSession() ko ZINDA session lautani chahiye, sirf sab se nayi nahi');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Madadgar
    // ══════════════════════════════════════════════════════════════════════════

    private function openSession(bool $sameTable = false): RestaurantTableSession
    {
        DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)
            ->update(['status' => 'occupied']);

        $id = DB::connection('tenant')->table('restaurant_table_sessions')->insertGetId([
            'session_no'          => 'TS-' . Str::upper(Str::random(10)),
            'branch_id'           => $this->branchId,
            'restaurant_table_id' => $this->tableId,
            'status'              => 'open',
            'guest_count'         => 1,
            'opened_by_user_id'   => $this->ownerId,
            'opened_at'           => now(),
            'business_date'       => now()->toDateString(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return RestaurantTableSession::on('tenant')->findOrFail($id);
    }

    /**
     * `force` = seedhe DB par band karo.
     *
     * Wajah: asli `close()` us session ko band karne se INKAAR karta hai jis par held order ho
     * (`RestaurantTableSessionController:241`) — aur wo guard bilkul theek hai. Magar 12 Sep wali
     * soorat me bill session BAND hone ke BAAD aaya tha, jo us guard se bach nikalta hai.
     * Is liye us haalat ko banane ke liye seedha DB chahiye.
     */
    private function closeSession(RestaurantTableSession $session, bool $force = false): void
    {
        DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $session->id)->update([
            'status'            => 'closed',
            'closed_at'         => now(),
            'closed_by_user_id' => $this->ownerId,
            'updated_at'        => now(),
        ]);
        DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)
            ->update(['status' => 'available']);
    }

    private function punchHeld(RestaurantTableSession $session): int
    {
        $saleId = $this->makeSale($this->branchId, [
            'status'                      => 'held',
            'order_type'                  => 'dine_in',
            'restaurant_table_session_id' => $session->id,
            'restaurant_table_id'         => $this->tableId,
            'terminal_id'                 => $this->terminalId,
            'shift_id'                    => $this->shiftId,
            'grand_total'                 => 750,
        ]);
        $this->makeSaleLine($saleId, $this->productId, ['quantity' => 1, 'unit_price' => 750, 'line_total' => 750]);

        return $saleId;
    }

    private function hold(array $extra = [])
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->postJson('http://' . $this->host . '/held-sales', array_merge([
                'branch_id'     => $this->branchId,
                'terminal_id'   => $this->terminalId,
                'order_type'    => 'dine_in',
                'discount_type' => 'none',
                'lines'         => [[
                    'product_id' => $this->productId,
                    'quantity'   => 1,
                    'unit_price' => 750,
                ]],
            ], $extra));
    }

    private function pay(int $sessionId, int $heldSaleId)
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->postJson('http://' . $this->host . '/sales-orders', [
                'branch_id'                   => $this->branchId,
                'terminal_id'                 => $this->terminalId,
                'order_type'                  => 'dine_in',
                'discount_type'               => 'none',
                'restaurant_table_session_id' => $sessionId,
                'held_sale_id'                => $heldSaleId,
                'lines'                       => [[
                    'product_id' => $this->productId,
                    'quantity'   => 1,
                    'unit_price' => 750,
                ]],
                // ⚠️ Bina `payments` ke validation session ki jaanch tak pahunchti hi nahi
                // ("The payments field is required.") — guard us se pehle mar jata hai.
                'payments'                    => [[
                    'payment_method_id' => $this->paymentMethodId,
                    'amount'            => 750,
                    'tendered_amount'   => 750,
                ]],
            ]);
    }

    /** 422/403 ke safhe se asli wajah nikalo — 400 characters CSS nahi. */
    private function kyunRada($res): string
    {
        $matn = trim(preg_replace('/\s+/', ' ', strip_tags($res->getContent())));

        return Str::limit($matn, 300);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Seed
    // ══════════════════════════════════════════════════════════════════════════

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'deadsession')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'deadsession', 'business_name' => 'Dead Session',
            'owner_name' => 'Owner', 'owner_email' => 'owner@deadsession.test',
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

        $planId = $m->table('plans')->where('code', 'deadsession-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'deadsession-plan', 'name' => 'Dead Session', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        // Jin routes par ye test chalta hai, un sab ke modules chalu hone chahiyen — warna
        // EnsureTenantSubscriptionAccess pehle hi 403 de dega aur guard kuch sabit nahi karega.
        foreach (['tenant.pos.index', 'tenant.held-sales.store', 'tenant.sales-orders.store'] as $routeName) {
            $key = $m->table('route_catalogs')->where('route_name', $routeName)->value('module_key');
            if (! $key) {
                continue; // unmapped route = fail-open
            }
            $module = Module::forRouteModuleKey($key)->first();

            // ⚠️ Yahan pehle `assertNotNull($module, ...)` tha aur wo BHURBHURA nikla.
            // Master DB SANJHI hai aur us ka module-landscape har run me badalta rehta hai: kabhi
            // koi doosra test `tenant.held-sales` ka dawedar module bana chuka hota hai, kabhi nahi.
            // Us assertion ki wajah se ye poora test file kisi din bilkul be-taalluq wajah se gir
            // jata tha. Sahi bartaao: mojooda haalat ke mutabiq DHALO —
            //   dawedar hai   -> plan me shamil karo (gate fail-CLOSED hai)
            //   dawedar nahi  -> kuch mat karo   (gate fail-OPEN hai)
            // Aur sanjhi DB me module BANAO kabhi nahi — us se doosre tests ka gate badal jata hai.
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
        // permissions/roles tenant MIGRATION se aate hain — inhen kabhi truncate na karo.
        $this->cleanTenant([
            'sales_order_lines', 'sales_orders', 'restaurant_table_sessions',
            'restaurant_tables', 'restaurant_floors', 'model_has_roles', 'users',
            'products', 'categories', 'terminals', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        // EnsureRoutePermission ROUTE KE NAAM par gate karta hai, is liye row ka hona lazmi hai —
        // asli tenant me ye migration + routes-sync banate hain, jo ye harness nahi chalata.
        foreach (['tenant.pos.index', 'tenant.held-sales.store', 'tenant.sales-orders.store'] as $routeName) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $routeName, 'guard_name' => 'tenant'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'DeadOwner', 'email' => 'owner@deadsession.test', 'password' => bcrypt('x'),
            'employee_code' => 'DSOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->branchId   = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->tableId    = $this->makeTable($this->branchId, ['table_no' => '9']);
        $this->productId  = $this->makeProduct(
            $this->makeCategory(['name' => 'Food', 'slug' => 'food-' . Str::random(4)])
        );

        // ⚠️ Bina KHULI SHIFT ke POS kuch nahi karta (mandatory-open-shift live hai). Pehli
        // koshish me ye rah gayi thi aur HAR guard 302 par gir gaya — control case bhi. Jab
        // control case gire to kharabi code me nahi, harness me hoti hai.
        $this->paymentMethodId = $this->makePaymentMethod();

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
