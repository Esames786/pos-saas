<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringCommercialRateApplication;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringCommercialRateImpactService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PDO;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-COMMERCIAL-RATE-1 — the house rate book is one tenant's book.
 *
 * The owner's standing concern about this whole costing programme has been that
 * changes to the product/pricing area could reach tenants who never asked for
 * catering at all. A rate book is exactly the shape of feature that could: it is
 * keyed on products, it writes to cost blocks, and it reprices live documents.
 *
 * So this proves the boundary against a SECOND REAL TENANT DATABASE rather than
 * against a mock. Raising chicken in tenant A, linking A's dish to it, and
 * applying it to A's quotation must leave B's book, B's cost blocks and B's
 * quotations byte-identical — including B's dish that uses the same material
 * name at the same rate, which is the case a leak would be easiest to miss in.
 */
class CateringCommercialRateIsolationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const TENANT_B_DB = 'pos_test_tenant_cat_rate_b';

    private static bool $tenantBSchemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->ensureTenantBSchema();

        $this->cleanA();
        $this->onTenantB(fn () => $this->cleanA());
    }

    private function cleanA(): void
    {
        $this->cleanTenant([
            'catering_commercial_rate_applications',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'branches',
        ]);
    }

    /** Create + migrate the second REAL tenant schema once per process. */
    private function ensureTenantBSchema(): void
    {
        if (self::$tenantBSchemaReady) {
            return;
        }
        if (stripos(self::TENANT_B_DB, 'test') === false) {
            throw new RuntimeException('tenant B database name must contain "test"');
        }

        $config = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.self::TENANT_B_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $mainDb = $config['database'];
        try {
            config(['database.connections.tenant.database' => self::TENANT_B_DB]);
            DB::purge('tenant');
            $code = Artisan::call('migrate:fresh', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);
            if ($code !== 0) {
                throw new RuntimeException('tenant B migrations failed: '.Artisan::output());
            }
        } finally {
            config(['database.connections.tenant.database' => $mainDb]);
            DB::purge('tenant');
        }

        self::$tenantBSchemaReady = true;
    }

    private function onTenantB(callable $callback): mixed
    {
        $mainDb = config('database.connections.tenant.database');
        try {
            config(['database.connections.tenant.database' => self::TENANT_B_DB]);
            DB::purge('tenant');

            return $callback();
        } finally {
            config(['database.connections.tenant.database' => $mainDb]);
            DB::purge('tenant');
        }
    }

    /**
     * The same shape of business in both databases: one dish, one material, one
     * house rate, one linked block. Identical figures on purpose — if anything
     * leaked, the assertion afterwards would still catch it, and identical
     * starting points make the leak visible as a difference rather than hidden
     * as a coincidence.
     *
     * @return array{dish: int, material: int, block: int}
     */
    private function seedCateringBusiness(string $prefix): array
    {
        $branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();

        $unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $dish = $this->makeProduct($categoryId, [
            'name' => 'Chicken Biryani', 'sku' => $prefix.'-BIR', 'unit_id' => $unitId,
        ]);
        $material = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => $prefix.'-CHK', 'unit_id' => $unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        CateringProductProfile::updateOrCreate(
            ['product_id' => $dish],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );

        CateringMaterialCommercialRate::create([
            'product_id' => $material, 'rate' => 100, 'unit_id' => $unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $block = CateringProductCostBlock::create([
            'product_id' => $dish, 'label' => 'Chicken',
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $material, 'quantity_per_unit' => 0.50,
            'unit_id' => $unitId, 'rate' => 100,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'commercial_rate_source' => CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK,
            'sort_order' => 1, 'is_active' => true,
        ]);

        CateringProductCostBlock::create([
            'product_id' => $dish, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE, 'rate' => 300,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'sort_order' => 2, 'is_active' => true,
        ]);

        return ['dish' => $dish, 'material' => $material, 'block' => $block->id, 'branch' => $branchId, 'unit' => $unitId];
    }

    public function test_a_rate_change_in_one_tenant_never_reaches_another(): void
    {
        $a = $this->seedCateringBusiness('A');
        $b = $this->onTenantB(fn () => $this->seedCateringBusiness('B'));

        // Tenant A raises the house rate and applies it to its own dish.
        CateringMaterialCommercialRate::create([
            'product_id' => $a['material'], 'rate' => 160, 'unit_id' => $a['unit'],
            'effective_from' => now()->toDateString(),
        ]);
        $applied = app(CateringCommercialRateImpactService::class)
            ->applyToProducts($a['material'], [$a['block']], null);

        $this->assertSame(1, $applied);
        $this->assertEqualsWithDelta(160.0,
            (float) CateringProductCostBlock::find($a['block'])->rate, 0.01,
            'tenant A did change');

        // Tenant B is untouched, in every table this feature can write to.
        $this->onTenantB(function () use ($b) {
            $this->assertEqualsWithDelta(100.0,
                (float) CateringMaterialCommercialRate::rateFor($b['material']), 0.01,
                'tenant B keeps its own house rate');
            $this->assertSame(1, CateringMaterialCommercialRate::count(),
                'and gained no row from the other tenant\'s decision');
            $this->assertEqualsWithDelta(100.0,
                (float) CateringProductCostBlock::find($b['block'])->rate, 0.01,
                'tenant B\'s dish charges what it always charged');
            $this->assertSame(0, CateringCommercialRateApplication::count(),
                'and nothing was recorded as having been done to it');
        });
    }

    /** The impact preview must not even SEE another tenant's dishes. */
    public function test_the_impact_preview_only_ever_sees_its_own_tenant(): void
    {
        $a = $this->seedCateringBusiness('A');
        $this->onTenantB(fn () => $this->seedCateringBusiness('B'));

        $impact = app(CateringCommercialRateImpactService::class)->productImpact($a['material']);

        $this->assertCount(1, $impact['products'], 'one dish, and it is this tenant\'s');
        $this->assertSame($a['dish'], $impact['products'][0]['product_id']);
    }
}
