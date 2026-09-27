<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * PUNCH-GRID-LEGIBILITY-1 — 27 September.
 *
 * Client: "qty box show hi nahi ho rahi", aur saath me grid ko bold, rows par
 * professional rang, aur chhoti screen par sab andar.
 *
 * ASAL KHARABI EK LAFZ THI. Numeric columns par `style="width:95px"` tha. HTML
 * me `width` browser ke liye TAJWEEZ hai, farsh nahi — jagah kam parey to
 * browser us se neeche chala jata hai. 13 columns ki grid par Qty itni sikuṛ
 * gayi ke sirf number-input ke spinner ke teer bache, aur likha hua adad kahin
 * nazar hi na aaye. `min-width` farsh hai: ab table apne wrapper me scroll hoti
 * hai aur koi khana kuchla nahi jata.
 *
 * AUR EK PEHRA JO MERI APNI GHALTI SE PEDA HUA. Pehle pass me maine do aise
 * selector likh diye thay jo markup me hain hi nahi — `tr.line-row` aur
 * `#lines-table-wrap`. Aisi CSS kuch torti nahi, bas kuch karti bhi nahi: safha
 * theek dikhta hai, kaam adhoora reh jata hai, aur koi test us par nahi
 * bolta. Is liye neeche har us selector ka nishana ginna jata hai.
 */
class CateringPunchGridLegibilityMySqlTest extends MySqlTenantTestCase
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

    /** Jo khana operator me type karta hai, wo kuchla na ja sake. */
    public function test_every_numeric_column_has_a_floor_not_a_suggestion(): void
    {
        // Punch grid.
        foreach (['Qty', 'System Rate', 'Customer Rate', 'Line Amount', 'Required Qty', 'Own', 'Party'] as $col) {
            $this->assertDoesNotMatchRegularExpression(
                '/<th[^>]*style="width:\d+px;"[^>]*>'.preg_quote($col, '/').'</',
                $this->blade,
                "'{$col}' par `width` hai — wo tajweez hai, farsh nahi, aur tang screen par khana sikuṛ jata hai"
            );
        }

        // Probe ko pehle khud ko zinda sabit karna chahiye: agar regex hi kabhi
        // match na karta ho to upar wale saare assert bemani hain.
        $this->assertMatchesRegularExpression(
            '/<th[^>]*style="width:\d+px;"/',
            $this->blade,
            'kuch columns par `width` ab bhi hai (36px wala action column) — yani jaanch andhi nahi'
        );
    }

    /** Aur Qty ka khana safhe par mojood ho. */
    public function test_the_punch_row_still_has_its_quantity_box(): void
    {
        $this->assertStringContainsString('id="punch-qty"', $this->blade);
        $this->assertMatchesRegularExpression(
            '/<th[^>]*style="min-width:\d+px;"[^>]*>Qty</',
            $this->blade,
            'Qty ka column farsh par ho'
        );
    }

    /**
     * HAR NAYE SELECTOR KA NISHANA MOJOOD HO.
     *
     * Ye test us ghalti ke liye hai jo pehle pass me hui: CSS likh di aur us ka
     * nishana markup me tha hi nahi. Ginti do se kam ka matlab hai ke selector
     * sirf apni CSS line me hai — kisi element par nahi.
     */
    public function test_no_stylesheet_rule_points_at_markup_that_does_not_exist(): void
    {
        foreach ([
            'lines-table-wrap' => 'lines table ka wrapper',
            'cost-details-row' => 'khuli tafseel wali qatar',
            'lines-body' => 'lines table ka tbody',
            'punch-qty' => 'punch ka qty khana',
            'punch-bar' => 'punch ka block',
            'punch-mat-col' => 'material ke columns',
        ] as $selector => $what) {
            $this->assertGreaterThan(
                1,
                substr_count($this->blade, $selector),
                "CSS '{$selector}' ({$what}) ko nishana banati hai magar wo markup me nahi — murda rule"
            );
        }
    }

    /** Aur jo maanga gaya tha: bold, rang, chhoti screen. */
    public function test_the_grid_is_bolder_coloured_and_survives_a_small_screen(): void
    {
        $this->assertStringContainsString('font-variant-numeric: tabular-nums', $this->blade,
            'qatar me adad seedhe rahein — warna 1 aur 7 alag chaurai lete hain');
        $this->assertStringContainsString('#lines-body > tr:not(.cost-details-row):hover', $this->blade,
            'qatar par hover');
        $this->assertStringContainsString('@media (max-width: 991.98px)', $this->blade,
            'chhoti screen ke liye apna hissa');
        $this->assertStringContainsString('table-responsive', $this->blade,
            'aur table apne wrapper me scroll kare');
    }
}
