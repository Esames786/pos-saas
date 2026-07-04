<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEPT-4 — end-day department physical count + reconciliation.
 *
 * Counts reconcile the department CUSTODY sub-ledger only. Approval adjusts
 * department_stock_balances/ledgers via DepartmentInventoryService — official
 * branch stock, stock_ledgers, and GL are never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('department_count_sessions')) {
            Schema::connection('tenant')->create('department_count_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
                $table->string('count_no', 60)->unique('dept_count_no_unique');
                $table->date('count_date');
                $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'cancelled'])->default('draft');
                $table->text('notes')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['branch_id', 'department_id', 'count_date'], 'dept_count_branch_dept_date_idx');
                $table->index(['status'], 'dept_count_status_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('department_count_lines')) {
            Schema::connection('tenant')->create('department_count_lines', function (Blueprint $table) {
                $table->id();
                // Explicit key: session-product-variant|0 — nullable variant makes a
                // composite unique unreliable in MySQL (same lesson as balance_key).
                $table->string('line_key')->unique('dept_count_line_key_unique');
                $table->foreignId('department_count_session_id')
                    ->constrained('department_count_sessions', indexName: 'dept_count_line_session_fk')
                    ->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->decimal('expected_qty', 14, 3)->default(0);
                $table->decimal('counted_qty', 14, 3)->default(0);
                $table->decimal('variance_qty', 14, 3)->default(0);
                $table->decimal('average_cost', 14, 4)->default(0);
                $table->decimal('variance_value', 14, 4)->default(0);
                $table->string('reason_code', 50)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['product_id', 'product_variant_id'], 'dept_count_line_prod_var_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('department_count_adjustments')) {
            Schema::connection('tenant')->create('department_count_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('department_count_session_id')
                    ->constrained('department_count_sessions', indexName: 'dept_count_adj_session_fk')
                    ->cascadeOnDelete();
                $table->foreignId('department_count_line_id')
                    ->constrained('department_count_lines', indexName: 'dept_count_adj_line_fk')
                    ->cascadeOnDelete();
                $table->foreignId('department_stock_ledger_id')->nullable()
                    ->constrained('department_stock_ledgers', indexName: 'dept_count_adj_ledger_fk')
                    ->nullOnDelete();
                $table->enum('direction', ['in', 'out']);
                $table->decimal('quantity', 14, 3);
                $table->decimal('unit_cost', 14, 4)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('department_count_adjustments');
        Schema::connection('tenant')->dropIfExists('department_count_lines');
        Schema::connection('tenant')->dropIfExists('department_count_sessions');
    }
};
