<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // Create promotions table
        Schema::connection('tenant')->create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('promotion_type', 50)->default('order'); // order, product, category
            $table->string('discount_type', 50); // fixed, percent
            $table->decimal('discount_value', 14, 4);
            $table->decimal('max_discount_amount', 14, 2)->nullable();
            $table->decimal('min_order_amount', 14, 2)->default(0);
            $table->json('order_types')->nullable(); // quick_sale, takeaway, dine_in, delivery
            $table->boolean('requires_code')->default(false);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('status', 50)->default('active');
            $table->unsignedInteger('priority')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
            $table->index(['code', 'status']);
        });

        // Create promotion_targets table
        Schema::connection('tenant')->create('promotion_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('target_type', 50); // product, category, variant
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });

        // Create service_charge_settings table
        Schema::connection('tenant')->create('service_charge_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('charge_type', 50)->default('percent'); // fixed, percent
            $table->decimal('charge_value', 14, 4)->default(0);
            $table->json('order_types')->nullable();
            $table->boolean('is_taxable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('branch_id');
        });

        // Create void_reasons table
        Schema::connection('tenant')->create('void_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('reason_type', 50)->default('void'); // void, discount, return, cancel, wastage, other
            $table->boolean('requires_manager_approval')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Create manager_pins table
        Schema::connection('tenant')->create('manager_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('pin_hash');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });

        // Create manager_approvals table
        Schema::connection('tenant')->create('manager_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('approval_no')->unique();
            $table->string('action_type', 80); // manual_discount, void_item, cancel_order, return_sale, price_override
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 14, 2)->nullable();
            $table->json('payload')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['action_type', 'reference_type', 'reference_id']);
        });

        // Extend sales_ledgers.entry_type enum
        Schema::connection('tenant')->table('sales_ledgers', function (Blueprint $table) {
            // MySQL ALTER ENUM is complex — use raw statement
            DB::connection('tenant')->statement(
                "ALTER TABLE sales_ledgers MODIFY COLUMN entry_type ENUM('sale_total', 'sale_payment', 'sale_discount', 'sale_tax', 'sale_return', 'refund', 'service_charge', 'tip')"
            );
        });

        // Patch sales_orders
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('sales_orders', 'promotion_id')) {
                $table->foreignId('promotion_id')->nullable()->after('customer_id')->constrained('promotions')->nullOnDelete();
            }
            if (!Schema::connection('tenant')->hasColumn('sales_orders', 'promo_code')) {
                $table->string('promo_code')->nullable()->after('promotion_id');
            }
            if (!Schema::connection('tenant')->hasColumn('sales_orders', 'service_charge_amount')) {
                $table->decimal('service_charge_amount', 14, 2)->default(0)->after('tax_amount');
            }
            if (!Schema::connection('tenant')->hasColumn('sales_orders', 'tip_amount')) {
                $table->decimal('tip_amount', 14, 2)->default(0)->after('service_charge_amount');
            }
            if (!Schema::connection('tenant')->hasColumn('sales_orders', 'manager_approval_id')) {
                $table->foreignId('manager_approval_id')->nullable()->after('tip_amount')->constrained('manager_approvals')->nullOnDelete();
            }
        });

        // Patch sales_order_lines
        Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'void_reason_id')) {
                $table->foreignId('void_reason_id')->nullable()->after('kot_sent_quantity')->constrained('void_reasons')->nullOnDelete();
            }
            if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'manager_approval_id')) {
                $table->foreignId('manager_approval_id')->nullable()->after('void_reason_id')->constrained('manager_approvals')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('manager_approvals');
        Schema::connection('tenant')->dropIfExists('manager_pins');
        Schema::connection('tenant')->dropIfExists('void_reasons');
        Schema::connection('tenant')->dropIfExists('service_charge_settings');
        Schema::connection('tenant')->dropIfExists('promotion_targets');
        Schema::connection('tenant')->dropIfExists('promotions');

        // Revert sales_ledgers enum
        DB::connection('tenant')->statement(
            "ALTER TABLE sales_ledgers MODIFY COLUMN entry_type ENUM('sale_total', 'sale_payment', 'sale_discount', 'sale_tax', 'sale_return', 'refund')"
        );

        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            $columns = Schema::connection('tenant')->getColumnListing('sales_orders');
            foreach (['manager_approval_id', 'tip_amount', 'service_charge_amount', 'promo_code', 'promotion_id'] as $col) {
                if (in_array($col, $columns)) {
                    $table->dropForeignKeyIfExists($col);
                    $table->dropColumn($col);
                }
            }
        });

        Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
            $columns = Schema::connection('tenant')->getColumnListing('sales_order_lines');
            foreach (['manager_approval_id', 'void_reason_id'] as $col) {
                if (in_array($col, $columns)) {
                    $table->dropForeignKeyIfExists($col);
                    $table->dropColumn($col);
                }
            }
        });
    }
};
