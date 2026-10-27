<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Product;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Services\Catering\CateringCostBlockWriter;
use App\Services\Catering\CateringLocalizationService;
use App\Services\Catering\CateringProfileWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\IsolatedPharmacyStore;
use Tests\MySql\Support\ReadsFormOnPage;

/**
 * CATERING-SIMPLIFY-1 (owner, 27 Oct) — one place to price and edit a catering dish, through the real routes and the
 * rendered pages (docs/plans/catering-simplify-2026-10-27.md):
 *  1. a caterer's cost-block dish shows its block price in Selling price, locked; the stored counter price is kept,
 *     whatever is posted — a posted "Priced by" counts only from a user who may edit the Event costing tab;
 *  2. Catering Products shows one Edit (the product form's Event costing tab) where that form opens;
 *  3. that tab edits what the Catering Products pop-up edited, through the same writer;
 *  4. choosing a material on either rate pop-up fills its unit;
 *  5. Material Cost Rates shows each material's last purchase price, from any supplier.
 */
class CateringSimplifyMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;
    use ReadsFormOnPage;

    private const STORE_CODE = 'cstest';
    private const STORE_SUFFIX = '_cs';
    private const STORE_FEATURES = ['product_screen_v2' => true];
    private const STORE_PLAN = 'enterprise';
    private const STORE_BUSINESS = 'catering';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
        $this->plan();
    }

    // ── 1. The selling price of a cost-block dish ────────────────────────────────────────────────────────────────

    public function test_a_caterers_cost_block_dish_shows_its_block_price_locked_and_keeps_its_counter_price(): void
    {
        $dish = $this->blockDish('CS Lock Biryani', 'blocks');   // Making 500 + chicken 0.5 KG × 200 = 600; counter 900

        $html = $this->page('/products/' . $dish->id . '/edit')->assertOk()->getContent();
        $box = $this->element($html, '//input[@id="default_selling_price"]');
        $this->assertTrue($box->hasAttribute('disabled'), 'the box is locked');
        $this->assertSame('600', $box->getAttribute('value'), 'and shows the block price');
        $this->assertStringContainsString('price comes from its cost blocks: <b>Rs 600</b>', $html);
        $this->assertStringContainsString('data-go-tab="catering"', $html, 'with a way to the blocks');
        $this->assertStringContainsString('Counter (POS) price saved: Rs 900', $html);

        // Saved as shown (the locked box posts nothing), then with a crafted price: the counter price stays 900.
        $form = $this->formOnPage($html, '/products/' . $dish->id);
        $this->assertArrayNotHasKey('default_selling_price', $form);
        $this->save($dish, $form);
        $this->save($dish, array_merge($form, ['default_selling_price' => '1234']));
        $this->assertEqualsWithDelta(900, (float) $dish->fresh()->default_selling_price, 0.001, 'a crafted price is ignored');

        // A dish costed by its recipe keeps the box open and saves what is typed.
        CateringProductProfile::where('product_id', $dish->id)->update(['costing_mode' => 'recipe']);
        $html = $this->page('/products/' . $dish->id . '/edit')->getContent();
        $this->assertFalse($this->element($html, '//input[@id="default_selling_price"]')->hasAttribute('disabled'));
        $form = $this->formOnPage($html, '/products/' . $dish->id);
        $this->save($dish, array_merge($form, ['default_selling_price' => '950']));
        $this->assertEqualsWithDelta(950, (float) $dish->fresh()->default_selling_price, 0.001);

        // Switching it to cost blocks in the same save locks it: what is typed with the switch is not saved.
        $form['catering']['costing_mode'] = 'blocks';
        $this->save($dish, array_merge($form, ['default_selling_price' => '1500']));
        $this->assertSame('blocks', CateringProductProfile::where('product_id', $dish->id)->value('costing_mode'));
        $this->assertEqualsWithDelta(950, (float) $dish->fresh()->default_selling_price, 0.001, 'the posted "Priced by" decides');

        // A restaurant with the same dish: the box stays open and says what events charge.
        $this->business('restaurant');
        $html = $this->page('/products/' . $dish->id . '/edit')->assertOk()->getContent();
        $this->assertFalse($this->element($html, '//input[@id="default_selling_price"]')->hasAttribute('disabled'));
        $this->assertStringContainsString('Events price this dish from its cost blocks (Rs 600)', $html);
        $this->assertSame(0, $this->nodes($html, '//*[@data-sp-locked]'), 'no lock note');
        $this->save($dish, array_merge($this->formOnPage($html, '/products/' . $dish->id), ['default_selling_price' => '990']));
        $this->assertEqualsWithDelta(990, (float) $dish->fresh()->default_selling_price, 0.001);
    }

    public function test_a_user_who_may_not_edit_event_costing_cannot_unlock_the_price_with_a_crafted_post(): void
    {
        $dish = $this->blockDish('CS Lock Qorma', 'blocks');
        $clerk = $this->productClerk();

        $html = $this->actingAs($clerk, 'tenant')->get($this->url('/products/' . $dish->id . '/edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="tp-catering"', $html, 'no Event costing tab for this user');
        $this->assertTrue($this->element($html, '//input[@id="default_selling_price"]')->hasAttribute('disabled'));
        $this->assertStringContainsString('changed on the Event costing tab, by someone who may edit it', $html);
        $this->assertStringNotContainsString('data-go-tab="catering"', $html);

        $form = $this->formOnPage($html, '/products/' . $dish->id);
        $form['catering'] = ['_was' => 'x', 'costing_mode' => 'recipe'];
        $this->actingAs($clerk, 'tenant')->call('POST', $this->url('/products/' . $dish->id), array_merge($form, ['default_selling_price' => '1234']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(900, (float) $dish->fresh()->default_selling_price, 0.001);
        $this->assertSame('blocks', CateringProductProfile::where('product_id', $dish->id)->value('costing_mode'), 'and the costing is not touched');
    }

    // ── 2. One Edit on Catering Products ─────────────────────────────────────────────────────────────────────────

    public function test_catering_products_shows_one_edit_where_the_product_form_opens(): void
    {
        $a = $this->blockDish('CS List Pulao', 'blocks');
        $b = $this->blockDish('CS List Karahi', 'recipe');
        $profiles = CateringProductProfile::count();

        $html = $this->page('/catering/profiles')->assertOk()->getContent();
        $this->assertSame($profiles, substr_count($html, 'data-one-edit'), 'one Edit per row');
        foreach ([$a, $b] as $p) {
            $this->assertStringContainsString('href="' . $this->url('/products/' . $p->id . '/edit#catering') . '"', $html);
        }
        $this->assertStringNotContainsString('Edit in product form', $html);
        $this->assertStringNotContainsString('edit-profile', $this->rows($html), 'no pop-up Edit on a row');
        $this->assertStringNotContainsString('/blocks"', $this->rows($html), 'no Cost Blocks button on a row');
        $this->assertStringContainsString('id="new-profile-btn"', $html, 'Add stays');

        // The new product screen off: the buttons as before.
        $this->setFeatures([]);
        $html = $this->page('/catering/profiles')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-one-edit', $html);
        $this->assertStringContainsString('edit-profile', $this->rows($html));
        $this->assertStringContainsString('/catering/profiles/' . CateringProductProfile::where('product_id', $a->id)->value('id') . '/blocks', $html);

        // A setup clerk who may not open the product form: the buttons as before.
        $this->setFeatures(self::STORE_FEATURES);
        $html = $this->actingAs($this->setupClerk(), 'tenant')->get($this->url('/catering/profiles'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-one-edit', $html);
        $this->assertStringContainsString('edit-profile', $this->rows($html));
    }

    // ── 3. The Event costing tab edits what the pop-up did ───────────────────────────────────────────────────────

    public function test_the_event_costing_tab_edits_what_the_catering_products_pop_up_edited(): void
    {
        $dish = $this->blockDish('CS Tab Haleem', 'recipe');
        $profileAt = fn () => (string) CateringProductProfile::where('product_id', $dish->id)->value('updated_at');
        $urdu = fn () => DB::connection('tenant')->table('product_translations')->where('product_id', $dish->id)->where('language_code', 'ur')->value('name');

        $html = $this->page('/products/' . $dish->id . '/edit')->assertOk()->getContent();
        $form = $this->formOnPage($html, '/products/' . $dish->id);
        $this->assertSame(['0', '1', '0', 'KEEP LABEL', 'حلیم'],
            [$form['catering']['catering_enabled'], $form['catering']['allow_party_supply'], $form['catering']['is_complimentary'],
                $form['catering']['production_label'], $form['catering']['name_ur']], 'the tab shows the saved switches, label and Urdu name');
        $this->assertStringNotContainsString('data-ws-full="catering-profile"', $html, 'no need to leave for Catering Products');

        // Saved unchanged: nothing is written.
        $was = $profileAt();
        $this->save($dish, $form);
        $this->assertSame($was, $profileAt());

        // Changed: saved through the pop-up's writer; the pricing mode is kept.
        $form['catering'] = array_merge($form['catering'], ['catering_enabled' => '1', 'allow_party_supply' => '0', 'is_complimentary' => '1',
            'production_label' => 'Big pot', 'production_label_ur' => 'بڑی دیگ', 'instructions' => "Less salt\nNo nuts", 'name_ur' => 'شاہی حلیم']);
        $this->save($dish, $form);
        $p = CateringProductProfile::where('product_id', $dish->id)->firstOrFail();
        $this->assertSame([true, false, true, 'Big pot', 'بڑی دیگ', "Less salt\nNo nuts", 'fixed'],
            [(bool) $p->catering_enabled, (bool) $p->allow_party_supply, (bool) $p->is_complimentary, $p->production_label,
                $p->production_label_ur, $p->instructions, $p->pricing_mode]);
        $this->assertSame('شاہی حلیم', $urdu());

        // A dish with no profile yet that gets its first cost block keeps its Urdu name (its new profile posts nothing).
        $plain = $this->product('CS Tab Kheer', $this->kg()->id, ['is_stock_tracked' => false, 'is_purchasable' => false, 'default_selling_price' => 300]);
        app(CateringLocalizationService::class)->setProductName($plain, 'ur', 'کھیر');
        $form = $this->formOnPage($this->page('/products/' . $plain->id . '/edit')->assertOk()->getContent(), '/products/' . $plain->id);
        $form['cost_blocks']['lines']['n1'] = ['id' => '', 'label' => 'Making', 'block_type' => 'charge', 'charge_basis' => 'per_unit', 'rate' => '120',
            'material_product_id' => '', 'quantity_per_unit' => '', 'unit_id' => ''];
        $this->save($plain, $form);
        $this->assertTrue(CateringProductProfile::where('product_id', $plain->id)->exists());
        $this->assertSame('کھیر', DB::connection('tenant')->table('product_translations')->where('product_id', $plain->id)->where('language_code', 'ur')->value('name'));

        // The Urdu name changed elsewhere after the page opened: refused, not overwritten. (Last: a refusal flashes the
        // form back, and the next page in this test would read it.)
        $form = $this->formOnPage($this->page('/products/' . $dish->id . '/edit')->getContent(), '/products/' . $dish->id);
        app(CateringLocalizationService::class)->setProductName($dish, 'ur', 'حلیم خاص');
        $form['catering']['production_station'] = 'Curry';
        $this->putForm($dish, $form)->assertSessionHasErrors('catering.stale');
        $this->assertSame('حلیم خاص', $urdu());
        $this->assertNull(CateringProductProfile::where('product_id', $dish->id)->value('production_station'));
    }

    // ── 4. The unit on both rate pop-ups ─────────────────────────────────────────────────────────────────────────

    public function test_choosing_a_material_fills_its_unit_on_both_rate_books(): void
    {
        $kg = $this->kg()->id;
        $pc = $this->piece()->id;
        $g = Unit::updateOrCreate(['code' => 'G'], ['name' => 'Gram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true])->id;
        $box = Unit::updateOrCreate(['code' => 'CSBOX'], ['name' => 'Old box', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => false])->id;

        $rated = $this->material('CS Unit Chicken', $g);       // house rates: PC, then KG (newest); cost rates none; blocks in PC
        $plain = $this->material('CS Unit Salt', $g);          // nothing: its own unit
        $boxed = $this->material('CS Unit Foil', $box);        // its own unit is switched off: no answer
        $mixed = $this->material('CS Unit Oil', $g);           // blocks: two in KG, one in PC
        CateringMaterialCommercialRate::create(['product_id' => $rated->id, 'rate' => 50, 'unit_id' => $pc, 'effective_from' => now()->subDays(5)->toDateString()]);
        CateringMaterialCommercialRate::create(['product_id' => $rated->id, 'rate' => 200, 'unit_id' => $kg, 'effective_from' => now()->subDays(2)->toDateString()]);
        foreach ([[$rated, $pc], [$mixed, $kg], [$mixed, $kg], [$mixed, $pc]] as $i => [$m, $unit]) {
            $d = $this->product('CS Unit Dish ' . $i, $kg, ['is_stock_tracked' => false, 'is_purchasable' => false]);
            app(CateringCostBlockWriter::class)->save($d->id, [['label' => 'Stuff', 'block_type' => 'material', 'charge_basis' => 'per_unit', 'rate' => 10,
                'material_product_id' => $m->id, 'quantity_per_unit' => 0.5, 'unit_id' => $unit, 'rate_basis' => 'per_material_unit']], null);
        }

        $commercial = $this->unitDefaults($this->page('/catering/commercial-rates')->assertOk()->getContent());
        $cost = $this->unitDefaults($this->page('/catering/material-rates')->assertOk()->getContent());

        $this->assertSame($kg, $commercial[$rated->id] ?? null, 'the house book: its latest house rate');
        $this->assertSame($pc, $cost[$rated->id] ?? null, 'the cost book has no rate for it: the unit its dishes use');
        $this->assertSame([$kg, $kg], [$commercial[$mixed->id] ?? null, $cost[$mixed->id] ?? null], 'the unit most of its blocks use');
        $this->assertSame([$g, $g], [$commercial[$plain->id] ?? null, $cost[$plain->id] ?? null], 'else its own unit');
        $this->assertArrayNotHasKey($boxed->id, $commercial, 'a switched-off unit is never filled in');
        $this->assertArrayNotHasKey($boxed->id, $cost);
    }

    // ── 5. Last purchase price on Material Cost Rates ────────────────────────────────────────────────────────────

    public function test_material_cost_rates_show_the_last_purchase_from_any_supplier(): void
    {
        $kg = $this->kg()->id;
        $rice = $this->material('CS Buy Rice', $kg);
        $ghee = $this->material('CS Buy Ghee', $kg);            // has a cost rate, never bought
        foreach ([$rice, $ghee] as $m) {
            CateringMaterialRate::create(['product_id' => $m->id, 'rate' => 700, 'unit_id' => $kg, 'effective_from' => now()->subDays(10)->toDateString()]);
        }
        $first = $this->supplier();
        $second = Supplier::firstOrCreate(['code' => 'CSTEST-S2'], ['name' => "Ali's Traders", 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);

        $this->buy($first, $rice, 720, 6);
        $this->buy($second, $rice, 760, 4);                      // the latest purchase
        $this->buy($first, $rice, 0, 3);                         // a free line is not a price
        $this->buy($first, $rice, 800, 2);                       // later, but voided below
        $voided = GoodsReceipt::latest('id')->firstOrFail();
        $this->owner()->post($this->url('/goods-receipts/' . $voided->id . '/void'), ['void_reason' => 'wrong supplier'])->assertRedirect();
        $this->assertSame('voided', $voided->fresh()->status);

        $html = $this->page('/catering/material-rates')->assertOk()->getContent();
        $cell = trim(preg_replace('/\s+/', ' ', $this->element($html, '//td[@data-last-purchase="' . $rice->id . '"]')->textContent));
        $this->assertSame('760.00 / KG ' . now()->subDays(4)->format('d M Y') . " · Ali's Traders", $cell);
        $this->assertSame('—', trim($this->element($html, '//td[@data-last-purchase="' . $ghee->id . '"]')->textContent));

        // The pop-up's data: parsed as the browser parses it (the supplier's apostrophe does not end the attribute).
        $bought = json_decode($this->element($html, '//form[@data-last-purchases]')->getAttribute('data-last-purchases'), true);
        $this->assertIsArray($bought);
        $this->assertSame([760.0, $kg, 'KG', now()->subDays(4)->toDateString(), "Ali's Traders"],
            [(float) $bought[$rice->id]['unit_cost'], $bought[$rice->id]['unit_id'], $bought[$rice->id]['unit'], $bought[$rice->id]['date'], $bought[$rice->id]['supplier']]);
        $this->assertArrayNotHasKey($ghee->id, $bought);
        $this->assertIsArray(json_decode($this->element($html, '//form[@data-rate-history]')->getAttribute('data-rate-history'), true));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * A dish as the Catering screens set it: Making 500 a kilo, and chicken (0.5 KG a kilo) following the house rate book
     * at 200 — so its block price is 600. Its counter price is 900. Profile: off, party supply on, a kept label, Urdu name.
     */
    private function blockDish(string $name, string $mode): Product
    {
        $kg = $this->kg()->id;
        $dish = $this->product($name, $kg, ['is_stock_tracked' => false, 'is_purchasable' => false, 'default_selling_price' => 900]);
        $chicken = $this->material($name . ' chicken', $kg);
        CateringMaterialRate::create(['product_id' => $chicken->id, 'rate' => 180, 'unit_id' => $kg, 'effective_from' => now()->subDay()->toDateString()]);
        CateringMaterialCommercialRate::create(['product_id' => $chicken->id, 'rate' => 200, 'unit_id' => $kg, 'effective_from' => now()->subDay()->toDateString()]);
        app(CateringProfileWriter::class)->save(null, ['product_id' => $dish->id, 'costing_mode' => 'recipe', 'pricing_mode' => 'fixed', 'catering_enabled' => false,
            'allow_party_supply' => true, 'is_complimentary' => false, 'production_label' => 'KEEP LABEL', 'name_ur' => match (true) {
                str_contains($name, 'Haleem') => 'حلیم', default => 'بریانی' }]);
        app(CateringCostBlockWriter::class)->save($dish->id, [
            ['label' => 'Making', 'block_type' => 'charge', 'charge_basis' => 'per_unit', 'rate' => 500, 'charge_role' => 'making'],
            ['label' => 'Chicken', 'block_type' => 'material', 'charge_basis' => 'per_unit', 'rate' => 0, 'material_product_id' => $chicken->id,
                'quantity_per_unit' => 0.5, 'unit_id' => $kg, 'rate_basis' => 'per_material_unit', 'commercial_rate_source' => 'commercial_book'],
        ], null);
        CateringProductProfile::where('product_id', $dish->id)->update(['costing_mode' => $mode]);

        return $dish->fresh();
    }

    private function material(string $name, int $unitId): Product
    {
        return $this->product($name, $unitId, ['product_kind' => 'raw_material', 'is_sellable' => false, 'is_pos_visible' => false]);
    }

    /** A goods receipt through the real route, $daysAgo days back. */
    private function buy(Supplier $supplier, Product $p, float $unitCost, int $daysAgo): void
    {
        $this->owner()->post($this->url('/goods-receipts'), ['branch_id' => $this->branchId(), 'supplier_id' => $supplier->id,
            'receipt_date' => now()->subDays($daysAgo)->toDateString(),
            'lines' => [['product_id' => $p->id, 'quantity_received' => 5, 'unit_cost' => $unitCost]]])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function unitDefaults(string $html): array
    {
        $map = json_decode($this->element($html, '//form[@data-unit-defaults]')->getAttribute('data-unit-defaults'), true);
        $this->assertIsArray($map);

        return array_map('intval', $map);
    }

    private function element(string $html, string $query): \DOMElement
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $el = (new \DOMXPath($dom))->query($query)->item(0);
        $this->assertInstanceOf(\DOMElement::class, $el, "the page has {$query}");

        return $el;
    }

    private function nodes(string $html, string $query): int
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return (new \DOMXPath($dom))->query($query)->length;
    }

    /** The list's table body: the rows, not the Add pop-up below them. */
    private function rows(string $html): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $body = (new \DOMXPath($dom))->query('//table//tbody')->item(0);
        $this->assertNotNull($body);

        return $dom->saveHTML($body);
    }

    /** May edit products — not the catering screens (a cloned role, as the owner would make one). */
    private function productClerk(): User
    {
        return $this->roleUser('CS Product Clerk', 'productclerk@cstest.test', fn ($q) => $q->where('name', 'like', 'tenant.products.%'));
    }

    /** May use the catering setup screens — not products. */
    private function setupClerk(): User
    {
        return $this->roleUser('CS Setup Clerk', 'setupclerk@cstest.test', fn ($q) => $q->where('name', 'like', 'tenant.catering.%'));
    }

    private function roleUser(string $roleName, string $email, callable $perms): User
    {
        DB::setDefaultConnection('tenant');
        $role = Role::findOrCreate($roleName, 'tenant');
        $role->syncPermissions(DB::connection('tenant')->table('permissions')->where('guard_name', 'tenant')
            ->where(fn ($q) => $perms($q)->orWhere('name', 'tenant.dashboard'))->pluck('name')->all());
        $user = User::on('tenant')->firstOrCreate(['email' => $email], ['name' => $roleName, 'password' => Hash::make('secret123'), 'status' => 'active']);
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** The store's plan: every active module (catering and purchasing included). */
    private function plan(): void
    {
        $m = DB::connection('master');
        $planId = $m->table('plans')->where('code', 'cs-all')->value('id') ?? $m->table('plans')->insertGetId([
            'code' => 'cs-all', 'name' => 'Catering simplify test', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->where('is_active', 1)->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
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

    private function putForm(Product $p, array $form)
    {
        return $this->owner()->call('POST', $this->url('/products/' . $p->id), $form);
    }

    private function save(Product $p, array $form): void
    {
        $this->putForm($p, $form)->assertRedirect()->assertSessionHasNoErrors();
    }
}
