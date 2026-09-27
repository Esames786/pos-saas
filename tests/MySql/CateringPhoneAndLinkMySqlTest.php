<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\Customer;
use App\Services\Catering\CateringEstimateService;
use App\Services\Tenant\CustomerDirectory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-PHONE-2-1 — phone lazmi, doosra phone marzi ka, aur purani
 * bookings ka link.
 *
 * Malik (27 Sep): "phone 1 required kardo, phone 2 optional kardo, phone ki
 * length … min 11 max 13 14 tak, aur jo customer unlink hain un ko peechey se
 * add ya update kar ke link kardo."
 *
 * Yahan "link lag gaya" sab se kam ahem baat hai. Ahem ye hai ke command
 * ANDAZA NA LAGAYE: ek shubhe wale number ko kaat kar do number bana dena, ya
 * naam ke khaane me likhe hue phone ko khud utha lena — dono ek asli number ko
 * ghalat number bana sakte hain, aur wo kharabi khamoshi se aage chalti
 * rehti hai.
 */
class CateringPhoneAndLinkMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'customers', 'units', 'products', 'categories', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
    }

    private function booking(array $attrs): CateringEvent
    {
        return app(CateringEstimateService::class)->createEvent(array_merge([
            'branch_id' => $this->branchId,
            'customer_name' => 'Test Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 40,
        ], $attrs));
    }

    // ── Phone ki ginti ────────────────────────────────────────────────────

    /**
     * Ginti ADADON par hoti hai, likhi hui shakl par nahi. Operator
     * "0312-2951623" likhta hai aur wo bilkul durust number hai — Laravel ka
     * digits_between usay rad kar deta, is liye apna rule hai.
     *
     * @dataProvider phoneShapes
     */
    public function test_a_phone_is_judged_on_its_digits_not_its_punctuation(string $phone, bool $ok): void
    {
        $digits = strlen(preg_replace('/\D+/', '', $phone) ?? '');
        $accepted = $digits >= 11 && $digits <= 14;

        $this->assertSame($ok, $accepted, "[{$phone}] me {$digits} adad — faisla ghalat nikla");
    }

    public static function phoneShapes(): array
    {
        return [
            'seedha 11 adad' => ['03122951623', true],
            'dash ke saath' => ['0312-2951623', true],
            'space ke saath' => ['0312 295 1623', true],
            '+92 wali shakl' => ['+92 312 2951623', true],
            'das adad — kam' => ['0332201120', false],
            'do number ek saath' => ['0312-0080000  0312-0090000', false],
            'khali' => ['', false],
        ];
    }

    // ── Purani bookings ka link ───────────────────────────────────────────

    /** Phone hai, link nahi — command jor deti hai. */
    public function test_a_booking_with_a_phone_gets_linked(): void
    {
        $event = $this->booking(['customer_name' => 'MR.NOMAN', 'customer_phone' => '03116570423']);
        $event->forceFill(['customer_id' => null])->save();

        $this->artisan('catering:link-unlinked-bookings', ['tenant_code' => $this->code(), '--yes' => true])
            ->assertExitCode(0);

        $this->assertNotNull($event->fresh()->customer_id, 'booking graahak se jurni chahiye');
        $this->assertSame('03116570423', Customer::find($event->fresh()->customer_id)->phone);
    }

    /**
     * DRY RUN kuch nahi likhta — malik pehle dekh sake ke kya hoga.
     */
    public function test_a_dry_run_links_nothing(): void
    {
        $event = $this->booking(['customer_name' => 'MR.NOMAN', 'customer_phone' => '03116570423']);
        $event->forceFill(['customer_id' => null])->save();

        $this->artisan('catering:link-unlinked-bookings', ['tenant_code' => $this->code()])
            ->assertExitCode(0);

        $this->assertNull($event->fresh()->customer_id, '--yes ke baghair kuch nahi jurna chahiye');
    }

    /**
     * Ek hi khaane me do number — pehla phone 1, doosra phone 2, aur pehchan
     * PEHLE par. Prod par ye asli soorat hai (FOOD BREAK GULSHAN).
     */
    public function test_two_numbers_in_one_box_are_split_and_identity_stays_on_the_first(): void
    {
        $event = $this->booking([
            'customer_name' => 'FOOD BREAK GULSHAN',
            'customer_phone' => '0312-0080000  0312-0090000',
        ]);
        $event->forceFill(['customer_id' => null])->save();

        $this->artisan('catering:link-unlinked-bookings', ['tenant_code' => $this->code(), '--yes' => true]);

        $fresh = $event->fresh();
        $this->assertSame('03120080000', $fresh->customer_phone);
        $this->assertSame('03120090000', $fresh->customer_phone_2);
        $this->assertSame('03120080000', Customer::find($fresh->customer_id)->phone,
            'pehchan PEHLE number par — dono par karne se ek shaks ke do customer ban jate');
    }

    /**
     * SAB SE AHEM. Ek 12 adad wala number TYPO hai, do number nahi. Usay kaat
     * dena ek asli number ko ghalat number bana deta — is liye command usay
     * chhod deti hai.
     */
    public function test_an_odd_length_number_is_never_chopped_into_two(): void
    {
        $event = $this->booking(['customer_name' => 'MR TYPO', 'customer_phone' => '031225516233']);
        $event->forceFill(['customer_id' => null])->save();

        $this->artisan('catering:link-unlinked-bookings', ['tenant_code' => $this->code(), '--yes' => true]);

        $fresh = $event->fresh();
        $this->assertSame('031225516233', $fresh->customer_phone, 'number waise ka waisa');
        $this->assertNull($fresh->customer_phone_2, 'do tukre nahi kiye gaye');
    }

    /** Phone hi na ho to command kuch nahi banati — farzi customer nahi. */
    public function test_a_booking_without_a_phone_is_left_alone(): void
    {
        $event = $this->booking(['customer_name' => 'KASHIF FOOD', 'customer_phone' => null]);
        $event->forceFill(['customer_id' => null])->save();

        $before = Customer::count();
        $this->artisan('catering:link-unlinked-bookings', ['tenant_code' => $this->code(), '--yes' => true]);

        $this->assertNull($event->fresh()->customer_id);
        $this->assertSame($before, Customer::count(), 'phone ke baghair koi farzi customer nahi banna chahiye');
    }

    /**
     * Ek hi shaks do bookings par — ek hi customer bane, do nahi. Command
     * wohi `findOrCreateByPhone` chalati hai jo booking banate waqt chalta
     * hai; apna alag raasta likhne par yehi cheez toot-ti.
     */
    public function test_the_same_phone_on_two_bookings_makes_one_customer(): void
    {
        foreach (['mr. farhat hussain', 'c/o the orbit academy'] as $name) {
            $e = $this->booking(['customer_name' => $name, 'customer_phone' => '03313129349']);
            $e->forceFill(['customer_id' => null])->save();
        }

        $this->artisan('catering:link-unlinked-bookings', ['tenant_code' => $this->code(), '--yes' => true]);

        $this->assertSame(1, Customer::where('phone', '03313129349')->count(),
            'ek phone = ek customer');
        $this->assertSame(1, CateringEvent::whereNotNull('customer_id')
            ->distinct()->count('customer_id'), 'dono bookings usi ek par');
    }

    /** Dobara chalane se kuch nahi bigadta. */
    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $event = $this->booking(['customer_name' => 'MR.NOMAN', 'customer_phone' => '03116570423']);
        $event->forceFill(['customer_id' => null])->save();

        $this->artisan('catering:link-unlinked-bookings', ['tenant_code' => $this->code(), '--yes' => true]);
        $first = $event->fresh()->customer_id;

        $this->artisan('catering:link-unlinked-bookings', ['tenant_code' => $this->code(), '--yes' => true]);

        $this->assertSame($first, $event->fresh()->customer_id);
        $this->assertSame(1, Customer::count(), 'doosri baar koi naya customer nahi banna chahiye');
    }

    /** Wohi directory jo booking banate waqt chalti hai — do raaste nahi. */
    public function test_the_command_uses_the_same_directory_as_the_booking_form(): void
    {
        $src = file_get_contents(app_path('Console/Commands/CateringLinkUnlinkedBookingsCommand.php'));

        $this->assertStringContainsString('findOrCreateByPhone', $src,
            'command ko wohi raasta chalana chahiye jo form chalata hai');
        $this->assertStringNotContainsString('Customer::create', $src,
            'apna alag customer banane ka tareeqa dono ko waqt ke saath alag kar dega');
    }

    /**
     * Tenant ka code, aur agar master me is test DB ka koi tenant nahi to
     * KHUD BANA LO.
     *
     * Pehle yahan `markTestSkipped` tha. Wo khatarnak nikla: is file ke 15 me
     * se 7 test — yani wo SAARE jo command ko waqai chalate hain — chup chaap
     * skip ho jate the, aur suite phir bhi hara nazar aata tha. Ek aisi
     * command ke liye jo prod par graahak ka data badalti hai, "hara suite"
     * ka matlab "command chal kar sahi nikli" hona chahiye, "command chali hi
     * nahi" nahi.
     *
     * Skip is liye lag raha tha ke test master ke us row par tik-a hua tha jo
     * kisi aur ne kabhi banaya ho — yani AMBIENT haalat par. Ab test apna
     * tenant khud banata hai, is liye ye kisi bhi machine par chalta hai.
     *
     * Aur is DB ka joran HAR BAAR dobara likha jata hai, purana mil jane par
     * bhi. Master test DB har run par saaf nahi hota, is liye ek kharab row
     * (misaal ke taur par bina encrypt kiya hua password) wahan pari reh kar
     * har agli run ko girati rehti — ek kharabi jo test ki apni chhori hui
     * ho aur code ki na ho, sab se mehngi hoti hai.
     */
    private function code(): string
    {
        $master = DB::connection('master');

        $code = $master->table('tenants')
            ->join('tenant_databases as d', 'd.tenant_id', '=', 'tenants.id')
            ->where('d.db_database', $this->tenantDb)
            ->value('tenants.tenant_code');

        if (! $code) {
            $code = 'kstest'.substr(md5($this->tenantDb), 0, 6);
            $master->table('tenants')->updateOrInsert(
                ['tenant_code' => $code],
                [
                    'business_name' => 'Catering Phone Link Test Tenant',
                    'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]
            );
        }

        $tenantId = $master->table('tenants')->where('tenant_code', $code)->value('id');

        // Wohi connection jo test khud istemaal kar raha hai — taake command
        // usi DB par chale jise is test ne banaya hai, kisi aur par nahi.
        $cfg = config('database.connections.tenant');
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenant_databases')->insert([
            'tenant_id' => $tenantId,
            'db_connection' => 'tenant',
            'db_host' => $cfg['host'] ?? '127.0.0.1',
            'db_port' => $cfg['port'] ?? 3306,
            'db_database' => $this->tenantDb,
            'db_username' => $cfg['username'] ?? 'root',
            // `db_password` par `encrypted` cast lagta hai. Yahan query
            // builder chal raha hai, model nahi, is liye cast khud nahi
            // lagta — saada text daalne par padhte waqt "payload is
            // invalid" aata hai.
            'db_password' => Crypt::encryptString((string) ($cfg['password'] ?? '')),
            'migration_status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $code;
    }
}
