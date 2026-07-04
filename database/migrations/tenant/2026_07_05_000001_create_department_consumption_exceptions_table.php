<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEPT-3A — shadow department consumption exceptions.
 *
 * When a paid sale's official stock-ledger out movements cannot be mirrored
 * into department custody (no mapping / insufficient custody), the sale is
 * NEVER blocked — the problem is recorded here for the exception report.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('department_consumption_exceptions')) {
            return;
        }

        Schema::connection('tenant')->create('department_consumption_exceptions', function (Blueprint $table) {
            $table->id();
            // Explicit key string (ledger id + reason) — nullable columns make a
            // composite unique unreliable in MySQL (same lesson as balance_key).
            $table->string('exception_key')->unique('dept_cons_exc_key_unique');
            $table->foreignId('stock_ledger_id')->nullable()->constrained('stock_ledgers')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('movement_type', 50)->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            // no_department_mapping | insufficient_department_stock |
            // invalid_stock_ledger | already_processed
            $table->string('reason', 50);
            $table->enum('status', ['open', 'resolved', 'ignored'])->default('open');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_no')->nullable();
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status'], 'dept_cons_exc_branch_status_idx');
            $table->index(['department_id', 'status'], 'dept_cons_exc_dept_status_idx');
            $table->index(['product_id'], 'dept_cons_exc_product_idx');
            $table->index(['reason', 'status'], 'dept_cons_exc_reason_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('department_consumption_exceptions');
    }
};
