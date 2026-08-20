<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEstimateLineCostBlock;
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
 * "SENT MEANS IMMUTABLE" — for every ordinary draft writer, not just Rate Impact.
 *
 * These five scenarios come from an independent certification (55ad8d5) that ran
 * against a head where Rate Impact had already been serialized. It found that
 * the fix had been applied to exactly one writer, and that the everyday editing
 * paths were still outside the contract:
 *
 *      saveDraftLines           quantity  20 -> 25 AFTER Send
 *      overrideMaterialQuantity      qty  10 -> 12 AFTER Send
 *      setCustomerSupplied         false -> true  AFTER Send
 *      overrideQuotedRate            382 -> 500   AFTER Send
 *      useCalculatedRate      reason -> null      AFTER Send
 *
 * Every one committed child state after the document had become immutable, and
 * every one passed its own guard, because every guard was reading a model loaded
 * before the wait.
 *
 * THE ADVERSARIAL SHAPE, preserved from the certification: an independent
 * connection holds a CHILD row — a line or a cost-block snapshot. The writer
 * therefore gets past whatever authorization it does up front and blocks deep
 * inside, at its actual write. That is the window. A sequential test that calls
 * markSent() and then the mutation proves only that the two cannot happen in
 * that order, which was never in doubt.
 *
 * What changes with the fix is WHERE the writer blocks. It now takes the
 * document lock before it looks at anything, so:
 *
 *   SEND WINS      Send holds the document; the writer waits, re-reads, refuses.
 *   WRITER WINS    the writer holds the document; SEND ITSELF waits, and sends
 *                  the finished state.
 *
 * Both directions are asserted, because a lock that only one side respects is
 * not a lock — and "the writer waited" is worthless without "and Send could not
 * slip past while it did".
 */
class CateringDraftWriterRaceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private int $branchId;

    private int $biryaniId;

    private int $chickenId;

    private int $riceId;

    private int $kgUnitId;

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
    // Fixtures: the worked example — 382/KG on a 20 KG line.
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

    private function draft(string $customer, float $qty = 20): CateringEstimate
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

    // ─────────────────────────────────────────────────────────────────────────
    // Process + lock plumbing.
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{proc: resource, pipes: array} */
    private function worker(array $args): array
    {
        $cmd = array_merge(
            [PHP_BINARY, base_path('tests/MySql/Support/catering_rate_race_worker.php')],
            array_map('strval', $args)
        );
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'EDGE_TEST_TENANT_DB' => $this->tenantDb,
            'APP_ENV' => 'testing',
            'START_FILE' => '',
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
        return (bool) (proc_get_status($h['proc'])['running'] ?? false);
    }

    private function letItReachTheLock(): void
    {
        usleep(2_500_000);
    }

    /**
     * SEND, mid-transaction: it holds the document and has written the new
     * status, but has not committed. Exactly the window a draft writer used to
     * read straight through.
     */
    private function sendInFlight(CateringEstimate $estimate): PDO
    {
        $pdo = $this->independentTenantPdo();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 60');
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM catering_events WHERE id = ? FOR UPDATE')
            ->execute([$estimate->catering_event_id]);
        $pdo->prepare('SELECT id FROM catering_estimates WHERE id = ? FOR UPDATE')
            ->execute([$estimate->id]);
        $pdo->prepare('UPDATE catering_estimates SET status = ?, sent_at = NOW() WHERE id = ?')
            ->execute([CateringEstimate::STATUS_SENT, $estimate->id]);

        return $pdo;
    }

    /**
     * The certification's shape: hold a CHILD row so the writer gets past its own
     * front door and blocks at the write itself.
     */
    private function holdChildRow(string $table, int $id): PDO
    {
        $this->assertContains($table, ['catering_estimate_lines', 'catering_estimate_line_cost_blocks']);

        $pdo = $this->independentTenantPdo();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 60');
        $pdo->beginTransaction();
        $pdo->prepare("SELECT id FROM {$table} WHERE id = ? FOR UPDATE")->execute([$id]);

        return $pdo;
    }

    /**
     * SEND WINS. The writer must wait for the send, then refuse — and leave
     * absolutely nothing behind.
     */
    private function assertSendWins(array $worker, PDO $send, callable $assertUnchanged): string
    {
        $this->letItReachTheLock();
        $this->assertTrue($this->stillRunning($worker),
            'the writer must be WAITING for the document — if it finished, it wrote around a send in flight');

        $send->commit();
        $out = $this->finish($worker);

        $this->assertStringStartsWith('ERR:', $out,
            "a sent quotation must refuse the write, got: $out");

        $assertUnchanged();

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A · the ordinary form save
    // ─────────────────────────────────────────────────────────────────────────

    public function test_send_wins_against_a_normal_draft_save(): void
    {
        $estimate = $this->draft('Save race');
        $line = $this->line($estimate);
        $send = $this->sendInFlight($estimate);

        $this->assertSendWins(
            $this->worker(['save-lines', $estimate->id, 25]),
            $send,
            function () use ($line, $estimate) {
                $this->assertEqualsWithDelta(20.0, (float) $line->fresh()->quantity, 0.001,
                    'a form save must not change a quantity on a quotation the customer already has');
                $this->assertEqualsWithDelta(7640.0, (float) $estimate->fresh()->subtotal, 0.01,
                    '382 x 20 — the totals the customer was sent');
            }
        );
    }

    /**
     * The other direction, and the one that proves the lock is mutual: the save
     * is blocked deep inside on a child row, and SEND ITSELF cannot get past it.
     */
    public function test_send_waits_while_a_normal_draft_save_holds_the_document(): void
    {
        $estimate = $this->draft('Save wins race');
        $line = $this->line($estimate);

        $child = $this->holdChildRow('catering_estimate_lines', $line->id);
        $save = $this->worker(['save-lines', $estimate->id, 25]);
        $this->letItReachTheLock();
        $this->assertTrue($this->stillRunning($save), 'the save should be blocked on the held child row');

        $send = $this->worker(['send', $estimate->id]);
        $this->letItReachTheLock();

        $this->assertTrue($this->stillRunning($send),
            'SEND must queue behind the writer that owns the document — this is the half that was missing');

        $child->commit();

        $this->assertSame('OK:save-lines', $this->finish($save));
        $this->assertSame('OK:send:sent', $this->finish($send));

        $estimate->refresh();
        $this->assertSame(CateringEstimate::STATUS_SENT, $estimate->status);
        $this->assertEqualsWithDelta(25.0, (float) $line->fresh()->quantity, 0.001,
            'the save completed in full first');
        $this->assertEqualsWithDelta(9550.0, (float) $estimate->subtotal, 0.01,
            '382 x 25 — and Send froze the FINISHED state, not a half-saved one');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B · material quantity override
    // ─────────────────────────────────────────────────────────────────────────

    public function test_send_wins_against_a_material_quantity_override(): void
    {
        $estimate = $this->draft('Material race');
        $snapshot = $this->snapshot($estimate);
        $send = $this->sendInFlight($estimate);

        $this->assertSendWins(
            $this->worker(['material-override', $snapshot->id, 12]),
            $send,
            function () use ($snapshot, $estimate) {
                $fresh = $snapshot->fresh();
                $this->assertEqualsWithDelta(10.0, (float) $fresh->event_material_qty, 0.001);
                $this->assertFalse((bool) $fresh->is_overridden);
                $this->assertEqualsWithDelta(1000.0, (float) $fresh->amount, 0.01);
                $this->assertEqualsWithDelta(382.0, (float) $this->line($estimate)->calculated_rate, 0.01);
                $this->assertEqualsWithDelta(7640.0, (float) $estimate->fresh()->subtotal, 0.01);
            }
        );
    }

    public function test_send_waits_while_a_material_override_holds_the_document(): void
    {
        $estimate = $this->draft('Material wins race');
        $snapshot = $this->snapshot($estimate);

        $child = $this->holdChildRow('catering_estimate_line_cost_blocks', $snapshot->id);
        $override = $this->worker(['material-override', $snapshot->id, 12]);
        $this->letItReachTheLock();

        $send = $this->worker(['send', $estimate->id]);
        $this->letItReachTheLock();
        $this->assertTrue($this->stillRunning($send));

        $child->commit();

        $this->assertSame('OK:material-override', $this->finish($override));
        $this->assertSame('OK:send:sent', $this->finish($send));

        $this->assertEqualsWithDelta(12.0, (float) $snapshot->fresh()->event_material_qty, 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) $snapshot->fresh()->amount, 0.01);
        $this->assertEqualsWithDelta(392.0, (float) $this->line($estimate)->calculated_rate, 0.01,
            '12 KG of chicken instead of 10 — and the reprice finished before the send');
        $this->assertEqualsWithDelta(7840.0, (float) $estimate->fresh()->subtotal, 0.01);
    }

    /** Reset is the same operation in the other direction and gets the same contract. */
    public function test_send_wins_against_a_material_quantity_reset(): void
    {
        $estimate = $this->draft('Reset race');
        $snapshot = $this->snapshot($estimate);
        $this->lineBlocks->overrideMaterialQuantity($snapshot, 12);

        $send = $this->sendInFlight($estimate);

        $this->assertSendWins(
            $this->worker(['material-reset', $snapshot->id]),
            $send,
            function () use ($snapshot) {
                $fresh = $snapshot->fresh();
                $this->assertEqualsWithDelta(12.0, (float) $fresh->event_material_qty, 0.001,
                    'the overridden quantity the customer was quoted stays overridden');
                $this->assertTrue((bool) $fresh->is_overridden);
            }
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C · customer-supplied
    // ─────────────────────────────────────────────────────────────────────────

    public function test_send_wins_against_a_customer_supplied_toggle(): void
    {
        $estimate = $this->draft('Customer supplied race');
        $snapshot = $this->snapshot($estimate);
        $send = $this->sendInFlight($estimate);

        $this->assertSendWins(
            $this->worker(['customer-supplied', $snapshot->id, 1]),
            $send,
            function () use ($snapshot, $estimate) {
                $fresh = $snapshot->fresh();
                $this->assertFalse((bool) $fresh->is_customer_supplied,
                    'who supplies the chicken is part of what was agreed');
                $this->assertEqualsWithDelta(10.0, $fresh->ourStockRequirement(), 0.001,
                    'and the store is still expected to hand it over');
                $this->assertEqualsWithDelta(1000.0, (float) $fresh->amount, 0.01);
                $this->assertEqualsWithDelta(7640.0, (float) $estimate->fresh()->subtotal, 0.01);
            }
        );
    }

    public function test_send_waits_while_a_customer_supplied_toggle_holds_the_document(): void
    {
        $estimate = $this->draft('Supplied wins race');
        $snapshot = $this->snapshot($estimate);

        $child = $this->holdChildRow('catering_estimate_line_cost_blocks', $snapshot->id);
        $toggle = $this->worker(['customer-supplied', $snapshot->id, 1]);
        $this->letItReachTheLock();

        $send = $this->worker(['send', $estimate->id]);
        $this->letItReachTheLock();
        $this->assertTrue($this->stillRunning($send));

        $child->commit();

        $this->assertSame('OK:customer-supplied', $this->finish($toggle));
        $this->assertSame('OK:send:sent', $this->finish($send));

        $fresh = $this->snapshot($estimate);
        $this->assertTrue((bool) $fresh->is_customer_supplied);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->amount, 0.01, 'the customer is not charged for it');
        $this->assertEqualsWithDelta(0.0, $fresh->ourStockRequirement(), 0.001, 'and our store hands over nothing');
        $this->assertEqualsWithDelta(10.0, $fresh->physicalRequirement(), 0.001,
            'though the kitchen still needs it — that never changed');
        $this->assertEqualsWithDelta(332.0, (float) $this->line($estimate)->calculated_rate, 0.01,
            '382 less the 50 of chicken per KG');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D · a negotiated rate
    // ─────────────────────────────────────────────────────────────────────────

    public function test_send_wins_against_a_quoted_rate_override(): void
    {
        $estimate = $this->draft('Quote race');
        $line = $this->line($estimate);
        $send = $this->sendInFlight($estimate);

        $this->assertSendWins(
            $this->worker(['quoted-rate', $line->id, 500]),
            $send,
            function () use ($line, $estimate) {
                $fresh = $line->fresh();
                $this->assertEqualsWithDelta(382.0, (float) $fresh->rate, 0.01,
                    'the customer was sent 382 and 382 is what they were sent');
                $this->assertNull($fresh->rate_override_reason);
                $this->assertEqualsWithDelta(7640.0, (float) $estimate->fresh()->subtotal, 0.01);
            }
        );
    }

    public function test_send_waits_while_a_quoted_rate_override_holds_the_document(): void
    {
        $estimate = $this->draft('Quote wins race');
        $line = $this->line($estimate);

        $child = $this->holdChildRow('catering_estimate_lines', $line->id);
        $quote = $this->worker(['quoted-rate', $line->id, 500]);
        $this->letItReachTheLock();

        $send = $this->worker(['send', $estimate->id]);
        $this->letItReachTheLock();
        $this->assertTrue($this->stillRunning($send));

        $child->commit();

        $this->assertSame('OK:quoted-rate', $this->finish($quote));
        $this->assertSame('OK:send:sent', $this->finish($send));

        $fresh = $this->line($estimate);
        $this->assertEqualsWithDelta(500.0, (float) $fresh->rate, 0.01);
        $this->assertSame('Concurrent quote test', $fresh->rate_override_reason);
        $this->assertEqualsWithDelta(382.0, (float) $fresh->calculated_rate, 0.01,
            'the calculation underneath is untouched — only the agreed price moved');
        $this->assertEqualsWithDelta(10000.0, (float) $estimate->fresh()->subtotal, 0.01,
            '500 x 20, and Send froze THAT');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E · putting a line back on its calculated rate
    // ─────────────────────────────────────────────────────────────────────────

    public function test_send_wins_against_use_calculated_rate(): void
    {
        $estimate = $this->draft('Calculated race');
        $line = $this->line($estimate);
        $this->lineBlocks->overrideQuotedRate($line, 500, 'Before concurrent send');

        $send = $this->sendInFlight($estimate);

        $this->assertSendWins(
            $this->worker(['use-calculated', $line->id]),
            $send,
            function () use ($line, $estimate) {
                $fresh = $line->fresh();
                $this->assertSame('Before concurrent send', $fresh->rate_override_reason,
                    'an agreed rate cannot lose its reason after the quotation is sent');
                $this->assertEqualsWithDelta(500.0, (float) $fresh->rate, 0.01);
                $this->assertEqualsWithDelta(10000.0, (float) $estimate->fresh()->subtotal, 0.01);
            }
        );
    }

    public function test_send_waits_while_use_calculated_rate_holds_the_document(): void
    {
        $estimate = $this->draft('Calculated wins race');
        $line = $this->line($estimate);
        $this->lineBlocks->overrideQuotedRate($line, 500, 'Before concurrent send');

        $child = $this->holdChildRow('catering_estimate_lines', $line->id);
        $reset = $this->worker(['use-calculated', $line->id]);
        $this->letItReachTheLock();

        $send = $this->worker(['send', $estimate->id]);
        $this->letItReachTheLock();
        $this->assertTrue($this->stillRunning($send));

        $child->commit();

        $this->assertSame('OK:use-calculated', $this->finish($reset));
        $this->assertSame('OK:send:sent', $this->finish($send));

        $fresh = $this->line($estimate);
        $this->assertNull($fresh->rate_override_reason);
        $this->assertEqualsWithDelta(382.0, (float) $fresh->rate, 0.01, 'back on the calculated rate');
        $this->assertEqualsWithDelta(7640.0, (float) $estimate->fresh()->subtotal, 0.01,
            'and the document totals were recomputed BEFORE the send froze them');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The boundary all of this sits inside.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_raced_draft_write_ever_posts_a_journal_or_moves_stock(): void
    {
        $estimate = $this->draft('Ledger race');
        $snapshot = $this->snapshot($estimate);

        $send = $this->sendInFlight($estimate);
        $worker = $this->worker(['material-override', $snapshot->id, 12]);
        $this->letItReachTheLock();
        $send->commit();
        $this->finish($worker);

        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, DB::connection('tenant')->table('journal_lines')->count());
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F · the recorded costing basis — CAT-RATE-011
    //
    // Found by the release audit AFTER the five commercial writers were fixed.
    // Both costing services announce "its costing basis is frozen" once an
    // estimate leaves draft, and both decided that on the model handed in,
    // outside the transaction that persists. So it failed the same way: waited
    // for the document lock, woke after Send committed, and recorded anyway.
    //
    // Internal cost only — estimated_unit_cost, estimated_cost_total,
    // estimated_material_cost, and a catering_cost_snapshots row. Nothing a
    // customer is charged. But a sent quotation whose recorded cost, and
    // therefore its margin, moves afterwards is the thing the message promises
    // cannot happen.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_send_wins_against_a_cost_snapshot(): void
    {
        $estimate = $this->draft('Cost snapshot race');
        $lineId = $this->line($estimate)->id;
        $costBefore = $this->line($estimate)->estimated_cost_total;
        $send = $this->sendInFlight($estimate);

        $worker = $this->worker(['cost-snapshot', $estimate->id]);
        $this->letItReachTheLock();

        $this->assertTrue($this->stillRunning($worker),
            'the cost snapshot must WAIT for the document rather than reading around a send in flight');

        $send->commit();
        $out = $this->finish($worker);

        $this->assertStringStartsWith('ERR:', $out,
            "a sent quotation must refuse a cost snapshot, got: $out");
        $this->assertStringContainsString('costing basis is frozen', $out);

        $this->assertSame(0, DB::connection('tenant')->table('catering_cost_snapshots')
            ->where('catering_estimate_id', $estimate->id)->count(),
            'no cost-snapshot row may be recorded against a sent estimate');

        $line = CateringEstimateLine::find($lineId);
        $this->assertSame($costBefore, $line->estimated_cost_total,
            'and no estimated_* value may move after the freeze');
        $this->assertNull($estimate->refresh()->estimated_material_cost);
    }

    /** And the other direction: Send waits for a costing run that owns the document. */
    public function test_send_waits_while_a_cost_snapshot_holds_the_document(): void
    {
        $estimate = $this->draft('Cost snapshot wins race');
        $line = $this->line($estimate);

        $child = $this->holdChildRow('catering_estimate_lines', $line->id);
        $cost = $this->worker(['cost-snapshot', $estimate->id]);
        $this->letItReachTheLock();

        $send = $this->worker(['send', $estimate->id]);
        $this->letItReachTheLock();

        $this->assertTrue($this->stillRunning($send),
            'Send must queue behind a costing run that owns the document');

        $child->commit();

        $this->assertStringStartsWith('OK:cost-snapshot:', $this->finish($cost));
        $this->assertSame('OK:send:sent', $this->finish($send));

        $this->assertSame(1, DB::connection('tenant')->table('catering_cost_snapshots')
            ->where('catering_estimate_id', $estimate->id)->count());
        $this->assertNotNull(CateringEstimateLine::find($line->id)->estimated_cost_total,
            'the costing basis completed in full');
        $this->assertNotNull($estimate->refresh()->estimated_material_cost);
        $this->assertSame(CateringEstimate::STATUS_SENT, $estimate->status,
            'and Send froze THAT costing basis, not a half-written one');
    }

    /** The sequential case must still refuse, exactly as it always did. */
    public function test_a_sent_estimate_refuses_a_cost_snapshot_outright(): void
    {
        $estimate = $this->draft('Sequential freeze');
        $this->estimates->markSent($estimate->refresh());

        $this->expectExceptionMessageMatches('/costing basis is frozen/');
        app(\App\Services\Catering\CateringEstimateCostingService::class)
            ->snapshot($estimate->refresh(), null);
    }

    /**
     * The internal recalculation helpers are not a side door. Calling one outside
     * a commercial transaction — the shape a controller shortcut would take — is
     * refused rather than quietly executed without a lock.
     */
    public function test_an_internal_reprice_cannot_be_called_outside_the_critical_section(): void
    {
        $estimate = $this->draft('Bypass attempt');

        $this->expectExceptionMessageMatches('/commercial document transaction/');
        $this->lineBlocks->repriceLocked($this->line($estimate));
    }
}
