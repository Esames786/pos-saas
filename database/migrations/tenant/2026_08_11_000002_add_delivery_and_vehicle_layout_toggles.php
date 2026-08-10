<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Client 2026-08-11: the bill prints delivery details / vehicle / order type but the layout editor
// had no switches for them. Default ON so nothing disappears from existing prints.
return new class extends Migration
{
    private const COLUMNS = ['show_delivery_details', 'show_vehicle_number', 'show_order_type'];

    public function up(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (! Schema::connection('tenant')->hasColumn('receipt_layout_settings', $column)) {
                    $table->boolean($column)->default(true);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (Schema::connection('tenant')->hasColumn('receipt_layout_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
