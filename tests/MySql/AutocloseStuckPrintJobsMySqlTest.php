<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PRINT-AUTOCLOSE-STUCK-1 — ek ghante se atki parchi khud band ho, magar SIRF wohi.
 *
 * Asal waqia: The Kashif Foods par teen Report Center ki parchiyan galti se doosri branch ki
 * printer par bhej di gayi thin. Wahan se pahunch hi nahi sakti thin, aur `deferForRetry()`
 * jaan-boojh kar "kabhi haar na maano" par chalta hai — to har ~45 second par agent 16 second
 * us gum printer par atka rehta aur usi dauran banne wali har receipt/KOT 12-17 second
 * intezar karti. Ek parchi 09 September se ghoom rahi thi.
 *
 * Is file ka kaam do baatein sabit karna hai, aur doosri pehli se zyada ahem hai:
 *
 *   1. Ek ghante se purani atki parchi band ho jaye.
 *   2. Aur us ke ilawa KISI parchi ko haath na lage — na chhapi hui ko, na nayi ko, na us ko
 *      jo IS WAQT printer se nikal rahi hai.
 */
class AutocloseStuckPrintJobsMySqlTest extends MySqlTenantTestCase
{
    private int $branchId;
    private int $printerId;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
        $this->bootTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)
                ->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'pjclose')->delete();
        } catch (\Throwable) {
            // best effort — asli nateeja kabhi na chhupe
        }
        parent::tearDown();
    }

    /**
     * ⚠️ Ye fixture LAZMI hai, sajawat nahi.
     *
     * Command asli raaste par `Tenant::where('status','active')` se ghoomti hai aur har ek ko
     * `TenancyManager::activate()` karti hai. Agar master me is suite ka tenant record na ho to
     * command ko KOI tenant milta hi nahi — aur har guard "kuch band nahi hua" keh kar JHOOTA
     * fail hota hai (pehli koshish me bilkul yehi hua: 6 test gire, code theek tha).
     */
    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $m->table('tenants')->where('tenant_code', 'pjclose')->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'pjclose', 'business_name' => 'Print Autoclose',
            'owner_name' => 'Owner', 'owner_email' => 'owner@pjclose.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_ek_ghante_se_purani_atki_parchi_band_ho_jaati_hai(): void
    {
        $stuck = $this->job(['print_status' => 'queued', 'created_at' => now()->subMinutes(90)]);

        $this->autoclose();

        $fresh = $stuck->fresh();
        $this->assertSame('cancelled', $fresh->print_status, '90 minute purani parchi band ho gayi');

        // Kuch chhapa NAHI — ye sab se ahem baat hai, warna ginti jhoot bol deti.
        $this->assertNull($fresh->printed_at, 'printed_at NULL raha — kuch chhapa nahi');
        $this->assertNull($fresh->claimed_at, 'claim saaf ho gaya');

        // Sabab likha hua ho, warna parchi khamoshi se gayab hui — aur wo sab se bura nateeja hai.
        $this->assertNotEmpty($fresh->error_message, 'sabab likha gaya');
        $this->assertStringContainsString('90', $fresh->error_message, 'umar likhi gayi');
        $this->assertStringContainsString('Retry', $fresh->error_message, 'wapas laane ka raasta bataya gaya');
    }

    /** `failed` bhi band hota hai — owner ne isi lafz me maanga tha. */
    public function test_failed_parchi_bhi_band_hoti_hai(): void
    {
        $failed = $this->job([
            'print_status' => 'failed',
            'created_at'   => now()->subMinutes(75),
            'failed_at'    => now()->subMinutes(70),
            'attempts'     => 3,
        ]);

        $this->autoclose();

        $this->assertSame('cancelled', $failed->fresh()->print_status);
    }

    /**
     * Hadd se NAYI parchi ko haath nahi lagta.
     *
     * Hadd 60 minute is liye hai ke Pakistan me bijli chali jaye to printer/PC der tak band
     * reh sakta hai — us dauran ki parchi ko bach jana chahiye.
     */
    public function test_hadd_se_nayi_parchi_chhoori_jaati_hai(): void
    {
        $recent = $this->job(['print_status' => 'queued', 'created_at' => now()->subMinutes(45)]);
        $fresh  = $this->job(['print_status' => 'queued', 'created_at' => now()->subMinutes(2)]);

        $this->autoclose();

        $this->assertSame('queued', $recent->fresh()->print_status, '45 minute purani abhi zinda hai');
        $this->assertSame('queued', $fresh->fresh()->print_status, '2 minute purani bilkul achhoot');
    }

    /**
     * ⚠️ Sab se naazuk shart: jo parchi IS WAQT chhap rahi ho, usay chhora jaye.
     *
     * Agent ka claim lease theek 2 minute hai (`PrintAgentApiController` pending fetch me
     * `claimed_at < now()->subMinutes(2)`). Us window ke andar claim hui parchi printer se
     * nikal rahi ho sakti hai, aur `cancelObsolete()` "printed" ko rok deta hai magar
     * "chhap rahi hai" ko nahi pehchan sakta — is liye faasla khud rakhna parta hai.
     */
    public function test_jo_parchi_abhi_chhap_rahi_hai_usay_chhora_jata_hai(): void
    {
        // Purani hai (band honi chahiye) MAGAR abhi claim hui hai.
        $inFlight = $this->job([
            'print_status' => 'queued',
            'created_at'   => now()->subMinutes(120),
            'claimed_at'   => now()->subSeconds(20),
        ]);

        // Utni hi purani, magar claim bhi purana — is par lease khatam ho chuki hai.
        $abandoned = $this->job([
            'print_status' => 'queued',
            'created_at'   => now()->subMinutes(120),
            'claimed_at'   => now()->subMinutes(30),
        ]);

        $this->autoclose();

        $this->assertSame('queued', $inFlight->fresh()->print_status,
            'abhi chhapne wali parchi bachi rahi — warna hum us parchi ko cancel kar dete jo usi lamhe nikal rahi thi');
        $this->assertSame('cancelled', $abandoned->fresh()->print_status,
            'jis ka lease khatam ho gaya, wo band hui');
    }

    /** Chhap chuki parchi par kabhi haath nahi. */
    public function test_chhapi_hui_parchi_kabhi_nahi_chhoori_jaati(): void
    {
        $printed = $this->job([
            'print_status' => 'printed',
            'created_at'   => now()->subDays(3),
            'printed_at'   => now()->subDays(3)->addSeconds(2),
        ]);

        $code = Artisan::call('printing:autoclose-stuck', ['--minutes' => 60, '--tenant' => 'pjclose']);
        $output = Artisan::output();

        $fresh = $printed->fresh();
        $this->assertSame('printed', $fresh->print_status, 'chhapi hui parchi achhoot');
        $this->assertNotNull($fresh->printed_at, 'uska printed_at bhi achhoot');

        // ⚠️ Sirf natejay par bharosa KAAFI NAHI. `cancelObsolete()` khud `queued|failed` ke
        // ilawa kuch qabool nahi karta aur throw kar deta hai — yani agar command ki query se
        // `printed` ki hifazat hata di jaye, parchi PHIR BHI bachi rahegi (service rok legi)
        // aur ye test jhoota pass ho jayega. Isi liye ye do assertion: command ko chhapi hui
        // parchi ko CHHOONA hi nahi chahiye, is liye na koi nakaami darj ho aur na exit code
        // kharab ho. Ye guard sabit hote dekha gaya hai (filter hataane par RED).
        $this->assertSame(0, $code, 'command kaamyab — koi nakaami darj nahi hui');
        $this->assertStringContainsString('0 nakaam', $output,
            'command ne chhapi hui parchi ko band karne ki koshish bhi nahi ki');
    }

    /** Pehle se cancelled par dobara kaam nahi hota (idempotent). */
    public function test_dobara_chalane_par_kuch_nahi_badalta(): void
    {
        $stuck = $this->job(['print_status' => 'queued', 'created_at' => now()->subMinutes(90)]);

        $this->autoclose();
        $first = $stuck->fresh();
        $this->assertSame('cancelled', $first->print_status);

        $this->autoclose();
        $second = $stuck->fresh();

        $this->assertSame($first->error_message, $second->error_message, 'sabab dobara nahi likha gaya');
        $this->assertSame((string) $first->updated_at, (string) $second->updated_at, 'row dobara chhui hi nahi');
        $this->assertSame(1, PrintJob::where('print_status', 'cancelled')->count());
    }

    /** `--dry-run` sirf batata hai, kuch band nahi karta. */
    public function test_dry_run_kuch_band_nahi_karta(): void
    {
        $stuck = $this->job(['print_status' => 'queued', 'created_at' => now()->subMinutes(90)]);

        Artisan::call('printing:autoclose-stuck', ['--minutes' => 60, '--tenant' => 'pjclose', '--dry-run' => true]);

        $this->assertSame('queued', $stuck->fresh()->print_status, 'dry-run ne kuch nahi badla');
        $this->assertStringContainsString('BAND HOTI', Artisan::output(), 'magar bataya ke kya band hota');
    }

    /** `--minutes` waqai hadd badalti hai. */
    public function test_minutes_ki_hadd_maani_rakhti_hai(): void
    {
        $job = $this->job(['print_status' => 'queued', 'created_at' => now()->subMinutes(45)]);

        Artisan::call('printing:autoclose-stuck', ['--minutes' => 60, '--tenant' => 'pjclose']);
        $this->assertSame('queued', $job->fresh()->print_status, '60 ki hadd par 45 minute purani bachi');

        Artisan::call('printing:autoclose-stuck', ['--minutes' => 30, '--tenant' => 'pjclose']);
        $this->assertSame('cancelled', $job->fresh()->print_status, '30 ki hadd par wohi parchi band hui');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // helpers
    // ══════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ `--tenant=pjclose` LAZMI hai. Command asli raaste par SAARE active tenants par ghoomti
     * hai, aur is master test DB me doosre tests ke chhoore hue orphan tenant rows pade hain
     * (`autobk6`, `limittest`) jo activate par nakaam hote hain aur command ka exit code kharab
     * kar dete hain. Un ko yahan se delete karna doosre tests ka data chherna hota; is liye
     * imtihan apne tenant tak mehdood rakha gaya — `--tenant` command ka asli option hai, to
     * raasta ab bhi wohi hai jo scheduler leta hai.
     */
    private function autoclose(): void
    {
        Artisan::call('printing:autoclose-stuck', ['--minutes' => 60, '--tenant' => 'pjclose']);
    }

    /**
     * ⚠️ `created_at` PrintJob ke `$fillable` me NAHI hai.
     *
     * Iska matlab `PrintJob::create(['created_at' => ...])` us qeemat ko CHUP-CHAAP gira deta
     * hai aur parchi "abhi ki" ban jaati hai. Pehli koshish me isi wajah se 6 guard fail hue:
     * command bilkul theek chal rahi thi (0 stuck jobs — kyunke koi purana tha hi nahi), meri
     * FIXTURE jhoot bol rahi thi. Ek probe se pata chala jo raw insert karta tha aur pass ho
     * gaya tha.
     *
     * Is liye umar Eloquent se NAHI, query builder se likhi jaati hai — wo `$fillable` aur
     * timestamps dono ko bypass karta hai.
     */
    private function job(array $attrs = []): PrintJob
    {
        static $n = 0;
        $n++;

        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $job = PrintJob::create(array_merge([
            'job_no'         => 'PJ-' . Str::upper(Str::random(8)),
            'logical_key'    => 'lk-' . Str::random(10) . '-' . $n,
            'copy_no'        => 1,
            'branch_id'      => $this->branchId,
            'printer_id'     => $this->printerId,
            'document_type'  => 'kot',
            'print_status'   => 'queued',
            'reference_type' => 'sales_order',
            'reference_id'   => $n,
            'reference_no'   => 'REF-' . $n,
            'payload'        => ['lines' => []],
            'attempts'       => 0,
        ], $attrs));

        if ($createdAt) {
            DB::connection('tenant')->table('print_jobs')->where('id', $job->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $job = $job->fresh();
        }

        return $job;
    }

    private function bootTenant(): void
    {
        DB::setDefaultConnection('tenant');

        $this->cleanTenant(['print_jobs', 'printers', 'branches']);

        $c = DB::connection('tenant');

        $this->branchId = $c->table('branches')->insertGetId([
            'name' => 'Main', 'code' => 'MAIN', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->printerId = $c->table('printers')->insertGetId([
            'branch_id' => $this->branchId, 'name' => 'Counter Printer',
            'code' => 'P-' . Str::upper(Str::random(4)), 'printer_type' => 'network', 'print_role' => 'both',
            'ip_address' => '192.168.1.50', 'port' => 9100,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Command ACTIVE tenants par chalti hai aur har ek ko activate karti hai; is suite ka
        // tenant record master me mojood hona chahiye warna command usay dekhti hi nahi.
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
    }
}
