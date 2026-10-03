<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialRate;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * KITCHEN-SHEET-LANG-1 — zabaan ka chunaav events list par bhi.
 *
 * Client (26 Sep): Production Release screen par Kitchen Sheet ke teen button
 * hain — EN / اردو / Both — magar events list par sirf ek link tha, jo tenant
 * ki default zabaan kholta tha. Bawarchi Urdu parhta hai aur daftar English, to
 * ek hi list se dono nikalne parte hain.
 *
 * Route pehle se `?lang=` maanta tha (CateringDocumentController::language aur
 * CateringBulkDocumentController::language) — sirf screen par chunaav nahi tha.
 * Is liye ye test do baatein poochta hai:
 *
 *   1. teenon pate safhe par mojood hon, poore `?lang=` ke saath
 *   2. aur wo pate waqai kaam karein — har ek se document us zabaan me nikle
 *
 * Doosri baat pehli se zyada ahem hai: `?lang=ur` likh dena aasan hai, us se
 * Urdu ka safha banna alag baat hai.
 */
class CateringKitchenSheetLanguageMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_production_release_lines', 'catering_production_releases',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
        $this->unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Events list ke Actions menu me teenon zabaanein. */
    public function test_the_events_list_offers_all_three_languages(): void
    {
        $release = $this->release();
        $html = $this->eventsListHtml();

        foreach (['en', 'ur', 'both'] as $lang) {
            $this->assertStringContainsString(
                "/catering/documents/kitchen-sheet/{$release->id}?lang={$lang}",
                $html,
                "{$lang} ka pata Actions menu me hona chahiye"
            );
        }
    }

    /** Aur bulk button par bhi — wahan se kai bookings ek saath chhapti hain. */
    public function test_the_bulk_button_offers_all_three_languages(): void
    {
        $this->release();
        $html = $this->eventsListHtml();

        foreach (['en', 'ur', 'both'] as $lang) {
            $this->assertStringContainsString(
                "/catering/documents/bulk/kitchen-sheets?lang={$lang}",
                $html,
                "bulk {$lang} ka pata hona chahiye"
            );
        }

        // Bare button, bina lang ke — tenant ki default zabaan, jaisa pehle tha.
        $this->assertStringContainsString('/catering/documents/bulk/kitchen-sheets"', $html,
            'purana bartaao bhi qayam rahe');
    }

    /**
     * ASAL SAWAL: pate sirf likhe hue nahi, chalte bhi hon. Har zabaan se
     * document render karke dekha ja raha hai.
     */
    public function test_each_language_actually_renders_that_sheet(): void
    {
        $release = $this->release();

        $en = $this->sheet($release->id, 'en');
        $ur = $this->sheet($release->id, 'ur');
        $both = $this->sheet($release->id, 'both');

        // Urdu safha RTL hota hai; English nahi. Ye document ka apna faisla hai,
        // sirf ek lafz ki mojoodgi nahi.
        $this->assertStringContainsString('dir="rtl"', $ur, 'Urdu sheet RTL honi chahiye');
        $this->assertStringContainsString('dir="ltr"', $en, 'English sheet LTR');

        // Urdu naam sirf Urdu aur Both par.
        $this->assertStringContainsString('کھانا 1', $ur);
        $this->assertStringContainsString('کھانا 1', $both);

        // Aur English par khane ka English naam.
        $this->assertStringContainsString('Dish 1', $en);
    }

    /**
     * Ek probe jo "nahi mila" keh sake, use pehle "mila" kehne ke qabil hona
     * chahiye — is liye ye jaanch ke ghalat lang chup chaap qubool na ho jaye.
     */
    public function test_a_nonsense_language_falls_back_instead_of_breaking(): void
    {
        $release = $this->release();

        $html = $this->sheet($release->id, 'klingon');

        $this->assertNotSame('', trim($html), 'safha phir bhi banna chahiye');
        $this->assertStringContainsString('Kitchen Sheet', $html);
    }

    /**
     * KITCHEN-SHEET-FILL-1 (27 Sep) — WAQT ULTA NA CHHAPE.
     *
     * Urdu parche par `10:00 PM` ki jagah `PM 10:00` chhap raha tha. Wajah
     * bidi hai: document `dir="rtl"` hai, aur us me "10:00" ADAD ka tukra hai
     * aur "PM" HARF ka — do alag tukre RTL me ulti tarteeb me lagte hain.
     *
     * Ye pehra isi liye likha ja raha hai ke ye kharabi EK BAAR "theek" keh
     * kar chhori ja chuki hai. 27 Sep ko malik ne "am pm time theek kardo"
     * kaha, aur us waqt sirf TOOT-NE wali kharabi dekhi gayi ("8:00" upar,
     * "PM" neeche) — us par `white-space: nowrap` laga diya gaya. Nowrap
     * tarteeb nahi badalta, is liye ULTA CHHAPNA live par chalta raha aur
     * malik ko dobara tasveer bhejni pari.
     *
     * Ilaj CSS ka nahi, markup ka hai: waqt apne `dir="ltr"` wale span me.
     */
    public function test_the_time_never_prints_back_to_front_on_the_urdu_sheet(): void
    {
        $release = $this->release(['service_time' => '22:00']);

        $html = $this->sheet($release->id, 'ur');

        // Probe pehle khud ko zinda sabit kare: waqt kaghaz par mojood hai.
        // Is ke baghair neeche wali jaanch khali parche par bhi "pass" ho
        // sakti thi.
        $this->assertStringContainsString('10:00 PM', $html,
            'waqt kaghaz par hona chahiye — warna neeche wali jaanch bemani hai');

        // Jaanch us cheez par hai jo AHEM hai — rukh — na ke class par. Pehla
        // version theek `<span dir="ltr">` maangta tha, aur jis din waqt ke
        // span par ek class lagi (dotted border ke liye) wo bina kisi asal
        // kharabi ke red ho gaya. Ek pehra jo saj-dhaj par kaat-ta ho, log
        // usay theek karne ke bajaye kamzor kar dete hain.
        $this->assertMatchesRegularExpression('/<span[^>]*\bdir="ltr"[^>]*>\s*10:00 PM\s*<\/span>/u', $html,
            'waqt dir="ltr" ke andar ho — RTL safhe par bina is ke "PM 10:00" chhapta hai');
    }

    /**
     * KITCHEN-SHEET-RTL-MEASURE-1 (27 Sep) — MIQDAAR bhi ulti chhap rahi thi.
     *
     * Malik ne parche par gol daira laga kar bheja: "KG 56" ki jagah "56 KG"
     * hona chahiye, aur Party/Own par bhi wohi.
     *
     * Ye wohi kharabi hai jo waqt par thi, teesri jagah — dekho
     * `test_the_time_never_prints_back_to_front_on_the_urdu_sheet`. Jahan bhi
     * RTL safhe par ADAD aur HARF saath likhe jate hain, RTL unhe ulta laga
     * deta hai. Ab tak ye teen jagah nikli: waqt, miqdaar, aur maal.
     *
     * Is liye ye test sirf aaj ki kharabi ka nahi — ye us TARAH ka pehra hai.
     * Koi naya khaana jo "adad + unit" chhape, usay bhi `dir="ltr"` chahiye.
     */
    public function test_the_quantity_prints_number_first_not_unit_first(): void
    {
        $release = $this->release();

        $html = $this->sheet($release->id, 'ur');

        // Probe zinda hai? Miqdaar kaghaz par mojood hai.
        $this->assertStringContainsString('27 KG', $html,
            'miqdaar kaghaz par honi chahiye — warna neeche wali jaanch bemani hai');

        $this->assertStringContainsString('<span class="qty" dir="ltr">', $html,
            'miqdaar dir="ltr" ke andar ho — warna RTL safhe par "KG 27" chhapta hai');
    }

    /**
     * Party/Own ka khaana — wohi kharabi, aur yahan zyada bareek: UNWAAN Urdu
     * me rehna chahiye (RTL) magar NAAP LTR. Poori satar ko LTR kar dene se
     * "اپنا" ghalat taraf chala jata.
     *
     * Partial ko seedha render kiya ja raha hai, poore parche ke zariye nahi:
     * ye us EK jagah ka pehra hai jahan kharabi thi, aur fixture ke maal par
     * tika hua test us din chup ho jata jis din fixture badal jaye.
     */
    public function test_the_party_and_own_measure_prints_number_first(): void
    {
        $html = view('tenant.catering.documents.partials.line-materials', [
            'materials' => [[
                'name' => 'Beef', 'qty' => 84, 'unit_code' => 'KG', 'supply' => 'customer',
            ]],
            'compact' => true,
            't' => fn ($en, $ur) => $ur,
        ])->render();

        $this->assertStringContainsString('84 KG', $html, 'naap mojood honi chahiye');
        // Dabbe par `dir="ltr"` LAZMI hai: us ke baghair poora dabba Urdu
        // safhe ki RTL rau me baith kar "43.5 KG OWN" parhne lagta hai —
        // label PEECHE. Ye wohi bidi kharabi hai jo waqt aur miqdaar par
        // pehle pakri ja chuki hai; ab chauthi jagah.
        // Poora tukra EK gehre dabbe me — malik: "[PARTY 18 KG] ese pora
        // background dark". Naap phir bhi apne LTR khaane me, warna Urdu
        // safhe par "KG 84" ulta chhapta hai.
        $this->assertStringContainsString('<span class="sup-tag" dir="ltr">PARTY <span dir="ltr">84 KG</span></span>', $html,
            'label aur naap ek hi gehre dabbe me hon');

        // KITCHEN-SHEET-SUPPLY-TAG-1 (29 Sep): label ab Latin me hai, Urdu
        // parche par bhi — malik ki misaal bhi Latin me thi aur purane
        // software par bhi ye lafz Latin hain. Pehle yahan 'اپنا' tha.
        // ...aur purana Urdu lafz WAPAS na aaye. Ulti jaanch is liye ke
        // seedhi jaanch ("OWN mojood hai?") us din bhi hari rehti jis din
        // dono lafz saath chhapne lag jayen.
        $this->assertStringNotContainsString('پارٹی', $html,
            'compact shakl me label tarjuma nahi hota — purana Urdu lafz wapas aa gaya');
    }

    /**
     * KITCHEN-SHEET-OWN-BARE-1 (2 Oct) — apna maal: sirf naap, koi label nahi.
     *
     * Client: "sirf OWN na likha hua aaye, 7 KG aa jaye." Bawarchi ke liye
     * khabar SIRF ye hai ke cheez us ke store se NAHI aayegi; jo waise bhi
     * store se aati hai us par label lagana shor hai. Prod ke parche par saat
     * me se chhe rows par wohi shor tha.
     *
     * PARTY ka label rehta hai — wahi to asal khabar hai.
     */
    public function test_own_material_prints_only_its_quantity(): void
    {
        $html = view('tenant.catering.documents.partials.line-materials', [
            'materials' => [['name' => 'Beef', 'qty' => 7, 'unit_code' => 'KG', 'supply' => 'ours']],
            'compact' => true,
            't' => fn ($en, $ur) => $ur,
        ])->render();

        $this->assertStringContainsString('7 KG', $html, 'naap aani chahiye');
        $this->assertStringNotContainsString('OWN', $html, 'magar label nahi');
        // Khali label par kaala dabba bhi nahi — wo apne aap me ek nishan ban
        // jata, aur nishan wahan nahi hona chahiye jahan kehne ko kuch nahi.
        $this->assertStringNotContainsString('sup-tag', $html, 'aur dabba bhi nahi');
    }

    /** PARTY ka label aur dabba barqarar — wahi asal khabar hai. */
    public function test_party_material_keeps_its_label_and_box(): void
    {
        $html = view('tenant.catering.documents.partials.line-materials', [
            'materials' => [['name' => 'Beef', 'qty' => 18, 'unit_code' => 'KG', 'supply' => 'customer']],
            'compact' => true,
            't' => fn ($en, $ur) => $ur,
        ])->render();

        $this->assertStringContainsString('<span class="sup-tag" dir="ltr">PARTY <span dir="ltr">18 KG</span></span>', $html);
    }

    /**
     * BATE HUE maal par OWN rehta hai — aur ye istisna jaan-boojh kar hai.
     *
     * Jab kuch party laati hai aur kuch hum, to adad akela bemani ho jata:
     * do satrein ek doosre ke saath parhi jati hain aur bawarchi ko jaanna
     * hota hai ke kaunsi kis ki hai.
     */
    public function test_a_split_still_names_both_sides(): void
    {
        $html = view('tenant.catering.documents.partials.line-materials', [
            'materials' => [[
                'name' => 'Beef', 'qty' => 30, 'unit_code' => 'KG', 'supply' => 'split',
                'customer' => 18, 'ours' => 12,
            ]],
            'compact' => true,
            't' => fn ($en, $ur) => $ur,
        ])->render();

        $this->assertStringContainsString('PARTY', $html);
        $this->assertStringContainsString('OWN', $html, 'bate hue maal par dono taraf ka naam zaroori hai');
    }

    /**
     * Sar ki chhoti satar par CHHAPNE ka waqt — aur wo ulta na chhape.
     *
     * Client: "preview wali line hata do, us ki jagah print time likh do."
     * Pehle wahan parche ke BANNE ka waqt aata tha aur deewar par laga parcha
     * do tareekhein dikhata tha; bawarchi ko sirf EVENT ki tareekh se kaam
     * hai.
     *
     * `dir="ltr"` par jaanch is liye ke Urdu lafz aur Latin tareekh saath
     * likhe hain — bina us ke "Oct 2026 6:55 PM 02 · چھپا" ban jata hai. Ye
     * wohi bidi kharabi hai jo is parche par ab tak PAANCH jagah nikal chuki
     * hai.
     */
    public function test_the_sheet_carries_its_print_time_the_right_way_round(): void
    {
        $release = $this->release();

        $html = $this->sheet($release->id, 'ur');

        $this->assertMatchesRegularExpression('/<span dir="ltr">[^<]*چھپا[^<]*\d{1,2} \w{3} \d{4}/u', $html,
            'chhapne ka waqt apne LTR khaane me ho — warna tareekh ulti chhapti hai');
    }
    // ── helpers ────────────────────────────────────────────────────────────

    private function sheet(int $releaseId, string $lang): string
    {
        $req = Request::create("/catering/documents/kitchen-sheet/{$releaseId}", 'GET', ['lang' => $lang]);

        return app(\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class)
            ->kitchenSheet($req, \App\Models\Tenant\CateringProductionRelease::findOrFail($releaseId))
            ->render();
    }

    private function eventsListHtml(): string
    {
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);

        $user = \App\Models\Tenant\User::on('tenant')->find(
            $this->makeUser(['employee_code' => 'KL'.Str::random(4)])
        );
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        DB::connection('tenant')->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['tenant.catering.documents.kitchen-sheet', 'tenant.catering.documents.bulk-kitchen-sheets'] as $p) {
            $user->givePermissionTo(\Spatie\Permission\Models\Permission::on('tenant')
                ->firstOrCreate(['name' => $p, 'guard_name' => 'tenant']));
        }

        return app(\App\Http\Controllers\Tenant\Catering\CateringEventController::class)
            ->index(Request::create('/catering/events', 'GET'))
            ->render();
    }

    /**
     * @param  array  $eventAttrs  booking par jo cheez RELEASE SE PEHLE honi
     *                             chahiye. Release ek jama hua snapshot hai
     *                             aur model use badalne nahi deta, is liye
     *                             "waqt wala parcha" banane ka sahi tareeqa
     *                             yehi hai — snapshot ko baad me chhedna nahi.
     */
    private function release(array $eventAttrs = []): \App\Models\Tenant\CateringProductionRelease
    {
        $categoryId = $this->makeCategory(['name' => 'RICE', 'sort_order' => 2]);
        $pid = $this->makeProduct($categoryId, ['name' => 'Dish 1', 'sku' => 'KL1', 'unit_id' => $this->unitId]);
        CateringMaterialRate::create([
            'product_id' => $pid, 'rate' => 100, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent(array_merge([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR,ABDUL NAEEM',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(2)->toDateString(),
            'pax' => 551,
        ], $eventAttrs));

        $estimate = $event->currentEstimate;
        $estimates->saveDraftLines($estimate, [[
            'product_id' => $pid, 'item_name' => 'Dish 1', 'item_name_ur' => 'کھانا 1',
            'quantity' => 27, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 500,
        ]]);
        $estimates->markSent($estimate->refresh());
        $estimates->markAccepted($estimate->refresh());
        $estimates->confirmEvent($event->refresh());

        return app(\App\Services\Catering\CateringProductionReleaseService::class)
            ->release(CateringEvent::find($event->id))
            ->load(['lines', 'event']);
    }
}
