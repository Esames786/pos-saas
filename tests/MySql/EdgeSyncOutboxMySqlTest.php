<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeSaleEnvelopeBuilder;
use App\Services\Edge\EdgeSyncOutboxService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/** §13 failure injection: the container auto-wires this subclass; only the seam differs. */
class ThrowingOutboxPosService extends EdgeLocalPosService
{
    protected function beforeOutboxInsert(): void
    {
        throw new RuntimeException('INJECTED_OUTBOX_FAILURE');
    }
}

/**
 * OFFLINE-SYNC-ENGINE-1B — the immutable sale envelope + durable outbox, proven through the REAL
 * Edge POS runtime (bound meta, authenticated cashier, active terminal, open shift, accepted
 * operational baseline). Matrix §14: one outbox per paid sale (quick/takeaway/dine-in settle),
 * none for holds/rounds, replay-safe, config-revision + epoch frozen per sale, canonical
 * identities + no secrets in the envelope, deterministic reproducible hash, immutability +
 * state-machine guards, whole-transaction rollback, and the concurrent lease primitive.
 */
class EdgeSyncOutboxMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $productId;
    private int $cashMethodId;
    private int $cardMethodId;
    private int $tableId;
    private int $baselineId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox',
            'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta',
            'sales_ledgers', 'kot_batch_lines', 'kot_batches', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CASH' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->cardMethodId = $this->makePaymentMethod(['method_type' => 'card', 'code' => 'CARD', 'name' => 'Card']);
        $this->tableId = $this->makeTable($this->branchId);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 10);
        $this->asBranchServerRuntime();
        $this->baselineId = (int) $this->acceptTestBaseline([
            ['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50],
        ])->id;
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function openShift(): \App\Models\Tenant\Shift
    {
        return app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 0.0);
    }

    private function user(): User
    {
        return User::on('tenant')->find($this->userId);
    }

    private function complete(array $overrides = []): SalesOrder
    {
        $data = array_merge([
            'order_type' => 'quick_sale', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200]],
        ], $overrides);

        return app(EdgeLocalPosService::class)->completePaidSale($data, $this->user(), $this->terminalId);
    }

    private function outboxFor(SalesOrder $sale): ?EdgeSyncOutbox
    {
        return EdgeSyncOutbox::query()->where('sale_uuid', $sale->sale_uuid)->first();
    }

    // ── §14: one outbox per paid sale, envelope content ──────────────────────

    public function test_quick_paid_sale_creates_exactly_one_immutable_outbox_row(): void
    {
        $shift = $this->openShift();
        $sale = $this->complete();

        $this->assertSame(1, EdgeSyncOutbox::query()->count());
        $row = $this->outboxFor($sale);
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $row->state);
        $this->assertSame('edge-sale-envelope-v1', $row->envelope_schema_version);
        $this->assertSame(10, (int) $row->config_revision, 'config revision frozen from the binding at sale time');
        $this->assertSame(1, (int) $row->activation_epoch);

        $env = $row->envelopeArray();
        // Canonical identities — never local numeric PKs for transactional rows.
        $this->assertSame($sale->sale_uuid, $env['sale_uuid']);
        $this->assertSame($sale->sale_no, $env['sale_no']);
        $this->assertSame($sale->lines()->first()->line_uuid, $env['lines'][0]['line_uuid']);
        $this->assertSame($sale->payments()->first()->payment_uuid, $env['payments'][0]['payment_uuid']);
        $this->assertSame($shift->shift_uuid, $env['shift']['shift_uuid']);
        $this->assertSame('test-device-uuid', $env['device_public_uuid']);
        $this->assertSame(42, (int) $env['tenant_id']);
        $this->assertSame($this->branchId, (int) $env['branch_id']);
        $this->assertSame('edge-config-v1', $env['config_schema_version']);
        // Frozen commercial content.
        $this->assertSame('quick_sale', $env['order_type']);
        $this->assertSame(200.0, (float) $env['totals']['grand_total']);
        $this->assertSame(2.0, (float) $env['lines'][0]['quantity']);
        $this->assertSame(100.0, (float) $env['lines'][0]['unit_price']);
        $this->assertSame('cash', $env['payments'][0]['method_type']);
        $this->assertSame($shift->business_date->toDateString(), $env['business_date']);
        // Operational evidence + local facts.
        $this->assertTrue($env['operational_stock']['posted']);
        $this->assertNotNull($env['operational_stock']['baseline_uuid']);
        $this->assertSame('pending', $env['local_state']['edge_sync_state']);
        $this->assertFalse($env['local_state']['inventory_posted']);

        // No secret material anywhere in the stored envelope bytes.
        $blob = strtolower((string) $row->envelope);
        foreach (['password', 'pin', 'credential_hash', 'device_secret', 'remember_token', '"secret"'] as $needle) {
            $this->assertStringNotContainsString($needle, $blob, "envelope must not contain [$needle]");
        }

        // §12: discovery state stays 'pending' — nothing marks synced in this slice.
        $this->assertSame('pending', $sale->fresh()->edge_sync_state);
    }

    public function test_takeaway_paid_sale_creates_one_outbox_row(): void
    {
        $this->openShift();
        $sale = $this->complete(['order_type' => 'takeaway']);

        $this->assertSame(1, EdgeSyncOutbox::query()->count());
        $this->assertSame('takeaway', $this->outboxFor($sale)->envelopeArray()['order_type']);
    }

    public function test_dine_in_held_rounds_produce_no_outbox_until_final_settlement(): void
    {
        $this->openShift();
        $pos = app(EdgeLocalPosService::class);
        $session = $pos->openTableSession($this->tableId, ['guest_count' => 2], $this->user(), $this->terminalId);

        // Hold (round 1) -> ZERO outbox.
        $sale = $pos->holdOrReviseSale([
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $session->id,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
        ], $this->user(), $this->terminalId);
        $this->assertSame(0, EdgeSyncOutbox::query()->count(), 'a held sale must not create an outbox row');

        // Add Round (revise) -> STILL zero.
        $sale = $pos->holdOrReviseSale([
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $session->id, 'held_sale_id' => $sale->id,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1], ['product_id' => $this->productId, 'quantity' => 2]],
        ], $this->user(), $this->terminalId);
        $this->assertSame(0, EdgeSyncOutbox::query()->count(), 'an Add Round must not create an outbox row');

        // A KOT identity exists for the check — the final envelope must snapshot it.
        $kotUuid = (string) Str::uuid();
        DB::connection('tenant')->table('kot_batches')->insert([
            'event_uuid' => $kotUuid, 'sales_order_id' => $sale->id, 'sequence_no' => 1, 'event_type' => 'new',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Final settlement -> exactly ONE outbox row for the FINAL commercial state.
        $settled = $pos->settleHeldSale($sale->id, [
            'client_uuid' => (string) Str::uuid(),
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => (float) $sale->fresh()->grand_total]],
        ], $this->user(), $this->terminalId);

        $this->assertSame(1, EdgeSyncOutbox::query()->count());
        $env = $this->outboxFor($settled)->envelopeArray();
        $this->assertSame('dine_in', $env['order_type']);
        $this->assertSame($session->session_uuid, $env['table_session']['session_uuid'], 'dine-in envelope snapshots the session identity');
        $this->assertSame([$kotUuid], array_column($env['kot_events'], 'event_uuid'), 'KOT event identity snapshotted');
        $this->assertSame(3.0, (float) $env['lines'][0]['quantity'] + (float) ($env['lines'][1]['quantity'] ?? 0), 'envelope carries the FINAL revised quantities');
        $this->assertSame((float) $settled->grand_total, (float) $env['totals']['grand_total']);
    }

    public function test_pos_replay_returns_same_sale_and_creates_no_second_outbox(): void
    {
        $this->openShift();
        $clientUuid = (string) Str::uuid();
        $sale1 = $this->complete(['client_uuid' => $clientUuid]);
        $sale2 = $this->complete(['client_uuid' => $clientUuid]); // identical replay

        $this->assertSame($sale1->id, $sale2->id);
        $this->assertSame($sale1->sale_uuid, $sale2->sale_uuid);
        $this->assertSame(1, EdgeSyncOutbox::query()->count(), 'a replay must not create a second outbox row');
        $this->assertSame($this->outboxFor($sale1)->content_hash, $this->outboxFor($sale2)->content_hash);
    }

    // ── §5/§14: config revision + epoch frozen per sale ──────────────────────

    public function test_config_revision_and_epoch_are_frozen_per_sale_across_refreshes(): void
    {
        $this->openShift();
        $saleA = $this->complete();
        $this->assertSame(10, (int) $this->outboxFor($saleA)->config_revision);

        // Config refresh N -> N+1 (and a later appliance generation) lands AFTER sale A. A real
        // epoch bump comes with a fresh epoch-bound operational baseline — mirror that here.
        DB::connection('tenant')->table('edge_local_meta')->where('singleton_guard', 1)
            ->update(['last_applied_config_revision' => 11, 'activation_epoch' => 2]);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);
        $this->acceptTestBaseline([
            ['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50],
        ]);

        $saleB = $this->complete();

        $rowA = $this->outboxFor($saleA)->fresh();
        $rowB = $this->outboxFor($saleB);
        $this->assertSame(10, (int) $rowA->config_revision, 'sale A stays at the revision it was created under');
        $this->assertSame(1, (int) $rowA->activation_epoch, 'sale A epoch frozen');
        $this->assertSame(10, (int) $rowA->envelopeArray()['config_revision'], 'envelope bytes frozen too');
        $this->assertSame(11, (int) $rowB->config_revision, 'sale B carries the new revision');
        $this->assertSame(2, (int) $rowB->activation_epoch);
    }

    // ── §9/§14: hash contract ────────────────────────────────────────────────

    public function test_content_hash_is_deterministic_and_reproducible_from_stored_bytes(): void
    {
        $this->openShift();
        $sale = $this->complete();
        $row = $this->outboxFor($sale);

        // Reproducible: stored canonical bytes minus content_hash re-hash to the stored hash.
        $decoded = $row->envelopeArray();
        $storedHash = $decoded['content_hash'];
        unset($decoded['content_hash']);
        $recomputed = hash('sha256', app(\App\Services\Edge\EdgeBootstrapService::class)->canonicalJson($decoded));
        $this->assertSame($storedHash, $recomputed);
        $this->assertSame($storedHash, $row->content_hash);

        // Deterministic: rebuilding from the same persisted model state yields identical bytes+hash.
        $builder = app(EdgeSaleEnvelopeBuilder::class);
        $meta = EdgeLocalMeta::current();
        $again = $builder->build($sale->fresh(), $meta);
        $this->assertSame($storedHash, $again['content_hash']);
        $this->assertSame((string) $row->envelope, $builder->canonicalEnvelopeJson($again));

        // A different commercial state produces a different hash.
        $other = $this->complete(['lines' => [['product_id' => $this->productId, 'quantity' => 3]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 300]]]);
        $this->assertNotSame($storedHash, $this->outboxFor($other)->content_hash);

        // Post-creation sale/config changes never rewrite the envelope (immutability by guard).
        DB::connection('tenant')->table('edge_local_meta')->where('singleton_guard', 1)->update(['last_applied_config_revision' => 99]);
        $this->assertSame($storedHash, $this->outboxFor($sale)->fresh()->content_hash);
    }

    // ── §3/§11/§14: immutability + state machine ─────────────────────────────

    public function test_outbox_row_is_immutable_and_transitions_are_guarded(): void
    {
        $this->openShift();
        $row = $this->outboxFor($this->complete());

        foreach (['envelope' => '{}', 'content_hash' => str_repeat('0', 64), 'sale_uuid' => (string) Str::ulid(), 'config_revision' => 99, 'activation_epoch' => 9, 'envelope_schema_version' => 'edge-sale-envelope-v9'] as $field => $value) {
            try {
                $row->fresh()->update([$field => $value]);
                $this->fail("updating immutable [$field] must throw");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }

        // Invalid direct transition pending -> acknowledged refused (only leased may ack).
        try {
            $row->fresh()->update(['state' => EdgeSyncOutbox::STATE_ACKNOWLEDGED]);
            $this->fail('pending -> acknowledged must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invalid edge_sync_outbox state transition', $e->getMessage());
        }

        // Append-only: no delete path.
        try {
            $row->fresh()->delete();
            $this->fail('outbox rows must never be deleted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // Delivery metadata (non-state) stays updatable.
        $row->fresh()->update(['last_error' => 'transient note']);
        $this->assertSame('transient note', $row->fresh()->last_error);
    }

    // ── §13/§14: whole-transaction rollback ──────────────────────────────────

    public function test_failure_before_outbox_insert_rolls_back_the_entire_sale_transaction(): void
    {
        $this->openShift();
        $clientUuid = (string) Str::uuid();
        $onHandBefore = $this->edgeOnHand($this->baselineId, $this->productId);

        try {
            app(ThrowingOutboxPosService::class)->completePaidSale([
                'order_type' => 'quick_sale', 'client_uuid' => $clientUuid,
                'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
                'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200]],
            ], $this->user(), $this->terminalId);
            $this->fail('injected outbox failure must abort the sale');
        } catch (RuntimeException $e) {
            $this->assertSame('INJECTED_OUTBOX_FAILURE', $e->getMessage());
        }

        $conn = DB::connection('tenant');
        $this->assertSame(0, $conn->table('sales_orders')->count(), 'no paid sale without its envelope');
        $this->assertSame(0, EdgeSyncOutbox::query()->count(), 'no envelope without its sale');
        $this->assertSame(0, $conn->table('sales_ledgers')->count(), 'settlement rolled back');
        $this->assertSame($onHandBefore, $this->edgeOnHand($this->baselineId, $this->productId), 'operational stock rolled back');
        $this->assertSame(0.0, (float) $conn->table('shifts')->value('total_sales'), 'shift counters rolled back');

        // Retry with the REAL service and the SAME client_uuid: sale + outbox commit exactly once.
        $sale = $this->complete(['client_uuid' => $clientUuid]);
        $this->assertSame(1, $conn->table('sales_orders')->count());
        $this->assertSame(1, EdgeSyncOutbox::query()->count());
        $this->assertNotNull($this->outboxFor($sale));
    }

    // ── §10/§14: lease primitive ─────────────────────────────────────────────

    public function test_two_workers_racing_the_lease_each_own_a_distinct_row(): void
    {
        $this->openShift();
        $this->complete();
        $this->complete();
        $svc = app(EdgeSyncOutboxService::class);

        // Worker A claims via a genuinely independent connection running the SAME atomic claim.
        $c = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->prepare(
            "UPDATE edge_sync_outbox SET state='leased', lease_owner=?, lease_expires_at=DATE_ADD(NOW(), INTERVAL 120 SECOND), attempts=attempts+1, first_sent_at=COALESCE(first_sent_at, NOW()), updated_at=NOW()
             WHERE (state='pending' OR (state='leased' AND lease_expires_at IS NOT NULL AND lease_expires_at <= NOW())) ORDER BY id LIMIT 1"
        );
        $stmt->execute(['worker-A:claim1']);
        $this->assertSame(1, $stmt->rowCount(), 'worker A claims exactly one row');

        $rowB = $svc->lease('worker-B');
        $this->assertNotNull($rowB, 'worker B claims the OTHER row');

        $owners = EdgeSyncOutbox::query()->where('state', EdgeSyncOutbox::STATE_LEASED)->pluck('lease_owner');
        $this->assertCount(2, $owners);
        $this->assertCount(2, $owners->unique(), 'no row has two owners; each worker owns a distinct row');

        // Nothing left to claim: a third worker gets null.
        $this->assertNull($svc->lease('worker-C'));
    }

    public function test_expired_lease_is_reclaimable_and_release_requeues(): void
    {
        $this->openShift();
        $this->complete();
        $svc = app(EdgeSyncOutboxService::class);

        $row = $svc->lease('worker-A');
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNull($svc->lease('worker-B'), 'a live lease is exclusive');

        // Lease expiry -> reclaimable (release+lease semantics, attempts increments again).
        $row->update(['lease_expires_at' => now()->subSecond()]);
        $reclaimed = $svc->lease('worker-B');
        $this->assertNotNull($reclaimed);
        $this->assertSame($row->id, $reclaimed->id);
        $this->assertSame(2, (int) $reclaimed->attempts);
        $this->assertStringStartsWith('worker-B:', $reclaimed->lease_owner);

        // Retryable release -> pending again, eligible immediately.
        $svc->releaseLease($reclaimed, 'network unreachable');
        $again = $svc->lease('worker-C');
        $this->assertSame($row->id, $again->id);
        $this->assertSame('network unreachable', $again->last_error);
    }

    public function test_acknowledged_rows_are_terminal_and_never_re_leased(): void
    {
        $this->openShift();
        $sale = $this->complete();
        $svc = app(EdgeSyncOutboxService::class);

        $row = $svc->lease('worker-A');

        // ACK primitive refuses a mismatched identity (1D will verify a REAL Cloud ACK with this).
        try {
            $svc->markAcknowledged($row, 'ing-1', ['sale_uuid' => $row->sale_uuid, 'content_hash' => 'wrong']);
            $this->fail('an ACK that does not identify the envelope must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('OUTBOX_ACK_MISMATCH', $e->getMessage());
        }

        $svc->markAcknowledged($row, 'ing-1', ['sale_uuid' => $row->sale_uuid, 'content_hash' => $row->content_hash, 'status' => 'applied']);
        $fresh = $row->fresh();
        $this->assertSame(EdgeSyncOutbox::STATE_ACKNOWLEDGED, $fresh->state);
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertSame('ing-1', $fresh->ack_ingestion_uuid);

        // Retained + terminal: never re-leased, never deleted; sale discovery state UNTOUCHED (§12).
        $this->assertNull($svc->lease('worker-B'), 'acknowledged rows are never re-leased');
        $this->assertSame(1, EdgeSyncOutbox::query()->count());
        $this->assertSame('pending', $sale->fresh()->edge_sync_state, 'edge_sync_state flips only via the future verified-ACK service');
    }

    // ── §8/§14: unsupported offline content fails closed ─────────────────────

    public function test_unsupported_offline_content_is_refused_by_the_envelope_builder(): void
    {
        $this->openShift();
        $sale = $this->complete();
        $builder = app(EdgeSaleEnvelopeBuilder::class);
        $meta = EdgeLocalMeta::current();

        // A card payment somehow reaching finalization must fail closed (defense in depth —
        // the POS gate already refuses it; the builder must too).
        DB::connection('tenant')->table('sale_payments')->where('sales_order_id', $sale->id)
            ->update(['payment_method_id' => $this->cardMethodId]);
        try {
            $builder->build($sale->fresh(), $meta);
            $this->fail('a card payment must never become a sync envelope');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ENVELOPE_UNSUPPORTED', $e->getMessage());
        }
        DB::connection('tenant')->table('sale_payments')->where('sales_order_id', $sale->id)
            ->update(['payment_method_id' => $this->cashMethodId]);

        // A discount that slipped through must fail closed.
        DB::connection('tenant')->table('sales_orders')->where('id', $sale->id)->update(['discount_amount' => 5]);
        try {
            $builder->build($sale->fresh(), $meta);
            $this->fail('a discounted sale must fail closed in V1');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ENVELOPE_UNSUPPORTED', $e->getMessage());
        }

        // An unpaid sale can never become an envelope.
        DB::connection('tenant')->table('sales_orders')->where('id', $sale->id)->update(['discount_amount' => 0, 'status' => 'held']);
        try {
            $builder->build($sale->fresh(), $meta);
            $this->fail('only a PAID sale may become an envelope');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ENVELOPE_UNSUPPORTED', $e->getMessage());
        }
    }
}
