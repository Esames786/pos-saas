<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\ManufacturingBom;
use App\Models\Tenant\Product;
use App\Models\Tenant\Recipe;
use App\Models\Tenant\User;
use App\Services\Catering\CateringCostBlockWriter;
use App\Services\Catering\CateringLocalizationService;
use App\Services\Catering\CateringProfileWriter;
use App\Services\Kitchen\RecipeWriter;
use App\Services\Manufacturing\BomWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\IsolatedPharmacyStore;
use Tests\MySql\Support\ReadsFormOnPage;

/**
 * W3/W4 S3c (24 Oct) — the recipe, bill of materials and event costing edited INSIDE the product form, through the
 * real routes, as the owner (and as a user without the recipe permission). Each test opens the real edit page and
 * posts what it shows, as a browser would, then changes only what a user would change.
 *  - an untouched section writes nothing: the same line / block ids, the same updated_at;
 *  - a changed one is saved through the screen's own writer, and what the tab does not show stays as saved;
 *  - a refusal anywhere rolls the whole save back, the product too;
 *  - a section changed on its own screen after the page opened is refused, not overwritten;
 *  - the server decides: no permission or no module → no tab, and a crafted post is ignored.
 */
class ProductWorkspaceMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;
    use ReadsFormOnPage;

    private const STORE_CODE = 'wstest';
    private const STORE_SUFFIX = '_ws';
    private const STORE_FEATURES = ['product_screen_v2' => true];
    private const STORE_PLAN = 'enterprise';
    private const STORE_BUSINESS = 'restaurant';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
        $this->plan(true, true);
    }

    // ── Recipe ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_recipe_edited_in_the_product_form_keeps_what_the_tab_does_not_show(): void
    {
        [$dish, $rice, $oil, $salt] = $this->dishWithRecipe('WS Biryani');
        $recipe = Recipe::where('product_id', $dish->id)->firstOrFail();
        // As a recipe saved elsewhere can have them: sort orders with gaps (5, 15). An untouched tab must not "tidy" them.
        // Only the tab's own untouched check stops that write — the writer alone would renumber the lines 0, 1 (batch
        // close-out: without this, removing that check left the test green).
        DB::connection('tenant')->table('recipe_ingredients')->where('recipe_id', $recipe->id)->update(['sort_order' => DB::raw('sort_order * 10 + 5')]);
        $before = $this->recipeRows($recipe);

        $html = $this->page('/products/' . $dish->id . '/edit')->assertOk()->assertSee('id="tp-recipe"', false)->getContent();
        $form = $this->formOnPage($html, '/products/' . $dish->id);
        $this->assertSame((string) $rice->id, $form['recipe']['lines'][0]['product_id'] ?? null, 'the saved lines are on the tab');

        $this->save($dish, $form);
        $this->assertSame($before, $this->recipeRows($recipe), 'saved unchanged: the same lines, ids and times — nothing written');

        $form['recipe']['lines'][1]['quantity'] = '0.25';
        $form['recipe']['lines']['n1'] = ['id' => '', 'product_id' => (string) $salt->id, 'quantity' => '0.01', 'unit_id' => '', 'line_section' => 'food_cost'];
        $this->save($dish, $form);

        $recipe->refresh();
        $lines = $recipe->ingredients()->orderBy('sort_order')->get();
        $this->assertSame([$rice->id, $oil->id, $salt->id], $lines->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        $this->assertEqualsWithDelta(0.25, (float) $lines[1]->quantity, 0.0001);
        $this->assertEqualsWithDelta(300, (float) $lines[0]->cost_override, 0.0001, 'a line\'s cost override is kept');
        $this->assertSame(['dine_in'], $lines[0]->applicable_order_types, 'a line\'s order types are kept');
        $this->assertSame(['D-7', 'R-12', 2, 5.0], [$recipe->doc_no, $recipe->recipe_no, (int) $recipe->revision_no, (float) $recipe->overhead_percent], 'the report numbers are kept');
        $this->assertSame('WS Biryani recipe', $recipe->name);
        $this->assertSame(1, Recipe::where('product_id', $dish->id)->count(), 'the same recipe, not a new one');
        $this->assertEquals(650, $dish->fresh()->default_selling_price, 'the product kept its price');
    }

    public function test_a_new_menu_item_is_saved_with_its_recipe_in_one_go(): void
    {
        $rice = $this->material('WS New Rice', $this->kg()->id);
        $form = $this->formOnPage($this->page('/products/create?card=menu_item')->assertOk()->getContent(), '/products', false);
        $form = array_merge($form, ['name' => 'WS Pulao', 'default_selling_price' => '400', '_recipe' => '1']);
        $form['recipe']['lines']['n1'] = ['id' => '', 'product_id' => (string) $rice->id, 'quantity' => '0.2', 'unit_id' => '', 'line_section' => 'food_cost'];
        $this->postForm('/products', $form)->assertRedirect()->assertSessionHasNoErrors();

        $p = Product::where('name', 'WS Pulao')->firstOrFail();
        $this->assertSame('recipe', $p->inventory_consumption_method);
        $recipe = Recipe::where('product_id', $p->id)->firstOrFail();
        $this->assertTrue((bool) $recipe->is_active);
        $this->assertSame('WS Pulao', $recipe->name, 'a new recipe is named after the item');
        $this->assertSame([$rice->id], $recipe->ingredients()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_a_refused_recipe_line_rolls_the_whole_save_back(): void
    {
        [$dish] = $this->dishWithRecipe('WS Karahi');
        $recipe = Recipe::where('product_id', $dish->id)->firstOrFail();
        $before = $this->recipeRows($recipe);
        $form = $this->formOnPage($this->page('/products/' . $dish->id . '/edit')->getContent(), '/products/' . $dish->id);

        $form['name'] = 'WS Karahi renamed';
        $form['recipe']['lines'][0]['quantity'] = '0';
        $this->putForm($dish, $form)->assertSessionHasErrors('recipe.lines.0.quantity');
        $this->assertSame('WS Karahi', $dish->fresh()->name, 'the product change is rolled back with the recipe');
        $this->assertSame($before, $this->recipeRows($recipe));

        unset($form['recipe']['lines'][0], $form['recipe']['lines'][1]);
        $this->putForm($dish, $form)->assertSessionHasErrors('recipe.lines');
        $this->assertStringContainsString('Keep at least one ingredient', collect(session('errors')?->all())->implode(' '));
        $this->assertSame($before, $this->recipeRows($recipe), 'a recipe is never emptied from here');
    }

    public function test_a_recipe_changed_on_the_recipes_screen_meanwhile_is_not_overwritten(): void
    {
        [$dish, $rice, $oil] = $this->dishWithRecipe('WS Haleem');
        $recipe = Recipe::where('product_id', $dish->id)->firstOrFail();
        $form = $this->formOnPage($this->page('/products/' . $dish->id . '/edit')->getContent(), '/products/' . $dish->id);

        // Someone changes the recipe on the Recipes screen after this page opened.
        $this->owner()->put($this->url('/recipes/' . $recipe->id), ['product_id' => $dish->id, 'name' => 'WS Haleem recipe', 'yield_quantity' => 10,
            'ingredients' => [['product_id' => $rice->id, 'quantity' => 2], ['product_id' => $oil->id, 'quantity' => 0.5]]])->assertSessionHasNoErrors();
        $theirs = $this->recipeRows($recipe);

        $untouched = $form;
        $untouched['default_selling_price'] = '700';
        $this->save($dish, $untouched);
        $this->assertSame($theirs, $this->recipeRows($recipe), 'a recipe this page did not change is left as the other screen saved it');
        $this->assertEquals(700, $dish->fresh()->default_selling_price);

        $form['recipe']['lines'][0]['quantity'] = '9';
        $form['default_selling_price'] = '800';
        $this->putForm($dish, $form)->assertSessionHasErrors('recipe.stale');
        $this->assertSame($theirs, $this->recipeRows($recipe), 'their change is not overwritten');
        $this->assertEquals(700, $dish->fresh()->default_selling_price, 'and nothing else of this save is written');
    }

    public function test_a_recipe_is_not_written_for_an_item_no_longer_made_from_one(): void
    {
        [$dish] = $this->dishWithRecipe('WS Tea');
        $recipe = Recipe::where('product_id', $dish->id)->firstOrFail();
        $before = $this->recipeRows($recipe);
        $form = $this->formOnPage($this->page('/products/' . $dish->id . '/edit')->getContent(), '/products/' . $dish->id);

        $form['_recipe'] = '0';
        $form['recipe']['lines'][0]['quantity'] = '5';
        $this->save($dish, $form);
        $this->assertNotSame('recipe', $dish->fresh()->inventory_consumption_method);
        $this->assertSame($before, $this->recipeRows($recipe), 'its saved recipe is kept as it was');
    }

    public function test_without_the_recipe_permission_or_module_there_is_no_tab_and_a_crafted_recipe_is_ignored(): void
    {
        [$dish, $rice] = $this->dishWithRecipe('WS Nihari');
        $recipe = Recipe::where('product_id', $dish->id)->firstOrFail();
        $before = $this->recipeRows($recipe);
        $was = $this->formOnPage($this->page('/products/' . $dish->id . '/edit')->getContent(), '/products/' . $dish->id)['recipe']['_was'];

        $clerk = $this->clerk();
        $html = $this->actingAs($clerk, 'tenant')->call('GET', $this->url('/products/' . $dish->id . '/edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="tp-recipe"', $html, 'no recipe permission: no Recipe tab');
        $this->assertStringContainsString('Recipe: <b>kept as saved', $html, 'the review names it as kept');
        $form = $this->formOnPage($html, '/products/' . $dish->id);
        $form['recipe'] = ['_was' => $was, 'yield_quantity' => '1', 'lines' => [['id' => '', 'product_id' => (string) $rice->id, 'quantity' => '9', 'unit_id' => '', 'line_section' => 'food_cost']]];
        $form['default_selling_price'] = '660';
        $this->actingAs($clerk, 'tenant')->call('POST', $this->url('/products/' . $dish->id), $form)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(660, $dish->fresh()->default_selling_price, 'the product part is saved');
        $this->assertSame($before, $this->recipeRows($recipe), 'the crafted recipe is ignored');

        $this->plan(false, true);   // a plan without kitchen recipes
        $this->page('/products/' . $dish->id . '/edit')->assertOk()->assertDontSee('id="tp-recipe"', false);
        $form = $this->formOnPage($this->page('/products/' . $dish->id . '/edit')->getContent(), '/products/' . $dish->id);
        $form['recipe'] = ['_was' => $was, 'yield_quantity' => '1', 'lines' => [['id' => '', 'product_id' => (string) $rice->id, 'quantity' => '9', 'unit_id' => '', 'line_section' => 'food_cost']]];
        $this->save($dish, $form);
        $this->assertSame($before, $this->recipeRows($recipe), 'no module: the crafted recipe is ignored too');
    }

    // ── Bill of materials ────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_bom_edited_in_the_product_form_keeps_its_number_version_status_and_notes(): void
    {
        $this->business('industry');
        $chair = $this->product('WS Chair', $this->piece()->id, ['product_kind' => 'finished_good', 'can_be_bom_output' => true, 'is_purchasable' => false, 'default_selling_price' => 5000]);
        $pipe = $this->material('WS Pipe', $this->piece()->id, ['can_be_bom_component' => true]);
        $screw = $this->material('WS Screw', $this->piece()->id, ['can_be_bom_component' => true]);
        $glue = $this->material('WS Glue', $this->litre()->id, ['can_be_bom_component' => true]);
        $boms = app(BomWriter::class);
        $bom = $boms->save(null, ['bom_no' => 'WS-BOM-1', 'finished_product_id' => $chair->id, 'name' => 'Chair, steel', 'version' => '3',
            'output_quantity' => 1, 'status' => 'active', 'notes' => 'keep me'], [
                ['component_product_id' => $pipe->id, 'unit_id' => null, 'quantity' => 2, 'notes' => 'cut to 40 cm'],
                ['component_product_id' => $screw->id, 'unit_id' => null, 'quantity' => 8],
            ]);
        $before = $this->bomRows($bom);

        $html = $this->page('/products/' . $chair->id . '/edit')->assertOk()->assertSee('id="tp-bom"', false)->getContent();
        $form = $this->formOnPage($html, '/products/' . $chair->id);
        $this->save($chair, $form);
        $this->assertSame($before, $this->bomRows($bom), 'saved unchanged: the same lines and ids — material requisitions stay pointed at them');

        $form['bom']['lines'][1]['quantity'] = '10';
        $form['bom']['lines']['n1'] = ['id' => '', 'component_product_id' => (string) $glue->id, 'quantity' => '0.05', 'unit_id' => '', 'wastage_percent' => '5'];
        $this->save($chair, $form);
        $bom->refresh();
        $this->assertSame(['WS-BOM-1', 'Chair, steel', '3', 'active', 'keep me'], [$bom->bom_no, $bom->name, (string) $bom->version, $bom->status, $bom->notes]);
        $lines = $bom->lines()->orderBy('sort_order')->get();
        $this->assertSame([$pipe->id, $screw->id, $glue->id], $lines->pluck('component_product_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('cut to 40 cm', $lines[0]->notes, 'a line\'s notes are kept');
        $this->assertEqualsWithDelta(10, (float) $lines[1]->quantity, 0.0001);
        $this->assertEqualsWithDelta(5, (float) $lines[2]->wastage_percent, 0.0001);

        $form = $this->formOnPage($this->page('/products/' . $chair->id . '/edit')->getContent(), '/products/' . $chair->id);
        $form['name'] = 'WS Chair renamed';
        $form['bom']['lines'][0]['component_product_id'] = (string) $chair->id;
        $this->putForm($chair, $form)->assertSessionHasErrors('bom.lines');
        $this->assertStringContainsString('A component cannot be the same product as the finished product.', collect(session('errors')?->all())->implode(' '));
        $this->assertSame('WS Chair', $chair->fresh()->name, 'refused: the product is not saved either');
    }

    public function test_a_new_factory_product_is_saved_with_its_bom(): void
    {
        $this->business('industry');
        $pipe = $this->material('WS New Pipe', $this->piece()->id, ['can_be_bom_component' => true]);
        $form = $this->formOnPage($this->page('/products/create?card=finished')->assertOk()->getContent(), '/products', false);
        $form = array_merge($form, ['name' => 'WS Table', 'default_selling_price' => '9000']);
        $form['bom']['output_quantity'] = '1';
        $form['bom']['lines']['n1'] = ['id' => '', 'component_product_id' => (string) $pipe->id, 'quantity' => '4', 'unit_id' => '', 'wastage_percent' => '0'];
        $this->postForm('/products', $form)->assertRedirect()->assertSessionHasNoErrors();

        $p = Product::where('name', 'WS Table')->firstOrFail();
        $bom = ManufacturingBom::where('finished_product_id', $p->id)->firstOrFail();
        $this->assertSame(['active', '1', 'WS Table'], [$bom->status, (string) $bom->version, $bom->name]);
        $this->assertStringStartsWith('BOM-', $bom->bom_no, 'numbered as the BOM screen numbers it');
        $this->assertSame(1, $bom->lines()->count());
    }

    // ── Event costing ────────────────────────────────────────────────────────────────────────────────────────────

    public function test_event_costing_edited_in_the_product_form_keeps_switches_labels_urdu_name_and_book_rates(): void
    {
        $this->business('catering');
        [$dish, $chicken, $gas] = $this->dishWithBlocks('WS Event Biryani');
        $before = $this->blockRows($dish);
        $profileAt = (string) CateringProductProfile::where('product_id', $dish->id)->value('updated_at');

        $html = $this->page('/products/' . $dish->id . '/edit')->assertOk()->assertSee('id="tp-catering"', false)->getContent();
        $form = $this->formOnPage($html, '/products/' . $dish->id);
        $this->save($dish, $form);
        $this->assertSame($before, $this->blockRows($dish), 'saved unchanged: the same blocks, ids and times');
        $this->assertSame($profileAt, (string) CateringProductProfile::where('product_id', $dish->id)->value('updated_at'));

        // Change the Making rate, try to change the book-linked chicken rate, add a gas block, set the station.
        $making = $this->blockKey($form, 'Making');
        $chick = $this->blockKey($form, 'Chicken');
        $form['cost_blocks']['lines'][$making]['rate'] = '550';
        $form['cost_blocks']['lines'][$chick]['rate'] = '1';
        $form['cost_blocks']['lines']['n1'] = ['id' => '', 'label' => 'Gas', 'block_type' => 'charge', 'charge_basis' => 'per_unit', 'rate' => '25',
            'material_product_id' => '', 'quantity_per_unit' => '', 'unit_id' => ''];
        $form['catering']['production_station'] = 'BBQ';
        $this->save($dish, $form);

        $blocks = CateringProductCostBlock::where('product_id', $dish->id)->where('is_active', true)->orderBy('sort_order')->get()->keyBy('label');
        $this->assertSame(['Making', 'Chicken', 'Gas'], $blocks->keys()->all());
        $this->assertEqualsWithDelta(550, (float) $blocks['Making']->rate, 0.0001);
        $this->assertSame('making', $blocks['Making']->charge_role, 'the Making role is kept');
        $this->assertEqualsWithDelta(200, (float) $blocks['Chicken']->rate, 0.0001, 'a block that follows the house rate book keeps the book rate');
        $this->assertSame('commercial_book', $blocks['Chicken']->commercial_rate_source);
        $profile = CateringProductProfile::where('product_id', $dish->id)->firstOrFail();
        $this->assertSame('BBQ', $profile->production_station);
        $this->assertSame([false, 'KEEP LABEL', 'fixed'], [(bool) $profile->catering_enabled, $profile->production_label, $profile->pricing_mode], 'what the tab does not show is kept');
        $this->assertSame('بریانی', DB::connection('tenant')->table('product_translations')->where('product_id', $dish->id)->where('language_code', 'ur')->value('name'), 'the Urdu name is kept');
    }

    public function test_switching_to_cost_blocks_that_cannot_cost_the_dish_is_refused_and_nothing_is_saved(): void
    {
        $this->business('catering');
        [$dish] = $this->dishWithBlocks('WS Event Qorma', false);
        $before = $this->blockRows($dish);
        $form = $this->formOnPage($this->page('/products/' . $dish->id . '/edit')->getContent(), '/products/' . $dish->id);

        $form['catering']['costing_mode'] = 'blocks';
        $form['cost_blocks']['lines'][$this->blockKey($form, 'Making')]['rate'] = '999';
        $form['default_selling_price'] = '1234';
        $this->putForm($dish, $form)->assertSessionHasErrors('catering.costing_mode');
        $this->assertStringContainsString('cannot switch to Cost Blocks yet', collect(session('errors')?->all())->implode(' '));
        $this->assertSame('recipe', CateringProductProfile::where('product_id', $dish->id)->value('costing_mode'));
        $this->assertSame($before, $this->blockRows($dish), 'the block change in the same save is rolled back');
        $this->assertNotEquals(1234, (float) $dish->fresh()->default_selling_price, 'and so is the product');
    }

    public function test_a_new_dish_with_cost_blocks_gets_a_profile_as_the_catering_screen_makes_one(): void
    {
        $this->business('catering');
        $form = $this->formOnPage($this->page('/products/create?card=dish')->assertOk()->getContent(), '/products', false);
        $form = array_merge($form, ['name' => 'WS Event Kheer', 'default_selling_price' => '300']);
        $form['cost_blocks']['lines']['n1'] = ['id' => '', 'label' => 'Making', 'block_type' => 'charge', 'charge_basis' => 'per_unit', 'rate' => '120',
            'material_product_id' => '', 'quantity_per_unit' => '', 'unit_id' => ''];
        $this->postForm('/products', $form)->assertRedirect()->assertSessionHasNoErrors();

        $p = Product::where('name', 'WS Event Kheer')->firstOrFail();
        $profile = CateringProductProfile::where('product_id', $p->id)->firstOrFail();
        $this->assertSame(['recipe', 'fixed', true, true, false],
            [$profile->costing_mode, $profile->pricing_mode, (bool) $profile->catering_enabled, (bool) $profile->allow_party_supply, (bool) $profile->is_complimentary]);
        $this->assertSame(['Making'], CateringProductCostBlock::where('product_id', $p->id)->pluck('label')->all());
    }

    // ── One form for Manufacturing Products and Catering Materials (S3d), English-only ─────────────────────────

    public function test_manufacturing_products_and_catering_materials_open_the_one_form_where_it_is_switched_on(): void
    {
        $this->business('restaurant');
        // Manufacturing Products: the factory's cards, its own routes, the BOM tab; no Urdu helper lines.
        $html = $this->page('/manufacturing/products/create')->assertOk()->getContent();
        $this->assertStringContainsString('id="pv2-form"', $html);
        $this->assertStringContainsString('Finished product', $html);
        $this->assertStringContainsString('id="tp-bom"', $html);
        $this->assertDoesNotMatchRegularExpression('/class="ur(-inline)?"/', $html, 'English-only: no Urdu helper lines');
        $form = $this->formOnPage($html, '/manufacturing/products', false);
        $this->postForm('/manufacturing/products', array_merge($form, ['_card' => 'raw', 'name' => 'WS Area Steel', 'default_selling_price' => '0']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $steel = Product::where('name', 'WS Area Steel')->firstOrFail();
        $this->assertSame(['raw_material', true], [$steel->product_kind, (bool) $steel->can_be_bom_component]);

        // An existing factory product opened there and saved unchanged keeps every column.
        $chair = $this->product('WS Area Chair', $this->piece()->id, ['product_kind' => 'finished_good', 'can_be_bom_output' => true,
            'is_manufactured_finished_good' => true, 'is_purchasable' => false, 'default_selling_price' => 4000]);
        $row = fn () => collect((array) DB::connection('tenant')->table('products')->where('id', $chair->id)->first())->except(['updated_at'])->all();
        $before = $row();
        $edit = $this->formOnPage($this->page('/manufacturing/products/' . $chair->id . '/edit')->assertOk()->getContent(), '/manufacturing/products/' . $chair->id);
        $this->owner()->call('POST', $this->url('/manufacturing/products/' . $chair->id), $edit)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $row(), 'opened in Manufacturing Products and saved unchanged: nothing changed');

        // Catering Materials: only the material cards, its own routes, no dish tabs.
        $html = $this->page('/catering/materials/create')->assertOk()->getContent();
        $this->assertStringContainsString('Disposables &amp; packing', $html);
        $this->assertStringNotContainsString('Menu dish', $html);
        $this->assertStringNotContainsString('id="tp-catering"', $html);
        $this->assertStringNotContainsString('id="tp-recipe"', $html);
        $form = $this->formOnPage($html, '/catering/materials', false);
        $this->postForm('/catering/materials', array_merge($form, ['_card' => 'dish', 'name' => 'WS Area Dish', 'default_selling_price' => '500']))
            ->assertSessionHasErrors('_card');
        $this->assertFalse(Product::where('name', 'WS Area Dish')->exists(), 'a dish is not added from Catering Materials');
        $this->postForm('/catering/materials', array_merge($form, ['_card' => 'ingredient', 'name' => 'WS Area Rice', 'default_selling_price' => '0']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('raw_material', Product::where('name', 'WS Area Rice')->value('product_kind'));

        // An item no card of the area fits, opened there and saved unchanged, keeps its role and every column:
        // a service in Manufacturing Products (factory cards), a factory finished good in Catering Materials.
        foreach ([['/manufacturing/products', ['product_kind' => 'service', 'product_type' => 'service', 'is_stock_tracked' => false,
                    'is_purchasable' => false, 'inventory_consumption_method' => 'none', 'default_selling_price' => 300], 'WS Area Service'],
                  ['/catering/materials', ['product_kind' => 'finished_good', 'can_be_bom_output' => true, 'is_manufactured_finished_good' => true,
                    'is_purchasable' => false, 'default_selling_price' => 900], 'WS Area Factory Good']] as [$area, $saved, $name]) {
            $item = $this->product($name, $this->piece()->id, $saved);
            $rowOf = fn () => collect((array) DB::connection('tenant')->table('products')->where('id', $item->id)->first())->except(['updated_at'])->all();
            $was = $rowOf();
            $page = $this->page($area . '/' . $item->id . '/edit')->assertOk()->getContent();
            $this->assertStringContainsString('id="pv2-misfit"', $page, "{$area}: the screen says no card fits");
            $this->owner()->call('POST', $this->url($area . '/' . $item->id), $this->formOnPage($page, $area . '/' . $item->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($was, $rowOf(), "{$area}: saved unchanged, its role ({$saved['product_kind']}) and every column are kept");
        }

        // Switched off: both open their old forms.
        $this->setFeatures([]);
        $this->assertStringNotContainsString('id="pv2-form"', $this->page('/manufacturing/products/create')->assertOk()->getContent());
        $this->assertStringNotContainsString('id="pv2-form"', $this->page('/catering/materials/create')->assertOk()->getContent());
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: Product, 1: Product, 2: Product, 3: Product}  the dish, rice, oil and salt */
    private function dishWithRecipe(string $name): array
    {
        $this->business('restaurant');
        $dish = $this->product($name, $this->piece()->id, ['inventory_consumption_method' => 'recipe', 'product_type' => 'recipe',
            'is_stock_tracked' => false, 'is_purchasable' => false, 'default_selling_price' => 650]);
        $rice = $this->material($name . ' rice', $this->kg()->id, ['default_purchase_price' => 200]);
        $oil = $this->material($name . ' oil', $this->litre()->id, ['default_purchase_price' => 520]);
        $salt = $this->material($name . ' salt', $this->kg()->id, ['default_purchase_price' => 60]);
        app(RecipeWriter::class)->save(null, ['product_id' => $dish->id, 'name' => $name . ' recipe', 'yield_quantity' => 10, 'doc_no' => 'D-7',
            'recipe_no' => 'R-12', 'revision_no' => 2, 'overhead_percent' => 5, 'notes' => 'house recipe', 'ingredients' => [
                ['product_id' => $rice->id, 'quantity' => 1.5, 'cost_override' => 300, 'applicable_order_types' => ['dine_in']],
                ['product_id' => $oil->id, 'quantity' => 0.2],
            ]], true);

        return [$dish->fresh(), $rice, $oil, $salt];
    }

    /** A dish priced as the Catering screens set it: a Making charge, and chicken following the house rate book. */
    private function dishWithBlocks(string $name, bool $ready = true): array
    {
        $dish = $this->product($name, $this->kg()->id, ['is_stock_tracked' => false, 'is_purchasable' => false, 'default_selling_price' => 900]);
        $chicken = $this->material($name . ' chicken', $this->kg()->id);
        $gas = $this->material($name . ' gas', $this->piece()->id);
        if ($ready) {
            CateringMaterialRate::create(['product_id' => $chicken->id, 'rate' => 180, 'unit_id' => $this->kg()->id, 'effective_from' => now()->subDay()->toDateString()]);
        }
        CateringMaterialCommercialRate::create(['product_id' => $chicken->id, 'rate' => 200, 'unit_id' => $this->kg()->id, 'effective_from' => now()->subDay()->toDateString()]);
        app(CateringProfileWriter::class)->save(null, ['product_id' => $dish->id, 'costing_mode' => 'recipe', 'pricing_mode' => 'fixed', 'catering_enabled' => false,
            'allow_party_supply' => true, 'is_complimentary' => false, 'production_label' => 'KEEP LABEL', 'name_ur' => 'بریانی']);
        app(CateringCostBlockWriter::class)->save($dish->id, [
            ['label' => 'Making', 'block_type' => 'charge', 'charge_basis' => 'per_unit', 'rate' => 500, 'charge_role' => 'making'],
            ['label' => 'Chicken', 'block_type' => 'material', 'charge_basis' => 'per_unit', 'rate' => 0, 'material_product_id' => $chicken->id,
                'quantity_per_unit' => 0.5, 'unit_id' => $this->kg()->id, 'rate_basis' => 'per_material_unit', 'commercial_rate_source' => 'commercial_book'],
        ], null);

        return [$dish->fresh(), $chicken, $gas];
    }

    private function material(string $name, int $unitId, array $extra = []): Product
    {
        return $this->product($name, $unitId, $extra + ['product_kind' => 'raw_material', 'is_sellable' => false, 'is_pos_visible' => false]);
    }

    private function recipeRows(Recipe $recipe): array
    {
        return DB::connection('tenant')->table('recipe_ingredients')->where('recipe_id', $recipe->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()
            + ['recipe' => (array) DB::connection('tenant')->table('recipes')->where('id', $recipe->id)->first()];
    }

    private function bomRows(ManufacturingBom $bom): array
    {
        return DB::connection('tenant')->table('manufacturing_bom_lines')->where('manufacturing_bom_id', $bom->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()
            + ['bom' => (array) DB::connection('tenant')->table('manufacturing_boms')->where('id', $bom->id)->first()];
    }

    private function blockRows(Product $dish): array
    {
        return DB::connection('tenant')->table('catering_product_cost_blocks')->where('product_id', $dish->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function blockKey(array $form, string $label): string|int
    {
        foreach ($form['cost_blocks']['lines'] ?? [] as $k => $b) {
            if (($b['label'] ?? null) === $label) {
                return $k;
            }
        }
        $this->fail("the block {$label} is on the tab");
    }

    /** A catalog clerk: may edit products, not recipes (a cloned role, as the owner would make one). */
    private function clerk(): User
    {
        DB::setDefaultConnection('tenant');
        $role = Role::findOrCreate('WS Catalog Clerk', 'tenant');
        $role->syncPermissions(DB::connection('tenant')->table('permissions')->where('guard_name', 'tenant')
            ->where(fn ($q) => $q->where('name', 'like', 'tenant.products.%')->orWhere('name', 'tenant.dashboard'))->pluck('name')->all());
        $user = User::on('tenant')->firstOrCreate(['email' => 'clerk@wstest.test'], ['name' => 'WS Clerk', 'password' => Hash::make('secret123'), 'status' => 'active']);
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** The store's plan: every module, with or without kitchen recipes and catering (catering is never in a stock plan). */
    private function plan(bool $kitchen, bool $catering): void
    {
        $m = DB::connection('master');
        $code = 'ws-' . ($kitchen ? 'k' : 'nok') . '-' . ($catering ? 'c' : 'noc');
        $planId = $m->table('plans')->where('code', $code)->value('id') ?? $m->table('plans')->insertGetId([
            'code' => $code, 'name' => 'Workspace test ' . $code, 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->where('is_active', 1)->get(['id', 'key']) as $mod) {
            if ((! $kitchen && $mod->key === 'kitchen_inventory') || (! $catering && $mod->key === 'catering')) {
                continue;
            }
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $mod->id, 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenantId = $m->table('tenants')->where('tenant_code', self::STORE_CODE)->value('id');
        $m->table('subscriptions')->where('tenant_id', $tenantId)->update(['plan_id' => $planId]);
    }

    private function business(string $b): void
    {
        DB::connection('master')->table('tenants')->where('tenant_code', self::STORE_CODE)->update(['primary_business' => $b]);
    }

    private function page(string $uri)
    {
        return $this->owner()->call('GET', $this->url($uri));
    }

    private function postForm(string $uri, array $form)
    {
        return $this->owner()->call('POST', $this->url($uri), $form);
    }

    /** Post the edit form (it carries _method=PUT). */
    private function putForm(Product $p, array $form)
    {
        return $this->owner()->call('POST', $this->url('/products/' . $p->id), $form);
    }

    private function save(Product $p, array $form): void
    {
        $this->putForm($p, $form)->assertRedirect()->assertSessionHasNoErrors();
    }
}
