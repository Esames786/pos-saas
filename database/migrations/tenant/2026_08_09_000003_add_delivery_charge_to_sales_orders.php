<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DELIVERY-CHARGE-1 (Khatri onboarding) — customer-facing delivery charge on a sale. Additive +
 * idempotent; default 0 so every existing sale/report keeps its exact numbers. Non-zero ONLY for
 * delivery orders (controllers enforce); offline/Edge REFUSES the field (delivery is not offered
 * offline — keeps Edge intent hashes stable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('sales_orders', 'delivery_charge_amount')) {
            return;
        }
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            $table->decimal('delivery_charge_amount', 14, 2)->default(0)->after('service_charge_amount');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('delivery_charge_amount');
        });
    }
};
