<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KOT-CANCEL-CROSS-1 — three KOT layout settings, asked by Khatri's owner on 28 Nov 2026.
 *
 *   show_cancel_cross     a cancel ticket's heading prints "XX CANCEL KOT #n XX", at least double size, so the kitchen
 *                         cannot read it as a fresh order (today a cancel ticket differs from the order's ticket only in
 *                         that one heading word, at the same size as every other big line).
 *   order_type_font_size  the order type line (DINE IN / TAKEAWAY / DELIVERY / QUICK SALE) gets its own size.
 *   table_font_size       the TABLE line gets its own size.
 *
 * Defaults keep every ticket exactly as it prints today: the switch OFF, both sizes NULL (= the KOT font, as now). The
 * same code prints for every business, so the change shows only where a branch sets it in Edit Layout (KOT).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        $schema->table('receipt_layout_settings', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('receipt_layout_settings', 'show_cancel_cross')) {
                $table->boolean('show_cancel_cross')->default(false)->after('show_deal_as_heading');
            }
            if (! $schema->hasColumn('receipt_layout_settings', 'order_type_font_size')) {
                $table->unsignedTinyInteger('order_type_font_size')->nullable()->after('customer_font_size');
            }
            if (! $schema->hasColumn('receipt_layout_settings', 'table_font_size')) {
                $table->unsignedTinyInteger('table_font_size')->nullable()->after('order_type_font_size');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        $schema->table('receipt_layout_settings', function (Blueprint $table) use ($schema) {
            foreach (['table_font_size', 'order_type_font_size', 'show_cancel_cross'] as $column) {
                if ($schema->hasColumn('receipt_layout_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
