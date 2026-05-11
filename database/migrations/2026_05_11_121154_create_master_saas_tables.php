<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('central_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::connection('master')->create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_code')->unique();
            $table->string('business_name');
            $table->string('owner_name')->nullable();
            $table->string('owner_email')->nullable();
            $table->string('currency_code', 3)->default('PKR');
            $table->enum('status', ['pending', 'active', 'suspended', 'cancelled'])->default('pending');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('master')->create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->boolean('is_primary')->default(true);
            $table->enum('status', ['pending', 'active', 'inactive'])->default('pending');
            $table->timestamps();

            $table->index(['domain', 'status']);
        });

        Schema::connection('master')->create('tenant_databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('db_connection')->default('tenant');
            $table->string('db_host');
            $table->unsignedInteger('db_port')->default(3306);
            $table->string('db_database')->unique();
            $table->string('db_username');
            $table->text('db_password')->nullable();
            $table->enum('migration_status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->timestamps();
        });

        Schema::connection('master')->create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency_code', 3)->default('PKR');
            $table->enum('billing_period', ['monthly', 'yearly'])->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('master')->create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_key');
            $table->string('feature_value')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
        });

        Schema::connection('master')->create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->enum('status', ['trial', 'active', 'past_due', 'cancelled'])->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->string('gateway_code')->nullable();
            $table->string('gateway_customer_id')->nullable();
            $table->string('gateway_subscription_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('master')->create('route_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('route_name')->unique();
            $table->string('uri');
            $table->string('method', 50);
            $table->string('module_key')->nullable();
            $table->string('action_key')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('master')->create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->boolean('is_rtl')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('master')->create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('type', ['global', 'local', 'manual']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('payment_gateways');
        Schema::connection('master')->dropIfExists('languages');
        Schema::connection('master')->dropIfExists('route_catalogs');
        Schema::connection('master')->dropIfExists('subscriptions');
        Schema::connection('master')->dropIfExists('plan_features');
        Schema::connection('master')->dropIfExists('plans');
        Schema::connection('master')->dropIfExists('tenant_databases');
        Schema::connection('master')->dropIfExists('tenant_domains');
        Schema::connection('master')->dropIfExists('tenants');
        Schema::connection('master')->dropIfExists('central_users');
    }
};
