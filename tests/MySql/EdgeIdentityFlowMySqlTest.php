<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\HeldSaleController;
use App\Http\Controllers\Tenant\SalesOrderController;
use App\Http\Controllers\Tenant\SplitBillController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\KotBatch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Printer;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Models\Tenant\SalesOrderLineCancellation;
use App\Services\Printing\PrintJobService;
use App\Services\Sales\KotCancellationService;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-IDENTITY-FINAL-PROOF-1 — identities proven through the REAL application controllers/services
 * (invoked via the container with an authenticated tenant user + open shift), NOT mirrored model
 * expressions. These tests fail if the controller wiring changes.
 *
 * HONEST contract established here (verified against the real code, not assumed):
 *  - `sale_uuid` is the DURABLE sale identity — stable across held->pay and Add Round (the sales_orders row
 *    is UPDATED, never recreated) and across an idempotent Direct-Pay replay.
 *  - `line_uuid`/`payment_uuid` identify the CURRENT row. Held re-saves (Add Round, held->pay) DELETE and
 *    recreate the lines/payments (HeldSaleController::store `$sale->lines()->delete()`), so those identities
 *    are stable for a finalized sale + its replays, but are NOT preserved across a held re-save. Because of
 *    that churn (+ nullOnDelete FKs), a KOT line / cancellation captures the source line's canonical id as an
 *    IMMUTABLE snapshot so the historical event stays cross-system resolvable.
 */
class EdgeIdentityFlowMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private ?string $originalDefaultConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultConnection = config('database.default');
        DB::setDefaultConnection('tenant');
    }

    protected function tearDown(): void
    {
        if ($this->originalDefaultConnection) {
            DB::setDefaultConnection($this->originalDefaultConnection);
        }
        parent::tearDown();
    }

    private function actingAsTenant(): int
    {
        $userId = $this->makeUser();
        $this->actingAs(User::on('tenant')->find($userId), 'tenant');
        Auth::shouldUse('tenant');

        return $userId;
    }

    private function openShift(int $branchId, int $terminalId, int $userId): Shift
    {
        return app(ShiftService::class)->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $userId, 0.0);
    }

    private function req(array $body): Request
    {
        $r = Request::create('/', 'POST', $body);
        $r->headers->set('Accept', 'application/json');

        return $r;
    }

    /** @return array{branchId:int,terminalId:int,productId:int,pmId:int,userId:int} */
    private function scaffold(): array
    {
        $this->cleanTenant(['kot_batch_lines', 'kot_batches', 'sale_payments', 'sales_order_lines', 'sales_orders', 'products', 'categories', 'payment_methods', 'shifts', 'terminals', 'branches']);
        $userId = $this->actingAsTenant();
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi', 'allow_negative_stock' => 1]);
        $terminalId = $this->makeTerminal($branchId);
        $productId = $this->makeProduct($this->makeCategory(), ['is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active']);
        $pmId = $this->makePaymentMethod();
        $this->openShift($branchId, $terminalId, $userId);

        return compact('branchId', 'terminalId', 'productId', 'pmId', 'userId');
    }

    // ── A. Direct Pay + REAL idempotent replay ───────────────────────────────
    public function test_direct_pay_and_real_replay_preserve_sale_identity_no_duplicates(): void
    {
        ['branchId' => $b, 'terminalId' => $t, 'productId' => $p, 'pmId' => $pm] = $this->scaffold();
        $clientUuid = (string) Str::uuid();
        $payload = [
            'branch_id' => $b, 'terminal_id' => $t, 'order_type' => 'quick_sale', 'order_source' => 'pos',
            'discount_type' => 'none', 'client_uuid' => $clientUuid,
            'lines' => [['product_id' => $p, 'quantity' => 1, 'unit_price' => 100]],
            'payments' => [['payment_method_id' => $pm, 'amount' => 100]],
        ];

        $resp1 = app()->call([app(SalesOrderController::class), 'store'], ['request' => $this->req($payload)]);
        $data1 = json_decode($resp1->getContent(), true);
        $this->assertArrayHasKey('sale_id', $data1 ?? [], 'Direct Pay must return a sale: ' . $resp1->getContent());
        $sale1 = SalesOrder::on('tenant')->find($data1['sale_id']);
        $saleUuid = $sale1->sale_uuid;
        $lineUuid = $sale1->lines()->first()->line_uuid;
        $paymentUuid = $sale1->payments()->first()->payment_uuid;
        $this->assertTrue(Str::isUlid($saleUuid) && Str::isUlid($lineUuid) && Str::isUlid($paymentUuid));

        $salesBefore = SalesOrder::on('tenant')->count();
        $paymentsBefore = $sale1->payments()->count();

        // REAL replay: same client_uuid + payload through the actual controller (idempotentReplayOrThrow).
        $resp2 = app()->call([app(SalesOrderController::class), 'store'], ['request' => $this->req($payload)]);
        $data2 = json_decode($resp2->getContent(), true);
        $this->assertSame($sale1->id, (int) ($data2['sale_id'] ?? 0), 'replay must resolve the SAME sale row');
        $this->assertSame($salesBefore, SalesOrder::on('tenant')->count(), 'replay must NOT create a duplicate sale');

        $sale1->refresh();
        $this->assertSame($saleUuid, $sale1->sale_uuid, 'sale_uuid unchanged across replay');
        $this->assertSame($paymentsBefore, $sale1->payments()->count(), 'replay must NOT create a duplicate payment');
        $this->assertSame($lineUuid, $sale1->lines()->first()->line_uuid, 'line identity unchanged across replay');
        $this->assertSame($paymentUuid, $sale1->payments()->first()->payment_uuid, 'payment identity unchanged across replay');
    }

    // ── B. Hold -> REAL Add Round: sale_uuid stable; lines are re-created (honest churn) ─
    public function test_add_round_keeps_sale_identity_and_recreates_lines_with_new_identities(): void
    {
        ['branchId' => $b, 'terminalId' => $t, 'productId' => $p] = $this->scaffold();

        $resp1 = app()->call([app(HeldSaleController::class), 'store'], ['request' => $this->req([
            'branch_id' => $b, 'terminal_id' => $t, 'order_type' => 'quick_sale', 'discount_type' => 'none',
            'lines' => [['product_id' => $p, 'quantity' => 1, 'unit_price' => 50, 'client_line_key' => 'a']],
        ])]);
        $heldId = json_decode($resp1->getContent(), true)['sale_id'];
        $held = SalesOrder::on('tenant')->find($heldId);
        $saleUuid = $held->sale_uuid;
        $origLineUuid = $held->lines()->first()->line_uuid;

        // Add Round through the REAL controller (re-sends existing + a new line).
        $resp2 = app()->call([app(HeldSaleController::class), 'store'], ['request' => $this->req([
            'held_sale_id' => $heldId, 'branch_id' => $b, 'terminal_id' => $t, 'order_type' => 'quick_sale', 'discount_type' => 'none',
            'lines' => [
                ['product_id' => $p, 'quantity' => 1, 'unit_price' => 50, 'client_line_key' => 'a'],
                ['product_id' => $p, 'quantity' => 2, 'unit_price' => 50, 'client_line_key' => 'b'],
            ],
        ])]);
        $this->assertSame(200, $resp2->getStatusCode(), 'Add Round must succeed: ' . $resp2->getContent());

        $held->refresh();
        // The SALE identity is stable (same row) — this is the durable cross-system sale identity.
        $this->assertSame($saleUuid, $held->sale_uuid, 'sale_uuid is stable across Add Round');
        // Honest: the controller deletes+recreates lines, so the original line_uuid no longer exists and
        // every current line carries a fresh, unique line_uuid.
        $current = $held->lines()->pluck('line_uuid')->all();
        $this->assertCount(2, $current);
        $this->assertNotContains($origLineUuid, $current, 'Add Round re-creates lines: original line_uuid is not reused');
        $this->assertSame(count($current), count(array_unique($current)), 'each current line has a distinct identity');
        foreach ($current as $u) {
            $this->assertTrue(Str::isUlid($u));
        }
    }

    // ── C. REAL Split Bill: parent identity unchanged, child distinct ────────
    public function test_split_creates_child_with_distinct_sale_identity_parent_unchanged(): void
    {
        ['branchId' => $b, 'terminalId' => $t, 'productId' => $p] = $this->scaffold();

        $resp1 = app()->call([app(HeldSaleController::class), 'store'], ['request' => $this->req([
            'branch_id' => $b, 'terminal_id' => $t, 'order_type' => 'quick_sale', 'discount_type' => 'none',
            'lines' => [
                ['product_id' => $p, 'quantity' => 2, 'unit_price' => 30, 'client_line_key' => 'a'],
                ['product_id' => $p, 'quantity' => 1, 'unit_price' => 70, 'client_line_key' => 'b'],
            ],
        ])]);
        $parent = SalesOrder::on('tenant')->find(json_decode($resp1->getContent(), true)['sale_id']);
        $parentUuid = $parent->sale_uuid;
        $lineToSplit = $parent->lines()->first();

        $resp2 = app()->call([app(SplitBillController::class), 'store'], [
            'request' => $this->req(['lines' => [['sales_order_line_id' => $lineToSplit->id, 'quantity' => 1]]]),
            'salesOrder' => $parent,
        ]);
        $this->assertContains($resp2->getStatusCode(), [200, 302], 'Split must succeed: ' . $resp2->getContent());

        $child = SalesOrder::on('tenant')->where('id', '!=', $parent->id)->latest('id')->first();
        $this->assertNotNull($child, 'Split must create a child sale');
        $this->assertSame($parentUuid, $parent->fresh()->sale_uuid, 'parent sale_uuid unchanged by split');
        $this->assertTrue(Str::isUlid($child->sale_uuid));
        $this->assertNotSame($parentUuid, $child->sale_uuid, 'child sale is a distinct business object with its own identity');
        // any child lines are freshly created rows with their own identities
        foreach ($child->lines as $cl) {
            $this->assertTrue(Str::isUlid($cl->line_uuid));
            $this->assertNotSame($lineToSplit->line_uuid, $cl->line_uuid, 'split child line is a new row with a new identity');
        }
    }

    // ── D. REAL KOT batch: identities + source-line snapshot survives line churn ──
    public function test_kot_batch_captures_source_line_uuid_snapshot_that_survives_line_deletion(): void
    {
        ['branchId' => $b, 'terminalId' => $t, 'productId' => $p] = $this->scaffold();
        $resp = app()->call([app(HeldSaleController::class), 'store'], ['request' => $this->req([
            'branch_id' => $b, 'terminal_id' => $t, 'order_type' => 'quick_sale', 'discount_type' => 'none',
            'lines' => [['product_id' => $p, 'quantity' => 2, 'unit_price' => 40, 'client_line_key' => 'a']],
        ])]);
        $sale = SalesOrder::on('tenant')->find(json_decode($resp->getContent(), true)['sale_id']);
        $line = $sale->lines()->first();
        $lineUuid = $line->line_uuid;

        // Real KOT batch creation service (createKotBatch) — sends the line to the kitchen.
        $svc = app(PrintJobService::class);
        $m = new \ReflectionMethod($svc, 'createKotBatch');
        $m->setAccessible(true);
        $batch = $m->invoke($svc, $sale, [(string) $line->id => 2.0], 'normal');

        $this->assertInstanceOf(KotBatch::class, $batch);
        $this->assertTrue(Str::isUuid($batch->event_uuid), 'KOT batch has its canonical event_uuid');
        $kotLine = $batch->lines()->first();
        $this->assertTrue(Str::isUlid($kotLine->kot_line_uuid), 'KOT line has its canonical kot_line_uuid');
        $this->assertSame($lineUuid, $kotLine->source_line_uuid, 'KOT line snapshots the source line canonical identity');

        // Simulate the Add Round churn: the source line is deleted (nullOnDelete nulls the numeric FK)...
        $eventUuid = $batch->event_uuid;
        $kotLineUuid = $kotLine->kot_line_uuid;
        SalesOrderLine::on('tenant')->whereKey($line->id)->delete();
        $kotLine->refresh();
        // ...the numeric link is gone, but the canonical snapshot + KOT identities survive intact.
        $this->assertNull($kotLine->sales_order_line_id, 'numeric FK is nulled by the delete');
        $this->assertSame($lineUuid, $kotLine->source_line_uuid, 'canonical source-line snapshot SURVIVES the line deletion');
        $this->assertSame($kotLineUuid, $kotLine->kot_line_uuid, 'KOT line identity is unchanged');
        $this->assertSame($eventUuid, $batch->fresh()->event_uuid, 'KOT batch identity is unchanged');
    }

    // ── E. REAL cancellation: captures canonical source snapshots that survive line churn ──
    public function test_cancellation_captures_source_snapshots_that_survive_line_deletion(): void
    {
        $s = $this->scaffold();
        $b = $s['branchId'];
        $t = $s['terminalId'];
        $p = $s['productId'];
        $userId = $s['userId'];
        // auto-approve mode so the cancellation needs no manager, + grant the void permission to the user.
        DB::connection('tenant')->table('branches')->where('id', $b)->update([
            'held_kot_cancellation_approval_mode' => Branch::KOT_CANCELLATION_MANAGER_REQUIRED,
            'held_kot_line_cancellation_approval_mode' => Branch::KOT_CANCELLATION_AUTO_APPROVE,
        ]);
        $c = DB::connection('tenant');
        $permId = $c->table('permissions')->where('name', 'tenant.pos.void-kot-item')->where('guard_name', 'tenant')->value('id')
            ?? $c->table('permissions')->insertGetId(['name' => 'tenant.pos.void-kot-item', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $c->table('model_has_permissions')->updateOrInsert(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $userId]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $reasonId = $c->table('void_reasons')->insertGetId(['name' => 'Wrong order', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // Hold a sale, send its line to the kitchen (real KOT batch), mark it KOT-sent.
        $resp = app()->call([app(HeldSaleController::class), 'store'], ['request' => $this->req([
            'branch_id' => $b, 'terminal_id' => $t, 'order_type' => 'quick_sale', 'discount_type' => 'none',
            'lines' => [['product_id' => $p, 'quantity' => 2, 'unit_price' => 40, 'client_line_key' => 'a']],
        ])]);
        $sale = SalesOrder::on('tenant')->find(json_decode($resp->getContent(), true)['sale_id']);
        $line = $sale->lines()->first();
        $lineUuid = $line->line_uuid;

        $svc = app(PrintJobService::class);
        $m = new \ReflectionMethod($svc, 'createKotBatch');
        $m->setAccessible(true);
        $batch = $m->invoke($svc, $sale, [(string) $line->id => 2.0], 'normal');
        $batchEventUuid = $batch->event_uuid;
        SalesOrderLine::on('tenant')->whereKey($line->id)->update(['kot_sent' => true, 'kot_sent_quantity' => 2]);

        // REAL cancellation service.
        app(KotCancellationService::class)->recordLineCancellations(
            $sale->fresh(),
            [['line_id' => $line->id, 'quantity' => 1, 'reason_id' => $reasonId]],
            $userId
        );

        $cancel = SalesOrderLineCancellation::on('tenant')->where('sales_order_id', $sale->id)->first();
        $this->assertNotNull($cancel, 'a durable cancellation record must be created');
        $this->assertSame(Branch::KOT_CANCELLATION_AUTO_APPROVE, $cancel->approval_mode);
        $this->assertSame('line', $cancel->policy_snapshot['scope'] ?? null);
        $this->assertTrue(Str::isUuid($cancel->event_uuid), 'cancellation has its canonical event_uuid');
        $this->assertSame($lineUuid, $cancel->source_line_uuid, 'cancellation snapshots the source line canonical identity');
        // referenced_kot_event_uuid is the canonical form of whatever kot_batch_id references (the cancel batch the
        // service links) — so the numeric kot_batch FK becomes cross-system resolvable.
        $this->assertNotNull($cancel->kot_batch_id);
        $referencedBatchUuid = KotBatch::on('tenant')->whereKey($cancel->kot_batch_id)->value('event_uuid');
        $this->assertSame($referencedBatchUuid, $cancel->referenced_kot_event_uuid, 'referenced_kot_event_uuid is the canonical identity of the referenced KOT batch');
        $this->assertTrue(Str::isUuid($cancel->referenced_kot_event_uuid));

        // Add Round churn deletes the source line — the cancellation must remain self-contained.
        SalesOrderLine::on('tenant')->whereKey($line->id)->delete();
        $cancel->refresh();
        $this->assertNull($cancel->sales_order_line_id, 'numeric line FK is nulled by the delete');
        $this->assertSame($lineUuid, $cancel->source_line_uuid, 'canonical source-line snapshot SURVIVES the line deletion');
        $this->assertSame($referencedBatchUuid, $cancel->referenced_kot_event_uuid, 'canonical source-KOT snapshot survives');
    }

    // ── F. REAL KOT reprint (public queueKot isReprint=true): batch/line identities unchanged ──
    public function test_real_kot_reprint_preserves_batch_and_line_identities_and_only_adds_a_copy_job(): void
    {
        ['branchId' => $b, 'terminalId' => $t, 'productId' => $p] = $this->scaffold();
        $resp = app()->call([app(HeldSaleController::class), 'store'], ['request' => $this->req([
            'branch_id' => $b, 'terminal_id' => $t, 'order_type' => 'quick_sale', 'discount_type' => 'none',
            'lines' => [['product_id' => $p, 'quantity' => 2, 'unit_price' => 40, 'client_line_key' => 'a']],
        ])]);
        $sale = SalesOrder::on('tenant')->find(json_decode($resp->getContent(), true)['sale_id']);
        $line = $sale->lines()->first();
        $printer = Printer::on('tenant')->find($this->makePrinter());
        $svc = app(PrintJobService::class);

        // Initial KOT through the REAL public path (creates the batch + marks the line sent).
        $svc->queueKot($sale->fresh(), $printer, [$line->id], (string) $t, false);
        $batch = KotBatch::on('tenant')->where('sales_order_id', $sale->id)->firstOrFail();
        $eventUuid = $batch->event_uuid;
        $seq = (int) $batch->sequence_no;
        $kotLineUuids = $batch->lines()->orderBy('id')->pluck('kot_line_uuid')->all();
        $sourceLineUuids = $batch->lines()->orderBy('id')->pluck('source_line_uuid')->all();
        $sentQty = (float) $line->fresh()->kot_sent_quantity;
        $batchesBefore = KotBatch::on('tenant')->where('sales_order_id', $sale->id)->count();
        $initialJob = PrintJob::on('tenant')->where('reference_id', $sale->id)->where('document_type', 'kot')->latest('id')->first();
        $this->assertStringStartsWith('kot:' . $eventUuid, (string) $initialJob->logical_key, 'initial KOT job keyed on kot:<event_uuid>');

        // REAL reprint #1, then #2 — via the actual public reprint path.
        $svc->queueKot($sale->fresh(), $printer, [$line->id], (string) $t, true);
        $copy1 = PrintJob::on('tenant')->where('reference_id', $sale->id)->where('document_type', 'kot')->latest('id')->first();
        $svc->queueKot($sale->fresh(), $printer, [$line->id], (string) $t, true);
        $copy2 = PrintJob::on('tenant')->where('reference_id', $sale->id)->where('document_type', 'kot')->latest('id')->first();

        // No new KOT business event; the batch + line canonical identities are unchanged.
        $this->assertSame($batchesBefore, KotBatch::on('tenant')->where('sales_order_id', $sale->id)->count(), 'reprint creates NO new KOT batch');
        $batch->refresh();
        $this->assertSame($eventUuid, $batch->event_uuid, 'event_uuid unchanged by reprint');
        $this->assertSame($seq, (int) $batch->sequence_no, 'sequence_no unchanged by reprint');
        $this->assertSame($kotLineUuids, $batch->lines()->orderBy('id')->pluck('kot_line_uuid')->all(), 'kot_line_uuid values unchanged');
        $this->assertSame($sourceLineUuids, $batch->lines()->orderBy('id')->pluck('source_line_uuid')->all(), 'source_line_uuid snapshots unchanged');
        $this->assertSame($sentQty, (float) $line->fresh()->kot_sent_quantity, 'reprint does NOT mutate sent quantity');

        // Reprint only adds copy jobs keyed kot-copy:<event_uuid>:...; copy_no increments per existing contract.
        $this->assertStringStartsWith('kot-copy:' . $eventUuid, (string) $copy1->logical_key, 'reprint keyed kot-copy:<event_uuid>');
        $this->assertStringStartsWith('kot-copy:' . $eventUuid, (string) $copy2->logical_key);
        $this->assertGreaterThan((int) $copy1->copy_no, (int) $copy2->copy_no, 'copy_no increments across reprints');
    }
}
