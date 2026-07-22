<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS-UX-2: capture the delivery address on delivery orders (nullable,
 * attribution-only — no stock/finance impact).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('sales_orders', 'delivery_address')) {
                $table->string('delivery_address', 500)->nullable()->after('delivery_rider_id');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('sales_orders', 'delivery_address')) {
                $table->dropColumn('delivery_address');
            }
        });
    }
};
