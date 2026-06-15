<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('payment_methods', 'cash_bank_account_id')) {
            Schema::connection('tenant')->table('payment_methods', function (Blueprint $table) {
                // Optional link: which cash/bank account receives money for this method (FIN-7B).
                $table->foreignId('cash_bank_account_id')->nullable()->after('is_cash_drawer')
                    ->constrained('cash_bank_accounts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('payment_methods', 'cash_bank_account_id')) {
            Schema::connection('tenant')->table('payment_methods', function (Blueprint $table) {
                $table->dropConstrainedForeignId('cash_bank_account_id');
            });
        }
    }
};
