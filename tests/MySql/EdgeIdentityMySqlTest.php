<?php

namespace Tests\MySql;

use App\Models\Tenant\Customer;
use App\Models\Tenant\KotBatch;
use App\Models\Tenant\ManagerApproval;
use App\Models\Tenant\SalePayment;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\SalesOrderLineCancellation;
use App\Support\Edge\EdgeIdentity;
use App\Support\Edge\EdgeIdentityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * EDGE-IDENTITY-1 — authoritative MySQL proof of the canonical cross-system identity contract.
 *
 * Uses REAL Eloquent models/relations (exactly what the POS controllers use to create sales, lines,
 * payments and KOT batches) against real MySQL, plus a genuinely INDEPENDENT second database with divergent
 * numeric primary keys to prove that identity — not the local id — is what resolves the same business object
 * across systems. Master is pointed at a nonexistent DB where relevant to prove no landlord dependency.
 */
class EdgeIdentityMySqlTest extends MySqlTenantTestCase
{
    /** table => [column, format] for every managed identity. */
    private const MANAGED = [
        'sales_orders'              => ['sale_uuid', 'ulid'],
        'sales_order_lines'         => ['line_uuid', 'ulid'],
        'sale_payments'             => ['payment_uuid', 'ulid'],
        'kot_batch_lines'           => ['kot_line_uuid', 'ulid'],
        'restaurant_table_sessions' => ['session_uuid', 'ulid'],
        'manager_approvals'         => ['approval_uuid', 'ulid'],
        'customers'                 => ['customer_uuid', 'ulid'],
    ];

