<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operational running-balance history for cash/bank accounts.
        // NOT the General Ledger — opening balance / manual adjustment only (FIN-3).
        Schema::connection('tenant')->create('cash_bank_account_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cash_bank_account_id')->constrained('cash_bank_accounts')->cascadeOnDelete();

            $table->date('transaction_date');

            // in | out
            $table->string('direction', 3);

            $table->decimal('amount', 15, 4);
            $table->decimal('balance_after', 15, 4);

            // opening_balance | manual_adjustment
            $table->string('transaction_type', 40);

            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('cash_bank_account_id');
            $table->index('transaction_date');
            $table->index('transaction_type');
            $table->index('direction');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cash_bank_account_transactions');
    }
};
