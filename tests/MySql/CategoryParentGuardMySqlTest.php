<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATEGORY-PARENT-GUARD-1 — a category can never be put inside itself or inside one of its own sub-categories.
 *
 * 21 Oct 2026: every main category of a live restaurant was set, by hand on the Edit Category screen, to a parent that
 * was its own sub-category. Each one then had a parent, the POS (which shows only top-level tabs) showed none of them,
 * and the counters had no menu. This file keeps that from happening again through the real screen:
 *
 *  1. the parent list leaves out the category and everything under it, and labels every choice with its path;
 *  2. the server refuses such a save even when the list is bypassed, and the POS keeps its tab;
 *  3. ordinary moves still save, and every save records who made it ("Last changed by");
 *  4. a loop already in the data neither hangs the screen nor survives the next save;
 *  5. before the migration has run (the seconds between `git pull` and `migrate`), a save still works.
 */
class CategoryParentGuardMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $main;
    private int $child;
    private int $grandchild;
    private int $other;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'catguard.' . config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedSubscription();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'catguard')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * 1. THE LIST
     * ──────────────────────────────────────────────────────────────────────── */

    public function test_the_parent_list_leaves_out_the_category_and_everything_under_it(): void
    {
        $options = $this->parentOptions($this->main);

        $this->assertArrayNotHasKey($this->main, $options, 'a category must not be offered as its own parent');
        $this->assertArrayNotHasKey($this->child, $options, 'its child must not be offered');
        $this->assertArrayNotHasKey($this->grandchild, $options, 'its grandchild must not be offered');
        $this->assertArrayHasKey($this->other, $options, 'an unrelated main category stays a choice');

        // The child's own list: it may go under the other main category, but never under its own child.
        $childOptions = $this->parentOptions($this->child);
        $this->assertArrayHasKey($this->main, $childOptions, 'the current parent stays selectable');
        $this->assertArrayHasKey($this->other, $childOptions);
        $this->assertArrayNotHasKey($this->grandchild, $childOptions);

        // Every choice shows its full path, so a main category and a sub-category cannot be confused.
        $this->assertSame('Guard Main › Guard Saadi', $this->parentOptions($this->other)[$this->child]);
        $this->assertSame('Guard Main › Guard Saadi › Guard Full', $this->parentOptions($this->other)[$this->grandchild]);
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * 2. THE SERVER CHECK
     * ──────────────────────────────────────────────────────────────────────── */

    public function test_saving_a_main_category_under_its_own_sub_category_is_refused_and_the_pos_keeps_its_tab(): void
    {
        foreach ([$this->child => 'child', $this->grandchild => 'grandchild'] as $parentId => $label) {
            $res = $this->saveCategory($this->main, 'Guard Main', $parentId);

            $res->assertStatus(302)->assertSessionHasErrors('parent_id');
            $message = session('errors')->first('parent_id');
            $this->assertStringContainsString('cannot go inside', $message, "the {$label} must be refused in plain words");
            $this->assertStringContainsString('None (Top Level)', $message, 'the message must say what to do instead');
            $this->assertNull($this->parentOf($this->main), "refusing the {$label} must leave the category at the top");
        }

        $res = $this->saveCategory($this->main, 'Guard Main', $this->main);
        $res->assertStatus(302)->assertSessionHasErrors('parent_id');
        $this->assertStringContainsString('cannot be its own parent', session('errors')->first('parent_id'));
        $this->assertNull($this->parentOf($this->main));

        $this->assertContains($this->main, $this->posTabs(), 'after the refused saves the POS must still show the tab');
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * 3. ORDINARY SAVES + WHO MADE THEM
     * ──────────────────────────────────────────────────────────────────────── */

    public function test_ordinary_moves_still_save_and_record_who_made_them(): void
    {
        $this->saveCategory($this->grandchild, 'Guard Full', $this->other)->assertStatus(302)->assertSessionHasNoErrors();
        $this->assertSame($this->other, $this->parentOf($this->grandchild), 'moving to an unrelated parent must save');

        $this->saveCategory($this->child, 'Guard Saadi', null)->assertStatus(302)->assertSessionHasNoErrors();
        $this->assertNull($this->parentOf($this->child), 'moving to the top level must save');

        $c = DB::connection('tenant');
        $this->assertSame($this->ownerId, (int) $c->table('categories')->where('id', $this->child)->value('updated_by'));
        $this->assertNull($c->table('categories')->where('id', $this->main)->value('updated_by'), 'an unsaved row stays blank');

        $page = $this->editPage($this->child);
        $this->assertStringContainsString('Last changed by Guard Owner,', $page);
        $this->assertStringNotContainsString('category-last-changed', $this->editPage($this->main),
            'a category nobody has saved since the change shows no line');

        $this->actingAs($this->owner(), 'tenant')->post('http://' . $this->host . '/categories', [
            'code' => 'GNEW', 'name' => 'Guard New', 'parent_id' => $this->other, 'sort_order' => 0, 'is_active' => 1,
        ])->assertStatus(302)->assertSessionHasNoErrors();
        $new = $c->table('categories')->where('code', 'GNEW')->first();
        $this->assertSame($this->other, (int) $new->parent_id, 'a new category may still be created under a parent');
        $this->assertSame($this->ownerId, (int) $new->updated_by, 'create records who made it too');
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * 4. A LOOP ALREADY IN THE DATA
     * ──────────────────────────────────────────────────────────────────────── */

    public function test_a_loop_already_in_the_data_neither_hangs_the_screen_nor_survives_the_next_save(): void
    {
        // The state the restaurant was in: the main category inside its own sub-category.
        DB::connection('tenant')->table('categories')->where('id', $this->main)->update(['parent_id' => $this->child]);
        $this->assertNotContains($this->main, $this->posTabs(), 'precondition: the loop hides the tab, as it did live');

        $options = $this->parentOptions($this->main);
        $this->assertArrayNotHasKey($this->child, $options, 'the bad parent is not offered back');

        $this->saveCategory($this->main, 'Guard Main', $this->child)->assertSessionHasErrors('parent_id');
        $this->saveCategory($this->main, 'Guard Main', null)->assertSessionHasNoErrors();

        $this->assertNull($this->parentOf($this->main));
        $this->assertContains($this->main, $this->posTabs(), 'one save on the screen puts the tab back');
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * 5. BEFORE THE MIGRATION HAS RUN
     * ──────────────────────────────────────────────────────────────────────── */

    public function test_a_save_still_works_before_the_new_column_exists(): void
    {
        $schema = Schema::connection('tenant');
        $schema->table('categories', fn (Blueprint $t) => $t->dropColumn('updated_by'));

        try {
            $this->saveCategory($this->grandchild, 'Guard Full Renamed', $this->other)
                ->assertStatus(302)->assertSessionHasNoErrors();
            $this->assertSame('Guard Full Renamed',
                DB::connection('tenant')->table('categories')->where('id', $this->grandchild)->value('name'));
            $this->assertStringNotContainsString('category-last-changed', $this->editPage($this->grandchild));
        } finally {
            if (! $schema->hasColumn('categories', 'updated_by')) {
                $schema->table('categories', fn (Blueprint $t) => $t->unsignedBigInteger('updated_by')->nullable()->after('is_active'));
            }
        }
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function owner(): User
    {
        return User::on('tenant')->find($this->ownerId);
    }

    private function editPage(int $categoryId): string
    {
        $res = $this->actingAs($this->owner(), 'tenant')->get('http://' . $this->host . '/categories/' . $categoryId . '/edit');
        $this->assertSame(200, $res->getStatusCode(), 'the edit screen must load; got ' . $res->getStatusCode() . ' — '
            . Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($res->getContent()))), 300));

        return $res->getContent();
    }

    /** @return array<int, string> option value => text, from the real Parent Category box */
    private function parentOptions(int $categoryId): array
    {
        $html = $this->editPage($categoryId);
        $this->assertSame(1, preg_match('#<select id="parent_id"[^>]*>(.*?)</select>#s', $html, $m),
            'the edit screen must have the Parent Category box, or this test proves nothing');
        preg_match_all('#<option value="(\d+)"[^>]*>\s*(.*?)\s*</option>#s', $m[1], $opts, PREG_SET_ORDER);
        $this->assertNotEmpty($opts, 'the parent box must list something');

        $out = [];
        foreach ($opts as [, $id, $text]) {
            $out[(int) $id] = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        }

        return $out;
    }

    private function saveCategory(int $categoryId, string $name, ?int $parentId)
    {
        $row = DB::connection('tenant')->table('categories')->where('id', $categoryId)->first();

        return $this->actingAs($this->owner(), 'tenant')
            ->from('http://' . $this->host . '/categories/' . $categoryId . '/edit')
            ->put('http://' . $this->host . '/categories/' . $categoryId, [
                'code' => $row->code, 'name' => $name, 'parent_id' => $parentId, 'sort_order' => 0, 'is_active' => 1,
            ]);
    }

    private function parentOf(int $categoryId): ?int
    {
        $p = DB::connection('tenant')->table('categories')->where('id', $categoryId)->value('parent_id');

        return $p === null ? null : (int) $p;
    }

    /** @return int[] the category tabs the POS screen hands the cashier */
    private function posTabs(): array
    {
        $res = $this->actingAs($this->owner(), 'tenant')->get('http://' . $this->host . '/pos?branch_id=' . $this->branchId);
        $this->assertSame(200, $res->getStatusCode(), 'the POS screen must load');
        $this->assertSame(1, preg_match('/const categories\s*=\s*(\[.*?\]);\s*$/m', $res->getContent(), $m),
            'the POS page must embed its categories, or this test proves nothing');

        return array_map(fn ($c) => (int) $c['id'], json_decode($m[1], true));
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'catguard')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'catguard', 'business_name' => 'Cat Guard',
            'owner_name' => 'Owner', 'owner_email' => 'owner@catguard.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'catguard-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'catguard-plan', 'name' => 'Cat Guard', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        $moduleIds = [];
        foreach (['tenant.pos.index', 'tenant.categories.create', 'tenant.categories.store', 'tenant.categories.edit', 'tenant.categories.update'] as $route) {
            $key = $m->table('route_catalogs')->where('route_name', $route)->value('module_key');
            if ($key) {
                $module = Module::forRouteModuleKey($key)->first();
                $this->assertNotNull($module, "route [{$route}] maps to [{$key}] but no module claims it");
                $moduleIds[$module->id] = true;
            }
        }
        foreach (array_keys($moduleIds) as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenant(): void
    {
        // permissions/roles come from a tenant MIGRATION — never truncate them.
        $this->cleanTenant(['category_translations', 'model_has_roles', 'users', 'products', 'categories', 'branches']);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        foreach (['tenant.pos.index', 'tenant.categories.create', 'tenant.categories.store', 'tenant.categories.edit', 'tenant.categories.update'] as $perm) {
            $c->table('permissions')->updateOrInsert(['name' => $perm, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'Guard Owner', 'email' => 'owner@catguard.test', 'password' => bcrypt('x'),
            'employee_code' => 'GUARDOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->branchId = $this->makeBranch(['name' => 'Guard Branch']);

        // The live shape: a main tab, its sub-category, one level deeper, and an unrelated main tab.
        $this->main = $this->makeCategory(['name' => 'Guard Main', 'code' => 'GMAIN', 'is_active' => 1]);
        $this->child = $this->makeCategory(['name' => 'Guard Saadi', 'code' => 'GSAADI', 'parent_id' => $this->main, 'is_active' => 1]);
        $this->grandchild = $this->makeCategory(['name' => 'Guard Full', 'code' => 'GFULL', 'parent_id' => $this->child, 'is_active' => 1]);
        $this->other = $this->makeCategory(['name' => 'Guard Desserts', 'code' => 'GDESS', 'is_active' => 1]);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
