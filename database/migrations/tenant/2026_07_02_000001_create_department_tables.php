<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEPARTMENT-FOUNDATION-1 — department master + category/product mapping ONLY.
 *
 * Departments are internal custody/responsibility areas INSIDE a branch
 * (Kitchen, Bar, Packing, Bakery, Main Store...). This phase is reporting/
 * mapping only: NO department stock balances, NO department stock ledgers,
 * NO GL impact. Branch stock remains the official/financial truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('departments')) {
            Schema::connection('tenant')->create('departments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->string('code', 50);
                $table->string('name');
                $table->text('description')->nullable();
                $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('status', ['active', 'inactive'])->default('active');
                // Preparation flags for future DEPT-2/DEPT-4 phases — no stock effect yet.
                $table->boolean('allow_stock_issue')->default(true);
                $table->boolean('require_end_day_count')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['branch_id', 'code'], 'departments_branch_code_unique');
                $table->index(['branch_id', 'status'], 'departments_branch_status_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('department_category_maps')) {
            Schema::connection('tenant')->create('department_category_maps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
                $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
                // Parent category mapped with include_children=true pulls in its
                // descendant categories automatically (categories.parent_id tree).
                $table->boolean('include_children')->default(true);
                $table->timestamps();

                $table->unique(['department_id', 'category_id'], 'dept_cat_maps_dept_cat_unique');
            });
        }

        if (! Schema::connection('tenant')->hasTable('department_product_overrides')) {
            Schema::connection('tenant')->create('department_product_overrides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                // include = claim this product even if its category is not mapped.
                // exclude = reject this product even if its category IS mapped.
                $table->enum('mapping_type', ['include', 'exclude'])->default('include');
                $table->timestamps();

                $table->unique(['department_id', 'product_id'], 'dept_prod_ovr_dept_prod_unique');
                $table->index(['product_id', 'mapping_type'], 'dept_prod_ovr_prod_type_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('department_product_overrides');
        Schema::connection('tenant')->dropIfExists('department_category_maps');
        Schema::connection('tenant')->dropIfExists('departments');
    }
};
