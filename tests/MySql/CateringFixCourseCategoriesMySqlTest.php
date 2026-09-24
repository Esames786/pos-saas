<?php

namespace Tests\MySql;

use App\Console\Commands\CateringFixCourseCategoriesCommand as Fix;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-COURSE-ORDER-1 (data) — ghalat course wale item theek karne wali
 * command.
 *
 * Jaancha ye ja raha hai ke command KIS CHEEZ KO HAATH NAHI LAGATI. "Paratha
 * BBQ se NAN-TANDOOR chala gaya" aasan hissa hai; mushkil hissa ye hai ke jis
 * din koi item wahan na mile jahan tawaqqo thi, command andaza na lagaye — warna
 * ye wohi ghalti khud kar degi jo theek karne aayi hai.
 */
class CateringFixCourseCategoriesMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $bbq;

    private int $nan;

    private int $sideLines;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant(['products', 'categories', 'branches']);

        $this->bbq = $this->makeCategory(['name' => 'BBQ', 'sort_order' => 4]);
        $this->nan = $this->makeCategory(['name' => 'NAN-TANDOOR', 'sort_order' => 8]);
        $this->sideLines = $this->makeCategory(['name' => 'SIDE LINES', 'sort_order' => 6]);
    }

    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection('tenant');
    }

    /** Jo BBQ me galat baitha hai, wo roti ke saath jata hai. */
    public function test_it_plans_to_move_paratha_out_of_bbq(): void
    {
        $id = $this->makeProduct($this->bbq, ['name' => 'Paratha']);

        $plan = Fix::plan($this->db());

        $names = array_column($plan['moves'], 1);
        $this->assertContains('Paratha', $names);

        $move = collect($plan['moves'])->firstWhere(1, 'Paratha');
        $this->assertSame($id, $move[0]);
        $this->assertSame('NAN-TANDOOR', $move[3]);
        $this->assertSame($this->bbq, $plan['rollback'][$id], 'rollback purani category yaad rakhe');
    }

    /**
     * SAB SE AHEM. Item wahan nahi jahan tawaqqo thi → chhod do.
     *
     * Ye us surat ke liye hai jab koi pehle haath se theek kar chuka ho, ya
     * yehi naam kisi bilkul aur cheez par lag gaya ho. Aise me "theek" karna
     * asal me bigadna hai.
     */
    public function test_it_refuses_to_guess_when_the_item_is_somewhere_unexpected(): void
    {
        $desserts = $this->makeCategory(['name' => 'DESSERTS', 'sort_order' => 7]);
        $this->makeProduct($desserts, ['name' => 'Paratha']);

        $plan = Fix::plan($this->db());

        $this->assertNotContains('Paratha', array_column($plan['moves'], 1),
            'tawaqqo BBQ thi, mila DESSERTS — isay chhodna chahiye');
        $this->assertContains('Paratha', array_column($plan['skips'], 0));
    }

    /** Pehle se sahi jagah ho to dobara kuch nahi karti. */
    public function test_an_item_already_in_the_right_place_is_left_alone(): void
    {
        $this->makeProduct($this->nan, ['name' => 'Paratha']);

        $plan = Fix::plan($this->db());

        $this->assertNotContains('Paratha', array_column($plan['moves'], 1));
        $this->assertContains('Paratha', array_column($plan['skips'], 0));
    }

    /** Jo product hai hi nahi, us par command girti nahi. */
    public function test_a_missing_product_is_skipped_not_fatal(): void
    {
        $plan = Fix::plan($this->db());

        $this->assertSame([], $plan['moves'], 'koi product hi nahi — kuch badalne ko nahi');
        $this->assertNotEmpty($plan['skips']);
    }

    /** Target category is tenant par na ho to bhi chhod deti hai. */
    public function test_it_skips_when_the_target_category_does_not_exist(): void
    {
        $this->db()->table('categories')->where('id', $this->nan)->delete();
        $this->makeProduct($this->bbq, ['name' => 'Paratha']);

        $plan = Fix::plan($this->db());

        $this->assertNotContains('Paratha', array_column($plan['moves'], 1));
    }

    /**
     * Sirf un paanch naamon par chalti hai. Ek product jo in me nahi, ghalat
     * category me ho tab bhi chhua nahi jata — ye command ek aam "categories
     * theek karo" script nahi hai.
     */
    public function test_it_touches_nothing_outside_its_five_known_names(): void
    {
        $this->makeProduct($this->bbq, ['name' => 'Chicken Karahi']);
        $this->makeProduct($this->bbq, ['name' => 'Nan Milky']);

        $plan = Fix::plan($this->db());

        $this->assertSame([], $plan['moves']);
    }

    /** Aaloo Tarkari salan ke saath jata hai, BBQ se. */
    public function test_aaloo_tarkari_moves_to_side_lines(): void
    {
        $this->makeProduct($this->bbq, ['name' => 'Aaloo Tarkari']);

        $move = collect(Fix::plan($this->db())['moves'])->firstWhere(1, 'Aaloo Tarkari');

        $this->assertNotNull($move);
        $this->assertSame('SIDE LINES', $move[3]);
    }

    /** Allow-list se bahar tenant par command inkaar karti hai. */
    public function test_the_command_refuses_a_tenant_outside_the_allow_list(): void
    {
        $this->artisan('catering:fix-course-categories', [
            'tenant_code' => 'definitely-not-a-real-tenant', '--yes' => true,
        ])->assertExitCode(1);
    }
}