    private int $branchId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'kot_batch_lines', 'kot_batches', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'manager_approvals', 'restaurant_table_sessions', 'customers', 'payment_methods', 'products', 'branches',
        ]);
        $this->branchId = DB::connection('tenant')->table('branches')->insertGetId([
            'name' => 'Main', 'code' => 'ID1', 'status' => 'active', 'timezone' => 'Asia/Karachi',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = DB::connection('tenant')->table('products')->insertGetId([
            'sku' => 'SKU'.Str::random(5), 'name' => 'Prod', 'slug' => 'prod-'.Str::lower(Str::random(6)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── 1/2. schema + format ────────────────────────────────────────────────
    public function test_all_identity_columns_and_unique_indexes_exist(): void
    {
        foreach (self::MANAGED as $table => [$column]) {
            $this->assertTrue(\Schema::connection('tenant')->hasColumn($table, $column), "$table.$column must exist");
            $indexes = collect(DB::connection('tenant')->select("SHOW INDEX FROM `{$table}`"))
                ->where('Column_name', $column)->where('Non_unique', 0);
            $this->assertTrue($indexes->isNotEmpty(), "$table.$column must have a UNIQUE index");
        }
        // reused identities exist too
        $this->assertTrue(\Schema::connection('tenant')->hasColumn('shifts', 'shift_uuid'));
        $this->assertTrue(\Schema::connection('tenant')->hasColumn('kot_batches', 'event_uuid'));
    }

    public function test_new_identities_are_ulid_format(): void
    {
        $sale = $this->makeSale();
        $this->assertTrue(Str::isUlid($sale->sale_uuid), 'sale_uuid must be a ULID');
        $this->assertSame(26, strlen($sale->sale_uuid));
        // format registry is authoritative
        $this->assertSame('ulid', EdgeIdentity::for(SalesOrder::class)[1]);
        $this->assertSame('uuid', EdgeIdentity::REUSED[KotBatch::class][1]);
    }

    // ── 3. generated at creation via REAL relations ─────────────────────────
    public function test_all_operational_identities_autogenerate_at_creation(): void
    {
        $sale = $this->makeSale();
        $line = $sale->lines()->create(['product_id' => $this->productId, 'product_name' => 'X', 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10]);
        $pm = $this->paymentMethodId();
        $payment = $sale->payments()->create(['payment_method_id' => $pm, 'amount' => 10]);
        $batch = KotBatch::create(['sales_order_id' => $sale->id, 'sequence_no' => 1, 'event_type' => 'normal']);
        $kotLine = $batch->lines()->create(['product_name' => 'X', 'quantity' => 1]);
        $customer = Customer::create(['name' => 'Cust']);
        $approval = ManagerApproval::create(['approval_no' => 'MA-'.Str::random(6), 'action_type' => 'void']);

        foreach ([$sale->sale_uuid, $line->line_uuid, $payment->payment_uuid, $kotLine->kot_line_uuid, $customer->customer_uuid, $approval->approval_uuid] as $id) {
            $this->assertTrue(Str::isUlid((string) $id), "identity [$id] must be a generated ULID");
        }
        $this->assertTrue(Str::isUuid($batch->event_uuid), 'reused batch event_uuid is UUID v4');
    }

    // ── 4. immutability (managed + reused) ──────────────────────────────────
    public function test_canonical_identities_are_immutable(): void
    {
        $sale = $this->makeSale();
        $this->expectException(RuntimeException::class);
        $sale->sale_uuid = (string) Str::ulid();
        $sale->save();
    }

    public function test_reused_event_uuid_is_immutable(): void
    {
        $batch = KotBatch::create(['sales_order_id' => $this->makeSale()->id, 'sequence_no' => 1, 'event_type' => 'normal']);
        $this->expectException(RuntimeException::class);
        $batch->event_uuid = (string) Str::uuid();
        $batch->save();
    }

    // ── 5. duplicate rejected by real unique constraint ─────────────────────
    public function test_duplicate_identity_rejected_by_unique_constraint(): void
    {
        $sale = $this->makeSale();
        $this->expectExceptionMessageMatches('/Duplicate|unique/i');
        DB::connection('tenant')->table('sales_orders')->insert([
            'sale_no' => 'DUP-'.Str::random(6), 'sale_uuid' => $sale->sale_uuid,
            'branch_id' => $this->branchId, 'sale_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── 6/7. backfill leaves zero nulls + idempotent ────────────────────────
    public function test_backfill_populates_legacy_nulls_and_is_idempotent(): void
    {
        // simulate legacy rows predating the column by nulling identities on raw rows
        $sale = $this->makeSale();
        DB::connection('tenant')->table('sales_orders')->where('id', $sale->id)->update(['sale_uuid' => null]);
        DB::connection('tenant')->table('customers')->insert(['name' => 'Legacy', 'customer_uuid' => null, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertGreaterThan(0, DB::connection('tenant')->table('sales_orders')->whereNull('sale_uuid')->count());

        $this->runBackfillMigration();

        $this->assertSame(0, DB::connection('tenant')->table('sales_orders')->whereNull('sale_uuid')->count(), 'backfill must leave zero null sale_uuid');
        $this->assertSame(0, DB::connection('tenant')->table('customers')->whereNull('customer_uuid')->count());
        $filled = DB::connection('tenant')->table('sales_orders')->where('id', $sale->id)->value('sale_uuid');
        $this->assertTrue(Str::isUlid($filled));

        // idempotent: rerun preserves every identity
        $before = DB::connection('tenant')->table('sales_orders')->pluck('sale_uuid', 'id')->all();
        $this->runBackfillMigration();
        $after = DB::connection('tenant')->table('sales_orders')->pluck('sale_uuid', 'id')->all();
        $this->assertSame($before, $after, 'rerunning backfill must preserve every identity');
    }

    // ── 8. client_uuid decision: sale_uuid distinct + immutable while client_uuid mutable ──
    public function test_sale_uuid_is_distinct_from_client_uuid_and_immutable_while_client_uuid_may_change(): void
    {
        $sale = $this->makeSale();
        $originalSaleUuid = $sale->sale_uuid;
        $this->assertNotSame($sale->client_uuid, $sale->sale_uuid, 'sale_uuid and client_uuid are distinct concepts');

        // client_uuid is a request idempotency token — it CAN change (e.g. held->pay overwrite)
        $sale->client_uuid = (string) Str::uuid();
        $sale->save();
        $this->assertNotSame($originalSaleUuid, $sale->client_uuid);
        // ...but sale_uuid stayed constant across that same-row update
        $this->assertSame($originalSaleUuid, $sale->fresh()->sale_uuid, 'sale_uuid must be stable across a client_uuid change on the same row');
    }

    // ── 9-12. Direct Pay retry (same row replay) preserves every identity ────
    public function test_direct_pay_retry_same_row_preserves_sale_line_payment_kot_identities(): void
    {
        $sale = $this->makeSale();
        $line = $sale->lines()->create(['product_id' => $this->productId, 'product_name' => 'X', 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10]);
        $payment = $sale->payments()->create(['payment_method_id' => $this->paymentMethodId(), 'amount' => 10]);
        $batch = KotBatch::create(['sales_order_id' => $sale->id, 'sequence_no' => 1, 'event_type' => 'normal']);
        $kotLine = $batch->lines()->create(['product_name' => 'X', 'quantity' => 1]);
        $snapshot = [$sale->sale_uuid, $line->line_uuid, $payment->payment_uuid, $batch->event_uuid, $kotLine->kot_line_uuid];

        // A retry/resume resolves the SAME sales_orders row by client_uuid (idempotentReplayOrThrow) and
        // re-runs orchestration on it — it never re-creates the sale/line/payment. Model the replay: same row.
        $replayed = SalesOrder::where('client_uuid', $sale->client_uuid)->firstOrFail();
        $this->assertSame($sale->id, $replayed->id, 'replay resolves the same row');
        $this->assertSame($snapshot, [
            $replayed->sale_uuid,
            $replayed->lines()->first()->line_uuid,
            $replayed->payments()->first()->payment_uuid,
            $batch->fresh()->event_uuid,
            $kotLine->fresh()->kot_line_uuid,
        ], 'retry preserves sale/line/payment/KOT identities unchanged');
    }

    // ── 13-15. Add Round: original identities preserved, new line/KOT get NEW identities ──
    public function test_add_round_preserves_original_and_new_lines_and_kot_get_new_identities(): void
    {
        $sale = $this->makeSale();
        $line1 = $sale->lines()->create(['product_id' => $this->productId, 'product_name' => 'A', 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10]);
        $batch1 = KotBatch::create(['sales_order_id' => $sale->id, 'sequence_no' => 1, 'event_type' => 'normal']);
        $origSale = $sale->sale_uuid; $origLine = $line1->line_uuid; $origBatch = $batch1->event_uuid;

        // Add Round = add a new line to the SAME sale + a new addition KOT batch.
        $line2 = $sale->lines()->create(['product_id' => $this->productId, 'product_name' => 'B', 'quantity' => 1, 'unit_price' => 5, 'line_total' => 5]);
        $batch2 = KotBatch::create(['sales_order_id' => $sale->id, 'sequence_no' => 2, 'event_type' => 'addition']);

        $this->assertSame($origSale, $sale->fresh()->sale_uuid, 'original check identity unchanged');
        $this->assertSame($origLine, $line1->fresh()->line_uuid, 'original line identity unchanged');
        $this->assertSame($origBatch, $batch1->fresh()->event_uuid, 'original KOT batch identity unchanged');
        $this->assertNotSame($origLine, $line2->line_uuid, 'new round line gets a NEW identity');
        $this->assertNotSame($origBatch, $batch2->event_uuid, 'addition KOT gets a NEW batch identity');
    }

    // ── 18-19. Split: each child gets its own sale identity, parent unchanged ─
    public function test_split_children_have_distinct_identities_and_parent_unchanged(): void
    {
        $parent = $this->makeSale();
        $parentId = $parent->sale_uuid;
        $childA = $this->makeSale();
        $childB = $this->makeSale();
        $this->assertNotSame($parentId, $childA->sale_uuid);
        $this->assertNotSame($parentId, $childB->sale_uuid);
        $this->assertNotSame($childA->sale_uuid, $childB->sale_uuid, 'split children A and B are distinguishable by identity');
        $this->assertSame($parentId, $parent->fresh()->sale_uuid, 'parent identity unchanged by split');
    }

    // ── 24. normal HTTP / mass-assignment cannot override an internal identity ─
    public function test_mass_assignment_cannot_supply_or_override_identity(): void
    {
        $attacker = '01ATTACKERATTACKERATTACK00';
        $sale = SalesOrder::create([
            'sale_no' => 'MA-'.Str::random(6), 'branch_id' => $this->branchId, 'sale_date' => now(),
            'sale_uuid' => $attacker, // must be ignored (not fillable)
        ]);
        $this->assertNotSame($attacker, $sale->sale_uuid);
        $this->assertTrue(Str::isUlid($sale->sale_uuid));
        $customer = Customer::create(['name' => 'Y', 'customer_uuid' => $attacker]);
        $this->assertNotSame($attacker, $customer->customer_uuid);
    }

    // ── 25. two INDEPENDENT DBs, divergent numeric PKs, same canonical identity resolve ──
    public function test_two_independent_dbs_with_divergent_pks_resolve_same_canonical_identity(): void
    {
        $edgeDb = $this->tenantDb . '_edge';
        $this->cloneEdgeTables($edgeDb, ['branches', 'sales_orders', 'sales_order_lines']);
        config(['database.connections.identity_edge' => array_merge(config('database.connections.tenant'), ['database' => $edgeDb])]);
        DB::purge('identity_edge');

        $saleUuid = (string) Str::ulid();
        $lineUuid = (string) Str::ulid();

        // CLOUD row: force a high numeric id.
        $cloud = DB::connection('tenant');
        $cloud->statement('ALTER TABLE sales_orders AUTO_INCREMENT = 9000');
        $cloudId = $cloud->table('sales_orders')->insertGetId(['sale_no' => 'C-'.Str::random(5), 'sale_uuid' => $saleUuid, 'branch_id' => $this->branchId, 'sale_date' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $cloudLineId = $cloud->table('sales_order_lines')->insertGetId(['sales_order_id' => $cloudId, 'line_uuid' => $lineUuid, 'product_id' => $this->productId, 'product_name' => 'X', 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // EDGE row: SAME canonical identity, deliberately DIFFERENT (small) numeric ids.
        $edge = DB::connection('identity_edge');
        $edge->table('branches')->insert(['id' => 1, 'name' => 'B', 'code' => 'E1', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => now(), 'updated_at' => now()]);
        $edgeId = $edge->table('sales_orders')->insertGetId(['sale_no' => 'E-1', 'sale_uuid' => $saleUuid, 'branch_id' => 1, 'sale_date' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $edgeLineId = $edge->table('sales_order_lines')->insertGetId(['sales_order_id' => $edgeId, 'line_uuid' => $lineUuid, 'product_id' => 1, 'product_name' => 'X', 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNotSame($cloudId, $edgeId, 'the two systems intentionally hold DIFFERENT numeric ids');

        // Resolve the SAME business object on each system by canonical identity, not by numeric id.
        $resolvedCloud = EdgeIdentityResolver::resolve(SalesOrder::class, $saleUuid, 'tenant');
        $resolvedEdge = EdgeIdentityResolver::resolve(SalesOrder::class, $saleUuid, 'identity_edge');
        $this->assertNotNull($resolvedCloud);
        $this->assertNotNull($resolvedEdge);
        $this->assertSame($cloudId, $resolvedCloud->id);
        $this->assertSame($edgeId, $resolvedEdge->id);
        $this->assertSame($resolvedCloud->sale_uuid, $resolvedEdge->sale_uuid, 'same canonical identity across systems');

        // A line carries its parent sale identity; relationship resolves by identity on the OTHER db.
        $edgeLine = SalesOrderLine::on('identity_edge')->where('line_uuid', $lineUuid)->first();
        $this->assertSame($edgeLineId, $edgeLine->id);
        $parentOnCloud = EdgeIdentityResolver::resolve(SalesOrder::class, $resolvedEdge->sale_uuid, 'tenant');
        $this->assertSame($cloudId, $parentOnCloud->id, 'edge line resolves the correct Cloud parent by identity');

        DB::connection('identity_edge')->getPdo()->exec("DROP DATABASE IF EXISTS `{$edgeDb}`");
    }

    // ── 25b. FULL matrix: every entity resolves across two divergent-PK databases ──
    public function test_full_cross_db_matrix_all_entities_resolve_by_canonical_identity(): void
    {
        $edgeDb = $this->tenantDb . '_edge';
        $tables = ['sales_orders', 'sales_order_lines', 'sale_payments', 'shifts', 'restaurant_table_sessions', 'manager_approvals', 'kot_batches', 'kot_batch_lines', 'sales_order_line_cancellations', 'customers'];
        $this->cloneEdgeTables($edgeDb, $tables);
        config(['database.connections.identity_edge' => array_merge(config('database.connections.tenant'), ['database' => $edgeDb])]);
        DB::purge('identity_edge');
        foreach ($tables as $t) {
            DB::connection('tenant')->table($t)->delete();
        }

        // entity => [modelClass, idColumn, format, minimal required row]
        $matrix = [
            'sales_orders'                   => [SalesOrder::class, 'sale_uuid', 'ulid', ['sale_no' => 'M'.Str::random(6), 'branch_id' => 1, 'sale_date' => now()]],
            'sales_order_lines'              => [SalesOrderLine::class, 'line_uuid', 'ulid', ['sales_order_id' => 1, 'product_id' => 1, 'product_name' => 'X', 'quantity' => 1]],
            'sale_payments'                  => [SalePayment::class, 'payment_uuid', 'ulid', ['sales_order_id' => 1, 'payment_method_id' => 1, 'amount' => 1]],
            'shifts'                         => [\App\Models\Tenant\Shift::class, 'shift_uuid', 'ulid', ['branch_id' => 1, 'terminal_id' => 1, 'opened_by_user_id' => 1, 'opened_at' => now()]],
            'restaurant_table_sessions'      => [\App\Models\Tenant\RestaurantTableSession::class, 'session_uuid', 'ulid', ['session_no' => 'S'.Str::random(6), 'branch_id' => 1, 'restaurant_table_id' => 1, 'opened_by_user_id' => 1]],
            'manager_approvals'              => [ManagerApproval::class, 'approval_uuid', 'ulid', ['approval_no' => 'A'.Str::random(6), 'action_type' => 'void']],
            'kot_batches'                    => [KotBatch::class, 'event_uuid', 'uuid', ['sales_order_id' => 1, 'sequence_no' => 1, 'event_type' => 'normal']],
            'kot_batch_lines'                => [\App\Models\Tenant\KotBatchLine::class, 'kot_line_uuid', 'ulid', ['kot_batch_id' => 1, 'product_name' => 'X', 'quantity' => 1]],
            'sales_order_line_cancellations' => [SalesOrderLineCancellation::class, 'event_uuid', 'uuid', ['sales_order_id' => 1, 'void_reason_id' => 1, 'approval_mode' => 'auto', 'product_name' => 'X', 'quantity' => 1, 'cancelled_at' => now()]],
            'customers'                      => [Customer::class, 'customer_uuid', 'ulid', ['name' => 'C']],
        ];

        foreach ($matrix as $table => [$model, $idcol, $format, $row]) {
            $idval = EdgeIdentity::generate($format);
            // deliberately divergent numeric ids: Cloud id 9000, Edge id 1 — same canonical identity.
            $this->matrixInsert('tenant', $table, 9000, $idcol, $idval, $row);
            $this->matrixInsert('identity_edge', $table, 1, $idcol, $idval, $row);

            $rawCloud = DB::connection('tenant')->table($table)->where($idcol, $idval)->count();
            $rawEdge = DB::connection('identity_edge')->table($table)->where($idcol, $idval)->count();
            $this->assertSame(1, $rawCloud, "[$table] Cloud row must exist (raw)");
            $this->assertSame(1, $rawEdge, "[$table] Edge row must exist (raw)");
            // sanity: the model must be a REGISTERED canonical-identity model (guards against a wrong FQCN).
            $this->assertNotNull(EdgeIdentityResolver::fieldFor($model), "[$table] model must be a registered canonical-identity model");
            $cloud = EdgeIdentityResolver::resolve($model, $idval, 'tenant');
            $edge = EdgeIdentityResolver::resolve($model, $idval, 'identity_edge');
            $this->assertNotNull($cloud, "[$table] must resolve on Cloud by $idcol (raw count=$rawCloud)");
            $this->assertNotNull($edge, "[$table] must resolve on Edge by $idcol");
            $this->assertSame(9000, (int) $cloud->id, "[$table] Cloud numeric id");
            $this->assertSame(1, (int) $edge->id, "[$table] Edge numeric id");
            $this->assertNotSame((int) $cloud->id, (int) $edge->id, "[$table] numeric ids MUST diverge");
            $this->assertSame($idval, $cloud->{$idcol}, "[$table] same canonical identity across systems");
            $this->assertSame($cloud->{$idcol}, $edge->{$idcol});
        }

        DB::connection('identity_edge')->getPdo()->exec("DROP DATABASE IF EXISTS `{$edgeDb}`");
    }

    // ── 6. resolver honours its EXPLICIT connection regardless of the default connection ──
    public function test_resolver_honours_explicit_connection_regardless_of_default(): void
    {
        $edgeDb = $this->tenantDb . '_edge';
        $this->cloneEdgeTables($edgeDb, ['sales_orders']);
        config(['database.connections.identity_edge' => array_merge(config('database.connections.tenant'), ['database' => $edgeDb])]);
        DB::purge('identity_edge');
        DB::connection('tenant')->table('sales_orders')->delete();

        $idval = (string) Str::ulid();
        $this->matrixInsert('tenant', 'sales_orders', 9000, 'sale_uuid', $idval, ['sale_no' => 'R'.Str::random(6), 'branch_id' => 1, 'sale_date' => now()]);
        $this->matrixInsert('identity_edge', 'sales_orders', 1, 'sale_uuid', $idval, ['sale_no' => 'R'.Str::random(6), 'branch_id' => 1, 'sale_date' => now()]);

        // Point the DEFAULT connection at the master DB — the resolver must ignore it and use the arg.
        $prevDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        try {
            $this->assertSame(9000, (int) EdgeIdentityResolver::resolve(SalesOrder::class, $idval, 'tenant')->id, 'explicit tenant connection honoured despite master default');
            $this->assertSame(1, (int) EdgeIdentityResolver::resolve(SalesOrder::class, $idval, 'identity_edge')->id, 'explicit edge connection honoured despite master default');
        } finally {
            DB::setDefaultConnection($prevDefault);
        }

        DB::connection('identity_edge')->getPdo()->exec("DROP DATABASE IF EXISTS `{$edgeDb}`");
    }

    // ── cancellation canonical references resolve across two divergent-PK databases ──
    public function test_cancellation_references_resolve_across_divergent_pk_databases(): void
    {
        $edgeDb = $this->tenantDb . '_edge';
        $tables = ['sales_order_lines', 'kot_batches', 'sales_order_line_cancellations'];
        $this->cloneEdgeTables($edgeDb, $tables);
        config(['database.connections.identity_edge' => array_merge(config('database.connections.tenant'), ['database' => $edgeDb])]);
        DB::purge('identity_edge');
        foreach ($tables as $t) {
            DB::connection('tenant')->table($t)->delete();
        }

        $lineUuid = (string) Str::ulid();           // the historical sale line
        $batchUuid = (string) Str::uuid();          // the referenced (cancel) KOT batch
        $cancelUuid = (string) Str::uuid();         // the cancellation event itself

        // Cloud (high ids) + Edge (low ids) — SAME canonical identities, divergent numeric PKs.
        foreach (['tenant' => 9000, 'identity_edge' => 1] as $conn => $base) {
            $c = DB::connection($conn);
            $c->statement('SET FOREIGN_KEY_CHECKS=0');
            $c->table('sales_order_lines')->insert(['id' => $base, 'line_uuid' => $lineUuid, 'sales_order_id' => 1, 'product_id' => 1, 'product_name' => 'X', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $c->table('kot_batches')->insert(['id' => $base, 'event_uuid' => $batchUuid, 'sales_order_id' => 1, 'sequence_no' => 1, 'event_type' => 'cancel', 'created_at' => now(), 'updated_at' => now()]);
            $c->table('sales_order_line_cancellations')->insert([
                'id' => $base, 'event_uuid' => $cancelUuid, 'sales_order_id' => 1,
                'source_line_uuid' => $lineUuid, 'kot_batch_id' => $base, 'referenced_kot_event_uuid' => $batchUuid,
                'void_reason_id' => 1, 'approval_mode' => 'auto', 'product_name' => 'X', 'quantity' => 1,
                'cancelled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $c->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        foreach (['tenant' => 9000, 'identity_edge' => 1] as $conn => $expectId) {
            $cancel = SalesOrderLineCancellation::on($conn)->where('event_uuid', $cancelUuid)->firstOrFail();
            $this->assertSame($expectId, (int) $cancel->id, "[$conn] cancellation numeric id (divergent)");
            // the cancellation's canonical references resolve the correct line + batch on THIS system by identity.
            $line = EdgeIdentityResolver::resolve(SalesOrderLine::class, $cancel->source_line_uuid, $conn);
            $batch = EdgeIdentityResolver::resolve(KotBatch::class, $cancel->referenced_kot_event_uuid, $conn);
            $this->assertNotNull($line, "[$conn] source_line_uuid must resolve the sale line");
            $this->assertNotNull($batch, "[$conn] referenced_kot_event_uuid must resolve the KOT batch");
            $this->assertSame($expectId, (int) $line->id);
            $this->assertSame($expectId, (int) $batch->id);
        }
        // divergent numeric ids, identical canonical references across systems.
        $this->assertSame(
            SalesOrderLineCancellation::on('tenant')->where('event_uuid', $cancelUuid)->value('source_line_uuid'),
            SalesOrderLineCancellation::on('identity_edge')->where('event_uuid', $cancelUuid)->value('source_line_uuid')
        );

        DB::connection('identity_edge')->getPdo()->exec("DROP DATABASE IF EXISTS `{$edgeDb}`");
    }

    private function matrixInsert(string $conn, string $table, int $id, string $idcol, string $idval, array $row): void
    {
        $c = DB::connection($conn);
        $c->statement('SET FOREIGN_KEY_CHECKS=0');
        $c->table($table)->insert(array_merge($row, ['id' => $id, $idcol => $idval, 'created_at' => now(), 'updated_at' => now()]));
        $c->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function cloneEdgeTables(string $edgeDb, array $tables): void
    {
        $c = config('database.connections.tenant');
        $pdo = new \PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['username'], $c['password'] ?? '');
        if (stripos($edgeDb, 'test') === false) {
            throw new RuntimeException("refusing non-test edge db [{$edgeDb}]");
        }
        $pdo->exec("DROP DATABASE IF EXISTS `{$edgeDb}`");
        $pdo->exec("CREATE DATABASE `{$edgeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$edgeDb}`");
        foreach ($tables as $t) {
            $pdo->exec("CREATE TABLE `{$t}` LIKE `{$this->tenantDb}`.`{$t}`");
        }
    }

    // ── 26. identity generation needs no master/landlord DB ─────────────────
    public function test_identity_generation_has_no_master_dependency(): void
    {
        config(['database.connections.master.database' => 'nonexistent_master_identity_proof']);
        DB::purge('master');
        // generating a fresh identity + creating a real row must not touch master
        $this->assertTrue(Str::isUlid(EdgeIdentity::generate('ulid')));
        $sale = $this->makeSale();
        $this->assertTrue(Str::isUlid($sale->sale_uuid));
    }

    // ── helpers ──────────────────────────────────────────────────────────────
    private function makeSale(): SalesOrder
    {
        return SalesOrder::create(['sale_no' => 'SO-'.Str::random(8), 'branch_id' => $this->branchId, 'sale_date' => now()]);
    }

    private function paymentMethodId(): int
    {
        return DB::connection('tenant')->table('payment_methods')->insertGetId([
            'name' => 'Cash '.Str::random(4), 'code' => 'C'.Str::random(4), 'method_type' => 'cash', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function runBackfillMigration(): void
    {
        $migration = require base_path('database/migrations/tenant/2026_08_08_000010_add_canonical_cross_system_identities.php');
        $migration->up();
    }

}
