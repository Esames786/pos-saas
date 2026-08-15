<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringAdvance;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-PRODUCT-UX-1 (item 9) — cancellation, on the record.
 *
 * The dangerous shortcut here would be making a cancel button tidy up the
 * money: delete the advance, reverse its journal, or write a refund. Money that
 * was received was really received. These tests exist to make that shortcut
 * fail loudly if anyone ever takes it.
 */
class CateringCancellationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEvent $event;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'cash_bank_account_transactions', 'cash_bank_accounts',
            'catering_advances', 'recipe_ingredients', 'recipes', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'journal_lines', 'journal_entries',
            'payment_methods', 'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();

        $this->event = app(CateringEstimateService::class)->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Cancel Test Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(20)->toDateString(),
            'venue' => 'Cancel Hall',
            'pax' => 150,
        ]);
    }

    private function service(): CateringEstimateService
    {
        return app(CateringEstimateService::class);
    }

    /** A cancellation with no explanation is refused. */
    public function test_a_reason_is_required(): void
    {
        foreach (['', '   '] as $empty) {
            try {
                $this->service()->cancelEvent($this->event->fresh(), $empty);
                $this->fail('cancelling without a reason must be refused');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('reason is required', $e->getMessage());
            }
        }

        $this->assertNotSame(CateringEvent::STATUS_CANCELLED, $this->event->fresh()->status,
            'a refused cancellation must leave the booking open');
    }

    /** The reason is persisted, with who and when. */
    public function test_the_reason_is_recorded_with_a_timestamp(): void
    {
        $userId = $this->makeUser(['employee_code' => 'CX'.\Illuminate\Support\Str::random(4)]);

        $cancelled = $this->service()->cancelEvent(
            $this->event->fresh(), '  Customer postponed to November  ', $userId
        );

        $this->assertSame(CateringEvent::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('Customer postponed to November', $cancelled->cancel_reason,
            'the reason is stored trimmed, exactly as given');
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame($userId, (int) $cancelled->cancelled_by_user_id);
    }

    /** With no advance, cancelling changes nothing financial. */
    public function test_cancelling_without_an_advance_is_financially_inert(): void
    {
        $before = [
            'entries' => DB::connection('tenant')->table('journal_entries')->count(),
            'lines' => DB::connection('tenant')->table('journal_lines')->count(),
            'advances' => CateringAdvance::count(),
        ];

        $this->service()->cancelEvent($this->event->fresh(), 'Venue became unavailable');

        $this->assertSame($before, [
            'entries' => DB::connection('tenant')->table('journal_entries')->count(),
            'lines' => DB::connection('tenant')->table('journal_lines')->count(),
            'advances' => CateringAdvance::count(),
        ]);
    }

    /**
     * The property this whole test class exists for: an advance already
     * received survives cancellation completely intact.
     */
    public function test_cancelling_after_an_advance_preserves_the_accounting_history(): void
    {
        // An advance is refused without a priced estimate to advance against —
        // a guard in CateringAdvance, not something to work around. Price it.
        $categoryId = $this->makeCategory();
        $unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = $this->makeProduct($categoryId, ['unit_id' => $unitId]);

        app(CateringEstimateService::class)->saveDraftLines(
            $this->event->fresh()->currentEstimate,
            [[
                'product_id' => $productId, 'item_name' => 'Biryani',
                'quantity' => 100, 'unit_id' => $unitId, 'unit_code' => 'KG', 'rate' => 1200,
            ]],
            []
        );

        $event = $this->event->fresh();
        $event->forceFill(['status' => CateringEvent::STATUS_CONFIRMED])->save();

        $advance = CateringAdvance::create([
            'catering_event_id' => $event->id,
            'amount' => 50000,
            'received_date' => now()->toDateString(),
            'reference' => 'ADV-CANCEL-TEST',
            'posting_type' => CateringAdvance::POSTING_ADVANCE,
        ]);

        $snapshot = [
            'advance_rows' => CateringAdvance::count(),
            'advance_amount' => (float) CateringAdvance::sum('amount'),
            'entries' => DB::connection('tenant')->table('journal_entries')->count(),
            'lines' => DB::connection('tenant')->table('journal_lines')->count(),
        ];

        $this->service()->cancelEvent($event->fresh(), 'Customer cancelled the wedding');

        $this->assertSame(CateringEvent::STATUS_CANCELLED, $event->fresh()->status);

        $this->assertSame($snapshot, [
            'advance_rows' => CateringAdvance::count(),
            'advance_amount' => (float) CateringAdvance::sum('amount'),
            'entries' => DB::connection('tenant')->table('journal_entries')->count(),
            'lines' => DB::connection('tenant')->table('journal_lines')->count(),
        ], 'cancellation must not delete, reverse, refund or otherwise rewrite a received advance');

        $this->assertNotNull($advance->fresh(), 'the advance row itself must survive');
        $this->assertSame(50000.0, (float) $advance->fresh()->amount,
            'the amount received must not be silently zeroed');
    }

    /** Cancelling twice must not overwrite the original record. */
    public function test_cancellation_is_idempotent_and_keeps_the_first_reason(): void
    {
        $first = $this->service()->cancelEvent($this->event->fresh(), 'Original reason');
        $originalAt = $first->cancelled_at;

        $second = $this->service()->cancelEvent($this->event->fresh(), 'A different reason later');

        $this->assertSame('Original reason', $second->cancel_reason,
            'a repeated cancellation must not rewrite why it was originally cancelled');
        $this->assertEquals($originalAt->toDateTimeString(), $second->cancelled_at->toDateTimeString());
    }

    /** A completed or closed booking still cannot be cancelled. */
    public function test_completed_and_closed_bookings_remain_uncancellable(): void
    {
        foreach ([CateringEvent::STATUS_COMPLETED, CateringEvent::STATUS_CLOSED] as $status) {
            $event = $this->event->fresh();
            $event->forceFill(['status' => $status, 'cancel_reason' => null])->save();

            try {
                $this->service()->cancelEvent($event->fresh(), 'Trying anyway');
                $this->fail("a {$status} booking must not be cancellable");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot be cancelled', $e->getMessage());
            }

            $this->assertSame($status, $event->fresh()->status);
        }
    }

    /**
     * The reason must be required on the HTTP path too, not only when the
     * service is called directly. A controller that forgot to validate would
     * leave the service guard as the only defence, and its message is a 500
     * rather than a form error.
     */
    public function test_the_controller_rejects_a_cancellation_with_no_reason(): void
    {
        $controller = app(\App\Http\Controllers\Tenant\Catering\CateringEventController::class);

        foreach ([[], ['cancel_reason' => ''], ['cancel_reason' => 'x']] as $payload) {
            try {
                $controller->cancel(
                    \Illuminate\Http\Request::create('/', 'POST', $payload),
                    $this->event->fresh()
                );
                $this->fail('the controller must reject a cancellation without a real reason');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('cancel_reason', $e->errors());
            }
        }

        $this->assertNotSame(CateringEvent::STATUS_CANCELLED, $this->event->fresh()->status,
            'a rejected request must leave the booking open');
    }

    /** The controller path records the reason and preserves the advance. */
    public function test_the_controller_records_the_reason(): void
    {
        app(\App\Http\Controllers\Tenant\Catering\CateringEventController::class)->cancel(
            \Illuminate\Http\Request::create('/', 'POST', ['cancel_reason' => 'Customer changed the date']),
            $this->event->fresh()
        );

        $fresh = $this->event->fresh();
        $this->assertSame(CateringEvent::STATUS_CANCELLED, $fresh->status);
        $this->assertSame('Customer changed the date', $fresh->cancel_reason);
        $this->assertNotNull($fresh->cancelled_at);
    }

    /** Historical rows predate the column and must stay valid with no reason. */
    public function test_a_historical_cancellation_without_a_reason_remains_valid(): void
    {
        $event = $this->event->fresh();
        $event->forceFill([
            'status' => CateringEvent::STATUS_CANCELLED,
            'cancel_reason' => null,
            'cancelled_at' => null,
        ])->save();

        $fresh = $event->fresh();

        $this->assertTrue($fresh->isCancelled());
        $this->assertNull($fresh->cancel_reason,
            'the migration is additive and nullable — old rows are not backfilled with an invented reason');
    }
}
