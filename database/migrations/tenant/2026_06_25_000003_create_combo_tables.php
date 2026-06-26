<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('combos')) {
            Schema::connection('tenant')->create('combos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('code')->nullable();
                $table->string('name');
                $table->decimal('price', 14, 2)->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('status', 50)->default('active');
                $table->text('description')->nullable();
                $table->timestamps();

                $table->index(['branch_id', 'status']);
                $table->unique(['branch_id', 'code']);
            });
        }

        if (!Schema::connection('tenant')->hasTable('combo_components')) {
            Schema::connection('tenant')->create('combo_components', function (Blueprint $table) {
                $table->id();
                $table->foreignId('combo_id')->constrained('combos')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->decimal('quantity', 14, 3)->default(1);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['combo_id', 'sort_order']);
            });
        }

        Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'parent_sales_order_line_id')) {
                $table->foreignId('parent_sales_order_line_id')
                    ->nullable()
                    ->after('sales_order_id')
                    ->constrained('sales_order_lines')
                    ->nullOnDelete();
            }

            if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'line_kind')) {
                $table->string('line_kind', 50)->default('standard')->after('parent_sales_order_line_id');
            }

            if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'combo_id')) {
                $table->foreignId('combo_id')->nullable()->after('line_kind')->constrained('combos')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
            foreach (['combo_id', 'parent_sales_order_line_id'] as $column) {
                if (Schema::connection('tenant')->hasColumn('sales_order_lines', $column)) {
                    $table->dropForeignKeyIfExists($column);
                    $table->dropColumn($column);
                }
            }

            if (Schema::connection('tenant')->hasColumn('sales_order_lines', 'line_kind')) {
                $table->dropColumn('line_kind');
            }
        });

        Schema::connection('tenant')->dropIfExists('combo_components');
        Schema::connection('tenant')->dropIfExists('combos');
    }
};
