<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\PrintAgentController;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * AGENT-VERSION-TRUTH-1 — download wohi de jo wo kehta hai.
 *
 * ── 4 OCTOBER, ASAL WAQEA ─────────────────────────────────────────────────
 *
 * Malik ne client ke PC par agent download kiya aur setup ke sar par likha
 * dekha: **2.5.0** — jabke screen "Latest version 2.6.0" ka ailan kar rahi thi.
 * Wo galat installer client ki machine tak pahunch gaya.
 *
 * Teen cheezein mil kar ye bani thin:
 *
 *   1. Version DO jagah likhi thi — `print-agent.js` aur `.iss` — aur maine
 *      sirf pehli badli. (Us par ab `version-match-test.js` hai.)
 *
 *   2. Screen `print-agent.js` se version parhti thi aur link
 *      `?version=2.6.0` maangta tha, jabke shelf par wo build thi hi nahi.
 *
 *   3. Aur ye sab se bura: controller us soorat me CHUP CHAAP purani
 *      `BingooPrintAgent-Setup.exe` de deta tha — aur us par
 *      `BingooPrintAgent-Setup-2.6.0.exe` ka naam aur `X-Agent-Version: 2.6.0`
 *      ka header bhi laga deta tha. Yani file par us version ka thappa lagta
 *      tha jo us me thi hi nahi.
 *
 * Teesri baat is file ka asal maqsad hai. Pehli do ghalat-fehmi thin; ye JHOOT
 * tha, aur sirf setup ke sar par wo ek jagah thi jahan sach bacha tha.
 */
class PrintAgentDownloadTruthMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $dist;

    private string $shelf;

    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->dist = base_path('tools/print-agent/dist');
        $this->shelf = $this->dist.'/releases';
    }

    protected function tearDown(): void
    {
        // Sirf wo files jo IS test ne banayi — asli shelf ko haath nahi lagta.
        foreach ($this->made as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->made = [];

        parent::tearDown();
    }

    private function fakeBuild(string $version): void
    {
        if (! is_dir($this->shelf)) {
            @mkdir($this->shelf, 0775, true);
        }
        $path = $this->shelf.'/BingooPrintAgent-Setup-'.$version.'.exe';
        if (! is_file($path)) {
            file_put_contents($path, 'fake installer '.$version);
            $this->made[] = $path;
        }
    }

    /**
     * SAB SE AHEM. Thappa SIRF saboot se lage — source ki version se kabhi nahi.
     *
     * 4 Oct ko naam `agentVersion()` se aata tha, yani "SOURCE kya kehti hai" —
     * aur isi ne 2.5.0 wali file par "2.6.0" likh kar client ke PC tak pahuncha
     * diya. Us ke baad kuch arsa yahan `'unknown'` likha gaya: jhoot se behtar,
     * magar client ko "BingooPrintAgent-Setup-unknown.exe" thamana bhi koi
     * jawab nahi tha.
     *
     * Ab faisla BYTES par hota hai. Shelf par har build apne version ke naam se
     * pari hai; jo file un me se kisi ke barabar nikle, wo wohi build hai. Is
     * me andaza hai hi nahi — aur jis ka koi jora na mile wo "unknown" hi
     * rehti hai, kyunke tab hum waqai nahi jaante.
     *
     * Ye test source ka MATN nahi, RAWAIYYA parakhta hai: barabar, ghair-barabar,
     * aur wo purani satar jo wapas nahi aani chahiye.
     */
    public function test_the_fallback_installer_is_stamped_only_from_proof(): void
    {
        $controller = app(PrintAgentController::class);
        $method = new \ReflectionMethod($controller, 'setupExeVersion');
        $method->setAccessible(true);

        $this->fakeBuild('9.9.9');
        $shelfFile = $this->shelf.'/BingooPrintAgent-Setup-9.9.9.exe';

        // (a) Bytes shelf wali build ke barabar -> wohi version.
        $same = $this->dist.'/truth-test-same.exe';
        copy($shelfFile, $same);
        $this->made[] = $same;
        $this->assertSame('9.9.9', $method->invoke($controller, $same),
            'jo file shelf wali build ke BYTE-BA-BYTE barabar hai, wo wohi build hai');

        // (b) Bytes kisi se na milein -> "unknown".
        $other = $this->dist.'/truth-test-other.exe';
        file_put_contents($other, 'kisi bhi shelf build se na milne wali file');
        $this->made[] = $other;
        $this->assertSame('unknown', $method->invoke($controller, $other),
            'jis file ka koi jora shelf par nahi, us par thappa nahi lagna chahiye');

        // (c) Aur wo satar kabhi wapas na aaye jis ne ye sab kiya tha.
        $src = file_get_contents(base_path('app/Http/Controllers/Tenant/PrintAgentController.php'));
        $code = preg_replace(['/\/\*.*?\*\//s', '/\/\/[^\n]*/'], '', $src);
        $this->assertStringNotContainsString('serveAgentExe($setupExe, $this->agentVersion())', $code,
            'yehi wo satar thi jis ne 2.5.0 ko "2.6.0" bana kar client ke PC par bheja');
    }

    /** Screen wo version pesh kare jo shelf par WAQAI mojood hai. */
    public function test_the_screen_offers_what_the_shelf_can_actually_deliver(): void
    {
        $controller = app(PrintAgentController::class);

        $this->fakeBuild('9.9.8');
        $this->fakeBuild('9.9.9');

        $this->assertSame('9.9.9', $controller->shippedVersion(),
            'sab se nayi build jo shelf par hai');

        // Aur wo source ki version se ALAG cheez hai — yehi poora sabaq hai.
        $this->assertNotSame($controller->agentVersion(), $controller->shippedVersion(),
            'source kya kehta hai aur haath me kya aata hai — do alag sawal hain');
    }

    /**
     * Probe zinda hai: shelf khali ho to `null`, koi andaza nahi.
     *
     * Yahan `null` ka matlab hai "kuch nahi diya ja sakta", aur screen us par
     * button hi nahi dikhati. Pehle is soorat me wo chup chaap purani file de
     * deti thi.
     */
    public function test_an_empty_shelf_reports_nothing_rather_than_guessing(): void
    {
        $existing = glob($this->shelf.'/BingooPrintAgent-Setup-*.exe') ?: [];

        if ($existing !== []) {
            // Asli shelf par builds hain — unhein chhue baghair itna hi sabit
            // kiya ja sakta hai ke jawab unhi me se aata hai, ijaad nahi hota.
            $versions = array_map(
                fn ($f) => preg_replace('/.*-([0-9.]+)\.exe$/', '$1', $f),
                $existing
            );
            $this->assertContains(app(PrintAgentController::class)->shippedVersion(), $versions,
                'jawab shelf par mojood kisi build ka hona chahiye');

            return;
        }

        $this->assertNull(app(PrintAgentController::class)->shippedVersion(),
            'shelf khali ho to koi version mat batao');
    }
}
