<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RETURN-NO-BILL-1 (schema first) — a customer return that has no bill.
 *
 * Owner, 20 Oct: the customer brings a medicine back without the bill. Today a return must point at a sale and a sale
 * line, so it cannot be recorded at all.
 *
 *   sales_returns.sales_order_id            NOT NULL → NULL   (NULL = returned without a bill)
 *   sales_return_lines.sales_order_line_id  NOT NULL → NULL
 *   sales_return_lines.unit_cost            new, NULL         (the cost the stock went back in at — a bill return
 *                                                              keeps reading it from its sale line, as today)
 *   sales_return_lines.inventory_batch_id   new, NULL         (the batch it went back into)
 *
 * Only relaxes and adds: every return written today stays exactly as it is, and today's code never writes a NULL
 * there — the code that does ships after this. The foreign keys stay. MySQL: INPLACE, concurrent DML allowed,
 * lock_wait_timeout 10; both tables are small. down() leaves the columns nullable: rows without a bill may exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_returns') || ! Schema::hasTable('sales_return_lines')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 10');
            if (! $this->isNullable('sales_returns', 'sales_order_id')) {
                DB::statement('ALTER TABLE `sales_returns` MODIFY `sales_order_id` BIGINT UNSIGNED NULL, ALGORITHM=INPLACE, LOCK=NONE');
            }
            if (! $this->isNullable('sales_return_lines', 'sales_order_line_id')) {
                DB::statement('ALTER TABLE `sales_return_lines` MODIFY `sales_order_line_id` BIGINT UNSIGNED NULL, ALGORITHM=INPLACE, LOCK=NONE');
            }
            $add = [];
            if (! Schema::hasColumn('sales_return_lines', 'unit_cost')) {
                $add[] = 'ADD COLUMN `unit_cost` DECIMAL(15,4) NULL';
            }
            if (! Schema::hasColumn('sales_return_lines', 'inventory_batch_id')) {
                $add[] = 'ADD COLUMN `inventory_batch_id` BIGINT UNSIGNED NULL';
            }
            if ($add) {
                DB::statement('ALTER TABLE `sales_return_lines` ' . implode(', ', $add) . ', ALGORITHM=INSTANT');
            }

            return;
        }

        Schema::table('sales_returns', fn (Blueprint $t) => $t->unsignedBigInteger('sales_order_id')->nullable()->change());
        Schema::table('sales_return_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('sales_order_line_id')->nullable()->change();
            if (! Schema::hasColumn('sales_return_lines', 'unit_cost')) {
                $t->decimal('unit_cost', 15, 4)->nullable();
            }
            if (! Schema::hasColumn('sales_return_lines', 'inventory_batch_id')) {
                $t->unsignedBigInteger('inventory_batch_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['unit_cost', 'inventory_batch_id'] as $col) {
            if (Schema::hasColumn('sales_return_lines', $col)) {
                Schema::table('sales_return_lines', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }

    private function isNullable(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return ($row->n ?? 'NO') === 'YES';
    }
};
