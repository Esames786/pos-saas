<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UNITS-PACKS batch, feature 1 — a pack may hold a part of a kg, litre or metre: Bag = 2.5 kg, Bottle = 1.5 L.
 *
 * product_pack_units.factor becomes DECIMAL(15,4). It is a small table (census 23 Oct: 78 rows on one demo tenant, 0
 * on every live one); its unique (product_id, factor) key stays.
 *
 * The line tables keep pack_factor (INT, still written when the size is whole) and get pack_size DECIMAL(15,4) beside
 * it, the exact size at that moment. They are added with ALGORITHM=INSTANT, the way pack_factor was: no table copy (a
 * type change would copy 62,277 sale lines on one tenant). Readers take pack_size, else pack_factor. Every existing
 * line has neither (census: 0 lines with a pack on any tenant), so nothing already written changes.
 */
return new class extends Migration
{
    private const LINE_TABLES = [
        'sales_order_lines', 'sales_return_lines', 'goods_receipt_lines', 'purchase_order_lines',
        'purchase_bill_lines', 'purchase_return_lines', 'stock_adjustment_lines', 'stock_transfer_lines',
    ];

    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';
        if ($mysql) {
            // Never queue a live counter behind a long query: wait at most 10 s and fail (deploy re-runs it).
            DB::statement('SET SESSION lock_wait_timeout = 10');
        }

        if (Schema::hasTable('product_pack_units') && $this->factorIsWhole()) {
            if ($mysql) {
                DB::statement('ALTER TABLE `product_pack_units` MODIFY `factor` DECIMAL(15,4) NOT NULL');
            } else {
                Schema::table('product_pack_units', function (Blueprint $table) {
                    $table->decimal('factor', 15, 4)->change();
                });
            }
        }

        foreach (self::LINE_TABLES as $name) {
            if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'pack_factor') || Schema::hasColumn($name, 'pack_size')) {
                continue;
            }
            if ($mysql) {
                DB::statement('ALTER TABLE `' . $name . '` ADD COLUMN `pack_size` DECIMAL(15,4) NULL, ALGORITHM=INSTANT');
            } else {
                Schema::table($name, function (Blueprint $table) {
                    $table->decimal('pack_size', 15, 4)->nullable();
                });
            }
        }

        if ($mysql) {
            DB::statement('SET SESSION lock_wait_timeout = DEFAULT');
        }
    }

    public function down(): void
    {
        foreach (self::LINE_TABLES as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'pack_size')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('pack_size');
                });
            }
        }
        // The size goes back to a whole number only when every pack is whole; otherwise it stays as it is.
        if (Schema::hasTable('product_pack_units') && ! $this->factorIsWhole()
            && DB::table('product_pack_units')->whereRaw('factor <> FLOOR(factor)')->doesntExist()) {
            Schema::table('product_pack_units', function (Blueprint $table) {
                $table->unsignedInteger('factor')->change();
            });
        }
    }

    /** True while factor is still the whole-number column of 19 Oct. */
    private function factorIsWhole(): bool
    {
        $type = strtolower((string) Schema::getColumnType('product_pack_units', 'factor'));

        return str_contains($type, 'int');
    }
};
