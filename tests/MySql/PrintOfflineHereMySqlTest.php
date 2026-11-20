<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Api\PrintAgentApiController;
use App\Models\Master\Module;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Printing\PrintHealthService;
use App\Services\Printing\PrintJobService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRINT-OFFLINE-HERE-1 — print agent chup ho to counter apni parchi khud chhaapta hai.
 *
 * Ek live restaurant, 19–20 Nov 2026: dukaan ka internet gaya, akela agent (dukaan ka ek PC) 17:46 par chup hua. Us ke baad
 * har parchi `queued` rahi — na `failed` hui (wo sirf agent likhta hai), na POS ne kuch dikhaya — aur 60
 * minute baad khud band. Raat ki 81 parchiyan, aur agle din Delivery counter ki 18, kabhi nahi niklin.
 *
 * Ye test asal raaste chalate hain: agent ka apna `pending()`, `PrintJobService`, aur HTTP routes ek
 * counter user ke saath jis ke paas sirf POS ki ijazat hai.
 *
 * Doc: docs/plans/print-offline-here-2026-11-20.md
 */
class PrintOfflineHereMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $cashierId;
    private int $branchId;
    private int $terminalId;
    private int $otherTerminalId;
    private int $printerId;
    private int $productId;
    private string $agentCode;
    private string $agentToken = 'poh-agent-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'pohtest.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'pohtest')->delete();
        } catch (\Throwable) {
            // best effort
        }
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Batti: agent zinda hai ya nahi — wohi shart jo agent ka pending() lagata hai
    // ══════════════════════════════════════════════════════════════════════════

    public function test_batti_hari_jab_agent_abhi_bola_aur_laal_15_second_chup_rehne_par(): void
    {
        $this->agentSeen(now()->subSeconds(3));
        $this->assertSame('ok', $this->health()['state'], 'agent 3 s pehle bola — parchi printer tak jayegi');

        $this->agentSeen(now()->subSeconds(14));
        $this->assertSame('ok', $this->health()['state'], '14 s chup — abhi bhi zinda (5 heartbeat miss nahi hue)');

        $this->agentSeen(now()->subSeconds(16));
        $h = $this->health();
        $this->assertSame('offline', $h['state'], '16 s chup — parchi printer tak nahi pahunchegi');
        $this->assertSame('Counter agent', $h['agent']['name']);
        $this->assertGreaterThanOrEqual(16, $h['silent_seconds']);
    }

    public function test_sirf_wohi_agent_ginta_hai_jo_is_counter_ki_parchi_utha_sakta_hai(): void
    {
        $this->agentSeen(now(), ['is_active' => 0]);
        $this->assertSame('offline', $this->health()['state'], 'band (inactive) agent kisi kaam ka nahi');

        $this->agentSeen(now(), ['is_active' => 1, 'branch_id' => $this->makeBranch()]);
        $this->assertSame('offline', $this->health()['state'], 'doosri branch ka agent is branch ki parchi nahi uthata');

        $this->agentSeen(now(), ['branch_id' => $this->branchId, 'terminal_id' => $this->otherTerminalId]);
        $this->assertSame('offline', $this->health()['state'], 'doosre counter se bandha agent is counter ki parchi nahi uthata');

        $this->agentSeen(now(), ['terminal_id' => $this->terminalId]);
        $this->assertSame('ok', $this->health()['state'], 'isi counter se bandha agent');

        $this->agentSeen(now(), ['terminal_id' => null, 'branch_id' => null]);
        $this->assertSame('ok', $this->health()['state'], 'har jagah ka agent');
    }

    /** Ye shart agent ki asal shart se juda nahi ho sakti: jis agent ko batti zinda kahe, wohi parchi uthaye. */
    public function test_batti_ki_shart_agent_ke_pending_se_milti_hai(): void
    {
        $job = $this->queuedJob('kot');

        $this->agentSeen(now(), ['terminal_id' => $this->otherTerminalId]);
        $this->assertSame('offline', $this->health()['state']);
        $this->assertSame([], $this->agentPending(), 'batti laal kehti hai — aur agent ne sach mein parchi nahi uthayi');

        $this->agentSeen(now(), ['terminal_id' => $this->terminalId]);
        $this->assertSame('ok', $this->health()['state']);
        $this->assertSame([$job], $this->agentPending(), 'batti hari kehti hai — aur agent ne parchi utha li');
    }

    public function test_network_printer_hi_na_ho_to_batti_nahi_dikhti(): void
    {
        DB::connection('tenant')->table('printers')->update(['is_active' => 0]);
        $this->agentSeen(now()->subHour());

        $this->assertSame('none', $this->health()['state'], 'bina network printer ke counter par batti ka koi matlab nahi');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Line mein ruki parchiyan — sirf is counter ki, sirf jo browser chhaap sake
    // ══════════════════════════════════════════════════════════════════════════

    public function test_ruki_parchiyon_me_sirf_is_counter_ki_queued_network_parchiyan(): void
    {
        $this->agentSeen(now()->subMinutes(5));
        $sale = $this->sale();

        $receipt = $this->queuedJob('receipt', ['reference_id' => $sale]);
        $kot = $this->queuedJob('kot', ['reference_id' => $sale]);
        $oldLease = $this->queuedJob('kot', ['claimed_at' => now()->subMinutes(3), 'claimed_by_agent_id' => 999]);

        $this->queuedJob('kot', ['terminal_id' => (string) $this->otherTerminalId]);                  // doosra counter
        $this->queuedJob('kot', ['print_status' => 'printed', 'printed_at' => now()]);                 // chhap chuki
        $this->queuedJob('kot', ['print_status' => 'cancelled']);                                      // band
        $this->makePrintJob(null, ['print_status' => 'queued', 'printed_at' => null, 'document_type' => 'kot',
            'branch_id' => $this->branchId, 'terminal_id' => (string) $this->terminalId]);              // bina printer (purana fallback)
        $this->queuedJob('kot', ['claimed_at' => now()->subSeconds(30), 'claimed_by_agent_id' => 999]); // agent chhaap raha hai
        $this->queuedJob('report');                                                                    // browser nahi dikha sakta

        $ids = collect($this->health()['waiting'])->pluck('id')->all();

        $this->assertSame([$kot, $receipt, $oldLease], $ids,
            'sirf is counter ki, network printer wali, queued parchiyan — aur ek order me KOT pehle, bill baad me');
    }

    public function test_batti_hari_ho_to_ruki_parchiyon_ki_fehrist_khaali(): void
    {
        $this->agentSeen(now());
        $this->queuedJob('kot');

        $this->assertSame([], $this->health()['waiting'], 'agent zinda hai — parchi us ke paas hai, counter ko nahi dikhani');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // print-here: network ki line se utha kar counter par
    // ══════════════════════════════════════════════════════════════════════════

    public function test_print_here_kot_ko_printed_karta_hai_bookkeeping_ke_saath_aur_agent_dobara_nahi_uthata(): void
    {
        $this->agentSeen(now()->subMinutes(5));
        $saleId = $this->sale();
        $lineId = $this->makeSaleLine($saleId, $this->productId, ['quantity' => 2, 'line_total' => 200]);
        $sale = SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);

        $jobs = app(PrintJobService::class)->queueKot($sale, \App\Models\Tenant\Printer::on('tenant')->findOrFail($this->printerId), [], (string) $this->terminalId);
        $this->assertCount(1, $jobs);
        $job = $jobs[0]->fresh();
        $this->assertSame('queued', $job->print_status);

        $result = app(PrintJobService::class)->printHere($job, $this->cashierId);
        $this->assertSame('taken', $result);

        $job->refresh();
        $this->assertSame('printed', $job->print_status, 'counter ne chhaapi — printed');
        $this->assertNotNull($job->printed_at);
        $this->assertSame('agent_offline', $job->payload['printed_here']['reason'] ?? null, 'kahan aur kyun chhapi, record me');
        $this->assertSame($this->cashierId, $job->payload['printed_here']['by'] ?? null);
        $this->assertSame(1, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('kot_print_count'),
            'KOT ki ginti wohi jo network print par hoti');
        $this->assertSame(2.0, (float) DB::connection('tenant')->table('sales_order_lines')->where('id', $lineId)->value('kot_sent_quantity'),
            'line kitchen ko bheji ja chuki — dobara KOT par nahi aayegi');

        // Agent wapas aaya: ye parchi us ki line me hai hi nahi.
        $this->agentSeen(now());
        $this->assertSame([], $this->agentPending(), 'agent wapas aa kar counter wali parchi DOBARA nahi chhaapta');
    }

    public function test_agent_pehle_chhaap_chuka_ho_to_counter_dobara_nahi_chhaapta(): void
    {
        $job = PrintJob::on('tenant')->findOrFail($this->queuedJob('kot', ['print_status' => 'printed', 'printed_at' => now()->subSeconds(2)]));
        $printedAt = $job->printed_at->toDateTimeString();

        $this->assertSame('already_printed', app(PrintJobService::class)->printHere($job, $this->cashierId));
        $job->refresh();
        $this->assertSame($printedAt, $job->printed_at->toDateTimeString(), 'kuch nahi badla');
        $this->assertArrayNotHasKey('printed_here', $job->payload ?? []);
    }

    public function test_agent_ne_abhi_uthayi_ho_to_ruko_lease_khatam_ho_to_le_lo(): void
    {
        $busy = PrintJob::on('tenant')->findOrFail($this->queuedJob('kot', ['claimed_at' => now()->subSeconds(30), 'claimed_by_agent_id' => 77]));
        $this->assertSame('in_flight', app(PrintJobService::class)->printHere($busy, $this->cashierId));
        $this->assertSame('queued', $busy->fresh()->print_status, 'chhap rahi ho sakti hai — counter haath na lagaye');

        $stale = PrintJob::on('tenant')->findOrFail($this->queuedJob('kot', ['claimed_at' => now()->subMinutes(3), 'claimed_by_agent_id' => 77]));
        $this->assertSame('taken', app(PrintJobService::class)->printHere($stale, $this->cashierId), '2 minute ka lease guzar gaya — agent mar chuka');
        $this->assertNull($stale->fresh()->claimed_at);
    }

    public function test_band_parchi_print_here_se_nahi_chhapti(): void
    {
        $job = PrintJob::on('tenant')->findOrFail($this->queuedJob('kot', ['print_status' => 'cancelled']));
        $this->assertSame('not_allowed', app(PrintJobService::class)->printHere($job, $this->cashierId));
        $this->assertSame('cancelled', $job->fresh()->print_status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HTTP — counter user, sirf POS ki ijazat
    // ══════════════════════════════════════════════════════════════════════════

    public function test_counter_user_batti_aur_ruki_parchiyan_dekh_sakta_hai_bina_naye_permission_ke(): void
    {
        $this->agentSeen(now()->subMinutes(10));
        $job = $this->queuedJob('kot', ['reference_id' => $this->sale()]);

        $res = $this->asCashier()->getJson('http://' . $this->host . '/api/pos/print-health?terminal_id=' . $this->terminalId);
        $res->assertOk();
        $this->assertSame('offline', $res->json('state'));
        $this->assertSame([$job], collect($res->json('waiting'))->pluck('id')->all());
        $this->assertStringEndsWith('/printing/documents/' . $job . '/preview', $res->json('waiting.0.preview_url'));
    }

    public function test_counter_user_parchi_yahin_le_sakta_hai_doosre_counter_ki_nahi(): void
    {
        $this->agentSeen(now()->subMinutes(10));
        $mine = $this->queuedJob('kot', ['reference_id' => $this->sale()]);
        $theirs = $this->queuedJob('kot', [
            'terminal_id' => (string) $this->otherTerminalId,
            'reference_id' => $this->sale(['terminal_id' => $this->otherTerminalId]),
        ]);

        $res = $this->asCashier()->postJson('http://' . $this->host . '/printing/jobs/' . $mine . '/print-here');
        $res->assertOk();
        $this->assertSame('taken', $res->json('status'));
        $this->assertSame('printed', DB::connection('tenant')->table('print_jobs')->where('id', $mine)->value('print_status'));

        $again = $this->asCashier()->postJson('http://' . $this->host . '/printing/jobs/' . $mine . '/print-here');
        $again->assertOk();
        $this->assertSame('already_printed', $again->json('status'), 'do baar dabaya — doosri baar nahi chhapti');

        $this->asCashier()->postJson('http://' . $this->host . '/printing/jobs/' . $theirs . '/print-here')->assertForbidden();
        $this->assertSame('queued', DB::connection('tenant')->table('print_jobs')->where('id', $theirs)->value('print_status'));
    }

    public function test_pos_par_batti_patti_aur_queue_window_maujood(): void
    {
        $res = $this->asCashier()->get('http://' . $this->host . '/pos');
        $res->assertOk();
        $html = $res->getContent();

        foreach (['id="pos-print-health"', 'id="pos-print-stuck"', 'id="print-here-queue"', 'id="print-here-next-btn"',
                  '/api/pos/print-health', 'window.printHereQueue', 'window.posPrintHealthPoke',
                  'window.printHereQueueDismissed', 'data-print-here-queue'] as $needle) {
            $this->assertStringContainsString($needle, $html, "POS par {$needle} hona chahiye");
        }
    }

    public function test_printers_page_agent_chup_ho_to_sach_bataye(): void
    {
        DB::connection('tenant')->table('printers')->where('id', $this->printerId)->update([
            'last_ping_ok' => 1, 'last_ping_ms' => 4, 'last_ping_at' => now()->subHours(21),
        ]);
        $this->agentSeen(now()->subHours(21), ['device_name' => 'COUNTER-PC']);

        $html = $this->asOwner()->get('http://' . $this->host . '/printing/printers')->assertOk()->getContent();
        $this->assertStringContainsString('id="print-agent-offline-banner"', $html);
        $this->assertStringContainsString('COUNTER-PC', $html);
        $this->assertStringContainsString('Pata nahi', $html);
        $this->assertStringNotContainsString('Online · 4ms', $html, 'agent 21 ghante se chup — "Online" jhoot hai');

        $this->agentSeen(now(), ['device_name' => 'COUNTER-PC']);
        $html = $this->asOwner()->get('http://' . $this->host . '/printing/printers')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="print-agent-offline-banner"', $html, 'agent zinda — patti nahi');
        $this->assertStringContainsString('Online · 4ms', $html);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function health(): array
    {
        return app(PrintHealthService::class)->forTerminal($this->branchId, (string) $this->terminalId);
    }

    /** Ek hi agent, jis ka aakhri rabta $seen par tha. */
    private function agentSeen($seen, array $attrs = []): void
    {
        $c = DB::connection('tenant');
        $row = array_merge([
            'name' => 'Counter agent', 'branch_id' => $this->branchId, 'terminal_id' => null,
            'is_active' => 1, 'last_seen_at' => $seen, 'paired_at' => now()->subDay(), 'updated_at' => now(),
        ], $attrs);

        if ($c->table('print_agents')->where('agent_code', $this->agentCode)->exists()) {
            $c->table('print_agents')->where('agent_code', $this->agentCode)->update($row);

            return;
        }
        $c->table('print_agents')->insert($row + [
            'agent_code' => $this->agentCode, 'token_hash' => Hash::make($this->agentToken), 'created_at' => now(),
        ]);
    }

    /** Agent ka ASAL pending() — jo parchiyan wo is waqt uthata. */
    private function agentPending(): array
    {
        $request = Request::create('/api/print-agent/pending', 'GET');
        $request->headers->set('X-Print-Agent-Code', $this->agentCode);
        $request->headers->set('X-Print-Agent-Token', $this->agentToken);
        DB::setDefaultConnection('tenant');
        $ids = collect(app(PrintAgentApiController::class)->pending($request)->getData(true)['jobs'])->pluck('id')->all();
        // pending() parchi CLAIM karta hai — agla sawal saaf hath se ho.
        DB::connection('tenant')->table('print_jobs')->whereIn('id', $ids)->update(['claimed_at' => null, 'claimed_by_agent_id' => null]);

        return $ids;
    }

    private function queuedJob(string $type, array $attrs = []): int
    {
        return $this->makePrintJob($this->printerId, array_merge([
            'document_type' => $type, 'print_status' => 'queued', 'printed_at' => null, 'attempts' => 0,
            'branch_id' => $this->branchId, 'terminal_id' => (string) $this->terminalId,
            'reference_type' => 'sales_order', 'payload' => json_encode(['sales_order_id' => null]),
            'raw_payload' => "SLIP\n",
        ], $attrs));
    }

    private function sale(array $attrs = []): int
    {
        return $this->makeSale($this->branchId, array_merge([
            'terminal_id' => $this->terminalId, 'status' => 'held', 'order_type' => 'takeaway',
        ], $attrs));
    }

    private function asCashier()
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($this->cashierId), 'tenant');
    }

    private function asOwner()
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant');
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'pohtest')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'pohtest', 'business_name' => 'Print Offline Here',
            'owner_name' => 'Owner', 'owner_email' => 'owner@pohtest.test',
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

        $planId = $m->table('plans')->where('code', 'pohtest-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'pohtest-plan', 'name' => 'Print Offline Here', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        // Module MOJOOD ho to plan me daalo — BANAO kabhi nahi (master DB sanjhi hai).
        foreach (['tenant.pos.index', 'tenant.printing.printers.index', 'tenant.printing.jobs.index'] as $routeName) {
            $key = $m->table('route_catalogs')->where('route_name', $routeName)->value('module_key');
            if (! $key) {
                continue;
            }
            $module = Module::forRouteModuleKey($key)->first();
            if (! $module) {
                continue;
            }
            $m->table('plan_modules')->updateOrInsert(['plan_id' => $planId, 'module_id' => $module->id], ['is_enabled' => 1]);
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
            'print_jobs', 'print_agents', 'kot_batch_lines', 'kot_batches', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'terminal_user', 'model_has_roles', 'users', 'products', 'categories', 'printers', 'terminals', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $counterRole = $c->table('roles')->where('name', 'POH Counter')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => 'POH Counter', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);

        foreach (['tenant.pos.index', 'tenant.printing.printers.index'] as $routeName) {
            $c->table('permissions')->updateOrInsert(['name' => $routeName, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }
        // Counter: SIRF POS. Naye raaste naya permission maangte to ye user 403 khata.
        $c->table('role_has_permissions')->where('role_id', $counterRole)->delete();
        $c->table('role_has_permissions')->insert([
            'permission_id' => $c->table('permissions')->where('name', 'tenant.pos.index')->where('guard_name', 'tenant')->value('id'),
            'role_id' => $counterRole,
        ]);

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'PohOwner', 'email' => 'owner@pohtest.test', 'password' => bcrypt('x'),
            'employee_code' => 'POHOWN', 'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId]);

        $this->branchId = $this->makeBranch(['status' => 'active']);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => '3-Delivery']);
        $this->otherTerminalId = $this->makeTerminal($this->branchId, ['name' => '4-Dine In']);
        $this->printerId = $this->makePrinter(['branch_id' => $this->branchId, 'name' => 'Delivery Receipt + KOT', 'print_role' => 'both', 'ip_address' => '192.168.100.206']);
        $this->productId = $this->makeProduct(
            $this->makeCategory(['name' => 'Biryani', 'slug' => 'biryani-' . Str::random(4)]),
            ['product_type' => 'service', 'product_kind' => 'service', 'is_stock_tracked' => 0]
        );

        $this->cashierId = $c->table('users')->insertGetId([
            'name' => 'Delivery Counter', 'email' => 'delivery@pohtest.test', 'password' => bcrypt('x'),
            'employee_code' => 'POHDEL', 'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $counterRole, 'model_type' => User::class, 'model_id' => $this->cashierId]);
        // Counter user apne counter se bandha — doosre counter ki parchi par 403.
        $c->table('terminal_user')->insert(['terminal_id' => $this->terminalId, 'user_id' => $this->cashierId, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->agentCode = 'AGT-POH-' . Str::random(5);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
    }
}
