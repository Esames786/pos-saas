<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\Branch;
use App\Models\Tenant\RestaurantTableSession;
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
 * HELD-SALE-DEAD-SESSION-1 / P3 — anaath bill ke liye cashier ka nikalne ka raasta.
 *
 * P1 bimari rokta hai (band session par bill banega hi nahi). Ye uska ILAJ hai: agar koi bill phir
 * bhi anaath ho jaye — ya P1 se pehle ban chuka ho — to cashier POS se hi usay kisi KHALI table par
 * le ja sake, bajaye is ke ke wo cancel kare (7 Sep, Rs 4,300) ya koi database me jaye (12 Sep,
 * Rs 2,465).
 *
 * Sab se bara khatra yahan YE hai: bill kisi OCCUPIED table par chala jaye. Tab do alag customers ka
 * bill ek hi check me mil jata hai — ye asal masle se kahin bura hai. Is file ka aadha hissa usi ek
 * cheez ko rokne ka imtihan hai.
 *
 * Dhaancha `RestaurantTableSessionController::merge()` se liya gaya hai — wo pehle se yehi kaam
 * (orders ko doosri session par le jana) prod par kar raha hai, us ke locks ki tarteeb samet.
 *
 * Doc: docs/plans/held-sale-dead-session-2026-09-12.md
 */
class HeldSaleReattachTableMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $branchId;
    private int $terminalId;
    private int $deadTableId;     // jahan bill phansa
    private int $freeTableId;     // jahan le jana hai
    private int $busyTableId;     // jahan le jana MANA hai
    private int $productId;
    private int $shiftId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'reattach.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'reattach')->delete();
        } catch (\Throwable) {
            // best effort
        }
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Kaam karta hai
    // ══════════════════════════════════════════════════════════════════════════

    /** Asal maqsad: anaath bill khali table par chala jaye aur wahan qabil-e-adaigi ho. */
    public function test_anaath_bill_khali_table_par_chala_jata_hai(): void
    {
        [$saleId, $deadSession] = $this->orphanBill();

        $res = $this->reattach($saleId, $this->freeTableId);

        $this->assertContains($res->getStatusCode(), [200, 201],
            'reattach chalna chahiye; mila ' . $res->getStatusCode() . ' — ' . $this->kyun($res));

        $sale = DB::connection('tenant')->table('sales_orders')->find($saleId);
        $this->assertSame($this->freeTableId, (int) $sale->restaurant_table_id,
            'bill nayi table par hona chahiye');
        $this->assertNotSame((int) $deadSession->id, (int) $sale->restaurant_table_session_id,
            'bill purani mari hui session par nahi rehna chahiye');

        $naya = DB::connection('tenant')->table('restaurant_table_sessions')
            ->find($sale->restaurant_table_session_id);
        $this->assertSame('open', $naya->status, 'nayi session khuli honi chahiye');
        $this->assertSame($this->freeTableId, (int) $naya->restaurant_table_id);
    }

    /**
     * NAYI session banni chahiye, purani ZINDA nahi honi chahiye.
     *
     * Wajah sirf safai nahi: `openSession()` us table ki sab se BARE id wali zinda session dhoondta
     * hai. Purani session ka id chhota hota hai, to agar us table par koi naye id wali band session
     * pari ho to board us bill ko dikhata hi nahi. Ye 12 Sep ki raat live hua tha.
     */
    public function test_purani_session_zinda_nahi_ki_jati_balke_nayi_banti_hai(): void
    {
        [$saleId, $deadSession] = $this->orphanBill();

        $this->reattach($saleId, $this->freeTableId);

        $purani = DB::connection('tenant')->table('restaurant_table_sessions')->find($deadSession->id);
        $this->assertSame('closed', $purani->status,
            'purani session band hi rehni chahiye — us ka record sach hai');
        $this->assertNotNull($purani->closed_at,
            'purani session ka closed_at mit nahi'."'".'na chahiye');
        $this->assertSame($this->deadTableId, (int) $purani->restaurant_table_id,
            'purani session apni asli table par hi rahe');
    }

    /** Reattach ke baad table board ko wo bill nazar aana chahiye — warna cashier phir dhoondta rahega. */
    public function test_reattach_ke_baad_board_par_bill_nazar_aata_hai(): void
    {
        [$saleId] = $this->orphanBill();

        $this->reattach($saleId, $this->freeTableId);

        $table = \App\Models\Tenant\RestaurantTable::on('tenant')
            ->with('openSession.salesOrders')->find($this->freeTableId);

        $this->assertNotNull($table->openSession, 'board ko nayi session milni chahiye');
        $this->assertSame('occupied', $table->status, 'table occupied honi chahiye');
        $this->assertTrue(
            $table->openSession->salesOrders->contains('id', $saleId),
            'us session par wohi bill hona chahiye'
        );
    }

    /** Bill ka floor bhi nayi table ka ho — `merge()` bhi yehi karta hai, aur mai ye bhool sakta tha. */
    public function test_bill_ka_floor_bhi_nayi_table_ka_ho_jata_hai(): void
    {
        [$saleId] = $this->orphanBill();

        $this->reattach($saleId, $this->freeTableId);

        $sale  = DB::connection('tenant')->table('sales_orders')->find($saleId);
        $table = DB::connection('tenant')->table('restaurant_tables')->find($this->freeTableId);

        $this->assertSame((int) $table->restaurant_floor_id, (int) $sale->restaurant_floor_id,
            'bill ka floor nayi table ke floor se mel khana chahiye');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Jo NAHI hona chahiye — yahan asli khatra hai
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * SAB SE AHEM GUARD.
     *
     * Occupied table par bill bhejna ka matlab hai do alag customers ka bill ek check me mil jana.
     * Screen sirf khali tables dikhati hai, magar screen par bharosa nahi kiya ja sakta: wo tab bani
     * thi jab table khali thi, aur click tak kisi aur counter ne wahan mehmaan bitha diye hon ge.
     * Is liye faisla server par, lock ke andar, us lamhe ki haalat par hota hai.
     */
    public function test_occupied_table_par_bill_nahi_ja_sakta(): void
    {
        [$saleId] = $this->orphanBill();
        $busySession = $this->openSessionOn($this->busyTableId);
        $doosraBill  = $this->heldOn($busySession, $this->busyTableId, 999);

        $res = $this->reattach($saleId, $this->busyTableId);

        $this->assertSame(422, $res->getStatusCode(),
            'occupied table par reattach rad hona chahiye; mila ' . $res->getStatusCode() . ' — ' . $this->kyun($res));

        // Aur us table ka apna check bilkul be-harkat rahe.
        $ginti = DB::connection('tenant')->table('sales_orders')
            ->where('restaurant_table_session_id', $busySession->id)->count();
        $this->assertSame(1, $ginti,
            'doosre customer ke check me koi nayi cheez nahi aani chahiye');
        $this->assertSame($busySession->id,
            (int) DB::connection('tenant')->table('sales_orders')->find($doosraBill)->restaurant_table_session_id,
            'us ka apna bill apni jagah rahe');
    }

    /** Jis bill ki session ZINDA ho us par ye raasta nahi — wo `move()` ka kaam hai. */
    public function test_zinda_session_wale_bill_par_reattach_mana_hai(): void
    {
        $session = $this->openSessionOn($this->deadTableId);
        $saleId  = $this->heldOn($session, $this->deadTableId, 750);

        $res = $this->reattach($saleId, $this->freeTableId);

        $this->assertSame(422, $res->getStatusCode(),
            'zinda session wale bill par reattach nahi chalna chahiye; mila ' . $res->getStatusCode()
            . ' — ' . $this->kyun($res));
    }

    /** ADA HO CHUKA bill kabhi na hile — `merge()` ka usool: "paid fiscal history stays". */
    public function test_ada_ho_chuka_bill_nahi_hilta(): void
    {
        $session = $this->openSessionOn($this->deadTableId);
        $saleId  = $this->heldOn($session, $this->deadTableId, 750);
        DB::connection('tenant')->table('sales_orders')->where('id', $saleId)
            ->update(['status' => 'paid', 'payment_status' => 'paid']);
        $this->killSession($session);

        $res = $this->reattach($saleId, $this->freeTableId);

        $this->assertSame(422, $res->getStatusCode(),
            'paid bill par reattach nahi chalna chahiye; mila ' . $res->getStatusCode() . ' — ' . $this->kyun($res));

        $sale = DB::connection('tenant')->table('sales_orders')->find($saleId);
        $this->assertSame((int) $session->id, (int) $sale->restaurant_table_session_id,
            'paid bill apni asli session par hi rehna chahiye');
    }

    /** Doosre branch ka bill nazar hi na aaye. */
    public function test_doosre_branch_ka_bill_chhua_nahi_ja_sakta(): void
    {
        $doosraBranch = $this->makeBranch(['name' => 'Doosri Branch']);
        $doosriTable  = $this->makeTable($doosraBranch, ['table_no' => 'X1']);
        $session      = $this->openSessionOn($doosriTable, $doosraBranch);
        $saleId       = $this->heldOn($session, $doosriTable, 500, $doosraBranch);
        $this->killSession($session);

        $res = $this->reattach($saleId, $this->freeTableId);

        $this->assertContains($res->getStatusCode(), [403, 404, 422],
            'doosre branch ke bill par kaam nahi chalna chahiye; mila ' . $res->getStatusCode()
            . ' — ' . $this->kyun($res));
    }

    /** Audit: nayi session par likha ho ke ye bill kahan se aaya — `merge()` bhi yehi karta hai. */
    public function test_nayi_session_par_audit_likha_hota_hai(): void
    {
        [$saleId, $deadSession] = $this->orphanBill();

        $this->reattach($saleId, $this->freeTableId);

        $sale  = DB::connection('tenant')->table('sales_orders')->find($saleId);
        $naya  = DB::connection('tenant')->table('restaurant_table_sessions')
            ->find($sale->restaurant_table_session_id);

        $this->assertNotEmpty($naya->notes, 'nayi session par audit ki satar honi chahiye');
        $this->assertStringContainsString($deadSession->session_no, $naya->notes,
            'audit me purani session ka number hona chahiye');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Madadgar
    // ══════════════════════════════════════════════════════════════════════════

    /** 12 Sep wali soorat: session pehle band hui, bill baad me us se chipak gaya. */
    private function orphanBill(): array
    {
        $session = $this->openSessionOn($this->deadTableId);
        $saleId  = $this->heldOn($session, $this->deadTableId, 2465);
        $this->killSession($session);

        return [$saleId, $session];
    }

    private function openSessionOn(int $tableId, ?int $branchId = null): RestaurantTableSession
    {
        DB::connection('tenant')->table('restaurant_tables')->where('id', $tableId)
            ->update(['status' => 'occupied']);

        $id = DB::connection('tenant')->table('restaurant_table_sessions')->insertGetId([
            'session_no'          => 'TS-' . Str::upper(Str::random(10)),
            'branch_id'           => $branchId ?? $this->branchId,
            'restaurant_table_id' => $tableId,
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
     * ⚠️ Seedha DB par band karo, `close()` se nahi.
     *
     * Asli `close()` us session ko band karne se INKAAR karta hai jis par held order ho — aur wo
     * guard bilkul theek hai. Magar jo soorat hum bana rahe hain us me bill session BAND hone ke
     * BAAD aaya tha, jo us guard se bach nikalta hai. Us haalat ko banane ke liye seedha DB hi hai.
     */
    private function killSession(RestaurantTableSession $session): void
    {
        DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $session->id)->update([
            'status'            => 'closed',
            'closed_at'         => now(),
            'closed_by_user_id' => $this->ownerId,
            'updated_at'        => now(),
        ]);
        DB::connection('tenant')->table('restaurant_tables')
            ->where('id', $session->restaurant_table_id)->update(['status' => 'available']);
    }

    private function heldOn(RestaurantTableSession $session, int $tableId, float $total, ?int $branchId = null): int
    {
        $saleId = $this->makeSale($branchId ?? $this->branchId, [
            'status'                      => 'held',
            'order_type'                  => 'dine_in',
            'restaurant_table_session_id' => $session->id,
            'restaurant_table_id'         => $tableId,
            'terminal_id'                 => $this->terminalId,
            'shift_id'                    => $this->shiftId,
            'grand_total'                 => $total,
        ]);
        $this->makeSaleLine($saleId, $this->productId, [
            'quantity' => 1, 'unit_price' => $total, 'line_total' => $total,
        ]);

        return $saleId;
    }

    private function reattach(int $saleId, int $tableId)
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->postJson('http://' . $this->host . '/held-sales/' . $saleId . '/reattach-table', [
                'restaurant_table_id' => $tableId,
                'terminal_id'         => $this->terminalId,
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
        $master->table('tenants')->where('tenant_code', 'reattach')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'reattach', 'business_name' => 'Reattach',
            'owner_name' => 'Owner', 'owner_email' => 'owner@reattach.test',
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

        $planId = $m->table('plans')->where('code', 'reattach-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'reattach-plan', 'name' => 'Reattach', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        foreach (['tenant.pos.index', 'tenant.held-sales.store', 'tenant.held-sales.reattach-table'] as $routeName) {
            $key = $m->table('route_catalogs')->where('route_name', $routeName)->value('module_key');
            if (! $key) {
                continue; // unmapped route = fail-open
            }
            $module = Module::forRouteModuleKey($key)->first();
            $this->assertNotNull($module,
                "route [{$routeName}] [{$key}] se juda hai magar koi module us ka dawedar nahi — "
                . 'ye test 403 par khatam hota aur kuch sabit na karta');
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

        // ⚠️ EnsureRoutePermission ROUTE KE NAAM par gate karta hai. `reattach-table` ek NAYA route
        // hai — asli tenant me ye row migration + routes-sync banate hain, aur `deploy.sh` usay sirf
        // OWNER ko deta hai. Cashier roles ko deploy ke baad additive `givePermissionTo` chahiye,
        // warna cashier ko wahi 403 milega jis se bachne ke liye ye feature bana hai.
        foreach (['tenant.pos.index', 'tenant.held-sales.store', 'tenant.held-sales.reattach-table'] as $routeName) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $routeName, 'guard_name' => 'tenant'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'ReOwner', 'email' => 'owner@reattach.test', 'password' => bcrypt('x'),
            'employee_code' => 'REOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->branchId    = $this->makeBranch();
        $this->terminalId  = $this->makeTerminal($this->branchId);
        $this->deadTableId = $this->makeTable($this->branchId, ['table_no' => '9']);
        $this->freeTableId = $this->makeTable($this->branchId, ['table_no' => '20']);
        $this->busyTableId = $this->makeTable($this->branchId, ['table_no' => '12']);
        $this->productId   = $this->makeProduct(
            $this->makeCategory(['name' => 'Food', 'slug' => 'food-' . Str::random(4)])
        );

        // POS bina KHULI SHIFT ke kuch nahi karta.
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
