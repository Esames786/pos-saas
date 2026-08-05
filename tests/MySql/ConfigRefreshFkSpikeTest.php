<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * §7 — FK / config-refresh spike (MYSQL-TEST-FOUNDATION-1).
 *
 * PURPOSE: prove, against the REAL tenant schema on MySQL, whether Contract A's
 * proposed "transactional full DELETE + INSERT" config refresh is safe.
 *
 * VERDICT (encoded as assertions below): it is NOT. Config tables (products, users,
 * printers, tables, ...) are referenced by operational/history rows with FK delete
 * rules that either REJECT the delete (RESTRICT / NO ACTION) or DESTROY / ORPHAN
 * history (CASCADE / SET NULL). A blind full-set delete during refresh therefore
 * loses or corrupts operational history. The safe pattern is UPSERT-existing-id +
 * tombstone-missing, which preserves every historical reference.
 *
 * This test is design-verification / test-infrastructure only. It does not implement
 * Edge config refresh.
 */
class ConfigRefreshFkSpikeTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    /** The census that makes the danger concrete: config tables are heavily referenced. */
    public function test_config_tables_are_referenced_with_history_destroying_delete_rules(): void
    {
        $configTables = ['products','product_variants','users','terminals','printers',
                         'restaurant_tables','restaurant_waiters','categories','void_reasons','payment_methods'];

        $placeholders = implode(',', array_fill(0, count($configTables), '?'));
        $rows = DB::connection('tenant')->select("
            SELECT k.TABLE_NAME AS child_table, k.COLUMN_NAME AS child_col,
                   k.REFERENCED_TABLE_NAME AS parent_table, r.DELETE_RULE AS delete_rule
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON k.CONSTRAINT_NAME = r.CONSTRAINT_NAME AND k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
            WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IN ($placeholders)
        ", array_merge([$this->tenantDb], $configTables));

        $byRule = [];
        foreach ($rows as $r) {
            $byRule[$r->delete_rule] = ($byRule[$r->delete_rule] ?? 0) + 1;
        }

        // There is at least one destructive/blocking rule of each dangerous kind —
        // so a blind DELETE of a config table is never safe in general.
        $cascade = $byRule['CASCADE'] ?? 0;
        $setNull = $byRule['SET NULL'] ?? 0;
        $restrict = ($byRule['RESTRICT'] ?? 0) + ($byRule['NO ACTION'] ?? 0);

        $this->assertGreaterThan(0, $cascade, 'Expected CASCADE FKs onto config tables (history would be deleted).');
        $this->assertGreaterThan(0, $setNull, 'Expected SET NULL FKs onto config tables (history would be orphaned).');
        $this->assertGreaterThan(0, $restrict, 'Expected RESTRICT/NO ACTION FKs onto config tables (delete would be rejected).');
    }

    /** Concrete: a blind DELETE of a config table CASCADE-destroys referencing history. */
    public function test_blind_delete_of_products_cascades_and_destroys_history(): void
    {
        $this->cleanTenant(['sales_order_lines','sales_orders','products','categories','branches']);

        $branchId = $this->makeBranch();
        $catId    = $this->makeCategory();
        $prodId   = $this->makeProduct($catId);
        $saleId   = $this->makeSale($branchId);
        $this->makeSaleLine($saleId, $prodId, ['product_name' => 'Burger', 'quantity' => 2]);

        $this->assertSame(1, DB::connection('tenant')->table('sales_order_lines')->count());

        // Contract A step: blindly clear the config table before re-inserting the new revision.
        DB::connection('tenant')->table('products')->delete();

        // The historical sale line was CASCADE-deleted — operational history is gone.
        $this->assertSame(
            0,
            DB::connection('tenant')->table('sales_order_lines')->count(),
            'CASCADE deleted the historical sale line — Contract A blind DELETE is unsafe.'
        );
    }

    /** The safe pattern: UPSERT the existing id + tombstone; history references stay intact. */
    public function test_upsert_and_tombstone_preserves_history(): void
    {
        $this->cleanTenant(['sales_order_lines','sales_orders','products','categories','branches']);

        $branchId = $this->makeBranch();
        $catId    = $this->makeCategory();
        $prodId   = $this->makeProduct($catId, ['status' => 'active']);
        $saleId   = $this->makeSale($branchId);
        $this->makeSaleLine($saleId, $prodId);

        // New revision: the product was removed upstream. Instead of DELETE, tombstone it.
        DB::connection('tenant')->table('products')->where('id', $prodId)->update([
            'name' => 'Renamed in new revision',
            'status' => 'inactive',
            'updated_at' => now(),
        ]);

        // Same id, updated contents, and the historical line still resolves to it.
        $this->assertSame(1, DB::connection('tenant')->table('sales_order_lines')
            ->where('product_id', $prodId)->count());
        $this->assertSame('inactive', DB::connection('tenant')->table('products')
            ->where('id', $prodId)->value('status'));
    }
}
