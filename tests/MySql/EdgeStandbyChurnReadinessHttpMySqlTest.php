<?php

namespace Tests\MySql;

use App\Models\Tenant\Printer;
use App\Models\Tenant\RestaurantTable;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeBaselineIssuanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeCloudBridgeFixture;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE OFFLINE-READINESS FIX (24 Nov 2026) — the Khatri defect, reproduced end to end with real code on both ends and two
 * databases (EdgeCloudBridgeFixture): a busy restaurant's warm standby must be able to take over (supervised "Switch to
 * Offline") when the internet drops, with config + stock baseline current up to the cut.
 *
 * Production showed `blocked: STOCK_AUTHORITY_READY, AUTHORITY_TAKEOVER_SAFE, STANDBY_FRESH_ENOUGH` while online, and
 * STOCK_AUTHORITY_READY has no supervisor override. Two Cloud defects, pinned separately:
 *
 *   A. the Cloud issued a stock baseline the appliance must refuse whole — it named purchase-only stock the appliance does not
 *      hold (BASELINE_UNKNOWN_PRODUCT) or, for a branch without official stock, had no items at all (BASELINE_EMPTY);
 *   B. print-agent heartbeats (printer health) and dine-in table occupancy minted a config revision on almost every tick, so an
 *      outage in the middle of a tick left the accepted baseline at the previous revision (no override) — takeover refused.
 *
 * Split-brain safety is untouched and asserted: inside the lease the Cloud still writes and the takeover gate stays closed; only
 * after the lease lapsed on the appliance clock may the supervisor switch.
 */
class EdgeStandbyChurnReadinessHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeCloudBridgeFixture;

    private const CONFIG_TABLES = ['branches', 'users', 'categories', 'products', 'terminals', 'payment_methods', 'printers', 'restaurant_floors', 'restaurant_tables'];

    private int $branchId;
    private int $categoryId;
    private int $menuItemId;      // a dish: sellable, not stock-tracked (Khatri after 21 Oct: "stock abhi kisi ka track nh krna ha")
    private int $raitaId;         // a sellable, stock-tracked item (used where the branch does track stock)
    private int $kotPrinterId;
    private int $receiptPrinterId;
    private int $tableA;
    private int $tableB;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->provisionTwoDatabases();
        Carbon::setTestNow(Carbon::now()->startOfSecond());

        // ── the Cloud's truth: a restaurant branch with KOT + receipt printers and a dining floor ──
        // unit_conversions too: they are tenant-global and shipped whole, and classes that clear `units` with FK checks off
        // (cleanTenant) can leave conversions pointing at deleted units, which the appliance's FK then refuses on refresh.
        $this->cleanTenant(['edge_branch_authority_leases', 'stock_ledgers', 'stock_balances', 'inventory_batches', 'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors',
            'category_printer_mappings', 'terminal_printer_settings', 'printers', 'recipe_ingredients', 'recipes', 'unit_conversions', 'payment_methods', 'products', 'categories', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['name' => 'Khatri-like Branch', 'allow_negative_stock' => 0]);
        $user = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CH' . Str::random(4)]);
        $this->makeTerminal($this->branchId);
        $this->makePaymentMethod(['method_type' => 'cash']);
        $this->categoryId = $this->makeCategory();
        $this->menuItemId = $this->makeProduct($this->categoryId, ['name' => 'Chicken Biryani', 'inventory_consumption_method' => 'none', 'is_stock_tracked' => 0, 'default_selling_price' => 450]);
        $this->raitaId = $this->makeProduct($this->categoryId, ['name' => 'Raita', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'default_selling_price' => 60]);
        $conn = DB::connection('tenant');
        $printer = fn (string $code, string $role, string $ip) => $conn->table('printers')->insertGetId([
            'branch_id' => $this->branchId, 'name' => $code, 'code' => $code, 'printer_type' => 'network', 'print_role' => $role, 'supports_reminder' => 0,
            'ip_address' => $ip, 'port' => 9100, 'paper_size' => '80mm', 'characters_per_line' => 42, 'is_default' => $role === 'receipt' ? 1 : 0, 'is_active' => 1,
            'agent_enabled' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->kotPrinterId = $printer('KOT-' . Str::random(4), 'kot', '192.168.100.61');
        $this->receiptPrinterId = $printer('RCPT-' . Str::random(4), 'receipt', '192.168.100.62');
        $this->tableA = $this->makeTable($this->branchId, ['table_no' => 'A1', 'name' => 'Table A1', 'capacity' => 4, 'status' => 'available', 'sort_order' => 1]);
        $floorId = (int) $conn->table('restaurant_tables')->where('id', $this->tableA)->value('restaurant_floor_id');
        $this->tableB = $conn->table('restaurant_tables')->insertGetId(['branch_id' => $this->branchId, 'restaurant_floor_id' => $floorId, 'table_no' => 'A2', 'name' => 'Table A2',
            'capacity' => 6, 'status' => 'available', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->registerCloudTenantAndDevice($this->branchId, 1);

        // ── the appliance: the shipped config, bound at revision 1, credentialed, no baseline yet ──
        $this->mirrorConfigToAppliance(self::CONFIG_TABLES);
        $this->asEdge(function () use ($user) {
            $this->cleanTenant(['edge_local_connection_transitions', 'edge_baseline_cutovers', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta']);
            $this->bindEdgeLocalMeta($this->branchId, 1, $this->cloudTenantId, $this->cloudDeviceUuid, 1);
            DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'tenant_code' => $this->bridgeTenantCode]);
            $this->seedEdgeCredential($user, $this->branchId, 1);
        });
        $this->configureApplianceCloudUrls();
        config([
            'edge.authority.ttl_seconds' => 60, 'edge.authority.skew_margin_seconds' => 10,
            'edge.authority.unstable_after_failures' => 2, 'edge.authority.lost_after_failures' => 4,
        ]);
        $this->bridgeCloud();
    }

    protected function tearDown(): void
    {
        $this->bridgeBeforeCall = null;
        Carbon::setTestNow();
        $this->cleanupCloudRegistration();
        $this->useDb($this->cloudDb);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** One authority-worker tick, $seconds after the previous one (the 0.9 worker beats about every 30–36 s). */
    private function tick(int $seconds = 30): array
    {
        Carbon::setTestNow(now()->addSeconds($seconds));

        return $this->asEdge(fn () => app()->make(EdgeAuthorityTick::class)->run('test-worker'));
    }

    private function authority(): EdgeAuthorityService
    {
        return app()->make(EdgeAuthorityService::class);
    }

    private function gates(): array
    {
        return $this->asEdge(fn () => $this->authority()->gates());
    }

    private function failing(array $gates): array
    {
        return array_keys(array_filter($gates, fn ($ok) => ! $ok));
    }

    private function cloudStock(int $productId, float $qty): void
    {
        $this->asCloud(function () use ($productId, $qty) {
            $conn = DB::connection('tenant');
            $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$productId}-" . Str::random(6), 'branch_id' => $this->branchId, 'product_id' => $productId,
                'batch_no' => 'B' . Str::random(4), 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$productId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $productId,
                'inventory_batch_id' => $batchId, 'quantity_on_hand' => $qty, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    /** A purchase-only crate the Cloud stocks but never ships to a POS (not sellable, not POS-visible, in no recipe). */
    private function cloudPurchaseOnlyCrateWithStock(float $qty): int
    {
        $crateId = $this->asCloud(fn () => $this->makeProduct($this->categoryId, ['name' => '7 UP DIET (CRT)', 'is_sellable' => 0, 'is_pos_visible' => 0,
            'item_kind' => 'ingredient', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]));
        $this->cloudStock($crateId, $qty);
        // The appliance never received it (the bootstrap / config refresh ship POS products only).
        $this->asEdge(fn () => $this->assertFalse(DB::connection('tenant')->table('products')->where('id', $crateId)->exists()));

        return $crateId;
    }

    /**
     * What the Cloud's other clients write during service, exactly as production writes it (Eloquent, so updated_at moves):
     * the print agent folding printer health onto every printer it reached (PrintAgentApiController::heartbeat), a print job
     * completing (…::complete), and dine-in tables going occupied → bill requested → available (RestaurantTableSessionController
     * / SalesService). Runs on the Cloud DB, one second after the previous write so the timestamps really move.
     */
    private function serviceChurn(int $step): void
    {
        Carbon::setTestNow(now()->addSecond());
        foreach ([$this->kotPrinterId, $this->receiptPrinterId] as $id) {
            Printer::on('tenant')->where('id', $id)->update(['last_ping_ok' => true, 'last_ping_at' => now(), 'last_ping_ms' => 10 + $step, 'last_seen_at' => now()]);
        }
        Printer::on('tenant')->find($this->kotPrinterId)->update(['last_seen_at' => now()->addMillisecond(), 'last_error' => $step % 2 ? 'paper low' : null]);
        $statuses = ['occupied', 'bill_requested', 'available'];
        RestaurantTable::on('tenant')->find($this->tableA)->update(['status' => $statuses[$step % 3], 'reserved_name' => null, 'reserved_phone' => null, 'reserved_at' => null]);
        RestaurantTable::on('tenant')->find($this->tableB)->update(['status' => $statuses[($step + 1) % 3]]);
    }

    private function churnDuringEveryCloudCall(): void
    {
        $step = 100;
        $this->bridgeBeforeCall = function (string $path) use (&$step) {
            $this->serviceChurn($step++);
        };
    }

    private function cloudRevisionCount(): int
    {
        return (int) DB::connection('master')->table('edge_branch_config_revisions')->where('tenant_id', $this->cloudTenantId)->where('branch_id', $this->branchId)->count();
    }

    private function acceptedBaseline(): ?object
    {
        return $this->asEdge(fn () => DB::connection('tenant')->table('edge_operational_stock_baselines')->where('status', 'accepted')->first());
    }

    private function acceptedItems(): array
    {
        return $this->asEdge(function () {
            $b = DB::connection('tenant')->table('edge_operational_stock_baselines')->where('status', 'accepted')->first();

            return $b ? DB::connection('tenant')->table('edge_operational_stock_balances')->where('baseline_id', $b->id)->orderBy('product_id')
                ->get(['product_id', 'product_variant_id', 'quantity_on_hand'])->map(fn ($r) => [(int) $r->product_id, (float) $r->quantity_on_hand])->all() : [];
        });
    }

    /** The WAN dies for good: failures recorded, never acted on; then the lease lapses on the appliance clock (TTL 60 + skew 10). */
    private function wanDiesAndTheLeaseLapses(): void
    {
        $this->bridgeBeforeCall = null;
        $this->bridgeFailures = ['authority/heartbeat' => 'down', 'config/refresh' => 'down', 'sync/baseline' => 'down', 'returnable/refresh' => 'down', 'supplier-finance/refresh' => 'down', 'purchase-returns/refresh' => 'down'];
        for ($i = 0; $i < 4; $i++) {
            $r = $this->tick(5);
            $this->assertFalse($r['heartbeat']['ok']);
        }
        $this->assertSame('standby', $this->asEdge(fn () => $this->authority()->state()));
        $this->assertContains('AUTHORITY_TAKEOVER_SAFE', $this->failing($this->gates()), 'inside the lease the takeover gate stays CLOSED (split-brain guard untouched)');
        $this->tick(71);
        $this->assertTrue($this->gates()['AUTHORITY_TAKEOVER_SAFE'], 'the lease lapsed on the appliance clock');
    }

    // ── A: the baseline the Cloud issues must be acceptable ─────────────────────

    public function test_purchase_only_stock_the_appliance_does_not_hold_no_longer_blocks_the_stock_baseline(): void
    {
        $crateId = $this->cloudPurchaseOnlyCrateWithStock(24);

        $r = $this->tick();
        $this->assertTrue($r['heartbeat']['ok'], json_encode($r['heartbeat']));
        // Before the fix: 'error:BASELINE_UNKNOWN_PRODUCT: item #0 product_id [crate] does not resolve locally.' on EVERY tick.
        $this->assertSame('refreshed:initial', $r['work']['stock'], json_encode($r['work']));
        $this->assertTrue($this->gates()['STOCK_AUTHORITY_READY'], json_encode($this->gates()));
        $this->assertSame(['AUTHORITY_TAKEOVER_SAFE'], $this->failing($this->gates()), 'online: only the live lease holds the standby');

        // The baseline names only products the appliance holds; the shipped dish states the official position (0).
        $items = $this->acceptedItems();
        $this->assertNotContains($crateId, array_column($items, 0), 'a product the appliance cannot resolve is never named');
        $this->assertContains($this->menuItemId, array_column($items, 0));
        $this->assertSame([0.0], array_values(array_unique(array_column($items, 1))), 'every shipped product is at its official on-hand: 0');
        // Its freshness proof still equals what the heartbeat advertises (the watermark itself is unchanged).
        $advertised = $this->asCloud(fn () => app(EdgeBaselineIssuanceService::class)->stockWatermark($this->branchId)['stock_watermark']);
        $this->assertSame($advertised, (string) $this->acceptedBaseline()->stock_watermark);
        $this->assertSame('current', $this->tick()['work']['stock'], 'nothing moved → nothing pulled');

        $this->wanDiesAndTheLeaseLapses();
        $this->assertSame([], $this->failing($this->gates()), json_encode($this->gates()));
        $res = $this->asEdge(fn () => $this->authority()->takeOver(true, 'supervisor'));
        $this->assertFalse($res['stale_accepted'], 'no override was needed');
        $this->assertSame('local_active', $this->asEdge(fn () => $this->authority()->state()));
    }

    public function test_a_branch_without_any_official_stock_gets_an_explicit_zero_baseline(): void
    {
        $r = $this->tick();
        $this->assertTrue($r['heartbeat']['ok'], json_encode($r['heartbeat']));
        // Before the fix: 'error:BASELINE_EMPTY: the baseline package has no items.' on EVERY tick.
        $this->assertSame('refreshed:initial', $r['work']['stock'], json_encode($r['work']));
        $this->assertTrue($this->gates()['STOCK_AUTHORITY_READY']);
        $items = $this->acceptedItems();
        $this->assertEqualsCanonicalizing([$this->menuItemId, $this->raitaId], array_column($items, 0), 'the shipped POS products, each stated at 0');
        $this->assertSame([0.0], array_values(array_unique(array_column($items, 1))));
        $this->assertTrue($this->asEdge(fn () => $this->authority()->freshness()['ok']), json_encode($this->asEdge(fn () => $this->authority()->freshness())));
    }

    // ── B: service churn must not move the config revision ──────────────────────

    public function test_print_agent_and_table_churn_never_moves_the_revision_and_an_outage_mid_tick_still_allows_takeover(): void
    {
        $this->cloudStock($this->raitaId, 50); // a branch that tracks stock: the baseline is non-empty with or without fix A
        $r = $this->tick();
        $this->assertSame('refreshed:initial', $r['work']['stock'], json_encode($r['work']));
        $this->assertSame(1, (int) $r['heartbeat']['advertised']['config_revision']);

        // Busy service: print agents beat, KOTs print, tables open and close — before AND during every Cloud call of every tick.
        $this->churnDuringEveryCloudCall();
        for ($i = 0; $i < 4; $i++) {
            $this->serviceChurn($i);
            $r = $this->tick();
            $this->assertTrue($r['heartbeat']['ok'], json_encode($r['heartbeat']));
            // Before the fix: revision 2, 3, 4, … ; config 'refreshed:N->M' and a baseline cutover on every tick.
            $this->assertSame(1, (int) $r['heartbeat']['advertised']['config_revision'], "tick {$i}: printer health / table occupancy is not configuration");
            $this->assertSame('current', $r['work']['config'], json_encode($r['work']));
            $this->assertSame('current', $r['work']['stock'], json_encode($r['work']));
            $this->assertSame(['AUTHORITY_TAKEOVER_SAFE'], $this->failing($this->gates()), "tick {$i}: " . json_encode($this->gates()));
        }
        $this->assertSame(1, $this->cloudRevisionCount(), 'no revision was minted by service churn');
        $this->assertSame(0, $this->asEdge(fn () => DB::connection('tenant')->table('edge_baseline_cutovers')->count()), 'no baseline cutover churn');

        // THE CUT, mid-tick: the heartbeat still gets through, then the internet drops before the stock call. Before the fix the
        // churn had minted a revision, this tick applied it, the baseline fetch failed, and the accepted baseline was left at the
        // old revision → STOCK_AUTHORITY_READY false for the whole outage, which no supervisor can override.
        $this->bridgeFailures = ['sync/baseline' => 'down', 'returnable/refresh' => 'down', 'supplier-finance/refresh' => 'down', 'purchase-returns/refresh' => 'down'];
        $r = $this->tick();
        $this->assertTrue($r['heartbeat']['ok']);
        $this->assertSame('current', $r['work']['config'], json_encode($r['work']));
        $this->assertTrue($this->gates()['STOCK_AUTHORITY_READY'], 'the baseline is still valid at the cut');

        $this->wanDiesAndTheLeaseLapses();
        $gates = $this->gates();
        $this->assertSame([], $this->failing($gates), 'every gate incl. STOCK_AUTHORITY_READY and STANDBY_FRESH_ENOUGH passes: ' . json_encode($gates));
        $res = $this->asEdge(fn () => $this->authority()->takeOver(true, 'supervisor'));
        $this->assertFalse($res['stale_accepted'], 'the standby was provably current at the cut — no override needed');
        $proof = json_decode((string) $this->asEdge(fn () => DB::connection('tenant')->table('edge_local_meta')->value('authority_takeover_freshness')), true);
        $this->assertTrue($proof['fresh']);
        $this->assertSame(1, $proof['config_revision_applied']);
        $this->assertSame(1, $proof['config_revision_advertised']);
        $this->assertSame([[$this->raitaId, 50.0]], $this->acceptedItems(), 'local selling starts from the Cloud position at the cut');
        $this->asEdge(fn () => $this->authority()->assertLocalMutationAllowed());
    }

    public function test_real_printer_and_table_edits_still_reach_the_standby_and_carry_the_baseline_along(): void
    {
        $this->cloudStock($this->raitaId, 50);
        $this->assertSame('refreshed:initial', $this->tick()['work']['stock']);

        $edits = [
            'printer IP moved' => [fn () => Printer::on('tenant')->find($this->kotPrinterId)->update(['ip_address' => '192.168.100.71']),
                fn () => $this->assertSame('192.168.100.71', (string) DB::connection('tenant')->table('printers')->where('id', $this->kotPrinterId)->value('ip_address'))],
            'printer switched off' => [fn () => Printer::on('tenant')->find($this->receiptPrinterId)->update(['is_active' => false]),
                fn () => $this->assertSame(0, (int) DB::connection('tenant')->table('printers')->where('id', $this->receiptPrinterId)->value('is_active'))],
            'table renamed' => [fn () => RestaurantTable::on('tenant')->find($this->tableA)->update(['name' => 'Family A1', 'capacity' => 8]),
                fn () => $this->assertSame('Family A1', (string) DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableA)->value('name'))],
            'table disabled' => [fn () => RestaurantTable::on('tenant')->find($this->tableB)->update(['status' => 'inactive']),
                fn () => $this->assertSame('inactive', (string) DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableB)->value('status'))],
        ];
        $revision = 1;
        foreach ($edits as $label => [$edit, $arrived]) {
            Carbon::setTestNow(now()->addSecond());
            $this->asCloud($edit);
            $r = $this->tick();
            $this->assertSame($revision + 1, (int) $r['heartbeat']['advertised']['config_revision'], "{$label}: a real edit mints a revision");
            $this->assertSame('refreshed:' . $revision . '->' . ($revision + 1), $r['work']['config'], "{$label}: " . json_encode($r['work']));
            $this->assertSame('refreshed:cutover', $r['work']['stock'], "{$label}: the baseline follows the new revision in the same tick");
            $this->asEdge($arrived);
            $this->assertSame(['AUTHORITY_TAKEOVER_SAFE'], $this->failing($this->gates()), "{$label}: " . json_encode($this->gates()));
            $revision++;
        }
    }
}
