<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Ajax\CustomerLookupController;
use App\Models\Tenant\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CUSTOMER-SEARCH-PUNCT-1 — graahak ka naam dhoondna nuqte par na atke.
 *
 * Client (1 Oct): "mr ( dot ) ke baad adnan search nahi ho raha."
 *
 * Wajah milan ka tareeqa tha: `name LIKE '%MR. adnan%'` — harf-ba-harf. Yani
 * operator ko wohi nuqta, wohi comma aur wohi space likhna parta tha jo record
 * me para hai.
 *
 * AUR RECORD ME WO EK JAISA HAI HI NAHI. Kashif Kitchen ke 4,880 graahak me
 * "MR" chaar shaklon me likha hua hai — MR, (2,836), MR. (1,117), MR (582),
 * aur baqi 345. Prod par naapa gaya:
 *
 *     "MR. adnan" -> 10 natije        "mr adnan" -> 2 natije
 *     "MR.adnan"  ->  7 natije        "adnan"    -> 45 natije
 *
 * Yani 45 me se 35 Adnan gayab. Operator ko lagta ke graahak hai hi nahi, aur
 * wo naya bana deta — ek hi shaks ke kai record, har ek ka apna hisaab.
 *
 * Neeche ke naam PROD SE liye gaye hain, farzi nahi.
 */
class CustomerSearchPunctuationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['customers']);

        // Prod par wohi ek naam, chhe shaklon me.
        foreach ([
            ['MR. ADNAN', '03362900200'],
            ['MR,ADNAN', '03348282077'],
            ['MR ADNAN', '03368293795'],
            ['MR.ADNAN', '03432474740'],
            ['MR,ADNAN BHAI', '03363105240'],
            ['MR. ADNAN SHARIF', '03346221289'],
            // Aur ek jo milna NAHI chahiye — warna test har haal me hara rehta.
            ['MR. SHEHZAD', '03363503101'],
        ] as [$name, $phone]) {
            Customer::on('tenant')->create([
                'code' => 'C-'.$phone, 'name' => $name, 'phone' => $phone, 'status' => 'active',
            ]);
        }
    }

    /** @return array<int, array{id: int, name: string}> */
    private function search(string $q): array
    {
        $json = app(CustomerLookupController::class)(
            Request::create('/ajax/customers', 'GET', ['q' => $q])
        )->getData(true);

        return $json['customers'];
    }

    /**
     * SAB SE AHEM: jo bhi shakl likhi jaye, natija WOHI aaye.
     *
     * Ye test shaklon ko AAPAS ME milata hai — kisi ek shakl ke liye koi adad
     * nahi thoosta. Is ka faida ye hai ke ye us din bhi sach rehta hai jis din
     * fixture me ek aur Adnan jur jaye: sawal "kitne mile" nahi, "sab ek jaisa
     * jawab dete hain ya nahi" hai.
     */
    public function test_every_spelling_of_the_same_name_finds_the_same_people(): void
    {
        $base = null;

        foreach (['MR. adnan', 'MR.adnan', 'mr adnan', 'MR,adnan', 'MR , Adnan'] as $q) {
            $names = array_column($this->search($q), 'name');
            sort($names);

            if ($base === null) {
                $base = $names;
                $this->assertNotEmpty($base,
                    'pehli shakl par kuch to milna chahiye — warna sab khali jawabon ka milan bemani hai');

                continue;
            }

            $this->assertSame($base, $names,
                "[{$q}] ka jawab pehli shakl se alag hai — milan abhi bhi nishan par atak raha hai");
        }
    }

    /** Chhe ke chhe Adnan milen — chahe kuch bhi likha jaye. */
    public function test_all_six_spellings_are_reachable_from_any_of_them(): void
    {
        foreach (['MR. adnan', 'MR.adnan', 'mr adnan', 'MR,adnan', 'mradnan'] as $q) {
            $names = array_column($this->search($q), 'name');

            $this->assertCount(6, $names, "[{$q}] par chhe ke chhe Adnan milne chahiyen");
            $this->assertNotContains('MR. SHEHZAD', $names,
                "[{$q}] par wo graahak nahi aana chahiye jo is naam ka hai hi nahi");
        }
    }

    /**
     * Probe khud ko zinda sabit kare: agar milan dobara harf-ba-harf ho jaye to
     * ye adad girte hain. Prod par yehi tha — 10, 7 aur 2.
     */
    public function test_a_literal_match_would_have_found_fewer(): void
    {
        $literal = fn (string $q) => Customer::on('tenant')->where('status', 'active')
            ->where(fn ($i) => $i->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
            ->count();

        $this->assertLessThan(6, $literal('MR. adnan'),
            'purana tareeqa kam natije deta tha — warna is test ka koi matlab nahi');
        $this->assertSame(6, count($this->search('MR. adnan')),
            'aur naya tareeqa poore chhe deta hai');
    }

    /** Phone bhi adadon par mile — operator dash lagaye ya na lagaye. */
    public function test_a_phone_is_found_with_or_without_its_dashes(): void
    {
        foreach (['03362900200', '0336-290 0200', '3362900200'] as $q) {
            $names = array_column($this->search($q), 'name');
            $this->assertContains('MR. ADNAN', $names, "[{$q}] par wo graahak milna chahiye");
        }
    }

    /**
     * URDU NAAM GUM NA HO.
     *
     * Ye jaanch us ghalti par kaat-ti hai jo yahan bohot aasani se ho sakti
     * thi: `[^a-z0-9]` se nishan hatana. Wo Urdu naam ko POORA mita deta, aur
     * phir har Urdu naam khali string ban kar ek doosre se match karne lagta —
     * yani kisi bhi Urdu naam par saare Urdu graahak nikal aate.
     *
     * Aaj aise naam sifar hain; ye kharabi us din khamoshi se shuru hoti jis
     * din pehla Urdu naam darj hota.
     */
    public function test_an_urdu_name_is_not_flattened_away(): void
    {
        Customer::on('tenant')->create(['code' => 'C-UR1', 'name' => 'محمد عدنان', 'phone' => '03001112233', 'status' => 'active']);
        Customer::on('tenant')->create(['code' => 'C-UR2', 'name' => 'عبد الرحمان', 'phone' => '03004445566', 'status' => 'active']);

        $names = array_column($this->search('محمد عدنان'), 'name');

        $this->assertContains('محمد عدنان', $names, 'Urdu naam milna chahiye');
        $this->assertNotContains('عبد الرحمان', $names,
            'aur doosra Urdu naam NAHI — warna matlab ye ke dono khali string ban gaye');
    }
}
