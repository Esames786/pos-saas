<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductPackUnit;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Services\Catalog\BarcodeLookupService;
use App\Services\Central\TenantBusinessService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\PackUnits;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDO;
use Spatie\Permission\PermissionRegistrar;

/**
 * PHARMACY-PACKS-1 — "1 Box = 10 Strip, 1 Strip = 10 Tablet" on the new product screen, through the real
 * routes on a provisioned tenant.
 *
 * The promises: packs appear only where Master switched them on; the server builds the sizes from the chain
 * and refuses anything wrong in plain words; editing shows and keeps the same packs; a pack already on a
 * bill is never removed or resized; a pack's barcode stays away from every screen that does not understand
 * packs yet (one scan, one meaning); and the line columns for the next steps are empty, nullable and
 * unchained.
 */
class PackUnitsMySqlTest extends MySqlTenantTestCase
{
    private const CODE = 'pk1test';

    private static bool $built = false;

    private string $host;
    private string $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = self::CODE . '.' . config('tenancy.tenant_base_domain');
        $this->db = $this->tenantDb . '_pk1';

        if (! self::$built) {
            $this->freshDatabase();
            DB::setDefaultConnection('master');
            Artisan::call('migrate', ['--database' => 'master', '--force' => true]);
            (new \Database\Seeders\MasterSeeder)->run();
            Artisan::call('system:routes-sync');
            $this->masterTenant();
            $this->useDb();
            $tenant = Tenant::on('master')->where('tenant_code', self::CODE)->firstOrFail();
            app()->instance('tenant', $tenant);
            DB::setDefaultConnection('tenant');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            (new \ReflectionMethod(TenantProvisioner::class, 'seedTenantBaseData'))->invoke(app(TenantProvisioner::class), $tenant, 'secret123');
            Unit::updateOrCreate(['code' => 'TAB'], ['name' => 'Tablet', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
            Unit::updateOrCreate(['code' => 'KGX'], ['name' => 'Kilo', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
            self::$built = true;
        }
        $this->useDb();
        $this->setTenant(['product_screen_v2' => true, 'packs' => true]);
    }

    // ── the schema ────────────────────────────────────────────────────────────────────────────────

    public function test_the_schema_only_adds_and_the_line_columns_are_empty_nullable_and_unchained(): void
    {
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $this->assertTrue($schema->hasTable('product_pack_units'));
        foreach (PackUnits::LINE_TABLES as $table) {
            foreach (['pack_unit_id', 'pack_factor', 'base_quantity'] as $col) {
                $c = DB::connection('tenant')->selectOne('SELECT IS_NULLABLE n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $col]);
                $this->assertNotNull($c, "{$table}.{$col} exists");
                $this->assertSame('YES', $c->n, "{$table}.{$col} is nullable — every old line stays as it is");
            }
            $fk = DB::connection('tenant')->selectOne('SELECT COUNT(*) n FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$table, 'pack_unit_id']);
            $this->assertSame(0, (int) $fk->n, "{$table}.pack_unit_id has no foreign key (a big table must never be locked to add one)");
        }
    }

    // ── where it appears ──────────────────────────────────────────────────────────────────────────

    public function test_packs_appear_only_where_master_switched_them_on(): void
    {
        $this->setTenant(['product_screen_v2' => true, 'packs' => false]);
        $off = $this->page('/products/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="packs-step"', $off);
        $this->assertStringContainsString('4. Code and barcode', $off, 'without packs the screen is exactly as before');

        $this->setTenant(['product_screen_v2' => true, 'packs' => true]);
        $on = $this->page('/products/create')->assertOk()->getContent();
        $this->assertStringContainsString('id="packs-step"', $on);
        $this->assertStringContainsString('Does it come in packs?', $on);
        $this->assertStringContainsString('Tablet → Strip → Box', $on, 'the pharmacy quick start');
        $this->assertStringContainsString('5. Code and barcode', $on);
    }

    // ── what a save writes ────────────────────────────────────────────────────────────────────────

    public function test_a_medicine_saves_its_strip_and_box_and_counts_stock_in_tablets(): void
    {
        $this->postAs('/products', $this->medicine('Panacet 500', [
            ['name' => 'Strip', 'contains' => '10', 'price' => '', 'barcode' => '', 'sell' => '1', 'buy' => '0'],
            ['name' => 'Box', 'contains' => '10', 'price' => '480', 'barcode' => '2001234567893', 'sell' => '1', 'buy' => '1'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $p = Product::where('name', 'Panacet 500')->firstOrFail();
        $packs = $p->packUnits()->get();
        $this->assertSame([1, 10, 100], $packs->pluck('factor')->all());
        $this->assertSame(['Tablet', 'Strip', 'Box'], $packs->pluck('name')->all(), 'the stock unit is named after "Sold and counted in"');
        [$base, $strip, $box] = $packs->all();
        $this->assertTrue($base->can_sell && $base->can_buy);
        $this->assertNull($strip->sale_price, 'empty price = size × the tablet price');
        $this->assertSame(50.0, $strip->priceFor($p));
        $this->assertSame('480.00', (string) $box->sale_price);
        $this->assertSame('2001234567893', $box->barcode);
        $this->assertFalse($strip->can_buy);
        $this->assertSame('4 Box 3 Strip 5 Tablet', PackUnits::describe($packs, 435, 'Tablet'));
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->where('product_id', $p->id)->count(), 'packs never move stock');

        $show = $this->page('/products/' . $p->id)->assertOk()->getContent();
        $this->assertStringContainsString('<strong>Packs</strong>', $show);
        $this->assertStringContainsString('100 Tablet', $show);
    }

    public function test_edit_shows_the_same_packs_and_keeps_their_ids(): void
    {
        $p = $this->saved('Edit Me 250', [['Strip', '10'], ['Box', '20']]);
        $boxId = $p->packUnits()->where('factor', 200)->value('id');

        $html = $this->page('/products/' . $p->id . '/edit')->assertOk()->getContent();
        $this->assertStringContainsString('name="packs[1][name]"', $html);
        $this->assertMatchesRegularExpression('/name="packs\[1\]\[contains\]"[^>]*value="20"/', $html, 'the box shows as 20 strips, as it was typed');

        // Rename the box, give it a price, and drop the strip: the box now holds 200 tablets directly.
        $this->putAs('/products/' . $p->id, $this->medicine('Edit Me 250', [
            ['id' => (string) $boxId, 'name' => 'Dabba', 'contains' => '200', 'price' => '1,050', 'barcode' => '', 'sell' => '1', 'buy' => '1'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $packs = $p->fresh()->packUnits()->get();
        $this->assertSame([1, 200], $packs->pluck('factor')->all());
        $this->assertSame($boxId, $packs->last()->id, 'the same pack row, renamed — not a new one');
        $this->assertSame('Dabba', $packs->last()->name);
        $this->assertSame('1050.00', (string) $packs->last()->sale_price);
    }

    public function test_answering_no_removes_the_packs_but_a_switched_off_tenant_keeps_them(): void
    {
        $p = $this->saved('Keep Or Drop', [['Strip', '10']]);

        $this->setTenant(['product_screen_v2' => true, 'packs' => false]);
        $this->putAs('/products/' . $p->id, $this->medicine('Keep Or Drop', null) + ['_packs' => '0'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $p->packUnits()->count(), 'switch off: nothing about packs is read, saved packs stay');

        $this->setTenant(['product_screen_v2' => true, 'packs' => true]);
        $this->putAs('/products/' . $p->id, $this->medicine('Keep Or Drop', null) + ['_packs' => '0'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, $p->packUnits()->count(), '"No" on the screen = no packs');
    }

    // ── what is refused ───────────────────────────────────────────────────────────────────────────

    public function test_wrong_packs_are_refused_in_plain_words_and_nothing_is_saved(): void
    {
        $other = $this->saved('Other Brand', [['Box', '30']]);
        $otherBox = $other->packUnits()->where('factor', 30)->value('barcode');
        $row = fn ($over) => array_merge(['name' => 'Strip', 'contains' => '10', 'price' => '', 'barcode' => '', 'sell' => '1', 'buy' => '1'], $over);

        $cases = [
            'packs.0.contains' => [$row(['contains' => '1'])],
            'packs.1.contains' => [$row([]), $row(['name' => 'Box', 'contains' => '2.5'])],
            'packs.1.name'     => [$row([]), $row(['name' => 'strip'])],
            'packs.0.price'    => [$row(['price' => 'ten'])],
            'packs.0.barcode'  => [$row(['barcode' => $otherBox])],
        ];
        foreach ($cases as $key => $packs) {
            $this->postAs('/products', $this->medicine('Refused ' . $key, $packs))->assertRedirect()->assertSessionHasErrors($key);
            $this->assertFalse(Product::where('name', 'Refused ' . $key)->exists(), "{$key}: the product is not half-saved");
        }

        // The box barcode cannot also be the single tablet's barcode on the same screen.
        $this->postAs('/products', $this->medicine('Same Code', [$row(['barcode' => '2009999999994'])]) + ['_barcode' => '1', 'barcode' => '2009999999994'])
            ->assertSessionHasErrors('packs.0.barcode');
        // No pack rows at all with "Yes", four packs, a kilo item.
        $this->postAs('/products', $this->medicine('No Rows', []))->assertSessionHasErrors('_packs');
        $this->postAs('/products', $this->medicine('Four', [$row([]), $row(['name' => 'Box']), $row(['name' => 'Carton']), $row(['name' => 'Pallet'])]))->assertSessionHasErrors('_packs');
        $this->postAs('/products', ['unit_id' => (string) Unit::where('code', 'KGX')->value('id')] + $this->medicine('Kilo Packs', [$row([])]))->assertSessionHasErrors('_packs');
    }

    public function test_a_pack_already_on_a_bill_is_never_removed_or_resized(): void
    {
        $p = $this->saved('On A Bill', [['Strip', '10'], ['Box', '10']]);
        $strip = $p->packUnits()->where('factor', 10)->firstOrFail();
        $box = $p->packUnits()->where('factor', 100)->firstOrFail();
        $line = $this->bareLine('stock_adjustment_lines', ['product_id' => $p->id, 'quantity' => 10, 'pack_unit_id' => $strip->id, 'pack_factor' => 10, 'base_quantity' => 10]);

        try {
            // Remove the strip.
            $this->putAs('/products/' . $p->id, $this->medicine('On A Bill', [
                ['id' => (string) $box->id, 'name' => 'Box', 'contains' => '100', 'price' => '', 'barcode' => '', 'sell' => '1', 'buy' => '1'],
            ]))->assertSessionHasErrors('_packs');
            // Resize it.
            $this->putAs('/products/' . $p->id, $this->medicine('On A Bill', [
                ['id' => (string) $strip->id, 'name' => 'Strip', 'contains' => '12', 'price' => '', 'barcode' => '', 'sell' => '1', 'buy' => '1'],
                ['id' => (string) $box->id, 'name' => 'Box', 'contains' => '10', 'price' => '', 'barcode' => '', 'sell' => '1', 'buy' => '1'],
            ]))->assertSessionHasErrors('_packs');
            $this->assertSame([1, 10, 100], $p->fresh()->packUnits()->pluck('factor')->all());

            // A new name and price are fine: they do not change what the old line meant.
            $this->putAs('/products/' . $p->id, $this->medicine('On A Bill', [
                ['id' => (string) $strip->id, 'name' => 'Patta', 'contains' => '10', 'price' => '55', 'barcode' => '', 'sell' => '1', 'buy' => '0'],
                ['id' => (string) $box->id, 'name' => 'Box', 'contains' => '10', 'price' => '', 'barcode' => '', 'sell' => '1', 'buy' => '1'],
            ]))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('Patta', $strip->fresh()->name);
        } finally {
            DB::connection('tenant')->table('stock_adjustment_lines')->where('id', $line)->delete();
        }
    }

    // ── one scan, one meaning ─────────────────────────────────────────────────────────────────────

    public function test_a_pack_barcode_stays_away_from_screens_that_do_not_know_packs_yet(): void
    {
        $p = $this->saved('Scan Safe', [['Box', '20']]);
        $code = $p->packUnits()->where('factor', 20)->value('barcode');
        $this->assertNotEmpty($code);

        $branchId = (int) DB::connection('tenant')->table('branches')->orderBy('id')->value('id');
        $found = app(BarcodeLookupService::class)->lookup(code: $code, branchId: $branchId);
        $this->assertFalse($found['found'], 'the catalog lookup does not sell ONE tablet for a scanned box');

        $pos = $this->page('/pos')->assertOk()->getContent();
        $this->assertStringContainsString('Scan Safe', $pos, 'the item IS on the counter page, so the next check means something');
        $this->assertStringNotContainsString($code, $pos, 'the counter does not carry the box barcode yet');

        // The old "Add Barcode" panel and the screen's own single barcode refuse it.
        $other = $this->saved('Other Item', []);
        $this->postAs('/products/' . $other->id . '/barcodes', ['barcode' => $code, 'barcode_type' => 'manual'])->assertSessionHasErrors('barcode');
        $this->postAs('/products', $this->medicine('Steals Code', null) + ['_packs' => '0', '_barcode' => '1', 'barcode' => $code])->assertSessionHasErrors('barcode');
        $this->assertSame(0, DB::connection('tenant')->table('product_barcodes')->where('barcode', $code)->count());
    }

    // ── Master ────────────────────────────────────────────────────────────────────────────────────

    public function test_master_refuses_packs_without_the_new_product_screen_and_records_the_change(): void
    {
        $tenant = Tenant::on('master')->where('tenant_code', self::CODE)->firstOrFail();
        $svc = app(TenantBusinessService::class);
        $this->setTenant([]);
        try {
            $svc->setFeatures($tenant->fresh(), ['product_screen_v2' => false, 'packs' => true], null);
            $this->fail('packs alone must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('needs "New product screen"', $e->errors()['features'][0]);
        }
        $this->assertSame(2, $svc->setFeatures($tenant->fresh(), ['product_screen_v2' => true, 'packs' => true], null));
        $this->assertTrue((bool) ($tenant->fresh()->features['packs'] ?? false));
        $this->assertSame(1, DB::connection('master')->table('tenant_setting_changes')->where('tenant_id', $tenant->id)->where('setting', 'feature:packs')->count());
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────────

    /** A medicine form; $packs = null leaves the packs question out, [] answers "Yes" with no rows. */
    private function medicine(string $name, ?array $packs): array
    {
        $form = ['_form_v2' => '1', '_card' => 'medicine', 'status' => 'active', 'sku' => '', 'category_id' => '', '_tax' => '0',
            'unit_id' => (string) Unit::where('code', 'TAB')->value('id'), 'name' => $name, 'default_selling_price' => '5', '_buy' => '1', '_barcode' => '0'];
        if ($packs !== null) {
            $form += ['_packs' => '1', 'pack_base_sell' => '1', 'packs' => $packs];
        }

        return $form;
    }

    /** @param list<array{0:string,1:string}> $chain  [name, contains] */
    private function saved(string $name, array $chain): Product
    {
        $i = 0;
        $packs = array_map(function ($c) use (&$i) {
            $code = '20' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);

            return ['name' => $c[0], 'contains' => $c[1], 'price' => '', 'barcode' => $code . $this->checkDigit($code), 'sell' => '1', 'buy' => '1'];
        }, $chain);
        $form = $chain ? $this->medicine($name, $packs) : $this->medicine($name, null) + ['_packs' => '0'];
        $this->postAs('/products', $form)->assertRedirect()->assertSessionHasNoErrors();

        return Product::where('name', $name)->firstOrFail();
    }

    private function checkDigit(string $d12): int
    {
        $s = 0;
        for ($i = 0; $i < 12; $i++) {
            $s += (int) $d12[$i] * ($i % 2 ? 3 : 1);
        }

        return (10 - $s % 10) % 10;
    }

    /** A real row in a line table, with only the columns it requires (foreign keys off: test DB only). */
    private function bareLine(string $table, array $values): int
    {
        $c = DB::connection('tenant');
        $required = $c->select('SELECT COLUMN_NAME n, DATA_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_NULLABLE = "NO" AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE "%auto_increment%"', [$table]);
        foreach ($required as $col) {
            $values[$col->n] ??= in_array($col->t, ['varchar', 'char', 'text'], true) ? 'x' : (in_array($col->t, ['timestamp', 'datetime', 'date'], true) ? now() : 999999);
        }
        $c->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            return (int) $c->table($table)->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
        } finally {
            $c->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function page(string $uri)
    {
        return $this->asOwner()->call('GET', 'http://' . $this->host . $uri);
    }

    private function postAs(string $uri, array $data = [])
    {
        return $this->asOwner()->call('POST', 'http://' . $this->host . $uri, $data);
    }

    private function putAs(string $uri, array $data = [])
    {
        return $this->asOwner()->call('PUT', 'http://' . $this->host . $uri, $data);
    }

    private function asOwner()
    {
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->where('email', 'owner@pk1test.test')->firstOrFail(), 'tenant');
    }

    private function setTenant(array $features): void
    {
        DB::connection('master')->table('tenants')->where('tenant_code', self::CODE)->update([
            'primary_business' => 'pharmacy', 'features' => json_encode($features),
        ]);
    }

    private function useDb(): void
    {
        config(['database.connections.tenant.database' => $this->db]);
        DB::purge('tenant');
    }

    private function freshDatabase(): void
    {
        if (stripos($this->db, 'test') === false) {
            throw new \RuntimeException('test database name must contain "test"');
        }
        $c = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']}", $c['username'], $c['password'] ?? '');
        $pdo->exec('DROP DATABASE IF EXISTS `' . $this->db . '`');
        $pdo->exec('CREATE DATABASE `' . $this->db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->useDb();
        if (Artisan::call('migrate:fresh', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]) !== 0) {
            throw new \RuntimeException('migrations failed: ' . Artisan::output());
        }
    }

    private function masterTenant(): void
    {
        $m = DB::connection('master');
        if ($old = $m->table('tenants')->where('tenant_code', self::CODE)->value('id')) {
            foreach (['tenant_domains', 'tenant_databases', 'subscriptions', 'tenant_setting_changes'] as $t) {
                $m->table($t)->where('tenant_id', $old)->delete();
            }
            $m->table('tenants')->where('id', $old)->delete();
        }
        // Another test may wipe tenants with foreign keys off, leaving this host's domain behind.
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenant_databases')->where('db_database', $this->db)->delete();
        $id = $m->table('tenants')->insertGetId([
            'tenant_code' => self::CODE, 'business_name' => 'Packs Test', 'owner_name' => 'Owner', 'owner_email' => 'owner@pk1test.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $id, 'db_connection' => 'tenant', 'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'), 'db_database' => $this->db,
            'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert(['tenant_id' => $id, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $m->table('subscriptions')->insert([
            'tenant_id' => $id, 'plan_id' => $m->table('plans')->where('code', 'enterprise')->value('id'), 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
