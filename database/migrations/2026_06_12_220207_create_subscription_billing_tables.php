<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        if (!Schema::connection('master')->hasTable('subscription_invoices')) {
            Schema::connection('master')->create('subscription_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('invoice_no')->unique();

                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
                $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();

                $table->enum('invoice_type', ['subscription', 'upgrade', 'addon', 'manual'])->default('subscription');
                $table->enum('status', ['draft', 'issued', 'paid', 'partially_paid', 'void', 'overdue'])->default('issued');

                $table->string('currency_code', 3)->default('PKR');

                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('tax_amount', 12, 2)->default(0);
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->decimal('paid_amount', 12, 2)->default(0);
                $table->decimal('balance_amount', 12, 2)->default(0);

                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->date('due_date')->nullable();

                $table->timestamp('issued_at')->nullable();
                $table->timestamp('paid_at')->nullable();

                $table->text('notes')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('central_users')->nullOnDelete();

                $table->timestamps();

                $table->index(['tenant_id', 'status']);
                $table->index(['subscription_id', 'status']);
                $table->index('due_date');
            });
        }

        if (!Schema::connection('master')->hasTable('subscription_payments')) {
            Schema::connection('master')->create('subscription_payments', function (Blueprint $table) {
                $table->id();

                $table->foreignId('subscription_invoice_id')->constrained('subscription_invoices')->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('payment_gateway_id')->nullable()->constrained('payment_gateways')->nullOnDelete();

                $table->string('payment_method_code')->nullable();
                $table->decimal('amount', 12, 2);
                $table->string('currency_code', 3)->default('PKR');
                $table->date('payment_date');
                $table->string('reference_no')->nullable();

                $table->enum('status', ['pending', 'verified', 'rejected'])->default('verified');

                $table->text('notes')->nullable();
                $table->foreignId('verified_by_user_id')->nullable()->constrained('central_users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();

                $table->timestamps();

                $table->index(['tenant_id', 'status']);
                $table->index(['subscription_invoice_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('subscription_payments');
        Schema::connection('master')->dropIfExists('subscription_invoices');
    }
};
