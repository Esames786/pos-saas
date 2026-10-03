<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GRN-BILL-CHARGES-1 — the receipt's extra charges have to reach the bill.
 *
 * GRN-EXTRA-CHARGES-1 gave a receipt somewhere to record cartage and put it into what the goods
 * cost. The bill, built from that same receipt, ignored it: a GRN of 28,700 of goods plus 400 of
 * cartage produced a 28,700 payable, so the supplier was owed 400 less than they had charged.
 *
 * Default 0, so every bill that already exists keeps the total it was posted with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('purchase_bills', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('purchase_bills', 'extra_charges')) {
                $table->decimal('extra_charges', 14, 4)->default(0)->after('subtotal');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('purchase_bills', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('purchase_bills', 'extra_charges')) {
                $table->dropColumn('extra_charges');
            }
        });
    }
};
