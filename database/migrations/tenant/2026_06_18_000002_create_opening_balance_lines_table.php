<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('opening_balance_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('opening_balance_batch_id')->constrained('opening_balance_batches')->cascadeOnDelete();

            // GL account this opening amount posts to.
            $table->foreignId('account_id')->constrained('accounts');

            // Set only for cash/bank opening lines (operational balance sync).
            $table->foreignId('cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->nullOnDelete();

            // Reserved for customer/supplier sub-ledger opening (not wired this prompt).
            $table->string('party_type')->nullable();
            $table->unsignedBigInteger('party_id')->nullable();

            $table->string('description')->nullable();

            $table->decimal('debit', 15, 4)->default(0);
            $table->decimal('credit', 15, 4)->default(0);

            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('opening_balance_batch_id');
            $table->index('account_id');
            $table->index('cash_bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('opening_balance_lines');
    }
};
