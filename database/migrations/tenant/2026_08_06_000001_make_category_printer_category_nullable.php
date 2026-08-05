<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * Allow category_id to be NULL on category_printer_mappings, meaning "All categories"
     * (a wildcard route). This is the natural model for Reminder printing (which always
     * prints the complete order) and a convenience for KOT ("send everything here").
     * The existing unique index (branch_id, category_id, printer_id, print_role, order_type)
     * stays; MySQL keeps it functional for concrete categories, and duplicate wildcard
     * rows are harmless because both routers de-duplicate by printer at print time.
     */
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('category_printer_mappings')) {
            DB::connection('tenant')->statement(
                'ALTER TABLE category_printer_mappings MODIFY category_id BIGINT UNSIGNED NULL'
            );
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('category_printer_mappings')) {
            // NOT NULL cannot be restored while wildcard rows exist — drop them first.
            DB::connection('tenant')->table('category_printer_mappings')->whereNull('category_id')->delete();
            DB::connection('tenant')->statement(
                'ALTER TABLE category_printer_mappings MODIFY category_id BIGINT UNSIGNED NOT NULL'
            );
        }
    }
};
