<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_key')->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('batch_no')->nullable();
            $table->date('manufactured_at')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('received_date')->nullable();
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->enum('status', ['active', 'expired', 'closed'])->default('active');
            $table->timestamps();

            $table->index(['branch_id', 'product_id', 'product_variant_id']);
            $table->index(['expiry_date', 'status']);
        });

        Schema::connection('tenant')->create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->string('balance_key')->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->decimal('quantity_on_hand', 14, 3)->default(0);
            $table->decimal('average_cost', 14, 4)->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'product_id', 'product_variant_id']);
            $table->index(['inventory_batch_id']);
        });

        Schema::connection('tenant')->create('stock_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->enum('movement_type', [
                'opening_stock',
                'adjustment_in',
                'adjustment_out',
                'wastage',
                'transfer_in',
                'transfer_out',
                'purchase',
                'purchase_return',
                'sale',
                'sale_return',
                'recipe_consumption',
                'production_in',
            ]);
            $table->enum('direction', ['in', 'out']);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->decimal('balance_after', 14, 3)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_no')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'product_id', 'product_variant_id']);
            $table->index(['movement_type', 'direction']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::connection('tenant')->create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no')->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->enum('adjustment_type', ['opening', 'increase', 'decrease', 'wastage'])->default('increase');
            $table->date('adjustment_date');
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'adjustment_date']);
        });

        Schema::connection('tenant')->create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->string('batch_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no')->unique();
            $table->foreignId('from_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('transfer_date');
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['from_branch_id', 'to_branch_id', 'transfer_date']);
        });

        Schema::connection('tenant')->create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_transfer_lines');
        Schema::connection('tenant')->dropIfExists('stock_transfers');
        Schema::connection('tenant')->dropIfExists('stock_adjustment_lines');
        Schema::connection('tenant')->dropIfExists('stock_adjustments');
        Schema::connection('tenant')->dropIfExists('stock_ledgers');
        Schema::connection('tenant')->dropIfExists('stock_balances');
        Schema::connection('tenant')->dropIfExists('inventory_batches');
    }
};
