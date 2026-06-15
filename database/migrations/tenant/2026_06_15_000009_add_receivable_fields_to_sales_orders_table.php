<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NOTE: sales_orders already has `paid_amount` — we reuse it (do NOT add amount_paid).
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('sales_orders', 'balance_due')) {
                $table->decimal('balance_due', 15, 4)->default(0)->after('paid_amount');
            }
            if (! Schema::connection('tenant')->hasColumn('sales_orders', 'payment_status')) {
                // unpaid | partial | paid — existing fully-paid sales default to paid.
                $table->string('payment_status', 20)->default('paid')->after('status');
            }
            if (! Schema::connection('tenant')->hasColumn('sales_orders', 'due_date')) {
                $table->date('due_date')->nullable()->after('payment_status');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            foreach (['balance_due', 'payment_status', 'due_date'] as $col) {
                if (Schema::connection('tenant')->hasColumn('sales_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
