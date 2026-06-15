<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('customer_payments', function (Blueprint $table) {
            $table->id();

            $table->string('payment_no')->unique();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            // Optional: when set, the payment also raises a cash/bank balance.
            $table->foreignId('cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->nullOnDelete();

            $table->date('payment_date');
            $table->decimal('amount', 15, 4);
            $table->string('payment_method')->nullable();
            $table->string('reference_no')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('customer_id');
            $table->index('branch_id');
            $table->index('sales_order_id');
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('customer_payments');
    }
};
