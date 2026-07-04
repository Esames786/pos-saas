<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEPARTMENT-STOCK-1 (DEPT-2) — department stock CUSTODY sub-ledger.
 *
 * Branch stock (stock_balances/stock_ledgers) stays the official/financial
 * truth. Department stock only tracks which department is holding how much
 * of it INSIDE a branch. Issue/return/transfer documents move custody only:
 * they never touch stock_balances, stock_ledgers, InventoryService, or GL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('department_stock_balances')) {
            Schema::connection('tenant')->create('department_stock_balances', function (Blueprint $table) {
                $table->id();
                // variant/batch are nullable and MySQL unique indexes treat NULLs
                // as distinct — so uniqueness lives in an explicit key string,
                // same pattern as stock_balances.balance_key.
                $table->string('balance_key')->unique('dept_stock_bal_key_unique');
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
                $table->decimal('quantity_on_hand', 14, 3)->default(0);
                $table->decimal('average_cost', 14, 4)->default(0);
                $table->timestamps();

                $table->index(['branch_id', 'department_id'], 'dept_stock_bal_branch_dept_idx');
                $table->index(['product_id', 'product_variant_id'], 'dept_stock_bal_prod_var_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('department_stock_ledgers')) {
            Schema::connection('tenant')->create('department_stock_ledgers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
                // string on purpose (NOT enum): future department movement types
                // must not need an ALTER migration like stock_ledgers does.
                $table->string('movement_type', 50);
                $table->enum('direction', ['in', 'out']);
                $table->decimal('quantity', 14, 3);
                $table->decimal('unit_cost', 14, 4)->default(0);
                $table->decimal('total_cost', 14, 4)->default(0);
                $table->decimal('balance_after', 14, 3)->default(0);
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('reference_no')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['branch_id', 'department_id'], 'dept_stock_led_branch_dept_idx');
                $table->index(['product_id', 'product_variant_id'], 'dept_stock_led_prod_var_idx');
                $table->index(['movement_type', 'direction'], 'dept_stock_led_type_dir_idx');
                $table->index(['reference_type', 'reference_id'], 'dept_stock_led_ref_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('department_stock_transfers')) {
            Schema::connection('tenant')->create('department_stock_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->string('transfer_no', 60)->unique('dept_stock_tr_no_unique');
                $table->date('transfer_date');
                // issue    = branch custody pool -> department
                // return   = department -> branch custody pool
                // transfer = department -> department (same branch)
                $table->enum('transfer_type', ['issue', 'return', 'transfer']);
                $table->foreignId('from_department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->foreignId('to_department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
                $table->text('notes')->nullable();
                $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['branch_id', 'transfer_date'], 'dept_stock_tr_branch_date_idx');
                $table->index(['status', 'transfer_type'], 'dept_stock_tr_status_type_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('department_stock_transfer_lines')) {
            Schema::connection('tenant')->create('department_stock_transfer_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('department_stock_transfer_id')
                    ->constrained('department_stock_transfers', indexName: 'dept_stock_trl_tr_fk')
                    ->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
                $table->decimal('quantity', 14, 3);
                $table->decimal('unit_cost', 14, 4)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['product_id', 'product_variant_id'], 'dept_stock_trl_prod_var_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('department_stock_transfer_lines');
        Schema::connection('tenant')->dropIfExists('department_stock_transfers');
        Schema::connection('tenant')->dropIfExists('department_stock_ledgers');
        Schema::connection('tenant')->dropIfExists('department_stock_balances');
    }
};
