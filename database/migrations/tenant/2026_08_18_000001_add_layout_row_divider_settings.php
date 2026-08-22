<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRINT-LAYOUT-ROWS-1 — decouple the item-row and TIME sizes from the document font, and add the
 * column-divider + category-header toggles.
 *
 * Every column is NULL / current-behavior by default: `item_font_size` and `time_font_size` NULL
 * mean "use the document's existing font_size / kot_font_size" (no scale change), dividers default
 * OFF (today's tickets have none) and the category header defaults ON (today's KOT prints it). So no
 * tenant's printed output changes on deploy — the values only take effect once an operator sets them
 * in Edit Layout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'item_font_size')) {
                $table->unsignedTinyInteger('item_font_size')->nullable()->after('kot_font_size');
            }
            if (! Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'time_font_size')) {
                $table->unsignedTinyInteger('time_font_size')->nullable()->after('item_font_size');
            }
            if (! Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_column_dividers')) {
                $table->boolean('show_column_dividers')->default(false)->after('time_font_size');
            }
            if (! Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_category_header')) {
                $table->boolean('show_category_header')->default(true)->after('show_column_dividers');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            foreach (['item_font_size', 'time_font_size', 'show_column_dividers', 'show_category_header'] as $col) {
                if (Schema::connection('tenant')->hasColumn('receipt_layout_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
