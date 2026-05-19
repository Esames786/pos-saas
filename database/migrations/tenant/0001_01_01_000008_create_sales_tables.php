<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('code', 50)->nullable()->unique();
            $table->string('name');
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_number', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->index(['phone', 'email']);
        });

        Schema::connection('tenant')->create('payment_methods', function (Blueprint $table) {
            $table->id();

            $table->string('code', 50)->unique();
            $table->string('name');

            $table->enum('method_type', [
                'cash',
                'card',
                'bank_transfer',
                'cheque',
                'wallet',
                'other',
            ])->default('cash');

            $table->boolean('requires_reference')->default(false);
            $table->boolean('is_cash_drawer')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        Schema::connection('tenant')->create('sales_orders', function (Blueprint $table) {
            $table->id();

            $table->string('sale_no')->unique();

            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('terminal_id')->nullable()->constrained('terminals')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->string('customer_email')->nullable();

            $table->enum('order_source', ['pos', 'manual'])->default('manual');
            $table->enum('order_type', ['quick_sale', 'takeaway', 'dine_in', 'delivery'])->default('quick_sale');

            $table->dateTime('sale_date');

            $table->decimal('subtotal', 14, 2)->default(0);

            $table->enum('discount_type', ['none', 'fixed', 'percent'])->default('none');
            $table->decimal('discount_value', 14, 4)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);

            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);

            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('change_amount', 14, 2)->default(0);

            $table->enum('status', [
                'draft',
                'held',
                'paid',
                'cancelled',
                'partially_returned',
                'returned',
            ])->default('draft');

            $table->boolean('inventory_posted')->default(false);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['branch_id', 'terminal_id', 'shift_id']);
            $table->index(['sale_date', 'status']);
            $table->index(['customer_id']);
        });

        Schema::connection('tenant')->create('sales_order_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->string('product_name');
            $table->string('variant_name')->nullable();

            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2)->default(0);

            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('cost_total', 14, 4)->default(0);

            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);

            $table->decimal('returned_quantity', 14, 3)->default(0);

            $table->timestamps();

            $table->index(['product_id', 'product_variant_id']);
        });

        Schema::connection('tenant')->create('sale_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();

            $table->decimal('amount', 14, 2);
            $table->decimal('tendered_amount', 14, 2)->nullable();
            $table->decimal('change_amount', 14, 2)->default(0);

            $table->string('bank_name')->nullable();
            $table->string('account_no')->nullable();
            $table->string('transaction_ref')->nullable();

            $table->string('card_last_four', 10)->nullable();

            $table->string('cheque_no')->nullable();
            $table->date('cheque_date')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['sales_order_id', 'payment_method_id']);
        });

        Schema::connection('tenant')->create('sales_ledgers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('sale_payment_id')->nullable()->constrained('sale_payments')->nullOnDelete();

            $table->enum('entry_type', [
                'sale_total',
                'sale_payment',
                'sale_discount',
                'sale_tax',
                'sale_return',
                'refund',
            ]);

            $table->enum('direction', ['debit', 'credit']);

            $table->decimal('amount', 14, 2);
            $table->string('reference_no')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['branch_id', 'entry_type']);
            $table->index(['sales_order_id']);
        });

        Schema::connection('tenant')->create('sales_returns', function (Blueprint $table) {
            $table->id();

            $table->string('return_no')->unique();

            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->dateTime('return_date');

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);

            $table->enum('refund_method', ['cash', 'bank_transfer', 'card', 'other'])->nullable();
            $table->decimal('refund_amount', 14, 2)->default(0);

            $table->enum('status', ['posted', 'cancelled'])->default('posted');

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('reason')->nullable();

            $table->timestamps();

            $table->index(['sales_order_id', 'branch_id']);
        });

        Schema::connection('tenant')->create('sales_return_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->cascadeOnDelete();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sales_return_lines');
        Schema::connection('tenant')->dropIfExists('sales_returns');
        Schema::connection('tenant')->dropIfExists('sales_ledgers');
        Schema::connection('tenant')->dropIfExists('sale_payments');
        Schema::connection('tenant')->dropIfExists('sales_order_lines');
        Schema::connection('tenant')->dropIfExists('sales_orders');
        Schema::connection('tenant')->dropIfExists('payment_methods');
        Schema::connection('tenant')->dropIfExists('customers');
    }
};
