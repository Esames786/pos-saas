<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHARMACY-PACKS-1 — how an item is packed: 1 box = 10 strips, 1 strip = 10 tablets.
 *
 * Stock stays in the smallest unit (the product's own unit, factor 1). A pack is a bigger unit of the SAME
 * product with a whole-number size in that unit, its own price (NULL = size × the unit price) and its own
 * barcode. Pack barcodes live here, not in product_barcodes: seven existing readers of product_barcodes
 * (POS, held sales, sales orders, search, catalog lookup, Edge, recipe cost) would sell ONE tablet for a
 * scanned box until the counter learns packs.
 *
 * The line columns are the schema for the next steps (GRN, POS, return) — added now so the code that writes
 * them ships a release later (risk review: "schema first"). They stay NULL on every existing line:
 *   pack_unit_id  which pack the line was typed in (no FK: these tables hold tens of thousands of rows),
 *   pack_factor   its size at that moment (a later edit of the pack never rewrites history),
 *   base_quantity quantity × size, in the stock unit — NULL means "same as quantity".
 * On MySQL they are added with ALGORITHM=INSTANT: no table copy, no lock that could freeze a live POS.
 * If INSTANT is not possible the statement fails instead of copying the table.
 */
return new class extends Migration
{
    private const LINE_TABLES = [
        'sales_order_lines', 'sales_return_lines', 'goods_receipt_lines', 'purchase_order_lines',
        'purchase_bill_lines', 'purchase_return_lines', 'stock_adjustment_lines', 'stock_transfer_lines',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('product_pack_units')) {
            Schema::create('product_pack_units', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('name', 40);
                // How many of the product's own unit are in one of this pack. 1 = the unit itself.
                $table->unsignedInteger('factor');
                $table->decimal('sale_price', 14, 2)->nullable();
                $table->string('barcode', 100)->nullable()->unique();
                $table->boolean('can_sell')->default(true);
                $table->boolean('can_buy')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['product_id', 'factor']);
            });
        }

        $mysql = DB::connection()->getDriverName() === 'mysql';
        if ($mysql) {
            // Even INSTANT needs a moment's table lock. If a long query holds the table, wait at most 10 s
            // and fail (deploy reports the tenant, re-run later) — never queue a live counter behind it.
            DB::statement('SET SESSION lock_wait_timeout = 10');
        }
        foreach (self::LINE_TABLES as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'base_quantity')) {
                continue;
            }
            if ($mysql) {
                DB::statement('ALTER TABLE `' . $name . '` '
                    . 'ADD COLUMN `pack_unit_id` BIGINT UNSIGNED NULL, '
                    . 'ADD COLUMN `pack_factor` INT UNSIGNED NULL, '
                    . 'ADD COLUMN `base_quantity` DECIMAL(15,3) NULL, '
                    . 'ALGORITHM=INSTANT');
            } else {
                Schema::table($name, function (Blueprint $table) {
                    $table->unsignedBigInteger('pack_unit_id')->nullable();
                    $table->unsignedInteger('pack_factor')->nullable();
                    $table->decimal('base_quantity', 15, 3)->nullable();
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
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'base_quantity')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn(['pack_unit_id', 'pack_factor', 'base_quantity']);
                });
            }
        }
        Schema::dropIfExists('product_pack_units');
    }
};
