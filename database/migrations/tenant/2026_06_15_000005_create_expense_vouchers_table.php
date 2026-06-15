<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('expense_vouchers', function (Blueprint $table) {
            $table->id();

            $table->string('voucher_no')->unique();

            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            // Restrict delete: cash/bank accounts are never hard-deleted, protect posted history.
            $table->foreignId('cash_bank_account_id')->constrained('cash_bank_accounts');

            $table->date('expense_date');
            $table->date('payment_date')->nullable();
            $table->string('payee_name')->nullable();

            // draft | posted | void
            $table->string('status', 20)->default('draft');

            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('total_amount', 15, 4)->default(0);

            $table->text('notes')->nullable();
            $table->string('receipt_path')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamps();

            $table->index('branch_id');
            $table->index('cash_bank_account_id');
            $table->index('expense_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('expense_vouchers');
    }
};
