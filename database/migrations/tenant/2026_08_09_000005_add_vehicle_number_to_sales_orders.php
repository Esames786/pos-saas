<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VEHICLE-NUMBER-1: quick-sale (drive-through) capture field — the customer's vehicle
// number, printed on KOT/receipt/reminder so staff can match food to car. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('sales_orders', 'vehicle_number')) {
                $table->string('vehicle_number', 50)->nullable()->after('delivery_address');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('sales_orders', 'vehicle_number')) {
                $table->dropColumn('vehicle_number');
            }
        });
    }
};
