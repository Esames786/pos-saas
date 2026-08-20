<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringCommercialRateApplication;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringLineCostBlockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PDO;
use Tests\MySql\Support\TenantFixtures;

/**
 * CAT-RATE-002 — Rate Impact must serialize with the quotation lifecycle.
 *
 * THE BUG THIS CLOSES. Two requests, milliseconds apart:
 *
 *      Rate Impact                     Send
 *      ───────────                     ────
 *      read quotation → draft
 *                                      read quotation → draft
 *                                      write status = sent   ✓ committed
 *      write snapshot rate  ✓
 *      reprice line + totals ✓
 *
 * The customer now holds a quotation whose numbers moved after it was sent.
 *
 * Three guards were supposed to stop that and none of them could, because every
 * one of them asks an object loaded BEFORE the other transaction committed:
 * CateringEstimate::updating reads getOriginal('status'); the line guard reads
 * the CACHED $line->estimate relation; and the snapshot table has no guard at
 * all. In-memory state cannot see another connection's write. Only the database
 * can arbitrate, so the fix is a lock taken before the state is read.
 *
 * WHY THIS FILE USES REAL PROCESSES. A race cannot be demonstrated by two calls
 * in one PHP process — one connection serializes itself by construction, and the
 * "race" would resolve in whatever order the statements happened to be written.
 * Each competing operation therefore runs in its own OS process on its own
 * connection through the real services, exactly as the Edge print-race suite
 * does, and the test drives the interleaving from a third, independent PDO
 * connection that holds a lock at a chosen moment.
 */
class CateringRateImpactRaceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private int $branchId;

    private int $biryaniId;

    private int $chickenId;

    private int $riceId;

    private int $kgUnitId;

    private int $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_commercial_rate_applications',
            'catering_final_invoices',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'users', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->actorId = $this->makeUser(['name' => 'Race Operator', 'employee_code' => 'RACEOP']);

        $this->kgUnitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->kgUnitId,
        ]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        $this->riceId = $this->makeProduct($categoryId, [
            'name' => 'Rice', 'sku' => 'RM-RICE', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        foreach ([[$this->chickenId, 80], [$this->riceId, 55]] as [$id, $rate]) {
            CateringMaterialRate::create([
                'product_id' => $id, 'rate' => $rate, 'unit_id' => $this->kgUnitId,
                'effective_from' => now()->subMonth()->toDateString(),
            ]);
        }

        CateringMaterialCommercialRate::create([
            'product_id' => $this->chickenId, 'rate' => 100, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $this->buildBiryani();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures — the business's worked example: 382/KG, 392 once chicken is 120.
    // ─────────────────────────────────────────────────────────────────────────

    private function buildBiryani(): void
    {
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryaniId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );

        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Chicken',
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $this->chickenId, 'quantity_per_unit' => 0.50,
            'unit_id' => $this->kgUnitId, 'rate' => 100,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'commercial_rate_source' => CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK,
            'sort_order' => 1, 'is_active' => true,
        ]);
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Rice',
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $this->riceId, 'quantity_per_unit' => 0.40,
            'unit_id' => $this->kgUnitId, 'rate' => 80,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'commercial_rate_source' => CateringProductCostBlock::SOURCE_MANUAL,
            'sort_order' => 2, 'is_active' => true,
        ]);
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE, 'rate' => 300,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'sort_order' => 3, 'is_active' => true,
        ]);
    }

    private function draft(string $customer = 'Race Customer', float $qty = 20): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => $customer,
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(7)->toDateString(),
            'pax' => 150,
        ]);

        return $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
            'quantity' => $qty, 'unit_id' => $this->kgUnitId, 'unit_code' => 'KG', 'rate' => 0,
        ]]);
    }

    private function line(CateringEstimate $estimate): CateringEstimateLine
    {
        return $estimate->refresh()->lines->first();
    }

    private function snapshot(CateringEstimate $estimate, string $label = 'Chicken'): CateringEstimateLineCostBlock
    {
        return $this->lineBlocks->snapshotsFor($this->line($estimate))->firstWhere('label', $label);
    }

    private function raiseChickenTo(float $rate): void
    {
        CateringMaterialCommercialRate::create([
            'product_id' => $this->chickenId, 'rate' => $rate, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->toDateString(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Process + lock plumbing.
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{proc: resource, pipes: array} */
    private function worker(array $args, ?string $startFile = null): array
    {
        $cmd = array_merge(
            [PHP_BINARY, base_path('tests/MySql/Support/catering_rate_race_worker.php')],
            array_map('strval', $args)
        );
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'EDGE_TEST_TENANT_DB' => $this->tenantDb,
            'APP_ENV' => 'testing',
            'START_FILE' => $startFile ?? '',
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

        return $out !== '' ? $out : 'STDERR:'.$err;
    }

    private function stillRunning(array $h): bool
    {
        $status = proc_get_status($h['proc']);

        return (bool) ($status['running'] ?? false);
    }

    /**
     * A third, independent connection that takes the SAME locks the real code
     * takes, in the same order, and holds them until told to let go.
     *
     * This is how the interleaving is driven: the lock stands in for "the other
     * transaction is midway through", and the worker process genuinely has to
     * wait for it rather than being scheduled to.
     */
    private function holdLock(int $eventId, ?int $estimateId = null): PDO
    {
        $pdo = $this->independentTenantPdo();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
        $pdo->beginTransaction();

        $pdo->prepare('SELECT id FROM catering_events WHERE id = ? FOR UPDATE')->execute([$eventId]);
        if ($estimateId !== null) {
            $pdo->prepare('SELECT id FROM catering_estimates WHERE id = ? FOR UPDATE')->execute([$estimateId]);
        }

        return $pdo;
    }

    /** Give the worker enough time to reach — and block on — the lock. */
    private function letItReachTheLock(): void
    {
        usleep(2_500_000);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CASE A — SEND WINS.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send commits first. The apply, already in flight, must WAIT for it rather
     * than reading around it — and then refuse, because what it queued behind
     * was the moment the quotation stopped being changeable.
     *
     * This is the P1 exactly: before the lock, the apply read the pre-send
     * status and wrote afterwards.
     */
    public function test_send_wins_and_the_sent_quotation_keeps_the_rate_it_was_sent_with(): void
    {
        $estimate = $this->draft();
        $snapshotId = $this->snapshot($estimate)->id;
        $this->raiseChickenTo(120);

        // Send is midway: it holds the locks and has written the new status, but
        // has not committed.
        $sender = $this->holdLock($estimate->catering_event_id, $estimate->id);
        $sender->prepare('UPDATE catering_estimates SET status = ?, sent_at = NOW() WHERE id = ?')
            ->execute([CateringEstimate::STATUS_SENT, $estimate->id]);

        $apply = $this->worker(['apply', $this->chickenId, $snapshotId, $this->actorId]);
        $this->letItReachTheLock();

        $this->assertTrue($this->stillRunning($apply),
            'the apply must be WAITING on the lock — if it finished, it read around a transaction in flight');

        $sender->commit();
        $out = $this->finish($apply);

        $this->assertSame('OK:apply:0', $out, 'it waited, re-read, and found a sent quotation');

        $estimate->refresh();
        $this->assertSame(CateringEstimate::STATUS_SENT, $estimate->status);
        $this->assertEqualsWithDelta(100.0, (float) $this->snapshot($estimate)->rate, 0.01,
            'the customer holds a quotation priced at 100 and it stays priced at 100');
        $this->assertEqualsWithDelta(1000.0, (float) $this->snapshot($estimate)->amount, 0.01);
        $this->assertEqualsWithDelta(382.0, (float) $this->line($estimate)->calculated_rate, 0.01);
        $this->assertEqualsWithDelta(382.0, (float) $this->line($estimate)->rate, 0.01);
        $this->assertEqualsWithDelta(7640.0, (float) $estimate->subtotal, 0.01, '382 x 20');
        $this->assertSame(0, CateringCommercialRateApplication::where('action',
            CateringCommercialRateApplication::ACTION_DRAFT_APPLIED)->count(),
            'and nothing is recorded, because nothing happened');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CASE B — APPLY WINS.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The other direction: the apply is midway, and Send has to wait for it.
     *
     * The half that establishes immutability must participate in the same
     * serialization as the half that changes prices — a lock only one side takes
     * is not a lock.
     */
    public function test_send_waits_for_an_apply_that_is_already_holding_the_document(): void
    {
        $estimate = $this->draft();
        $this->raiseChickenTo(120);

        $applier = $this->holdLock($estimate->catering_event_id, $estimate->id);

        $send = $this->worker(['send', $estimate->id]);
        $this->letItReachTheLock();

        $this->assertTrue($this->stillRunning($send),
            'markSent must queue behind the document lock rather than transitioning around it');

        $applier->commit();

        $this->assertSame('OK:send:sent', $this->finish($send),
            'and once the apply is done, the send proceeds on the state the apply left behind');
    }

    /**
     * Both at genuinely the same moment, no lock held by the test. Whoever wins,
     * the result must be COHERENT — never a sent quotation carrying a rate that
     * no audit row accounts for, and never a half-applied one.
     */
    public function test_a_simultaneous_apply_and_send_never_leave_a_half_applied_quotation(): void
    {
        $estimate = $this->draft();
        $snapshotId = $this->snapshot($estimate)->id;
        $this->raiseChickenTo(120);

        $startFile = sys_get_temp_dir().'/catering_rate_race_'.bin2hex(random_bytes(4)).'.start';
        @unlink($startFile);

        $apply = $this->worker(['apply', $this->chickenId, $snapshotId, $this->actorId], $startFile);
        $send = $this->worker(['send', $estimate->id], $startFile);

        usleep(1_500_000);
        file_put_contents($startFile, '1');

        $applyOut = $this->finish($apply);
        $sendOut = $this->finish($send);
        @unlink($startFile);

        $this->assertStringStartsWith('OK:', $applyOut, "apply leaked a raw error: $applyOut");
        $this->assertStringStartsWith('OK:', $sendOut, "send leaked a raw error: $sendOut");

        $estimate->refresh();
        $this->assertSame(CateringEstimate::STATUS_SENT, $estimate->status, 'the send always lands');

        $rate = (float) $this->snapshot($estimate)->rate;
        $line = $this->line($estimate);
        $audited = CateringCommercialRateApplication::where('action',
            CateringCommercialRateApplication::ACTION_DRAFT_APPLIED)->count();

        if ($applyOut === 'OK:apply:1') {
            // Apply won the lock: it must be COMPLETE — snapshot, line and
            // document totals all moved together, and the record agrees.
            $this->assertEqualsWithDelta(120.0, $rate, 0.01);
            $this->assertEqualsWithDelta(1200.0, (float) $this->snapshot($estimate)->amount, 0.01);
            $this->assertEqualsWithDelta(392.0, (float) $line->calculated_rate, 0.01);
            $this->assertEqualsWithDelta(392.0, (float) $line->rate, 0.01);
            $this->assertEqualsWithDelta(7840.0, (float) $estimate->subtotal, 0.01, '392 x 20');
            $this->assertSame(1, $audited);
        } else {
            // Send won: nothing moved at all, and nothing claims it did.
            $this->assertSame('OK:apply:0', $applyOut);
            $this->assertEqualsWithDelta(100.0, $rate, 0.01);
            $this->assertEqualsWithDelta(1000.0, (float) $this->snapshot($estimate)->amount, 0.01);
            $this->assertEqualsWithDelta(382.0, (float) $line->calculated_rate, 0.01);
            $this->assertEqualsWithDelta(7640.0, (float) $estimate->subtotal, 0.01);
            $this->assertSame(0, $audited);
        }
    }

    /** A negotiated rate is a decision about a customer, and a race cannot erase it. */
    public function test_an_agreed_rate_and_its_reason_survive_the_race_either_way(): void
    {
        $estimate = $this->draft();
        $this->lineBlocks->overrideQuotedRate($this->line($estimate), 500, 'Customer agreed rate');
        $snapshotId = $this->snapshot($estimate)->id;
        $this->raiseChickenTo(120);

        $startFile = sys_get_temp_dir().'/catering_rate_race_'.bin2hex(random_bytes(4)).'.start';
        @unlink($startFile);

        $apply = $this->worker(['apply', $this->chickenId, $snapshotId, $this->actorId], $startFile);
        $send = $this->worker(['send', $estimate->id], $startFile);
        usleep(1_500_000);
        file_put_contents($startFile, '1');

        $applyOut = $this->finish($apply);
        $this->finish($send);
        @unlink($startFile);

        $line = $this->line($estimate->refresh());

        $this->assertEqualsWithDelta(500.0, (float) $line->rate, 0.01,
            'whoever won, the customer was told 500 and is still being told 500');
        $this->assertSame('Customer agreed rate', $line->rate_override_reason);
        $this->assertEqualsWithDelta(10000.0, (float) $estimate->subtotal, 0.01, '500 x 20');

        // Only the calculation underneath is allowed to have moved.
        $this->assertEqualsWithDelta(
            $applyOut === 'OK:apply:1' ? 392.0 : 382.0,
            (float) $line->calculated_rate,
            0.01
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CASE C — an immutable transition established mid-flight.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cancelling ends commercial change for the whole booking. An apply in
     * flight must wait for it and then refuse — a repriced line on a cancelled
     * booking is a number nobody can explain.
     */
    public function test_a_cancellation_committed_mid_flight_stops_the_apply(): void
    {
        $estimate = $this->draft();
        $snapshotId = $this->snapshot($estimate)->id;
        $this->raiseChickenTo(120);

        $canceller = $this->holdLock($estimate->catering_event_id);
        $canceller->prepare('UPDATE catering_events SET status = ?, cancel_reason = ?, cancelled_at = NOW() WHERE id = ?')
            ->execute([CateringEvent::STATUS_CANCELLED, 'Race test', $estimate->catering_event_id]);

        $apply = $this->worker(['apply', $this->chickenId, $snapshotId, $this->actorId]);
        $this->letItReachTheLock();

        $this->assertTrue($this->stillRunning($apply),
            'the event lock is taken first precisely so an event-level closure can stop this');

        $canceller->commit();

        $this->assertSame('OK:apply:0', $this->finish($apply));
        $this->assertEqualsWithDelta(100.0, (float) $this->snapshot($estimate)->rate, 0.01);
        $this->assertSame(0, CateringCommercialRateApplication::where('action',
            CateringCommercialRateApplication::ACTION_DRAFT_APPLIED)->count());
    }

    /** And a cancellation must itself wait for an apply already holding the booking. */
    public function test_a_cancellation_waits_for_an_apply_holding_the_booking(): void
    {
        $estimate = $this->draft();

        $applier = $this->holdLock($estimate->catering_event_id, $estimate->id);

        $cancel = $this->worker(['cancel', $estimate->catering_event_id]);
        $this->letItReachTheLock();

        $this->assertTrue($this->stillRunning($cancel));

        $applier->commit();

        $this->assertSame('OK:cancel:cancelled', $this->finish($cancel));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CASE D — the final invoice boundary.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A final invoice issued mid-flight ends everything. A revision created and
     * repriced behind it would leave the customer holding a bill that nothing in
     * the system agrees with.
     *
     * The invoice row and the event's completion are written here on the holding
     * connection rather than through CateringFinalInvoiceService, because the
     * real authority needs a full chart of accounts and a mail path that are
     * beside the point of this proof — what is under test is the boundary, and
     * documentIsOpen() is the code that enforces it. The service was brought
     * under the same lock order and its own suite still passes.
     */
    public function test_a_final_invoice_issued_mid_flight_stops_a_revision_apply(): void
    {
        $estimate = $this->draft();
        $sent = $this->estimates->markSent($estimate->refresh());
        $this->raiseChickenTo(120);

        $invoicer = $this->holdLock($sent->catering_event_id, $sent->id);
        $invoicer->prepare(
            'INSERT INTO catering_final_invoices
             (invoice_no, catering_event_id, catering_estimate_id, snapshot, subtotal,
              service_charge_amount, other_charge_amount, discount_amount, tax_amount, grand_total,
              advance_total, advance_applied, balance_due, status, issued_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0, 0, 0, 0, ?, 0, 0, ?, ?, NOW(), NOW(), NOW())'
        )->execute([
            'FI-RACE-1', $sent->catering_event_id, $sent->id, json_encode(['lines' => []]),
            7640, 7640, 7640, CateringFinalInvoice::STATUS_ISSUED,
        ]);
        $invoicer->prepare('UPDATE catering_events SET status = ? WHERE id = ?')
            ->execute([CateringEvent::STATUS_COMPLETED, $sent->catering_event_id]);

        $revise = $this->worker(['revise-apply', $this->chickenId, $sent->id, $this->actorId]);
        $this->letItReachTheLock();

        $this->assertTrue($this->stillRunning($revise),
            'the revision must wait for the invoice transaction rather than racing past it');

        $invoicer->commit();
        $out = $this->finish($revise);

        $this->assertStringStartsWith('ERR:RuntimeException:', $out,
            "an invoiced booking must refuse a revision, got: $out");

        $sent->refresh();
        $this->assertSame(CateringEstimate::STATUS_SENT, $sent->status,
            'and the sent version is emphatically NOT superseded by a revision that never happened');
        $this->assertSame(1, CateringEstimate::where('catering_event_id', $sent->catering_event_id)->count(),
            'no orphan revision was left behind');
        $this->assertEqualsWithDelta(100.0, (float) $this->snapshot($sent)->rate, 0.01);
        $this->assertSame(0, CateringCommercialRateApplication::where('action',
            CateringCommercialRateApplication::ACTION_REVISION_APPLIED)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The boundary the whole feature sits inside, under contention.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_raced_operation_ever_posts_a_journal_or_moves_stock(): void
    {
        $estimate = $this->draft();
        $snapshotId = $this->snapshot($estimate)->id;
        $this->raiseChickenTo(120);

        $startFile = sys_get_temp_dir().'/catering_rate_race_'.bin2hex(random_bytes(4)).'.start';
        @unlink($startFile);
        $apply = $this->worker(['apply', $this->chickenId, $snapshotId, $this->actorId], $startFile);
        $send = $this->worker(['send', $estimate->id], $startFile);
        usleep(1_500_000);
        file_put_contents($startFile, '1');
        $this->finish($apply);
        $this->finish($send);
        @unlink($startFile);

        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, DB::connection('tenant')->table('journal_lines')->count());
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count());
    }
}
