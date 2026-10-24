<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UNITS-PACKS batch, feature 2 — a kg item sold as "250 g". The line's quantity stays in the stock unit (0.250 kg), as
 * every report and the stock read it; beside it, what the cashier typed: entered_quantity 250, entered_unit_code G, so
 * the receipt can say 250 g. Both stay NULL on every existing line and on every line typed in the stock unit.
 * ALGORITHM=INSTANT: no copy of the sale lines (62,277 rows on one tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_order_lines') || Schema::hasColumn('sales_order_lines', 'entered_unit_code')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 10');
            DB::statement('ALTER TABLE `sales_order_lines` ADD COLUMN `entered_quantity` DECIMAL(15,3) NULL, '
                . 'ADD COLUMN `entered_unit_code` VARCHAR(20) NULL, ALGORITHM=INSTANT');
            DB::statement('SET SESSION lock_wait_timeout = DEFAULT');

            return;
        }
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->decimal('entered_quantity', 15, 3)->nullable();
            $table->string('entered_unit_code', 20)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_order_lines') && Schema::hasColumn('sales_order_lines', 'entered_unit_code')) {
            Schema::table('sales_order_lines', function (Blueprint $table) {
                $table->dropColumn(['entered_quantity', 'entered_unit_code']);
            });
        }
    }
};
