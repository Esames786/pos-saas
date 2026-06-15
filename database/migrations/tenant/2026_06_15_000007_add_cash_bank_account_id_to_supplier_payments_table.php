<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('supplier_payments', 'cash_bank_account_id')) {
            Schema::connection('tenant')->table('supplier_payments', function (Blueprint $table) {
                // Optional link: when set, the payment also moves a cash/bank balance (FIN-5).
                $table->foreignId('cash_bank_account_id')->nullable()->after('branch_id')
                    ->constrained('cash_bank_accounts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('supplier_payments', 'cash_bank_account_id')) {
            Schema::connection('tenant')->table('supplier_payments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('cash_bank_account_id');
            });
        }
    }
};
