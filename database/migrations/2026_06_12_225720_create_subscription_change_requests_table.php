<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        if (Schema::connection('master')->hasTable('subscription_change_requests')) {
            return;
        }

        Schema::connection('master')->create('subscription_change_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            $table->foreignId('current_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('requested_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('requested_module_id')->nullable()->constrained('modules')->nullOnDelete();

            $table->foreignId('related_invoice_id')->nullable()->constrained('subscription_invoices')->nullOnDelete();

            $table->enum('type', ['upgrade', 'downgrade', 'addon', 'remove_addon'])->default('upgrade');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled', 'invoiced', 'paid'])->default('pending');

            // Tenant DB user id — NO FK from master DB.
            $table->unsignedBigInteger('requested_by_user_id')->nullable();

            $table->foreignId('approved_by_user_id')->nullable()->constrained('central_users')->nullOnDelete();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('central_users')->nullOnDelete();

            $table->text('customer_notes')->nullable();
            $table->text('admin_notes')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['type', 'status']);
            $table->index('related_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('subscription_change_requests');
    }
};
