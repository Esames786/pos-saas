<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\ManufacturingBom;
use App\Models\Tenant\Product;
use App\Models\Tenant\Recipe;
use App\Models\Tenant\User;
use App\Services\Catering\CateringCostBlockWriter;
use App\Services\Catering\CateringProfileWriter;
use App\Services\Kitchen\RecipeWriter;
use App\Services\Manufacturing\BomWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * PRODUCT-NAV-1 (26 Oct) — one entry for product setup, through the real pages:
 *  - switch ON: each product-setup link shows once (Products is the main entry); operational screens all stay;
 *  - switch OFF: the menu and the lists exactly as before (no new link, both old duplicates still there);
 *  - Recipes, Bill of materials, Catering Products and Cost blocks open the product form on its tab — only for a user
 *    who may edit products; the tabs link back to the full screens — only for a user who may open them;
 *  - every old address still opens, for whoever may open it, with the switch on and off; nothing redirects.
 */
class ProductNavCleanupMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 'pnavtest';
    private const STORE_SUFFIX = '_pnav';
    private const STORE_FEATURES = ['product_screen_v2' => true];
    private const STORE_PLAN = 'enterprise';
    private const STORE_BUSINESS = 'restaurant';

    /**
     * Links that must stay (setup kept, and operational screens that never leave), by the menu that carries them.
     * Catering shows on a plan with the catering module; the Manufacturing menu shows only on the ERP plans (enterprise /
     * standard / finance_erp, as production has it), and the seeded enterprise plan has no catering — so both are read.
     */
    private const KEPT_EVERYWHERE = ['/products', '/modifier-groups', '/categories', '/units', '/recipes', '/unit-conversions',
        '/inventory/movements', '/stock-adjustments', '/stock-transfers', '/purchase-returns', '/sales-returns'];
    private const KEPT_CATERING = ['/catering/materials', '/catering/profiles', '/catering/events', '/catering/store-issues'];
    private const KEPT_MANUFACTURING = ['/manufacturing/products', '/manufacturing/bom', '/manufacturing/production-orders',
        '/manufacturing/material-requisitions', '/manufacturing/consumption', '/manufacturing/finished-goods'];

    private static array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
        $this->allModules();
        if (! self::$ids) {
            self::$ids = $this->buildSetup();
        }
        $this->setFeatures(self::STORE_FEATURES);
        $this->business('restaurant');
    }

    /* ── the menu ────────────────────────────────────────────────────────── */

    public function test_with_the_switch_on_each_setup_link_shows_once_and_operational_screens_stay(): void
    {
        [$catering, $factory] = $this->menus();

        $this->assertSame(1, $this->linkCount($catering, '/catering/store-issues'), 'Store Issue once');
        $this->assertSame(0, $this->linkCount($factory, '/categories?context=manufacturing'), 'Categories under Catalog only');
        $this->assertSame(0, $this->linkCount($factory, '/units?context=manufacturing'), 'Units under Catalog only');
        $this->assertKept($catering, $factory);
        foreach ([$catering, $factory] as $menu) {
            $this->assertSame(1, $this->linkCount($menu, '/categories'), 'one Categories link');
            $this->assertSame(1, $this->linkCount($menu, '/units'), 'one Units link');
        }
    }

    public function test_with_the_switch_off_the_menu_and_lists_are_as_before(): void
    {
        $this->setFeatures([]);
        [$catering, $factory] = $this->menus();

        $this->assertSame(2, $this->linkCount($catering, '/catering/store-issues'), 'both Store Issue links, as before');
        $this->assertSame(1, $this->linkCount($factory, '/categories?context=manufacturing'));
        $this->assertSame(1, $this->linkCount($factory, '/units?context=manufacturing'));
        $this->assertKept($catering, $factory);
        foreach (['/recipes', '/manufacturing/bom', '/catering/profiles', '/catering/profiles/' . self::$ids['profile'] . '/blocks'] as $list) {
            $this->owner()->get($this->url($list))->assertOk()->assertDontSee('data-edit-in-product', false);
        }
        // The old product form, as before.
        $this->owner()->get($this->url('/catering/materials/' . self::$ids['material'] . '/edit'))->assertOk()->assertDontSee('id="pv2-save"', false);
    }

    /* ── the lists open the product form on its tab ───────────────────────── */

    public function test_the_lists_open_the_product_form_on_its_tab_only_for_who_may_edit_products(): void
    {
        $i = self::$ids;
        $this->owner()->get($this->url('/recipes'))->assertOk()->assertSee('/products/' . $i['dish'] . '/edit#recipe', false);
        $this->owner()->get($this->url('/manufacturing/bom'))->assertOk()->assertSee('/products/' . $i['chair'] . '/edit#bom', false);
        $this->owner()->get($this->url('/catering/profiles'))->assertOk()->assertSee('/products/' . $i['biryani'] . '/edit#catering', false);
        $this->owner()->get($this->url('/catering/profiles/' . $i['profile'] . '/blocks'))->assertOk()->assertSee('/products/' . $i['biryani'] . '/edit#catering', false);
        $this->assertStringContainsString('target="_blank"', (string) $this->owner()->get($this->url('/recipes'))->getContent(), 'a new tab: the list stays');

        // The link lands on a page that opens: the product form, with that tab.
        $this->owner()->get($this->url('/products/' . $i['dish'] . '/edit'))->assertOk()->assertSee('id="tab-recipe"', false);

        // A setup clerk without the product permission keeps the specialized screens and gets no such link.
        $clerk = $this->setupClerk();
        foreach (['/recipes', '/manufacturing/bom', '/catering/profiles', '/catering/profiles/' . $i['profile'] . '/blocks'] as $list) {
            $this->actingAs($clerk, 'tenant')->get($this->url($list))->assertOk()->assertDontSee('data-edit-in-product', false);
        }
        $this->actingAs($clerk, 'tenant')->get($this->url('/products/' . $i['dish'] . '/edit'))->assertForbidden();
    }

    public function test_the_tabs_link_to_the_full_screens_only_for_who_may_open_them(): void
    {
        $i = self::$ids;
        $this->business('restaurant');
        $this->owner()->get($this->url('/products/' . $i['dish'] . '/edit'))->assertOk()
            ->assertSee('data-ws-full="recipe-report"', false)->assertSee('/recipes/' . $i['recipe'] . '/edit', false);
        $this->business('industry');
        $this->owner()->get($this->url('/products/' . $i['chair'] . '/edit'))->assertOk()
            ->assertSee('/manufacturing/bom/' . $i['bom'] . '/edit', false);
        $this->business('catering');
        // CATERING-SIMPLIFY-1: the Catering Products fields are on the tab now, so only the Cost blocks link stays.
        $this->owner()->get($this->url('/products/' . $i['biryani'] . '/edit'))->assertOk()
            ->assertSee('data-ws-full="cost-blocks"', false)->assertSee('/catering/profiles/' . $i['profile'] . '/blocks', false)
            ->assertDontSee('data-ws-full="catering-profile"', false);

        // A product clerk who may not open those screens sees the tabs without links to them.
        $clerk = $this->productClerk();
        $this->business('restaurant');
        $this->actingAs($clerk, 'tenant')->get($this->url('/products/' . $i['dish'] . '/edit'))->assertOk()->assertDontSee('data-ws-full=', false);
        $this->business('catering');
        $this->actingAs($clerk, 'tenant')->get($this->url('/products/' . $i['biryani'] . '/edit'))->assertOk()->assertDontSee('data-ws-full=', false);
    }

    /* ── old addresses ───────────────────────────────────────────────────── */

    public function test_every_old_address_still_opens_for_who_may_open_it_with_the_switch_on_and_off(): void
    {
        $i = self::$ids;
        $old = ['/catering/materials', '/catering/materials/create', '/catering/materials/' . $i['material'] . '/edit',
            '/manufacturing/products', '/manufacturing/products/create', '/manufacturing/products/' . $i['chair'] . '/edit',
            '/recipes', '/recipes/' . $i['recipe'], '/recipes/' . $i['recipe'] . '/edit',
            '/manufacturing/bom', '/manufacturing/bom/' . $i['bom'], '/manufacturing/bom/' . $i['bom'] . '/edit',
            '/catering/profiles', '/catering/profiles/' . $i['profile'] . '/blocks', '/categories?context=manufacturing', '/units?context=manufacturing'];
        $clerk = $this->setupClerk();

        foreach ([self::STORE_FEATURES, []] as $features) {
            $this->setFeatures($features);
            $on = $features !== [];
            foreach ($old as $path) {
                $this->owner()->get($this->url($path))->assertOk();                                   // never a redirect
                $this->actingAs($clerk, 'tenant')->get($this->url($path))->assertOk();
            }
            // Catering Materials and Manufacturing Products Add / Edit: the one form when on, the old one when off.
            $form = (string) $this->actingAs($clerk, 'tenant')->get($this->url('/catering/materials/' . $i['material'] . '/edit'))->getContent();
            $this->assertSame($on, str_contains($form, 'id="pv2-save"'), 'the materials permission opens the ' . ($on ? 'one' : 'old') . ' form');
            $this->assertStringContainsString('PNav Rice', $form, 'with the material\'s own values');
        }
    }

    /* ── fixtures ────────────────────────────────────────────────────────── */

    private function buildSetup(): array
    {
        $dish = $this->product('PNav Burger', $this->piece()->id, ['inventory_consumption_method' => 'recipe', 'product_type' => 'recipe',
            'is_stock_tracked' => false, 'is_purchasable' => false, 'default_selling_price' => 650]);
        $rice = $this->product('PNav Rice', $this->kg()->id, ['product_kind' => Product::KIND_RAW_MATERIAL, 'is_sellable' => false, 'is_pos_visible' => false,
            'can_be_bom_component' => true, 'default_purchase_price' => 200]);
        $recipe = app(RecipeWriter::class)->save(null, ['product_id' => $dish->id, 'name' => 'PNav Burger recipe', 'yield_quantity' => 1,
            'ingredients' => [['product_id' => $rice->id, 'quantity' => 0.2]]], true);

        $chair = $this->product('PNav Chair', $this->piece()->id, ['product_kind' => Product::KIND_FINISHED_GOOD, 'can_be_bom_output' => true]);
        $bom = app(BomWriter::class)->save(null, ['bom_no' => 'PNAV-BOM-1', 'finished_product_id' => $chair->id, 'name' => 'PNav Chair',
            'version' => '1', 'output_quantity' => 1, 'status' => 'active'], [['component_product_id' => $rice->id, 'unit_id' => null, 'quantity' => 2]]);

        $biryani = $this->product('PNav Biryani', $this->kg()->id, ['is_stock_tracked' => false, 'is_purchasable' => false, 'default_selling_price' => 900]);
        app(CateringProfileWriter::class)->save(null, ['product_id' => $biryani->id, 'costing_mode' => 'recipe', 'pricing_mode' => 'fixed',
            'catering_enabled' => true, 'allow_party_supply' => true, 'is_complimentary' => false]);
        app(CateringCostBlockWriter::class)->save($biryani->id, [['label' => 'Making', 'block_type' => 'charge', 'charge_basis' => 'per_unit', 'rate' => 500, 'charge_role' => 'making']], null);

        return ['dish' => $dish->id, 'material' => $rice->id, 'recipe' => ($recipe instanceof Recipe ? $recipe : Recipe::where('product_id', $dish->id)->firstOrFail())->id,
            'chair' => $chair->id, 'bom' => ($bom instanceof ManufacturingBom ? $bom : ManufacturingBom::where('finished_product_id', $chair->id)->firstOrFail())->id,
            'biryani' => $biryani->id, 'profile' => CateringProductProfile::where('product_id', $biryani->id)->firstOrFail()->id];
    }

    /** The menu on a plan with catering, then on the enterprise plan (which carries the Manufacturing menu). */
    private function menus(): array
    {
        $catering = $this->menu($this->owner());
        $this->onPlan('enterprise');
        $factory = $this->menu($this->owner());
        $this->allModules();

        return [$catering, $factory];
    }

    private function assertKept(string $catering, string $factory): void
    {
        foreach ([[$catering, array_merge(self::KEPT_EVERYWHERE, self::KEPT_CATERING)], [$factory, array_merge(self::KEPT_EVERYWHERE, self::KEPT_MANUFACTURING)]] as [$menu, $paths]) {
            foreach ($paths as $path) {
                $this->assertGreaterThanOrEqual(1, $this->linkCount($menu, $path), "{$path} stays in the menu");
            }
        }
    }

    private function onPlan(string $code): void
    {
        $m = DB::connection('master');
        $m->table('subscriptions')->where('tenant_id', $m->table('tenants')->where('tenant_code', self::STORE_CODE)->value('id'))
            ->update(['plan_id' => $m->table('plans')->where('code', $code)->value('id')]);
    }

    /** The sidebar only (from id="sidebar" to the page content), so a dashboard tile is never counted as a menu link. */
    private function menu($as): string
    {
        $html = (string) $as->get($this->url('/dashboard'))->assertOk()->getContent();
        $from = strpos($html, 'id="sidebar"');
        $to = strpos($html, 'class="page-wrapper"', (int) $from);
        $this->assertNotFalse($from, 'the page has the sidebar');

        return substr($html, (int) $from, $to === false ? null : $to - $from);
    }

    /** Links in the page whose address is exactly this path (with the store's host). */
    private function linkCount(string $html, string $path): int
    {
        return preg_match_all('#href="https?://[^"/]+' . preg_quote(htmlspecialchars($path, ENT_QUOTES), '#') . '"#', $html);
    }

    /** A setup clerk: Catering Materials, Catering Products, Cost blocks, Recipes, Manufacturing Products and BOM — no products.*. */
    private function setupClerk(): User
    {
        return $this->roleUser('PNav Setup Clerk', 'setup@pnavtest.test', fn ($q) => $q->where('name', 'like', 'tenant.catering.materials.%')
            ->orWhere('name', 'like', 'tenant.catering.profiles.%')->orWhere('name', 'like', 'tenant.catering.cost-blocks.%')
            ->orWhere('name', 'like', 'tenant.recipes.%')->orWhere('name', 'like', 'tenant.manufacturing.products.%')
            ->orWhere('name', 'like', 'tenant.manufacturing.bom.%')->orWhere('name', 'tenant.categories.index')->orWhere('name', 'tenant.units.index')
            ->orWhere('name', 'tenant.dashboard'));
    }

    /**
     * A product clerk: products.* and the right to change a recipe, a catering profile and cost blocks from the product
     * form (so those tabs show) — but not to open the Recipes, Catering Products or Cost blocks screens themselves.
     */
    private function productClerk(): User
    {
        return $this->roleUser('PNav Product Clerk', 'products@pnavtest.test', fn ($q) => $q->where('name', 'like', 'tenant.products.%')
            ->orWhereIn('name', ['tenant.dashboard', 'tenant.recipes.update', 'tenant.recipes.store', 'tenant.catering.profiles.update',
                'tenant.catering.profiles.store', 'tenant.catering.cost-blocks.update']));
    }

    private function roleUser(string $roleName, string $email, \Closure $permissions): User
    {
        DB::setDefaultConnection('tenant');
        $role = Role::findOrCreate($roleName, 'tenant');
        $role->syncPermissions(DB::connection('tenant')->table('permissions')->where('guard_name', 'tenant')->where($permissions)->pluck('name')->all());
        $user = User::on('tenant')->firstOrCreate(['email' => $email], ['name' => $roleName, 'password' => Hash::make('secret123'), 'status' => 'active']);
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** Every active module in this store's plan (catering, kitchen, manufacturing, purchasing, inventory …). */
    private function allModules(): void
    {
        $m = DB::connection('master');
        $planId = $m->table('plans')->where('code', 'pnav-all')->value('id') ?? $m->table('plans')->insertGetId([
            'code' => 'pnav-all', 'name' => 'Product nav test', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->where('is_active', 1)->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $m->table('subscriptions')->where('tenant_id', $m->table('tenants')->where('tenant_code', self::STORE_CODE)->value('id'))->update(['plan_id' => $planId]);
    }

    private function business(string $b): void
    {
        DB::connection('master')->table('tenants')->where('tenant_code', self::STORE_CODE)->update(['primary_business' => $b]);
        if (app()->bound('tenant')) {
            app('tenant')->primary_business = $b;
        }
    }
}
