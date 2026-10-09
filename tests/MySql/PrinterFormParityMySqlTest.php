<?php

namespace Tests\MySql;

use App\Models\Tenant\Printer;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRINTER-FORM-PARITY-1 — form wohi chunao de jo DB qubool karta hai.
 *
 * ── 9 OCTOBER, ASAL NUQSAN ────────────────────────────────────────────────
 *
 * 5 Oct ko DB ke enum me `windows`, `document` aur `A5` daale gaye, magar
 * Edit Printer ka form aur us ka validation dono wahin ke wahin rahe — teenon
 * fehristein Blade aur controller me HAATH SE likhi hui thin.
 *
 * Natija 9 Oct ko prod par nikla. Malik ne printer ka IP badalne ke liye Edit
 * khola:
 *
 *   • Type ne `windows` ki jagah "Browser" dikhaya — kyunke asli qeemat list
 *     me thi hi nahi aur select pehle option par gir gaya
 *   • Role ne `document` ki jagah "Receipt"
 *   • Paper ne `A5` ki jagah "58mm"
 *
 * Aur Save dabate hi ye sirf DIKHAWA nahi raha — DB me waqai likha gaya. Dono
 * A4/A5 document printers thermal receipt printers ban gaye, aur "Send to
 * network" ka button teenon catering safhon se gayab ho gaya
 * (`documentCapable()` sifar).
 *
 * ── SABAQ ────────────────────────────────────────────────────────────────
 *
 * Ek chunao jis me MOJOODA qeemat shaamil na ho, wo khamoshi se data badal
 * deta hai. Us par koi error nahi aata, koi laal nahi hota — screen bas ek
 * ghalat jawab dikhati hai aur user us ki tasdeeq kar deta hai.
 *
 * Ab teenon fehristein `Printer::TYPES / ROLES / PAPER_SIZES` me EK jagah hain,
 * aur ye test unhein DB ke asli enum se mila kar dekhta hai — yani agli baar
 * koi migration enum chaura kare aur form bhool jaye, ye test bolega.
 */
class PrinterFormParityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
    }

    /** DB ka enum parho — form ki fehrist se us ka muqabla hoga. */
    private function enumValues(string $column): array
    {
        $row = DB::connection('tenant')->selectOne("SHOW COLUMNS FROM printers LIKE '{$column}'");
        $this->assertNotNull($row, "printers.{$column} mojood hona chahiye");

        preg_match_all("/'([^']+)'/", $row->Type, $m);

        return $m[1];
    }

    /**
     * SAB SE AHEM. Jo qeemat DB rakh sakta hai, wo form me bhi honi chahiye.
     *
     * Ulti simt par bhi jaancha jata hai: form me koi aisi qeemat na ho jo DB
     * qubool hi na kare — wo Save par 500 ya khamosh nakami deti.
     */
    public function test_every_value_the_database_allows_is_offered_by_the_form(): void
    {
        foreach ([
            'printer_type' => array_keys(Printer::TYPES),
            'print_role' => array_keys(Printer::ROLES),
            'paper_size' => Printer::PAPER_SIZES,
        ] as $column => $offered) {
            $allowed = $this->enumValues($column);

            $missing = array_values(array_diff($allowed, $offered));
            $this->assertSame([], $missing,
                "printers.{$column}: DB ye qeematein rakh sakta hai magar form inhein pesh nahi karta — "
                .'aisi row ko edit karne par select pehle option par gir jata hai aur Save us ghalti ko '
                .'DB me likh deta hai: '.implode(', ', $missing));

            $unknown = array_values(array_diff($offered, $allowed));
            $this->assertSame([], $unknown,
                "printers.{$column}: form ye qeematein pesh karta hai jo DB qubool hi nahi karega: "
                .implode(', ', $unknown));
        }
    }

    /** Wohi fehrist validation par bhi lage — warna form deta hai aur server rad karta hai. */
    public function test_the_controller_validates_against_the_same_lists(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Tenant/PrinterController.php'));
        $code = preg_replace(['/\/\*.*?\*\//s', '/\/\/[^\n]*/'], '', $src);

        foreach ([
            'array_keys(Printer::TYPES)',
            'array_keys(Printer::ROLES)',
            'Printer::PAPER_SIZES',
        ] as $needle) {
            $this->assertStringContainsString($needle, $code,
                "validation ko {$needle} se bandha hona chahiye — haath se likhi list wohi cheez hai "
                .'jo 9 Oct ko DB se bichhar gayi thi');
        }

        $this->assertStringNotContainsString("Rule::in(['network', 'usb', 'browser'])", $code,
            'purani haath se likhi fehrist wapas nahi aani chahiye');
        $this->assertStringNotContainsString("Rule::in(['58mm', '80mm', 'A4'])", $code,
            'A5 ke baghair purani paper fehrist wapas nahi aani chahiye');
    }

    /**
     * Form me Windows ka naam ka khaana HO.
     *
     * Us ke baghair `windows` printer banaya hi nahi ja sakta — aur edit par wo
     * naam khamoshi se khali ho jata, jis ke baad agent printer pehchanta hi
     * nahi. IP us ki jagah nahi le sakta: ye wahi farq hai jis par poora
     * document-printing khara hai.
     */
    public function test_the_form_can_set_the_windows_printer_name(): void
    {
        $form = file_get_contents(base_path('resources/views/tenant/printing/printers/_form.blade.php'));

        $this->assertStringContainsString('name="windows_printer_name"', $form);
        $this->assertStringContainsString('Printer::TYPES', $form,
            'Type ki fehrist model se aani chahiye');
        $this->assertStringContainsString('Printer::PAPER_SIZES', $form,
            'Paper ki fehrist model se aani chahiye');
    }

    /**
     * Probe zinda hai: ek `windows` printer banta bhi hai aur `documentCapable()`
     * use dekhta bhi hai.
     *
     * Ye jaanch is liye hai ke upar wale teen test sirf FEHRIST dekhte hain.
     * Fehrist theek hone ke bawajood agar model ya scope tooti ho, to "Send to
     * network" phir bhi gayab rahega — aur 9 Oct ko asal shikayat wohi thi.
     */
    public function test_a_windows_printer_is_seen_as_document_capable(): void
    {
        DB::connection('tenant')->table('printers')->where('code', 'PARITY-TEST')->delete();

        $p = Printer::create([
            'name' => 'Parity Test', 'code' => 'PARITY-TEST',
            'printer_type' => Printer::TYPE_WINDOWS,
            'print_role' => Printer::ROLE_DOCUMENT,
            'paper_size' => 'A5',
            'windows_printer_name' => 'HP LaserJet Pro MFP M127fn',
            'is_active' => true,
        ]);

        $this->assertSame(Printer::TYPE_WINDOWS, $p->fresh()->printer_type, 'type waisa hi mehfooz ho');
        $this->assertSame('A5', $p->fresh()->paper_size);
        $this->assertTrue(Printer::documentCapable()->where('id', $p->id)->exists(),
            'aur document printing use dekhe — warna "Send to network" nazar hi nahi aayega');

        DB::connection('tenant')->table('printers')->where('id', $p->id)->delete();
    }
}
