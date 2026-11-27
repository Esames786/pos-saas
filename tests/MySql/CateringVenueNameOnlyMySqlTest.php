<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringEventController;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\TenantFixtures;

/**
 * VENUE-NAME-ONLY-1 (5 Nov) — booking ke Venue me sirf hall ya jagah ka NAAM.
 *
 * Owner: "venue itna bara ke wo ek address jaisa lag raha hai … sirf one two
 * word pe kar do". Prod par 1 Oct se 77 bhare venue me 32 me 5+ lafz the —
 * asal me poore pate — aur wohi pata Address me bhi hota tha, is liye kitchen
 * sheet par do baar chhapta tha.
 *
 * Ahem baat "lamba rad hua" nahi. Ahem ye hai ke PURANI booking ka lamba venue
 * (qaide se pehle likha gaya) us booking ki baqi editing na roke — warna kal
 * koi operator kisi purani booking ka PAX badalne jayega aur venue ki ghalti
 * par atak jayega jo us ne likhi hi nahi thi.
 *
 * VENUE-LIMIT-200-1 (27 Nov) — malik: hadd kam hai, 200 kar do. Ab 200 huroof:
 * jo pata pehle rad hota tha (48) ab samata hai; rad wo hai jo 200 se lamba ho.
 */
class CateringVenueNameOnlyMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private const FULL_ADDRESS = 'a 489 bl 5 gulshan iqbal near sirsyed university';

    /** 200 se lamba, magar database ke 255 me samata hai — purani booking jaisa. */
    private const TOO_LONG = 'a 489 bl 5 gulshan iqbal near sirsyed university, opposite dental clinic, '
        .'second lane after the mosque, ground floor hall with the green gate, '
        .'ask for the manager at reception, parking at the back of the building';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();
        View::share('errors', new \Illuminate\Support\ViewErrorBag);
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true);

        $this->cleanTenant([
            'catering_email_logs',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_events', 'catering_settings',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
    }

    /** Form ka khana khud naam maangta hai — aur typing hadd par ruk jati hai. */
    public function test_the_venue_box_asks_for_a_name_and_stops_at_the_limit(): void
    {
        $html = $this->controller()->create()->render();

        $this->assertSame(200, CateringEvent::VENUE_MAX_LENGTH);
        $this->assertMatchesRegularExpression('/name="venue"[^>]*maxlength="200"/s', $html,
            'venue ka khana maxlength ke baghair hai — lamba pata phir likha ja sakta hai');
        $this->assertStringContainsString('placeholder="Jaise: Universal Banquet"', $html);
        $this->assertStringContainsString('Poora pata upar <strong>Address</strong> me likhein', $html);
    }

    /** Nayi booking: naam aur 48 huroof ka pata qubool, 200 se lamba rad — saaf wajah ke saath. */
    public function test_a_new_booking_takes_a_name_and_refuses_a_venue_over_the_limit(): void
    {
        // Pehle probe zinda: naam wali booking banti hai.
        $this->controller()->store($this->request($this->valid(['customer_name' => 'NAME OK', 'venue' => 'Universal Banquet'])));
        $this->assertSame('Universal Banquet', CateringEvent::where('customer_name', 'NAME OK')->value('venue'));

        // VENUE-LIMIT-200-1: jo 30 ki hadd par rad hota tha, ab samata hai.
        $this->controller()->store($this->request($this->valid(['customer_name' => 'ADDRESS OK', 'venue' => self::FULL_ADDRESS])));
        $this->assertSame(self::FULL_ADDRESS, CateringEvent::where('customer_name', 'ADDRESS OK')->value('venue'));

        $this->assertGreaterThan(200, mb_strlen(self::TOO_LONG));
        $this->assertLessThanOrEqual(255, mb_strlen(self::TOO_LONG), 'database ke khaane me samana chahiye — rad hamara qaida kare, database nahi');
        try {
            $this->controller()->store($this->request($this->valid(['customer_name' => 'LONG VENUE', 'venue' => self::TOO_LONG])));
            $this->fail('200 se lamba venue qubool ho gaya');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->validator->errors()->get('venue'));
            $this->assertStringContainsString('sirf hall ya jagah ka naam', $message);
            $this->assertStringContainsString('Address me likhein', $message, 'paigham batae ke pata kahan likhna hai');
        }

        $this->assertSame(0, CateringEvent::where('customer_name', 'LONG VENUE')->count());
    }

    /** Hadd par theek 200 huroof qubool, 201 nahi. */
    public function test_the_limit_is_two_hundred_characters_exactly(): void
    {
        $this->controller()->store($this->request($this->valid(['customer_name' => 'TWO HUNDRED', 'venue' => str_repeat('a', 200)])));
        $this->assertSame(1, CateringEvent::where('customer_name', 'TWO HUNDRED')->count());

        $this->expectException(ValidationException::class);
        $this->controller()->store($this->request($this->valid(['customer_name' => 'TWO HUNDRED ONE', 'venue' => str_repeat('a', 201)])));
    }

    /** Purani booking ka lamba venue, bina chhede wapas aaye, to baqi editing nahi rukti. */
    public function test_an_old_long_venue_does_not_block_editing_the_rest_of_the_booking(): void
    {
        $event = $this->oldBooking();

        $this->controller()->update($this->request($this->valid(['venue' => self::TOO_LONG, 'pax' => 250])), $event);

        $event->refresh();
        $this->assertSame(250, (int) $event->pax, 'PAX ki tabdeeli venue ki wajah se ruk gayi');
        $this->assertSame(self::TOO_LONG, $event->venue);
    }

    /** Magar purane venue ko KISI AUR lambe text se badalna rad hai. */
    public function test_changing_an_old_venue_to_another_long_text_is_refused(): void
    {
        $event = $this->oldBooking();

        try {
            $this->controller()->update($this->request($this->valid(['venue' => str_replace('green gate', 'black gate', self::TOO_LONG)])), $event);
            $this->fail('badla hua lamba venue qubool ho gaya');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('venue', $e->validator->errors()->toArray());
        }

        $this->assertSame(self::TOO_LONG, $event->refresh()->venue);
    }

    /** Aur purane lambe venue ko chhote naam se badalna — yehi to chahiye — qubool. */
    public function test_shortening_an_old_venue_to_a_name_is_accepted(): void
    {
        $event = $this->oldBooking();

        $this->controller()->update($this->request($this->valid(['venue' => 'Sirsyed Hall'])), $event);

        $this->assertSame('Sirsyed Hall', $event->refresh()->venue);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** Qaide se PEHLE ki booking: venue me poora pata (service se, jo form ka qaida nahi lagati). */
    private function oldBooking(): CateringEvent
    {
        return app(CateringEstimateService::class)->createEvent($this->valid(['venue' => self::TOO_LONG]));
    }

    private function controller(): CateringEventController
    {
        return app(CateringEventController::class);
    }

    private function request(array $data): Request
    {
        $request = Request::create('/catering/events', 'POST', $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branchId,
            'customer_name' => 'Venue Customer',
            'customer_phone' => '0300-1231234',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 120,
        ], $overrides);
    }
}
