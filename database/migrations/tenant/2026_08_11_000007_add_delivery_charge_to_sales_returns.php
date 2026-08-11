<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A return could never give back the DELIVERY CHARGE.
 *
 * SalesReturnService computed subtotal − discount + tax and stopped there, and the return's GL
 * posting had no reversal for 4150 Delivery Income. So when a customer sent an entire order back
 * and the counter handed over the full amount, the system recorded less money leaving than
 * actually did: Khatri kept 350 of "delivery income" the shop had already refunded, and the close
 * screen expected 350 more cash than the drawer held.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('sales_returns', 'delivery_charge_amount')) {
            return;
        }

        Schema::connection('tenant')->table('sales_returns', function (Blueprint $table) {
            $table->decimal('delivery_charge_amount', 14, 2)->default(0)->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_returns', function (Blueprint $table) {
            $table->dropColumn('delivery_charge_amount');
        });
    }
};
