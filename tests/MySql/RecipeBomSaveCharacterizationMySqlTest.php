<?php

namespace Tests\MySql;

use App\Models\Tenant\ManufacturingBom;
use App\Models\Tenant\Recipe;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * W3/W4 S3a (24 Oct) — today's recipe and BOM saves, pinned BEFORE their code moves into writers the product workspace
 * can share. Nothing here is a wish: each assertion is how the existing screens behave now, quirks included, so a move
 * that changes any of it shows up red.
 *  - a recipe is created with its lines, is active unless told otherwise, and switches the product's older recipe off;
 *    an update REPLACES every line (new ids) and an absent "active" keeps it active; a recipe needs a line;
 *  - a BOM gets a number when none is typed, switches the product's older active BOM off, replaces its lines on update,
 *    refuses the finished product as its own part and a part twice, and is archived (never deleted) by Delete.
 */
class RecipeBomSaveCharacterizationMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 'rbtest';
    private const STORE_SUFFIX = '_rb';
    private const STORE_FEATURES = ['product_screen_v2' => true];
    private const STORE_PLAN = 'enterprise';
    private const STORE_BUSINESS = 'restaurant';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
    }

    public function test_a_recipe_is_created_with_its_lines_and_replaced_on_update_as_today(): void
    {
        $dish = $this->product('RB Biryani', $this->piece()->id, ['default_selling_price' => 650]);
        $rice = $this->product('RB Rice', $this->kg()->id, ['product_kind' => 'raw_material', 'is_sellable' => false]);
        $oil = $this->product('RB Oil', $this->litre()->id, ['product_kind' => 'raw_material', 'is_sellable' => false]);
        $older = Recipe::create(['product_id' => $dish->id, 'name' => 'RB older recipe', 'yield_quantity' => 1, 'is_active' => true]);

        $this->owner()->post($this->url('/recipes'), ['product_id' => $dish->id, 'name' => 'RB Biryani recipe', 'yield_quantity' => 10,
            'yield_unit_id' => $this->kg()->id, 'ingredients' => [
                ['product_id' => $rice->id, 'quantity' => 1.5, 'unit_id' => $this->kg()->id, 'applicable_order_types' => ['all']],
                ['product_id' => $oil->id, 'quantity' => 0.2, 'unit_id' => $this->litre()->id],
            ]])->assertRedirect()->assertSessionHasNoErrors();
        $recipe = Recipe::where('name', 'RB Biryani recipe')->firstOrFail();
        $this->assertTrue((bool) $recipe->is_active, 'active unless told otherwise');
        $lines = $recipe->ingredients()->orderBy('sort_order')->get();
        $this->assertSame([$rice->id, $oil->id], $lines->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('food_cost', $lines[0]->line_section, 'the default section');
        $this->assertNull($lines[0]->applicable_order_types, '"all" is stored as null');
        $this->assertSame([0, 1], $lines->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
        $this->assertFalse((bool) $older->fresh()->is_active, 'the older recipe of the dish is switched off');

        $firstIds = $lines->pluck('id')->all();
        $this->owner()->put($this->url('/recipes/' . $recipe->id), ['product_id' => $dish->id, 'name' => 'RB Biryani recipe', 'yield_quantity' => 10,
            'ingredients' => [['product_id' => $oil->id, 'quantity' => 0.3]]])->assertRedirect()->assertSessionHasNoErrors();
        $after = $recipe->fresh()->ingredients()->get();
        $this->assertCount(1, $after, 'an update replaces every line');
        $this->assertEmpty(array_intersect($firstIds, $after->pluck('id')->all()), 'today the lines get new ids');
        $this->assertEqualsWithDelta(0.3, (float) $after[0]->quantity, 0.0001);
        $this->assertTrue((bool) $recipe->fresh()->is_active, 'today an absent "active" keeps it active (quirk, pinned as is)');

        $count = Recipe::count();
        $this->owner()->post($this->url('/recipes'), ['product_id' => $dish->id, 'name' => 'RB empty', 'yield_quantity' => 1, 'ingredients' => []])
            ->assertSessionHasErrors('ingredients');
        $this->assertSame($count, Recipe::count(), 'a recipe needs a line; nothing written');
    }

    public function test_a_bom_is_numbered_replaced_refused_and_archived_as_today(): void
    {
        $chair = $this->product('RB Chair', $this->piece()->id, ['product_kind' => 'finished_good', 'can_be_bom_output' => true]);
        $pipe = $this->product('RB Pipe', $this->piece()->id, ['product_kind' => 'raw_material', 'is_sellable' => false, 'can_be_bom_component' => true]);
        $screw = $this->product('RB Screw', $this->piece()->id, ['product_kind' => 'raw_material', 'is_sellable' => false, 'can_be_bom_component' => true]);
        $older = ManufacturingBom::create(['bom_no' => 'RB-OLD-BOM', 'finished_product_id' => $chair->id, 'version' => '1', 'output_quantity' => 1, 'status' => 'active']);

        // The screen posts every line with its unit box (empty = the item's own unit); a line without the key 500s today.
        $post = fn (array $lines, array $extra = []) => $extra + ['bom_no' => '', 'finished_product_id' => $chair->id, 'version' => '2', 'output_quantity' => 1,
            'status' => 'active', 'lines' => $lines];
        $this->owner()->post($this->url('/manufacturing/bom'), $post([['component_product_id' => $pipe->id, 'unit_id' => '', 'quantity' => 2], ['component_product_id' => $screw->id, 'unit_id' => '', 'quantity' => 8]]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $bom = ManufacturingBom::where('finished_product_id', $chair->id)->where('version', '2')->firstOrFail();
        $this->assertNotSame('', (string) $bom->bom_no, 'a number is made when none is typed');
        $this->assertSame(2, $bom->lines()->count());
        $this->assertSame('inactive', $older->fresh()->status, 'the older active BOM is switched off');

        $firstIds = $bom->lines()->pluck('id')->all();
        $this->owner()->put($this->url('/manufacturing/bom/' . $bom->id), $post([['component_product_id' => $pipe->id, 'unit_id' => '', 'quantity' => 3]], ['bom_no' => $bom->bom_no]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $bom->lines()->count(), 'an update replaces every line');
        $this->assertEmpty(array_intersect($firstIds, $bom->lines()->pluck('id')->all()), 'today the lines get new ids');

        $this->owner()->put($this->url('/manufacturing/bom/' . $bom->id), $post([['component_product_id' => $chair->id, 'unit_id' => '', 'quantity' => 1]], ['bom_no' => $bom->bom_no]))
            ->assertSessionHasErrors();
        $this->assertStringContainsString('A component cannot be the same product as the finished product.', collect(session('errors')?->all())->implode(' '));
        $this->owner()->put($this->url('/manufacturing/bom/' . $bom->id), $post([['component_product_id' => $pipe->id, 'unit_id' => '', 'quantity' => 1], ['component_product_id' => $pipe->id, 'unit_id' => '', 'quantity' => 2]], ['bom_no' => $bom->bom_no]))
            ->assertSessionHasErrors();
        $this->assertStringContainsString('Duplicate component products are not allowed. Combine them into one line.', collect(session('errors')?->all())->implode(' '));
        $this->assertSame(1, $bom->lines()->count(), 'refused: the lines are as they were');

        $this->owner()->delete($this->url('/manufacturing/bom/' . $bom->id))->assertRedirect();
        $this->assertSame('archived', $bom->fresh()->status, 'Delete archives; the BOM stays');
        $this->assertSame(1, DB::connection('tenant')->table('manufacturing_boms')->where('id', $bom->id)->count());
    }
}
