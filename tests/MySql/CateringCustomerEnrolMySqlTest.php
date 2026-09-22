<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\Customer;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-CUSTOMER-ENROL-1 + CATERING-CUSTOMER-MISMATCH-1.
 *
 * Reported from the live trial on 2026-09-22: a new client was booked, the
 * quotation went out, and Customers had never heard of them. Searching "mairaj"
 * returned "No customers found" while a booking in their name sat on the
 * calendar.
 *
 * Two separate faults were behind it, and this file holds both honest:
 *
 *  1. A typed name was never enrolled. KASHIF-EVENT-FORM-3 made that path WORK
 *     — a non-numeric customer_id is dropped so the booking is not refused —
 *     but it never made the person EXIST.
 *
 *  2. Worse, two live bookings were filed under a stranger. The operator picked
 *     a customer, then corrected the name and phone by hand; the visible fields
 *     obeyed and the hidden id did not. EV-20260921-0018 read "MR. Fazal
 *     Mairaj / 03452291667" while pointing at "MR,SOHAIL IDREES / 0213497532",
 *     and EV-20260917-0009 read "Bilal Danish" while pointing at "IZHAR CHACHA"
 *     — that one carrying 70,000 in advances.
 */
class CateringCustomerEnrolMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'customer_translations', 'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
    }

    /** The whole point: a new client booked here can be found afterwards. */
    public function test_a_typed_name_with_a_phone_becomes_a_customer(): void
    {
        $this->assertSame(0, Customer::count(), 'the book starts empty');

        $event = $this->book('MR. Fazal Mairaj', '03452291667');

        $customer = Customer::first();
        $this->assertNotNull($customer, 'the person now exists in Customers');
        $this->assertSame('MR. Fazal Mairaj', $customer->name);
        $this->assertSame('03452291667', $customer->phone, 'stored as digits, the one spelling we compare on');
        $this->assertSame($customer->id, $event->fresh()->customer_id, 'and the booking is filed under them');
    }

    /**
     * The counter's hard-won lesson, inherited rather than relearned: a known
     * phone REUSES its customer. A real book once carried five "tabish 0333…".
     */
    public function test_the_same_phone_never_makes_a_second_customer(): void
    {
        $first = $this->book('MR. Fazal Mairaj', '03452291667');
        $second = $this->book('Fazal Mairaj Sahab', '0345-229-1667');

        $this->assertSame(1, Customer::count(), 'one person, however the number was typed');
        $this->assertSame(
            $first->fresh()->customer_id,
            $second->fresh()->customer_id,
            'both bookings point at the same person'
        );
    }

    /** And the book is not rewritten by a booking's spelling of a known name. */
    public function test_an_existing_customer_is_not_renamed_by_a_booking(): void
    {
        $existing = Customer::create([
            'code' => null, 'name' => 'MR. FAZAL MAIRAJ', 'phone' => '03452291667', 'status' => 'active',
        ]);

        $this->book('fazal bhai', '03452291667');

        $this->assertSame('MR. FAZAL MAIRAJ', $existing->fresh()->name,
            'whoever is in the book was put there deliberately');
    }

    /**
     * A name alone identifies nobody. Creating from one is how a book fills
     * with near-duplicates — "MR", "MR.", "Mr Ahmed" — so it does nothing and
     * the booking keeps its own copy, exactly as it did before.
     */
    public function test_a_name_with_no_phone_enrols_nobody(): void
    {
        $event = $this->book('Walk In Customer', null);

        $this->assertSame(0, Customer::count(), 'no phone, no identity, no row');
        $this->assertNull($event->fresh()->customer_id);
        $this->assertSame('Walk In Customer', $event->fresh()->customer_name,
            'the booking still carries its own copy');
    }

    /** Adding the phone later enrols them — the edit path, not only create. */
    public function test_adding_a_phone_on_edit_enrols_them(): void
    {
        $event = $this->book('Later Phone', null);
        $this->assertSame(0, Customer::count());

        app(CateringEstimateService::class)->updateEvent($event, [
            'customer_name' => 'Later Phone',
            'customer_phone' => '03001234567',
        ]);

        $this->assertSame(1, Customer::count());
        $this->assertSame(Customer::first()->id, $event->fresh()->customer_id);
    }

    /**
     * THE DELIBERATE LIMIT, and the reason the form warns instead.
     *
     * When a booking already names a customer, the link is LEFT ALONE even if
     * the phone now disagrees. A catering booking legitimately carries somebody
     * else's number — the secretary, the son, the venue manager — so silently
     * re-pointing it at whoever owns that number would invent a different wrong
     * answer. Resolving it is the operator's call, and the form says so on
     * screen before the save.
     *
     * If this ever starts re-pointing, this test goes red and that decision has
     * to be made again on purpose.
     */
    public function test_an_existing_link_is_never_silently_repointed(): void
    {
        $stranger = Customer::create([
            'code' => null, 'name' => 'MR,SOHAIL IDREES', 'phone' => '0213497532', 'status' => 'active',
        ]);
        $other = Customer::create([
            'code' => null, 'name' => 'Somebody Else', 'phone' => '03452291667', 'status' => 'active',
        ]);

        $event = CateringEvent::find($this->book('MR. Fazal Mairaj', null)->id);
        $event->forceFill([
            'customer_id' => $stranger->id,
            'customer_phone' => '03452291667',
        ])->save();

        app(CateringEstimateService::class)->updateEvent($event, [
            'customer_name' => 'MR. Fazal Mairaj',
            'customer_phone' => '03452291667',
        ]);

        $this->assertSame($stranger->id, $event->fresh()->customer_id,
            'the wrong-looking link is the operator\'s to resolve, not ours to guess at');
        $this->assertSame(2, Customer::count(), 'and nobody new was invented');
        $this->assertNotNull($other->fresh());
    }

    private function book(string $name, ?string $phone): CateringEvent
    {
        return app(CateringEstimateService::class)->createEvent(array_filter([
            'branch_id' => $this->branchId,
            'customer_name' => $name,
            'customer_phone' => $phone,
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 50,
        ], fn ($v) => $v !== null));
    }
}
