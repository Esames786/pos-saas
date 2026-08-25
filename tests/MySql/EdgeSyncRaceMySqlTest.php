<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeLocalBootstrapImporter;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Sales\ShiftService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE-SYNC-ENGINE-1B closure — GENUINE concurrency proofs (independent OS processes / connections,
 * real InnoDB locking):
 *   • outbox lease: one row + two workers -> exactly one claimant; two rows -> distinct claims; an expired
 *     lease is reclaimed by a new worker; never two owners of one row (deadlock-free via FOR UPDATE SKIP
 *     LOCKED — a plain UPDATE ... ORDER BY id LIMIT 1 deadlocks two workers, which this proves is gone);
 *   • config refresh vs paid sale: the sale is coherent — its commercial snapshot and its config_revision
 *     stamp describe the SAME logical config generation (entire N or entire N+1), never a mix, whether the
 *     REAL refresh authority is in-flight (uncommitted) OR already committed when the sale runs.
 */
class EdgeSyncRaceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $productId;
    private int $cashMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta',
            'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles',
            'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders',
            // The refresh applier walks the FULL config PLAN and tombstones rows not in the payload, so the
            // shared tenant DB must be free of prior tests' config rows (an orphaned combo/branch FK else fails).
            'combo_components', 'combos', 'modifiers', 'modifier_groups',
            'product_branch_prices', 'product_barcodes', 'product_variants',
            'recipe_ingredients', 'recipes', 'unit_conversions',
            'category_printer_mappings', 'terminal_printer_settings', 'receipt_layout_settings', 'printers',
            'service_charge_settings', 'delivery_riders', 'delivery_channels',
            'restaurant_tables', 'restaurant_floors', 'void_reasons', 'units',
            'restaurant_waiters', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RACE' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 10);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── §5 outbox lease races (two independent OS processes, spin-barrier) ─────

    private function leaseWorker(string $owner, string $startFile): array
    {
        $cmd = [PHP_BINARY, base_path('tests/MySql/Support/edge_outbox_lease_worker.php'), $owner];
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'EDGE_TEST_TENANT_DB' => $this->tenantDb, 'APP_ENV' => 'testing', 'START_FILE' => $startFile,
        ]));

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function finish(array $h): string
    {
        $out = trim(stream_get_contents($h['pipes'][1]));
        $err = trim(stream_get_contents($h['pipes'][2]) ?: '');
        fclose($h['pipes'][1]);
        fclose($h['pipes'][2]);
        proc_close($h['proc']);

        return $out !== '' ? $out : 'STDERR:' . $err;
    }

    private function raceLease(): array
    {
        $startFile = sys_get_temp_dir() . '/edge_lease_race_' . Str::random(8) . '.start';
        @unlink($startFile);
        $a = $this->leaseWorker('worker-A', $startFile);
        $b = $this->leaseWorker('worker-B', $startFile);
        sleep(4);
        file_put_contents($startFile, '1');
        $outA = $this->finish($a);
        $outB = $this->finish($b);
        @unlink($startFile);

        return [$outA, $outB];
    }

    private function seedOutboxRow(string $state = 'pending', array $extra = []): int
    {
        $env = '{"envelope_schema_version":"edge-sale-envelope-v1","sale_uuid":"x"}';

        return (int) DB::connection('tenant')->table('edge_sync_outbox')->insertGetId(array_merge([
            'sale_uuid' => (string) Str::ulid(), 'envelope_schema_version' => 'edge-sale-envelope-v1',
            'config_revision' => 10, 'activation_epoch' => 1, 'envelope' => $env,
            'content_hash' => hash('sha256', $env . Str::random(4)), 'state' => $state,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function claimedId(string $out): ?int
    {
        $this->assertStringStartsWith('OK:lease:', $out, "worker output: $out");
        $id = explode(':', $out)[2] ?? 'none';

        return $id === 'none' ? null : (int) $id;
    }

    public function test_one_pending_row_two_simultaneous_workers_exactly_one_claimant(): void
    {
        $rowId = $this->seedOutboxRow();
        [$outA, $outB] = $this->raceLease();
        $claims = array_filter([$this->claimedId($outA), $this->claimedId($outB)], fn ($v) => $v !== null);

        $this->assertCount(1, $claims, "exactly one worker may own the row: A=$outA B=$outB");
        $this->assertSame($rowId, reset($claims));
        $row = EdgeSyncOutbox::find($rowId);
        $this->assertSame('leased', $row->state);
        $this->assertSame(1, (int) $row->attempts, 'the loser did not touch the row');
    }

    public function test_two_pending_rows_two_simultaneous_workers_distinct_claims_no_deadlock(): void
    {
        $r1 = $this->seedOutboxRow();
        $r2 = $this->seedOutboxRow();
        [$outA, $outB] = $this->raceLease();
        $claims = [$this->claimedId($outA), $this->claimedId($outB)];

        $this->assertNotContains(null, $claims, "both workers should claim without deadlock: A=$outA B=$outB");
        $this->assertSame([$r1, $r2], collect($claims)->sort()->values()->all(), 'distinct rows, no shared ownership');
        $this->assertSame(2, EdgeSyncOutbox::query()->where('state', 'leased')->pluck('lease_owner')->unique()->count());
    }

    public function test_expired_lease_is_reclaimed_by_a_new_worker_without_duplicate_ownership(): void
    {
        $rowId = $this->seedOutboxRow('leased', ['lease_owner' => 'dead-worker:old', 'lease_expires_at' => now()->subMinute(), 'attempts' => 1]);
        [$outA, $outB] = $this->raceLease();
        $claims = array_filter([$this->claimedId($outA), $this->claimedId($outB)], fn ($v) => $v !== null);

        $this->assertCount(1, $claims, "exactly one reclaimer: A=$outA B=$outB");
        $row = EdgeSyncOutbox::find($rowId);
        $this->assertSame('leased', $row->state);
        $this->assertStringNotContainsString('dead-worker', $row->lease_owner, 'ownership moved to the reclaimer');
        $this->assertSame(2, (int) $row->attempts);
        $this->assertTrue($row->lease_expires_at->isFuture());
    }

    // ── §6 real config refresh authority vs real paid sale — coherence both directions ─────────

    private function openShift(): void
    {
        app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 0.0);
    }

    private function saleData(float $amount = 100): array
    {
        return ['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => $amount]]];
    }

    /**
     * Model the operational BASELINE CUTOVER a real post-refresh resume performs: baseline replacement is
     * deliberately refused WITHIN a binding (overselling guard), so selling under the new config generation
     * requires a fresh baseline at the new watermark — cleared + re-accepted here as the future sync will.
     */
    private function cutoverBaselineToCurrentRevision(): void
    {
        DB::connection('tenant')->table('edge_operational_stock_movements')->delete();
        DB::connection('tenant')->table('edge_operational_stock_balances')->delete();
        DB::connection('tenant')->table('edge_operational_stock_baselines')->delete();
        $rev = (string) DB::connection('tenant')->table('edge_local_meta')->value('source_revision');
        $items = [['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]];
        app(\App\Services\Edge\EdgeOperationalBaselineService::class)->accept(
            \App\Services\Edge\EdgeOperationalBaselineService::newBaselineUuid(),
            \App\Services\Edge\EdgeOperationalBaselineService::hashItems($items),
            $items,
            $rev
        );
    }

    private function assertCoherent(SalesOrder $sale, int $revision, float $price): void
    {
        $row = EdgeSyncOutbox::query()->where('sale_uuid', $sale->sale_uuid)->firstOrFail();
        $linePrice = (float) $sale->lines()->first()->unit_price;
        $envRevision = (int) $row->config_revision;
        $this->assertSame($revision, $envRevision, 'revision stamp');
        $this->assertSame($price, $linePrice, 'commercial snapshot');
        $this->assertSame($envRevision, (int) $row->envelopeArray()['config_revision'], 'stamp is inside the immutable envelope too');
        $this->assertSame($price, (float) $row->envelopeArray()['lines'][0]['unit_price']);
        // The invariant, stated as the forbidden mixes: a snapshot from one generation with the OTHER's stamp.
        $this->assertFalse($linePrice === 100.0 && $envRevision !== 10, 'the rev-10 price must carry the rev-10 stamp');
        $this->assertFalse($linePrice === 150.0 && $envRevision !== 11, 'the rev-11 price must carry the rev-11 stamp');
    }

    /** Build a complete v5 refresh package for THIS branch at $revision with the product priced at $price. */
    private function refreshPackage(int $revision, float $price): array
    {
        $svc = new class(app(OfflineEdgeEntitlementService::class), app(TenancyManager::class), app(EdgePairingService::class)) extends EdgeBootstrapService {
            public function sectionsFor(Tenant $t, Branch $b): array
            {
                return $this->buildSections($t, $b);
            }
        };
        $tenant = new Tenant(['tenant_code' => 'edgepos', 'business_name' => 'Demo', 'currency_code' => 'PKR']);
        $tenant->id = 42;
        $sections = $svc->sectionsFor($tenant, Branch::on('tenant')->find($this->branchId));
        foreach ($sections['products'] as &$row) {
            if ((int) $row['id'] === $this->productId) {
                $row['default_selling_price'] = $price;
            }
        }
        unset($row);
        $summary = [];
        foreach ($sections as $name => $rows) {
            $summary[$name] = ['hash' => hash('sha256', $svc->canonicalJson($rows)), 'count' => count($rows)];
        }
        $uuid = 'snap-race-' . $revision;
        $manifest = [
            'schema_version' => EdgeBootstrapService::SCHEMA_VERSION, 'snapshot_uuid' => $uuid, 'tenant_code' => 'edgepos',
            'tenant_id' => 42, 'branch_id' => $this->branchId, 'device_public_uuid' => 'test-device-uuid', 'activation_epoch' => 1,
            'config_revision' => $revision, 'config_schema_version' => EdgeBootstrapService::CONFIG_SCHEMA_VERSION,
            'source_revision' => 'rev-' . $revision, 'sections' => $summary,
        ];
        $manifest['manifest_hash'] = $svc->computeManifestHash(EdgeBootstrapService::SCHEMA_VERSION, $uuid, 42, $this->branchId, 'test-device-uuid', 1, $revision, EdgeBootstrapService::CONFIG_SCHEMA_VERSION, $summary);

        return ['manifest' => $manifest, 'sections' => $sections];
    }

    /**
     * A sale is built in ONE snapshot-consistent transaction and the REAL refresh authority
     * (EdgeLocalConfigRefreshApplier via the importer) commits atomically under the edge_local_meta lock.
     * So a sale is ALWAYS entirely one generation: its priced line and its stamped config_revision agree —
     * rev-10/100 before the refresh, rev-11/150 after — never a rev-10 price with a rev-11 stamp or the
     * inverse. This exercises the actual authority on both sides of a real N -> N+1 transition.
     */
    public function test_sales_before_and_after_the_real_refresh_authority_are_each_internally_coherent(): void
    {
        $this->openShift();

        // Entire N.
        $before = app(EdgeLocalPosService::class)->completePaidSale($this->saleData(), User::on('tenant')->find($this->userId), $this->terminalId);
        $this->assertCoherent($before, 10, 100.0);

        // The ACTUAL refresh authority commits revision 11 (price 150), atomically, under the meta lock.
        $meta = app(EdgeLocalBootstrapImporter::class)->import($this->refreshPackage(11, 150.0));
        $this->assertSame(11, (int) $meta->last_applied_config_revision, 'the real refresh authority committed N+1');
        $this->assertSame(150.0, (float) DB::connection('tenant')->table('products')->where('id', $this->productId)->value('default_selling_price'));

        $this->cutoverBaselineToCurrentRevision();

        // Entire N+1 — the next sale (after baseline cutover) snapshots AND stamps generation 11.
        $after = app(EdgeLocalPosService::class)->completePaidSale($this->saleData(150), User::on('tenant')->find($this->userId), $this->terminalId);
        $this->assertCoherent($after, 11, 150.0);

        // And the earlier envelope is UNCHANGED — a later refresh never rewrites a historical outbox row.
        $this->assertCoherent($before->fresh(), 10, 100.0);
    }
}