<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-PUNCH-OWN-FOLLOWS-QTY-1 — 2 October.
 *
 * Malik ne before/after bhej kar dikhaya:
 *
 *     qty 4  →  Required 6 KG,  Own 6   ✔ "OK — recipe se mel khata hai"
 *     qty 10 →  Required 15 KG, Own 6   ✘ "1 material recipe se mukhtalif hai"
 *
 * Required chal para aur Own wahin jam gaya. Screen 15 KG maang rahi thi aur
 * sirf 6 KG gin rahi thi — aur system rate 1,780 se gir kar 1,258 ho gaya,
 * kyunke jami hui 6 KG material zyada dish par phail gayi.
 *
 * Malik: "jaise add karte hue qty daalte waqt Own bhi barh jata hai, waise hi
 * edit karte waqt hona chahiye."
 *
 * ── KHARABI KI JAR ─────────────────────────────────────────────────────────
 *
 * Edit ka raasta har material par `ownTouched: true` laga deta tha, jis ka
 * matlab hai "operator ne khud likha hai, haath na lagao". Niyat durust thi —
 * us line par kisi ne waqai nuskhe se zyada likha tha (nuskha 4 KG, likha 6
 * KG) — magar wo nishan PICHHLI baar ke likhe par laga hua tha, is baar ke
 * nahi. Natija: edit me Own kabhi hilta hi nahi tha.
 *
 * Ab nishan ki jagah NISBAT yaad rakhi jati hai (own ÷ qty = 1.5) aur adad
 * qty ke saath chalta hai. `ownTouched` ka matlab wohi ho gaya jo naye row par
 * hai: "is baar operator ne khud likha hai".
 *
 * ── YE TEST ASAL CODE CHALATA HAI ──────────────────────────────────────────
 *
 * Blade me CSS/markup dhoondna yahan kaafi nahi hota: sawal hisaab ka hai, aur
 * hisaab JS me hai. Is liye `punchOwnFor` aur `punchCustFor` ko Blade se waise
 * ka waisa NIKAL kar Node me chalaya jata hai. Agar kal koi un ka hisaab
 * badle, ye test us par bolega — selector parhne wala test khamosh rehta.
 */
class CateringPunchOwnFollowsQtyMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $blade;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->blade = file_get_contents(
            resource_path('views/tenant/catering/events/show.blade.php')
        );
    }

    /** Blade se function ka poora jism, jahan se shuru wahin tak. */
    private function jsFunction(string $name): string
    {
        $start = strpos($this->blade, 'function '.$name.'(');
        $this->assertNotFalse($start, "{$name}() Blade me milna chahiye");

        $depth = 0;
        $open = strpos($this->blade, '{', $start);
        for ($i = $open; $i < strlen($this->blade); $i++) {
            if ($this->blade[$i] === '{') {
                $depth++;
            } elseif ($this->blade[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->blade, $start, $i - $start + 1);
                }
            }
        }

        $this->fail("{$name}() ka band kosha nahi mila");
    }

    /**
     * Asal do function Node me chala kar jawab lo.
     *
     * @param  array<string, mixed>  $mat
     */
    private function evalJs(array $mat, float $qty): array
    {
        $node = 'D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe';
        if (! is_file($node)) {
            // Loud, kyunke khamosh skip hi wo soorat hai jis me ye pehra hota
            // hua lagta hai aur hota nahi. Baqi do test phir bhi chalte hain.
            $this->markTestSkipped('node nahi mila: '.$node);
        }

        $script = $this->jsFunction('punchOwnFor')."\n".$this->jsFunction('punchCustFor')."\n"
            .'const m = '.json_encode($mat).', q = '.$qty.";\n"
            .'process.stdout.write(JSON.stringify({own: punchOwnFor(m, q), cust: punchCustFor(m, q)}));';

        $file = tempnam(sys_get_temp_dir(), 'punch').'.js';
        file_put_contents($file, $script);
        $out = shell_exec('"'.$node.'" '.escapeshellarg($file).' 2>&1');
        @unlink($file);

        $decoded = json_decode(trim((string) $out), true);
        $this->assertIsArray($decoded, 'node ka jawab JSON hona chahiye, mila: '.$out);

        return $decoded;
    }

    /** Wo material jo edit kholte waqt banta hai: qty 4, own 6, party 0. */
    private function editedMaterial(float $own = 6, float $cust = 0, float $qty = 4): array
    {
        $total = $own + $cust;

        return [
            'ratio' => $qty > 0 ? $total / $qty : 0,
            'own' => $own, 'ownTouched' => false, 'ownRatio' => $qty > 0 ? $own / $qty : 0,
            'cust' => $cust, 'custTouched' => false, 'custRatio' => $qty > 0 ? $cust / $qty : 0,
        ];
    }

    /**
     * MALIK KA APNA MISAAL. Yehi wo adad hain jo unhone screenshot me bheje.
     */
    public function test_own_follows_the_quantity_on_an_edited_row(): void
    {
        $m = $this->editedMaterial(own: 6, qty: 4);

        // Kholte hi kuch na badle — jo save tha wohi dikhe.
        $this->assertSame(6.0, (float) $this->evalJs($m, 4)['own'],
            'edit kholte waqt Own wohi rehna chahiye jo save tha');

        // Aur ab wo chale.
        $this->assertSame(15.0, (float) $this->evalJs($m, 10)['own'],
            'qty 4→10 par Own 6→15 hona chahiye (nisbat 1.5) — yehi malik ne maanga');
        $this->assertSame(3.0, (float) $this->evalJs($m, 2)['own'],
            'qty kam karne par bhi — nisbat dono simt chalti hai');
    }

    /**
     * Own aur Required hamesha mel khayen, warna screen ek rakam maangti hai
     * aur doosri ginti hai. Yehi wo khala tha jo screenshot me nazar aaya.
     */
    public function test_own_plus_party_always_adds_up_to_required(): void
    {
        foreach ([[6.0, 0.0], [4.0, 2.0], [10.0, 5.0]] as [$own, $cust]) {
            $m = $this->editedMaterial(own: $own, cust: $cust, qty: 4);

            foreach ([2.0, 4.0, 10.0, 17.5] as $qty) {
                $r = $this->evalJs($m, $qty);
                $required = $qty * $m['ratio'];
                $this->assertEqualsWithDelta($required, (float) $r['own'] + (float) $r['cust'], 0.0005,
                    "own+party ko Required ke barabar hona chahiye (own={$own} party={$cust} qty={$qty})");
            }
        }
    }

    /**
     * Magar jaise hi operator KHUD likhe, Own rukk jaye — bilkul naye row ki
     * tarah. Warna hum ek masla doosre se badal dete: pehle adad kabhi hilta
     * nahi tha, ab kabhi theharta nahi.
     */
    public function test_a_hand_typed_own_stops_following(): void
    {
        $m = $this->editedMaterial(own: 6, qty: 4);
        $m['ownTouched'] = true;
        $m['own'] = 9;

        $this->assertSame(9.0, (float) $this->evalJs($m, 10)['own'],
            'haath se likha hua adad qty badalne par bhi wohi rehna chahiye');

        $p = $this->editedMaterial(own: 4, cust: 2, qty: 4);
        $p['custTouched'] = true;
        $p['cust'] = 3;
        $this->assertSame(3.0, (float) $this->evalJs($p, 10)['cust'],
            'Party par bhi wohi qaida');
    }

    /** Naya row jaisa tha waisa hi rahe — wahan nuskhe ki nisbat chalti hai. */
    public function test_a_fresh_row_still_follows_the_recipe(): void
    {
        // Naye row par `ownRatio` hota hi nahi; helper ko `ratio` par girna hai.
        $fresh = ['ratio' => 1.5, 'own' => null, 'ownTouched' => false, 'cust' => 0, 'custTouched' => false];

        $this->assertSame(15.0, (float) $this->evalJs($fresh, 10)['own'],
            'naye row par Own = qty × nuskhe ki nisbat');
        $this->assertSame(0.0, (float) $this->evalJs($fresh, 10)['cust'],
            'aur Party sifar se shuru ho');
    }

    /**
     * Ye pehra source par hai aur hamesha chalta hai — Node ho ya na ho.
     *
     * Do cheezon par: (1) koi edit raasta dobara `ownTouched: true` na lagaye,
     * aur (2) Own/Party ka faisla har jagah EK hi function se aaye. Pehle wo
     * faisla aath jagah alag alag likha tha, aur kharabi usi ka nateeja thi.
     */
    public function test_the_rule_is_written_in_exactly_one_place(): void
    {
        $code = preg_replace(['/\{\{--.*?--\}\}/s', '/\/\*.*?\*\//s', '/^\s*\/\/[^\n]*$/m'], '', $this->blade);

        $this->assertStringNotContainsString('ownTouched: true', $code,
            'koi raasta save shuda adad ko "operator ne abhi likha hai" na kahe — yehi kharabi thi');
        $this->assertDoesNotMatchRegularExpression('/ownTouched\s*\?\s*m\.own\s*:/', $code,
            'Own ka faisla sirf punchOwnFor() me ho, jagah jagah dobara na likha jaye');
        $this->assertDoesNotMatchRegularExpression('/Math\.max\(0,\s*m\.cust\s*\|\|\s*0\)/', $code,
            'Party ka faisla sirf punchCustFor() me ho');

        // Aur dono helper waqai istemaal ho rahe hon, sirf maujood na hon.
        $this->assertGreaterThanOrEqual(7, substr_count($code, 'punchOwnFor('),
            'punchOwnFor() har us jagah lage jahan pehle hisaab likha tha');
        $this->assertGreaterThanOrEqual(7, substr_count($code, 'punchCustFor('),
            'punchCustFor() bhi');
    }
}
