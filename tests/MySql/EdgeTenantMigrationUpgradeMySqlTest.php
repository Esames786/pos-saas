<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Services\Edge\EdgeLocalSchemaUpgrader;
use App\Services\Edge\EdgeTenantMigrationManifest;
use App\Services\Edge\MigrationSubstitutes\OrderNoteToggleDefaultOnly;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OWNER DECISION D7 — the Edge UPGRADE TESTS named by the manifest for every post-baseline tenant migration that SHIPS
 * AND RUNS on the appliance (EDGE_REQUIRED / EDGE_SCHEMA_SAFE). Each test:
 *
 *   1. is SKIPPED (not passed) while the canonical file has not been merged yet (PENDING_MERGE_FROM 99e9365) — the
 *      manifest CI gate only requires the test to EXIST; it activates the moment the merge lands the file;
 *   2. makes the appliance look OLDER (drops exactly what the migration adds, deletes its migrations row);
 *   3. runs the REAL EdgeLocalSchemaUpgrader (manifest-governed, forward-only, data-loss audited) and asserts the plan
 *      lists the migration as RUN, the schema is forward, the seeded operational rows survive and the Cloud-shaped
 *      bootstrap row the migration exists for can now be inserted.
 */
class EdgeTenantMigrationUpgradeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $paidId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_sync_outbox', 'print_jobs', 'sales_order_lines', 'sales_orders', 'shifts', 'edge_local_meta', 'printers', 'products', 'combos', 'categories', 'branches', 'users']);
        config(['database.connections.edge_local.database' => $this->tenantDb, 'database.connections.edge_local.host' => '127.0.0.1']);
        $this->asBranchServerRuntime();
        $this->branchId = $this->makeBranch();
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 10);
        $productId = $this->makeProduct($this->makeCategory());
        $this->paidId = $this->makeSale($this->branchId, ['status' => 'paid', 'grand_total' => 250]);
        $this->makeSaleLine($this->paidId, $productId, ['quantity' => 1, 'unit_price' => 250]);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── driver ────────────────────────────────────────────────────────────────

    /**
     * @param  callable  $makeOlder  drops what the migration adds (schema + the migrations row is deleted here)
     * @param  callable  $assertForward  asserts the post-upgrade shape
     */
    private function proveUpgrade(string $migration, string $expectedClass, callable $makeOlder, callable $assertForward): void
    {
        $manifest = EdgeTenantMigrationManifest::load();
        $this->assertSame($expectedClass, $manifest->classification($migration), "manifest classification of {$migration}");
        // RUN, or RUN_EDGE_SUBSTITUTE for a migration the manifest executes through an Edge substitute (owner decision 16 Nov 2026, item 3)
        $this->assertContains($manifest->decisionFor($migration), [EdgeTenantMigrationManifest::DECISION_RUN, EdgeTenantMigrationManifest::DECISION_RUN_EDGE_SUBSTITUTE]);
        if (! is_file(database_path("migrations/tenant/{$migration}.php"))) {
            $this->markTestSkipped("{$migration} is pending merge from canonical " . $manifest->pendingMergeFrom($migration) . ' — this upgrade test activates when the file lands.');
        }
        $conn = DB::connection('tenant');
        // Owner D7-6 / exception gate (22 Oct 2026): a CLEAN install (this freshly migrated tenant DB) and an UPGRADED appliance must end
        // with the same schema — snapshot the touched tables' columns before making the DB older, compare after the upgrade.
        $manifestTables = (array) ($manifest->entry($migration)['TABLES_CHANGED'] ?? []);
        $fresh = $this->columnShape($conn, $manifestTables);
        $makeOlder($conn, Schema::connection('tenant'));
        $conn->table('migrations')->where('migration', $migration)->delete();

        $upgrader = app(EdgeLocalSchemaUpgrader::class);
        $plan = $upgrader->plan();
        $this->assertContains($migration, array_merge($plan['run'], $plan['substitute']), 'the plan lists the migration as RUN or RUN_EDGE_SUBSTITUTE');
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_OK, $plan['verdict']);
        $result = $upgrader->upgrade();
        $this->assertContains($migration, $result['applied']);
        $this->assertTrue($conn->table('migrations')->where('migration', $migration)->exists());
        $assertForward($conn, Schema::connection('tenant'));
        $this->assertSame($fresh, $this->columnShape($conn, $manifestTables), "{$migration}: the upgraded appliance ends with the clean-install schema (name, type, nullability, default) on " . implode(', ', $manifestTables));

        $meta = EdgeLocalMeta::current();
        $this->assertNull($meta->last_error);
        $this->assertSame('paid', $conn->table('sales_orders')->where('id', $this->paidId)->value('status'), 'operational rows survive');
        $this->assertSame(1, $conn->table('sales_order_lines')->where('sales_order_id', $this->paidId)->count());
        $this->assertSame([], $upgrader->upgrade()['applied'], 'idempotent');
    }

    /** name → [type, nullable, default] per column of the given tables (information_schema, ordered by name) — the clean-install shape. */
    private function columnShape($conn, array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            if (! Schema::connection('tenant')->hasTable($t)) {
                continue;
            }
            foreach ($conn->select('SELECT column_name AS c, column_type AS ty, is_nullable AS n, column_default AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY column_name', [$t]) as $r) {
                $out[$t][$r->c] = [$r->ty, $r->n, $r->d];
            }
        }

        return $out;
    }

    private function dropColumns(string $table, array $columns): void
    {
        $schema = Schema::connection('tenant');
        $present = array_values(array_filter($columns, fn ($c) => $schema->hasColumn($table, $c)));
        if ($present !== []) {
            $schema->table($table, fn (Blueprint $t) => $t->dropColumn($present));
        }
    }

    private function dropFkColumn(string $table, string $column): void
    {
        $schema = Schema::connection('tenant');
        if ($schema->hasColumn($table, $column)) {
            $schema->table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId($column));
        }
    }

    private function enumValues(string $table, string $column): array
    {
        $row = DB::connection('tenant')->selectOne("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        preg_match_all("/'([^']*)'/", (string) ($row->Type ?? ''), $m);

        return $m[1];
    }

    // ── EDGE_REQUIRED (canonical 99e9365) ──────────────────────────────────────

    public function test_kot_heading_stars_toggle_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_09_26_000001_add_kot_heading_stars_toggle', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('receipt_layout_settings', ['show_heading_stars']),
            fn ($conn, $schema) => $this->assertTrue($schema->hasColumn('receipt_layout_settings', 'show_heading_stars')));
    }

    public function test_report_channels_on_branches_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_09_30_000002_add_report_channels_to_branches', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('branches', ['report_channels', 'report_whatsapp']),
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('branches', 'report_channels'));
                $this->assertTrue($schema->hasColumn('branches', 'report_whatsapp'));
                // the whole Cloud branch row (with the report columns) now upserts on the appliance
                $conn->table('branches')->where('id', $this->branchId)->update(['report_channels' => json_encode(['email']), 'report_whatsapp' => json_encode([])]);
            });
    }

    public function test_windows_document_printer_support_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_05_000001_add_windows_document_printer_support', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn) {
                $this->dropColumns('printers', ['windows_printer_name']);
                $conn->statement("ALTER TABLE printers MODIFY printer_type ENUM('network','usb','browser') NOT NULL DEFAULT 'browser'");
                $conn->statement("ALTER TABLE printers MODIFY print_role ENUM('receipt','kot','both') NOT NULL DEFAULT 'receipt'");
                $conn->statement("ALTER TABLE printers MODIFY paper_size ENUM('58mm','80mm','A4') NOT NULL DEFAULT '80mm'");
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('printers', 'windows_printer_name'));
                $this->assertContains('windows', $this->enumValues('printers', 'printer_type'));
                $this->assertContains('document', $this->enumValues('printers', 'print_role'));
                $this->assertContains('A5', $this->enumValues('printers', 'paper_size'));
                // a Cloud "windows document A5" printer row now inserts on the appliance (bootstrap section shape)
                $id = $this->makePrinter(['branch_id' => $this->branchId, 'printer_type' => 'windows', 'print_role' => 'document', 'paper_size' => 'A5', 'windows_printer_name' => 'Office Laser']);
                $this->assertSame('windows', $conn->table('printers')->where('id', $id)->value('printer_type'));
            });
    }

    public function test_reminder_pause_on_shifts_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_05_000002_add_reminder_pause_to_shifts', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn) {
                $this->dropFkColumn('shifts', 'reminders_paused_by_user_id');
                $this->dropColumns('shifts', ['reminders_paused_at']);
                $conn->table('permissions')->where('name', 'tenant.pos.pause-reminders')->delete();
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('shifts', 'reminders_paused_at'));
                $this->assertTrue($schema->hasColumn('shifts', 'reminders_paused_by_user_id'));
                $this->assertTrue($conn->table('permissions')->where('name', 'tenant.pos.pause-reminders')->exists());
            });
    }

    public function test_cost_of_sales_account_on_categories_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_14_000001_add_cost_of_sales_account_to_categories', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function () {
                $this->dropFkColumn('categories', 'cogs_account_id');
                $this->dropFkColumn('goods_receipt_lines', 'cost_account_id');
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('categories', 'cogs_account_id'));
                $this->assertTrue($schema->hasColumn('goods_receipt_lines', 'cost_account_id'), 'placed after affects_stock — the EDGE_SCHEMA_SAFE dependency held');
            });
    }

    public function test_online_ordering_tables_upgrade_an_older_appliance(): void
    {
        // Older appliance = the bootstrap-section columns are missing; the Cloud-only tables are left in place
        // (hasTable guards) so the Cloud-shaped test DB keeps its dependents.
        $this->proveUpgrade('2026_10_14_000001_create_online_ordering_tables', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function () {
                $this->dropColumns('products', ['website_price']);
                $this->dropColumns('combos', ['website_price']);
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('products', 'website_price'));
                $this->assertTrue($schema->hasColumn('combos', 'website_price'));
                foreach (['web_settings', 'web_order_routes', 'delivery_zones'] as $cloudOnly) {
                    $this->assertTrue($schema->hasTable($cloudOnly), "{$cloudOnly} exists (empty) on the appliance");
                }
            });
    }

    public function test_website_fields_on_catalog_upgrade_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_15_000001_add_website_fields_to_catalog', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn, $schema) {
                $schema->dropIfExists('combo_modifier_group');
                foreach (['products', 'combos'] as $t) {
                    $this->dropFkColumn($t, 'website_category_id');
                    $this->dropColumns($t, ['show_on_website', 'website_card_name', 'website_size_label', 'website_tag', 'website_popular', 'website_sort_order', 'website_image_path', 'website_description']);
                }
                $this->dropColumns('categories', ['show_on_website', 'website_name', 'website_sort_order', 'website_visible_from', 'website_visible_to']);
                $this->dropColumns('modifier_groups', ['channel']);
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('modifier_groups', 'channel'));
                $this->assertTrue($schema->hasColumn('products', 'website_category_id'));
                $this->assertTrue($schema->hasColumn('combos', 'show_on_website'));
                $this->assertTrue($schema->hasColumn('categories', 'website_name'));
                $this->assertTrue($schema->hasTable('combo_modifier_group'));
                $this->assertSame('pos', $conn->selectOne("SHOW COLUMNS FROM `modifier_groups` LIKE 'channel'")->Default);
            });
    }

    public function test_medicine_fields_on_products_upgrade_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_19_000001_add_medicine_fields_to_products', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('products', ['generic_name', 'strength', 'needs_prescription']),
            function ($conn, $schema) {
                foreach (['generic_name', 'strength', 'needs_prescription'] as $c) {
                    $this->assertTrue($schema->hasColumn('products', $c), $c);
                }
                $this->assertNotNull($this->makeProduct($this->makeCategory(), ['generic_name' => 'Paracetamol', 'strength' => '500mg', 'needs_prescription' => 0]));
            });
    }

    // ── EDGE_SCHEMA_SAFE (canonical 99e9365) ───────────────────────────────────

    public function test_affects_stock_on_goods_receipt_lines_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_06_000001_add_affects_stock_to_goods_receipt_lines', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE,
            fn () => $this->dropColumns('goods_receipt_lines', ['affects_stock']),
            fn ($conn, $schema) => $this->assertTrue($schema->hasColumn('goods_receipt_lines', 'affects_stock')));
    }

    public function test_website_order_fields_on_sales_orders_upgrade_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_15_000002_add_website_order_fields_to_sales_orders', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE,
            function ($conn, $schema) {
                if ($schema->hasColumn('sales_orders', 'web_order_key')) {
                    $schema->table('sales_orders', function (Blueprint $t) {
                        $t->dropUnique(['web_order_key']);
                        $t->dropUnique(['web_token']);
                        $t->dropColumn(['web_order_key', 'web_token', 'web_meta']);
                    });
                }
                $conn->table('sales_orders')->where('order_source', 'website')->delete();
                $conn->statement("ALTER TABLE sales_orders MODIFY order_source ENUM('pos','manual') NOT NULL DEFAULT 'manual'");
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('sales_orders', 'web_order_key'));
                $this->assertTrue($schema->hasColumn('sales_orders', 'web_meta'));
                $this->assertContains('website', $this->enumValues('sales_orders', 'order_source'));
                $this->assertNull($conn->table('sales_orders')->where('id', $this->paidId)->value('web_order_key'), 'a local sale carries no website fields');
            });
    }

    // ── CANONICAL MERGE (D8, 20 Oct 2026) — migrations after 99e9365, classified when the merge landed them ──────────

    public function test_product_pack_units_upgrade_an_older_appliance(): void
    {
        $lineTables = ['sales_order_lines', 'sales_return_lines', 'goods_receipt_lines', 'purchase_order_lines',
            'purchase_bill_lines', 'purchase_return_lines', 'stock_adjustment_lines', 'stock_transfer_lines'];
        $this->proveUpgrade('2026_10_19_000002_create_product_pack_units', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn, $schema) use ($lineTables) {
                $schema->dropIfExists('product_pack_units');
                foreach ($lineTables as $t) {
                    if ($schema->hasTable($t)) {
                        // Phase E (30 Oct 2026): pack_size belongs to the LATER decimal_pack_sizes migration, which also re-types
                        // factor; an appliance older than product_pack_units has neither, so both must run forward again —
                        // otherwise the shared harness DB is left with INT factor and the clean-install comparison fails here
                        // and in test_decimal_pack_sizes_upgrade_an_older_appliance.
                        $this->dropColumns($t, ['pack_unit_id', 'pack_factor', 'base_quantity', 'pack_size']);
                    }
                }
                $conn->table('migrations')->where('migration', '2026_10_23_000001_decimal_pack_sizes')->delete();
            },
            function ($conn, $schema) use ($lineTables) {
                $this->assertTrue($schema->hasTable('product_pack_units'));
                foreach (['sales_order_lines', 'sales_return_lines'] as $t) {
                    foreach (['pack_unit_id', 'pack_factor', 'base_quantity'] as $c) {
                        $this->assertTrue($schema->hasColumn($t, $c), "{$t}.{$c}");
                    }
                }
                // The operational rows carry NULL packs (a non-medical counter never writes them).
                $this->assertNull($conn->table('sales_order_lines')->where('sales_order_id', $this->paidId)->value('pack_unit_id'));
            });
    }

    public function test_purchase_discounts_upgrade_an_older_appliance(): void
    {
        $productCols = ['purchase_discount_type', 'purchase_discount_value', 'purchase_scheme_buy', 'purchase_scheme_free', 'sale_discount_type', 'sale_discount_value'];
        $this->proveUpgrade('2026_10_20_000001_add_purchase_discounts', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn, $schema) use ($productCols) {
                $this->dropColumns('products', $productCols);
                if ($schema->hasTable('suppliers')) {
                    $this->dropColumns('suppliers', ['default_discount_percent']);
                }
                foreach (['goods_receipt_lines', 'purchase_order_lines'] as $t) {
                    if ($schema->hasTable($t)) {
                        $this->dropColumns($t, ['entry_json']);
                    }
                }
            },
            function ($conn, $schema) use ($productCols) {
                foreach ($productCols as $c) {
                    $this->assertTrue($schema->hasColumn('products', $c), $c);
                }
                // A refreshed product row with the new columns upserts (bootstrap section shape).
                $this->assertNotNull($this->makeProduct($this->makeCategory(), ['sale_discount_type' => 'percent', 'sale_discount_value' => 5]));
            });
    }
    public function test_sale_line_batch_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_20_000002_add_sale_line_batch', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('sales_order_lines', ['inventory_batch_id', 'batch_note']),
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('sales_order_lines', 'inventory_batch_id'));
                $this->assertTrue($schema->hasColumn('sales_order_lines', 'batch_note'));
                $this->assertNull($conn->table('sales_order_lines')->where('sales_order_id', $this->paidId)->value('batch_note'));
            });
    }
    public function test_bill_barcode_toggle_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_20_000003_add_bill_barcode_toggle', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('receipt_layout_settings', ['show_bill_barcode']),
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('receipt_layout_settings', 'show_bill_barcode'));
                // Default OFF — an upgraded layout row prints no bill barcode until the owner switches it on.
                $this->assertSame(0, (int) ($conn->table('receipt_layout_settings')->value('show_bill_barcode') ?? 0));
            });
    }
    public function test_refund_shift_on_sales_returns_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_20_000004_add_refund_shift_to_sales_returns', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('sales_returns', ['shift_id', 'terminal_id']),
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('sales_returns', 'shift_id'));
                $this->assertTrue($schema->hasColumn('sales_returns', 'terminal_id'));
            });
    }

    // ── CANONICAL 025728d8 (owner CANONICAL_RELEASE_FREEZE, 22 Oct 2026) ──────────────────────────────────────────────────

    public function test_restocked_on_sales_return_lines_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_20_000005_add_restocked_to_sales_return_lines', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('sales_return_lines', ['restocked']),
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('sales_return_lines', 'restocked'));
                // today's behaviour for every row the appliance writes without a restock choice: back on the shelf
                $this->assertSame('1', (string) $conn->selectOne("SELECT COLUMN_DEFAULT AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_return_lines' AND column_name = 'restocked'")->d);
            });
    }

    public function test_ask_prescription_on_branches_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_20_000006_add_ask_prescription_to_branches', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('branches', ['ask_prescription']),
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('branches', 'ask_prescription'), 'the bootstrap branch section can upsert the whole row');
                $this->assertSame(1, (int) $conn->table('branches')->value('ask_prescription'));
            });
    }

    public function test_return_without_bill_schema_upgrades_an_older_appliance(): void
    {
        $nullable = fn ($conn, string $table, string $col) => ($conn->selectOne('SELECT IS_NULLABLE AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $col])->n ?? 'NO') === 'YES';
        $this->proveUpgrade('2026_10_20_000007_return_without_bill_schema', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn) {
                // an older appliance: both links NOT NULL, no unit_cost / inventory_batch_id on return lines
                $this->dropColumns('sales_return_lines', ['unit_cost', 'inventory_batch_id']);
                $conn->statement('ALTER TABLE sales_returns MODIFY sales_order_id BIGINT UNSIGNED NOT NULL');
                $conn->statement('ALTER TABLE sales_return_lines MODIFY sales_order_line_id BIGINT UNSIGNED NOT NULL');
            },
            function ($conn, $schema) use ($nullable) {
                $this->assertTrue($nullable($conn, 'sales_returns', 'sales_order_id'), 'NON-ADDITIVE step (owner review D7-6): the link is nullable after');
                $this->assertTrue($nullable($conn, 'sales_return_lines', 'sales_order_line_id'));
                $this->assertTrue($schema->hasColumn('sales_return_lines', 'unit_cost'));
                $this->assertTrue($schema->hasColumn('sales_return_lines', 'inventory_batch_id'));
                // the foreign keys stayed
                $this->assertGreaterThanOrEqual(1, (int) $conn->selectOne("SELECT COUNT(*) AS c FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = 'sales_returns' AND column_name = 'sales_order_id' AND referenced_table_name IS NOT NULL")->c);
            });
    }
    /** POST_FREEZE_EXCEPTION 59787ff6 (owner, 22 Oct 2026): categories.updated_by — the whole-row categories refresh needs the column. */
    public function test_updated_by_on_categories_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_21_000020_add_updated_by_to_categories', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE,
            fn () => $this->dropColumns('categories', ['updated_by']),
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('categories', 'updated_by'), 'schema parity with the Cloud (the section export never carries it)');
                $this->assertSame('YES', $conn->selectOne("SELECT IS_NULLABLE AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'categories' AND column_name = 'updated_by'")->n);
            });
    }

    /** REFUND-SHIFT-H1 (owner decision H1, 22 Oct 2026): sales_returns.processing_shift_uuid — the paying shift's stable identity. */
    public function test_processing_shift_uuid_on_sales_returns_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_22_000001_add_processing_shift_uuid_to_sales_returns', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn, $schema) {
                if ($schema->hasColumn('sales_returns', 'processing_shift_uuid')) {
                    $schema->table('sales_returns', fn (Blueprint $t) => $t->dropIndex('sales_returns_processing_shift_uuid_index'));
                }
                $this->dropColumns('sales_returns', ['processing_shift_uuid']);
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('sales_returns', 'processing_shift_uuid'), 'the Edge till and the Cloud both write the paying shift\'s shift_uuid');
                $col = $conn->selectOne("SELECT COLUMN_TYPE AS t, IS_NULLABLE AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_returns' AND column_name = 'processing_shift_uuid'");
                $this->assertSame('char(26)', strtolower((string) $col->t));
                $this->assertSame('YES', $col->n, 'NULL on every pre-H1 row');
                $this->assertSame(1, (int) $conn->selectOne("SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sales_returns' AND index_name = 'sales_returns_processing_shift_uuid_index'")->c, 'indexed: the future mirror resolves by identity');
            });
    }

    // ── CANONICAL REHEARSAL (Agent B, 30 Oct 2026) — chunk 1 (canonical 59787ff6 range); MySQL proof PENDING (not run in the rehearsal) ──

    /** UB-06 (canonical 5c49790c): sales_returns.submission_key / submission_hash + unique index — posted once. */
    public function test_submission_key_on_sales_returns_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_21_000001_add_submission_key_to_sales_returns', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn, $schema) {
                if ($schema->hasColumn('sales_returns', 'submission_key')) {
                    $schema->table('sales_returns', fn (Blueprint $t) => $t->dropUnique('sales_returns_submission_key_unique'));
                }
                $this->dropColumns('sales_returns', ['submission_key', 'submission_hash']);
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('sales_returns', 'submission_key'));
                $this->assertTrue($schema->hasColumn('sales_returns', 'submission_hash'));
                $this->assertSame('YES', $conn->selectOne("SELECT IS_NULLABLE AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_returns' AND column_name = 'submission_key'")->n, 'NULL on every row the till writes (it sends no key)');
                $this->assertSame(1, (int) $conn->selectOne("SELECT COUNT(DISTINCT index_name) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sales_returns' AND index_name = 'sales_returns_submission_key_unique' AND non_unique = 0")->c);
            });
    }

    /** UB-06 (canonical 5c49790c): the same on purchase_returns — carried on the appliance, never written there. */
    public function test_submission_key_on_purchase_returns_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_21_000002_add_submission_key_to_purchase_returns', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE,
            function ($conn, $schema) {
                if ($schema->hasColumn('purchase_returns', 'submission_key')) {
                    $schema->table('purchase_returns', fn (Blueprint $t) => $t->dropUnique('purchase_returns_submission_key_unique'));
                }
                $this->dropColumns('purchase_returns', ['submission_key', 'submission_hash']);
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('purchase_returns', 'submission_key'));
                $this->assertTrue($schema->hasColumn('purchase_returns', 'submission_hash'));
            });
    }

    /** KHATRI-STOCK-TO-PNL-1 (canonical 18d552a0): stock_ledgers.movement_type gains 'expensed_to_cost' at the end of the ENUM. */
    public function test_expensed_to_cost_movement_type_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_21_000010_add_expensed_to_cost_movement_type', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn, $schema) {
                $values = array_values(array_filter($this->enumValues('stock_ledgers', 'movement_type'), fn ($v) => $v !== 'expensed_to_cost'));
                $list = implode(',', array_map(fn ($v) => "'" . $v . "'", $values));
                // Phase E (30 Oct 2026): keep the column's own nullability (the migration preserves it) — forcing NOT NULL here
                // made the "upgraded = clean install" comparison fail on a column that is nullable on a clean install.
                $null = (DB::connection('tenant')->selectOne("SHOW COLUMNS FROM stock_ledgers WHERE Field = 'movement_type'")->Null ?? 'NO') === 'YES' ? 'NULL' : 'NOT NULL';
                $conn->statement("ALTER TABLE stock_ledgers MODIFY COLUMN movement_type ENUM({$list}) {$null}");
            },
            function ($conn, $schema) {
                $values = $this->enumValues('stock_ledgers', 'movement_type');
                $this->assertContains('expensed_to_cost', $values);
                $this->assertSame('expensed_to_cost', end($values), 'appended at the end — existing rows keep their ordinal');
                $this->assertSame(0, $conn->table('stock_ledgers')->where('movement_type', 'expensed_to_cost')->count(), 'nothing on the appliance writes it');
            });
    }

    /**
     * VOID-PURGE-1 (canonical 9550e045) — Phase E resolution of owner item R-2: purged_entries + purged_numbers are CLOUD_ONLY
     * (no Edge runtime path reads or writes them; the purge is a Cloud back-office screen). The appliance never gets the file
     * (artifact EXCLUDE) and would skip it with record if it did; the SHIPPED number generators stay safe without the tables
     * because PurgedNumbers is table-guarded (ready() → false → null / 0) — the shape the deploy window was designed for.
     */
    public function test_purged_entries_tables_are_cloud_only_and_the_generators_stay_safe_without_them(): void
    {
        $name = '2026_10_22_000001_create_purged_entries_tables';
        $m = EdgeTenantMigrationManifest::load();
        $this->assertSame(EdgeTenantMigrationManifest::CLOUD_ONLY, $m->classification($name));
        $this->assertSame(EdgeTenantMigrationManifest::DECISION_SKIP_CLOUD_ONLY, $m->decisionFor($name));
        $plan = $m->plan(['tenant' => [$name]]);
        $this->assertSame([$name], $plan['skip'], 'present on an appliance → skipped with record, never executed');
        $this->assertSame([], $plan['run']);
        $this->assertSame([], $plan['blockers']);

        $conn = DB::connection('tenant');
        $schema = $conn->getSchemaBuilder();
        try {
            // The appliance shape: neither table exists (nothing on the appliance ever purges).
            $schema->dropIfExists('purged_numbers');
            $schema->dropIfExists('purged_entries');
            \App\Support\PurgedNumbers::forget();
            $this->assertFalse(\App\Support\PurgedNumbers::ready());
            $this->assertNull(\App\Support\PurgedNumbers::highest(\App\Support\PurgedNumbers::JOURNAL_ENTRY, 'JE-'), 'JournalService::nextEntryNo sees "nothing removed"');
            $this->assertSame(0, \App\Support\PurgedNumbers::highestInt(\App\Support\PurgedNumbers::MANUAL_JOURNAL));
            $this->assertNull(\App\Support\PurgedNumbers::highest(\App\Support\PurgedNumbers::EXPENSE_VOUCHER, 'EXP-'));
        } finally {
            // CP6 (22 Nov 2026): put the Cloud shape back. The schema is migrated once per process, so a later test in the same
            // process (VoidPurgeMySqlTest, SupplierCreditClarityMySqlTest) found both tables missing: 11 order-dependent errors in
            // the single-process release gate. The migration's up() is idempotent (hasTable guards).
            (require base_path('database/migrations/tenant/' . $name . '.php'))->up();
            \App\Support\PurgedNumbers::forget();
        }
        $this->assertTrue($schema->hasTable('purged_entries') && $schema->hasTable('purged_numbers'), 'the shared test schema is restored for later tests');
    }

    /** UNITS-PACKS F1 (canonical 7308db7e): product_pack_units.factor DECIMAL(15,4); pack_size on the eight line tables. Owner review R-1. */
    public function test_decimal_pack_sizes_upgrade_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_23_000001_decimal_pack_sizes', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn, $schema) {
                foreach (['sales_order_lines', 'sales_return_lines', 'goods_receipt_lines', 'purchase_order_lines', 'purchase_bill_lines', 'purchase_return_lines', 'stock_adjustment_lines', 'stock_transfer_lines'] as $t) {
                    if ($schema->hasTable($t)) {
                        $this->dropColumns($t, ['pack_size']);
                    }
                }
                $conn->statement('ALTER TABLE `product_pack_units` MODIFY `factor` INT UNSIGNED NOT NULL');
            },
            function ($conn, $schema) {
                $this->assertStringStartsWith('decimal', strtolower((string) $conn->selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'product_pack_units' AND column_name = 'factor'")->t));
                $this->assertTrue($schema->hasColumn('sales_order_lines', 'pack_size'));
                $this->assertTrue($schema->hasColumn('sales_return_lines', 'pack_size'));
                $this->assertSame(1, $conn->table('sales_order_lines')->where('sales_order_id', $this->paidId)->whereNull('pack_size')->count(), 'existing lines keep pack_factor, pack_size NULL');
            });
    }

    /** UNITS-PACKS F2 (canonical 9ec6b22f): sales_order_lines.entered_quantity / entered_unit_code (what the cashier typed). */
    public function test_sale_line_entered_unit_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_23_000002_sale_line_entered_unit', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('sales_order_lines', ['entered_quantity', 'entered_unit_code']),
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('sales_order_lines', 'entered_quantity'));
                $this->assertTrue($schema->hasColumn('sales_order_lines', 'entered_unit_code'));
                $this->assertSame(1, $conn->table('sales_order_lines')->where('sales_order_id', $this->paidId)->whereNull('entered_unit_code')->count(), 'NULL on every line typed in the stock unit');
            });
    }

    // ── CANONICAL REHEARSAL chunks 9-11 (15 Nov 2026) — canonical 073238de..5c74107f ─────────────────────────────────────

    /** KOT-ORDER-NOTE-1 (canonical 114413b9 + c51b2fe7): the toggle lands with its default flipped by the second migration. */
    public function test_order_note_toggle_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_11_02_000001_add_order_note_toggle', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn) {
                $this->dropColumns('receipt_layout_settings', ['show_order_note']);
                // the default-off migration depends on the column: an appliance older than the toggle runs both forward again
                $conn->table('migrations')->where('migration', '2026_11_02_000002_order_note_toggle_default_off')->delete();
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('receipt_layout_settings', 'show_order_note'));
                $this->assertTrue($conn->table('migrations')->where('migration', '2026_11_02_000002_order_note_toggle_default_off')->exists(), 'the default-off step ran with it');
                $this->assertSame('0', (string) $conn->selectOne("SELECT column_default AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'receipt_layout_settings' AND column_name = 'show_order_note'")->d, 'OFF by default (canonical c51b2fe7)');
            });
    }

    /**
     * KOT-ORDER-NOTE-1 (2) (canonical c51b2fe7) on an APPLIANCE — owner decision 16 Nov 2026 (item 3): the existing-row rewrite is NOT
     * approved; the manifest executes the migration through the Edge substitute OrderNoteToggleDefaultOnly (default OFF for new
     * layouts, no row touched) and records the canonical name. The upgraded appliance still ends with the clean-install column shape.
     */
    public function test_order_note_toggle_default_off_upgrades_an_older_appliance_through_the_edge_substitute_keeping_an_operator_on_choice(): void
    {
        $this->assertSame(EdgeTenantMigrationManifest::DECISION_RUN_EDGE_SUBSTITUTE, EdgeTenantMigrationManifest::load()->decisionFor('2026_11_02_000002_order_note_toggle_default_off'));
        $this->proveUpgrade('2026_11_02_000002_order_note_toggle_default_off', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn) {
                $conn->statement('ALTER TABLE receipt_layout_settings MODIFY show_order_note TINYINT(1) NOT NULL DEFAULT 1');
                $branchId = (int) $conn->table('branches')->orderBy('id')->value('id');
                $conn->table('receipt_layout_settings')->where('document_type', 'kot-upgrade-probe')->delete();
                $conn->table('receipt_layout_settings')->insert(['branch_id' => $branchId, 'document_type' => 'kot-upgrade-probe', 'show_order_note' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            },
            function ($conn) {
                $this->assertSame('0', (string) $conn->selectOne("SELECT column_default AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'receipt_layout_settings' AND column_name = 'show_order_note'")->d, 'new layouts default OFF');
                $this->assertSame(1, (int) $conn->table('receipt_layout_settings')->where('document_type', 'kot-upgrade-probe')->value('show_order_note'), "an operator's explicit ON survives the Edge execution (owner decision 16 Nov 2026)");
                $conn->table('receipt_layout_settings')->where('document_type', 'kot-upgrade-probe')->delete();
            });
    }

    /** KOT-DEAL-HEADING-1 (canonical 7230a7b3). */
    public function test_deal_heading_toggle_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_11_11_000001_add_deal_heading_toggle', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('receipt_layout_settings', ['show_deal_as_heading']),
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('receipt_layout_settings', 'show_deal_as_heading'));
                $this->assertSame('0', (string) $conn->selectOne("SELECT column_default AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'receipt_layout_settings' AND column_name = 'show_deal_as_heading'")->d, 'OFF unless the owner switches it on per tenant');
            });
    }

    /** RECEIPT-CUSTOMER-SIZE-1 (canonical c8e333d9). */
    public function test_customer_font_size_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_11_14_000001_add_customer_font_size_to_receipt_layouts', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('receipt_layout_settings', ['customer_font_size']),
            fn ($conn, $schema) => $this->assertTrue($schema->hasColumn('receipt_layout_settings', 'customer_font_size')));
    }

    /** PURCHASE-EDIT-1 (1/3) + SUPPLIER-LEDGER-DAILY-1 (canonical f0019787 / 0d07db90): entry_date + its index, carried. */
    public function test_supplier_ledger_entry_date_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_11_03_000001_add_entry_date_to_supplier_ledgers', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE,
            function ($conn, $schema) {
                if ($schema->hasColumn('supplier_ledgers', 'entry_date')) {
                    // MySQL keeps the composite (supplier_id, entry_date) index for the supplier_id FK on a fresh DB — an older
                    // appliance had its own plain index; give the FK one for the probe (removed once the upgrade restored the composite).
                    $conn->statement('ALTER TABLE supplier_ledgers ADD INDEX sl_probe_supplier_idx (supplier_id)');
                    $schema->table('supplier_ledgers', fn (Blueprint $t) => $t->dropIndex('supplier_ledgers_supplier_entry_date_index'));
                }
                $this->dropColumns('supplier_ledgers', ['entry_date']);
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('supplier_ledgers', 'entry_date'));
                $this->assertSame(1, (int) $conn->selectOne("SELECT COUNT(DISTINCT index_name) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'supplier_ledgers' AND index_name = 'supplier_ledgers_supplier_entry_date_index'")->c);
                $conn->statement('ALTER TABLE supplier_ledgers DROP INDEX sl_probe_supplier_idx');
            });
    }

    /** PURCHASE-EDIT-1 (1/3) (canonical f0019787): the revision record table + goods_receipts.revision_no, carried empty. */
    public function test_goods_receipt_revisions_upgrade_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_11_03_000002_create_goods_receipt_revisions', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE,
            function ($conn, $schema) {
                $schema->dropIfExists('goods_receipt_revisions');
                $this->dropColumns('goods_receipts', ['revision_no']);
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasTable('goods_receipt_revisions'));
                $this->assertTrue($schema->hasColumn('goods_receipts', 'revision_no'));
                $this->assertSame(0, (int) $conn->table('goods_receipt_revisions')->count(), 'empty on the appliance — goods receipts are Cloud screens');
            });
    }

    /** PURCHASE-EDIT-1 (1/3) (canonical f0019787): the ENUM gains voided (owner-review item: a column MODIFY) + the void columns. */
    public function test_purchase_bill_voidable_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_11_03_000003_let_a_purchase_bill_be_voided', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE,
            function ($conn) {
                $this->dropColumns('purchase_bills', ['voided_at', 'voided_by_user_id', 'void_reason']);
                $values = array_values(array_diff($this->enumValues('purchase_bills', 'status'), ['voided']));
                $this->assertNotEmpty($values);
                $col = $conn->selectOne("SHOW COLUMNS FROM purchase_bills WHERE Field = 'status'");
                $null = ($col->Null ?? '') === 'YES' ? 'NULL' : 'NOT NULL';
                $default = $col->Default !== null ? " DEFAULT '" . $col->Default . "'" : '';
                $conn->statement("ALTER TABLE purchase_bills MODIFY COLUMN status ENUM('" . implode("','", $values) . "') {$null}{$default}");
                $this->assertNotContains('voided', $this->enumValues('purchase_bills', 'status'), 'older appliance: no voided status');
            },
            function ($conn, $schema) {
                $this->assertContains('voided', $this->enumValues('purchase_bills', 'status'));
                foreach (['voided_at', 'voided_by_user_id', 'void_reason'] as $c) {
                    $this->assertTrue($schema->hasColumn('purchase_bills', $c), $c);
                }
            });
    }

    /** DEAL-SPLIT-REPORT-1 (canonical 7762ab39): the three report-only columns, carried; never written by the till. */
    public function test_deal_split_report_columns_upgrade_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_11_08_000001_add_deal_split_report_columns', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE,
            function () {
                $this->dropColumns('sales_order_lines', ['deal_share']);
                $this->dropColumns('combo_components', ['report_amount']);
                $this->dropColumns('combos', ['report_by_items']);
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('sales_order_lines', 'deal_share'));
                $this->assertTrue($schema->hasColumn('combo_components', 'report_amount'));
                $this->assertTrue($schema->hasColumn('combos', 'report_by_items'));
                $this->assertSame('YES', $conn->selectOne("SELECT IS_NULLABLE AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_order_lines' AND column_name = 'deal_share'")->n, 'NULL on every line the till writes');
            });
    }

    // ── POPULATED-DATA upgrade / rollback proofs for the three OWNER_REVIEW migrations (owner, 16 Nov 2026) ──────────────
    // The clean-install proofs above run on empty tables; these seed the rows each migration touches, upgrade through the
    // appliance upgrader, roll back with the migration's own down(), and upgrade again — every seeded value must survive.

    /** Loads the migration file and returns its anonymous class (default connection is `tenant` in setUp). */
    private function migrationObject(string $migration): object
    {
        return require database_path("migrations/tenant/{$migration}.php");
    }

    /** R-1: product_pack_units.factor INT → DECIMAL(15,4) keeps every factor and the (product_id, factor) unique key; lines keep pack_factor. */
    public function test_decimal_pack_sizes_populated_rows_survive_upgrade_and_rollback(): void
    {
        $conn = DB::connection('tenant');
        $productId = (int) $conn->table('sales_order_lines')->where('sales_order_id', $this->paidId)->value('product_id');
        $conn->table('product_pack_units')->whereIn('name', ['Dozen', 'Box', 'Bag 2.5kg'])->delete();

        $this->proveUpgrade('2026_10_23_000001_decimal_pack_sizes', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn, $schema) use ($productId) {
                foreach (['sales_order_lines', 'sales_return_lines', 'goods_receipt_lines', 'purchase_order_lines', 'purchase_bill_lines', 'purchase_return_lines', 'stock_adjustment_lines', 'stock_transfer_lines'] as $t) {
                    if ($schema->hasTable($t)) {
                        $this->dropColumns($t, ['pack_size']);
                    }
                }
                $conn->statement('ALTER TABLE `product_pack_units` MODIFY `factor` INT UNSIGNED NOT NULL');
                $dozen = $conn->table('product_pack_units')->insertGetId(['product_id' => $productId, 'name' => 'Dozen', 'factor' => 12, 'created_at' => now(), 'updated_at' => now()]);
                $conn->table('product_pack_units')->insert(['product_id' => $productId, 'name' => 'Box', 'factor' => 6, 'created_at' => now(), 'updated_at' => now()]);
                $conn->table('sales_order_lines')->where('sales_order_id', $this->paidId)->update(['pack_unit_id' => $dozen, 'pack_factor' => 12]);
            },
            function ($conn, $schema) use ($productId) {
                $factors = fn () => $conn->table('product_pack_units')->where('product_id', $productId)->orderBy('name')->pluck('factor', 'name')->map(fn ($v) => (float) $v)->all();
                $this->assertSame(['Box' => 6.0, 'Dozen' => 12.0], $factors(), 'whole factors survive the widening');
                $this->assertStringStartsWith('decimal', strtolower((string) $conn->selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'product_pack_units' AND column_name = 'factor'")->t));
                $line = $conn->table('sales_order_lines')->where('sales_order_id', $this->paidId)->first();
                $this->assertSame(12, (int) $line->pack_factor, 'the line keeps pack_factor');
                $this->assertNull($line->pack_size, 'pack_size NULL on an existing line (readers fall back to pack_factor)');
                try {
                    $conn->table('product_pack_units')->insert(['product_id' => $productId, 'name' => 'DupDozen', 'factor' => 12, 'created_at' => now(), 'updated_at' => now()]);
                    $this->fail('the (product_id, factor) unique key must survive the type change');
                } catch (\Illuminate\Database\QueryException $e) {
                    $this->assertStringContainsString('product_pack_units_product_id_factor_unique', $e->getMessage());
                }

                $m = $this->migrationObject('2026_10_23_000001_decimal_pack_sizes');
                // rollback with a FRACTIONAL pack present: pack_size columns go, factor stays DECIMAL (the migration's own rule)
                $conn->table('product_pack_units')->insert(['product_id' => $productId, 'name' => 'Bag 2.5kg', 'factor' => 2.5, 'created_at' => now(), 'updated_at' => now()]);
                $m->down();
                $this->assertFalse($schema->hasColumn('sales_order_lines', 'pack_size'));
                $this->assertStringStartsWith('decimal', strtolower((string) $conn->selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'product_pack_units' AND column_name = 'factor'")->t), 'a fractional pack keeps the column DECIMAL on rollback');
                $this->assertSame(2.5, (float) $conn->table('product_pack_units')->where('name', 'Bag 2.5kg')->value('factor'));
                $m->up();
                $this->assertTrue($schema->hasColumn('sales_order_lines', 'pack_size'));
                // rollback with whole packs only: factor returns to INT UNSIGNED, every value and the line's pack_factor kept
                $conn->table('product_pack_units')->where('name', 'Bag 2.5kg')->delete();
                $m->down();
                $this->assertStringContainsString('int', strtolower((string) $conn->selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'product_pack_units' AND column_name = 'factor'")->t));
                $this->assertSame(['Box' => 6.0, 'Dozen' => 12.0], $factors(), 'factors survive the rollback');
                $this->assertSame(12, (int) $conn->table('sales_order_lines')->where('sales_order_id', $this->paidId)->value('pack_factor'));
                $m->up();
                $this->assertSame(['Box' => 6.0, 'Dozen' => 12.0], $factors(), 'and the second upgrade');
                $conn->table('product_pack_units')->whereIn('name', ['Dozen', 'Box'])->delete();
                $conn->table('sales_order_lines')->where('sales_order_id', $this->paidId)->update(['pack_unit_id' => null, 'pack_factor' => null]);
            });
    }

    /** purchase_bills.status ENUM append: every existing bill keeps its status AND ordinal; 'voided' becomes usable; down() leaves the shape. */
    public function test_purchase_bill_voidable_populated_rows_survive_upgrade_and_rollback(): void
    {
        $conn = DB::connection('tenant');
        $conn->table('purchase_bills')->where('bill_no', 'like', 'MR-%')->delete();
        $conn->table('suppliers')->where('code', 'MIGREV')->delete();
        $supplierId = $conn->table('suppliers')->insertGetId(['code' => 'MIGREV', 'name' => 'Migration review supplier', 'created_at' => now(), 'updated_at' => now()]);
        $bills = fn () => $conn->table('purchase_bills')->where('bill_no', 'like', 'MR-%')->orderBy('bill_no')->selectRaw('bill_no, status, status+0 AS ordinal')->get()->map(fn ($r) => [$r->bill_no, $r->status, (int) $r->ordinal])->all();

        $this->proveUpgrade('2026_11_03_000003_let_a_purchase_bill_be_voided', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE,
            function ($conn) use ($supplierId) {
                $this->dropColumns('purchase_bills', ['voided_at', 'voided_by_user_id', 'void_reason']);
                $values = array_values(array_diff($this->enumValues('purchase_bills', 'status'), ['voided']));
                $col = $conn->selectOne("SHOW COLUMNS FROM purchase_bills WHERE Field = 'status'");
                $null = ($col->Null ?? '') === 'YES' ? 'NULL' : 'NOT NULL';
                $default = $col->Default !== null ? " DEFAULT '" . $col->Default . "'" : '';
                $conn->statement("ALTER TABLE purchase_bills MODIFY COLUMN status ENUM('" . implode("','", $values) . "') {$null}{$default}");
                $i = 0;
                foreach ($values as $status) {
                    $conn->table('purchase_bills')->insert(['bill_no' => 'MR-' . ++$i, 'supplier_id' => $supplierId, 'branch_id' => $this->branchId, 'bill_date' => now()->toDateString(), 'status' => $status, 'subtotal' => 100 * $i, 'grand_total' => 100 * $i, 'balance_due' => 100 * $i, 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->seededBills = $this->billsSnapshot();
                $this->assertCount(count($values), $this->seededBills);
            },
            function ($conn, $schema) {
                $this->assertSame($this->seededBills, $this->billsSnapshot(), 'every bill keeps its status and its ENUM ordinal');
                $conn->table('purchase_bills')->where('bill_no', 'MR-1')->update(['status' => 'voided', 'voided_at' => now(), 'void_reason' => 'review probe']);
                $this->assertSame('voided', $conn->table('purchase_bills')->where('bill_no', 'MR-1')->value('status'), "'voided' is usable");
                $conn->table('purchase_bills')->where('bill_no', 'MR-1')->update(['status' => 'draft', 'voided_at' => null, 'void_reason' => null]);
                // down() is intentionally empty: the member and the three columns stay, rows untouched
                $this->migrationObject('2026_11_03_000003_let_a_purchase_bill_be_voided')->down();
                $this->assertContains('voided', $this->enumValues('purchase_bills', 'status'));
                $this->assertTrue($schema->hasColumn('purchase_bills', 'voided_at'));
                $this->assertSame($this->seededBills, $this->billsSnapshot());
                $conn->table('purchase_bills')->where('bill_no', 'like', 'MR-%')->delete();
                $conn->table('suppliers')->where('code', 'MIGREV')->delete();
            });
    }

    private array $seededBills = [];

    private function billsSnapshot(): array
    {
        return DB::connection('tenant')->table('purchase_bills')->where('bill_no', 'like', 'MR-%')->orderBy('bill_no')
            ->selectRaw('bill_no, status, status+0 AS ordinal')->get()->map(fn ($r) => [$r->bill_no, $r->status, (int) $r->ordinal])->all();
    }

    /** order_note default-off: an operator's ON choice is rewritten to OFF (the owner-review risk); down() restores the default only. */
    /**
     * Owner decision 16 Nov 2026 (item 3), populated layouts with ON and OFF values: the Edge execution (substitute) keeps every
     * stored value — ON stays ON, OFF stays OFF, a row created under the old default keeps its stored 1 — and only a layout created
     * AFTER the upgrade starts OFF; the substitute's down() restores the default and touches no row. The canonical body, run by hand
     * on the same rows, rewrites all of them to OFF: that is the behaviour the owner refused, kept here as the documented contrast.
     */
    public function test_order_note_default_off_edge_substitute_preserves_populated_on_and_off_rows_while_the_canonical_body_would_rewrite_them(): void
    {
        $conn = DB::connection('tenant');
        $conn->table('receipt_layout_settings')->where('document_type', 'like', 'note-probe-%')->delete();
        $notes = fn () => $conn->table('receipt_layout_settings')->where('document_type', 'like', 'note-probe-%')->orderBy('document_type')->pluck('show_order_note', 'document_type')->map(fn ($v) => (int) $v)->all();
        $default = fn () => (string) $conn->selectOne("SELECT column_default AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'receipt_layout_settings' AND column_name = 'show_order_note'")->d;
        $insert = function (string $type, ?int $value) use ($conn) {
            $row = ['branch_id' => $this->branchId, 'document_type' => $type, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()];
            if ($value !== null) {
                $row['show_order_note'] = $value;
            }
            $conn->table('receipt_layout_settings')->insert($row);
        };

        $this->proveUpgrade('2026_11_02_000002_order_note_toggle_default_off', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn) use ($insert) {
                $conn->statement('ALTER TABLE receipt_layout_settings MODIFY show_order_note TINYINT(1) NOT NULL DEFAULT 1');
                foreach (['note-probe-on' => 1, 'note-probe-off' => 0, 'note-probe-default' => null] as $type => $value) {
                    $insert($type, $value);
                }
            },
            function ($conn) use ($notes, $default, $insert) {
                $this->assertSame('0', $default(), 'new layouts default OFF');
                $this->assertSame(['note-probe-default' => 1, 'note-probe-off' => 0, 'note-probe-on' => 1], $notes(), 'NO stored value changed: ON stays ON, OFF stays OFF');
                $insert('note-probe-after', null);
                $this->assertSame(0, (int) $conn->table('receipt_layout_settings')->where('document_type', 'note-probe-after')->value('show_order_note'), 'a layout created after the upgrade starts OFF');

                $substitute = new OrderNoteToggleDefaultOnly();
                $substitute->down();
                $this->assertSame('1', $default(), "the substitute's down() restores the default");
                $this->assertSame(['note-probe-after' => 0, 'note-probe-default' => 1, 'note-probe-off' => 0, 'note-probe-on' => 1], $notes(), 'and touches no row');
                $substitute->up();
                $this->assertSame('0', $default());

                // The refused behaviour, for the record: the canonical body rewrites every row (never executed by the upgrader on an appliance).
                $this->migrationObject('2026_11_02_000002_order_note_toggle_default_off')->up();
                $this->assertSame(['note-probe-after' => 0, 'note-probe-default' => 0, 'note-probe-off' => 0, 'note-probe-on' => 0], $notes(), 'canonical body: EVERY row OFF, the operator ON choice lost — the reason for the substitute');
                $conn->table('receipt_layout_settings')->where('document_type', 'like', 'note-probe-%')->delete();
            });
    }
    // ── the manifest names only tests that exist here ───────────────────────────

    public function test_every_post_baseline_shipped_migration_names_a_test_method_of_this_class_or_a_phase4_proof(): void
    {
        $m = EdgeTenantMigrationManifest::load();
        foreach ($m->entries() as $name => $entry) {
            if (($entry['BASELINE'] ?? false) || $entry['CLASSIFICATION'] === EdgeTenantMigrationManifest::CLOUD_ONLY) {
                continue;
            }
            foreach ((array) $entry['UPGRADE_TEST'] as $ref) {
                [$class, $method] = array_pad(explode('::', $ref, 2), 2, null);
                $this->assertTrue(class_exists($class), "{$name}: {$class}");
                $this->assertTrue($method === null || method_exists($class, $method), "{$name}: {$ref}");
            }
        }
        $canonicalRun = [
            '2026_09_26_000001_add_kot_heading_stars_toggle', '2026_09_30_000002_add_report_channels_to_branches',
            '2026_10_05_000001_add_windows_document_printer_support', '2026_10_05_000002_add_reminder_pause_to_shifts',
            '2026_10_14_000001_add_cost_of_sales_account_to_categories', '2026_10_14_000001_create_online_ordering_tables',
            '2026_10_15_000001_add_website_fields_to_catalog', '2026_10_19_000001_add_medicine_fields_to_products',
            '2026_10_06_000001_add_affects_stock_to_goods_receipt_lines', '2026_10_15_000002_add_website_order_fields_to_sales_orders',
            // CANONICAL MERGE (D8, 20 Oct 2026): classified when the merge landed them.
            '2026_10_19_000002_create_product_pack_units', '2026_10_20_000001_add_purchase_discounts',
            '2026_10_20_000002_add_sale_line_batch', '2026_10_20_000003_add_bill_barcode_toggle',
            '2026_10_20_000004_add_refund_shift_to_sales_returns',
            // CANONICAL_RELEASE_FREEZE 025728d8 (22 Oct 2026)
            '2026_10_20_000005_add_restocked_to_sales_return_lines', '2026_10_20_000006_add_ask_prescription_to_branches',
            '2026_10_20_000007_return_without_bill_schema',
            // POST_FREEZE_EXCEPTION 59787ff6 (owner, 22 Oct 2026)
            '2026_10_21_000020_add_updated_by_to_categories',
            // REFUND-SHIFT-H1 (owner decision H1, 22 Oct 2026)
            '2026_10_22_000001_add_processing_shift_uuid_to_sales_returns',
            // CANONICAL REHEARSAL (Agent B, 30 Oct 2026) chunk 1
            '2026_10_21_000001_add_submission_key_to_sales_returns', '2026_10_21_000002_add_submission_key_to_purchase_returns',
            '2026_10_21_000010_add_expensed_to_cost_movement_type',
            // chunk 2: purged_entries tables — Phase E R-2 → CLOUD_ONLY (asserted below as SKIP, not RUN)
            // chunk 3
            '2026_10_23_000001_decimal_pack_sizes', '2026_10_23_000002_sale_line_entered_unit',
        ];
        $this->assertSame(EdgeTenantMigrationManifest::DECISION_SKIP_CLOUD_ONLY, $m->decisionFor('2026_10_22_000001_create_purged_entries_tables'), 'Phase E R-2');
        foreach ($canonicalRun as $name) {
            $this->assertFalse($m->entry($name)['BASELINE'] ?? true, $name);
            $this->assertSame(EdgeTenantMigrationManifest::DECISION_RUN, $m->decisionFor($name), $name);
            // an entry may name several tests (a list); the FIRST must be an upgrade proof of this class
            $this->assertStringStartsWith('Tests\MySql\EdgeTenantMigrationUpgradeMySqlTest::test_', (string) ((array) $m->entry($name)['UPGRADE_TEST'])[0], $name);
        }
    }
}
