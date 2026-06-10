<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('stock_count_sessions')) {
            Schema::connection('tenant')->create('stock_count_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('count_no', 50)->unique();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

                $table->enum('status', ['draft', 'counting', 'review', 'posted', 'cancelled'])
                    ->default('draft')
                    ->index();

                $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamp('started_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();

                // 13F-2 will fill these when posting variances
                $table->foreignId('increase_stock_adjustment_id')->nullable()->constrained('stock_adjustments')->nullOnDelete();
                $table->foreignId('decrease_stock_adjustment_id')->nullable()->constrained('stock_adjustments')->nullOnDelete();

                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['branch_id', 'status']);
            });
        }

        if (!Schema::connection('tenant')->hasTable('stock_count_lines')) {
            Schema::connection('tenant')->create('stock_count_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_count_session_id')
                    ->constrained('stock_count_sessions')
                    ->cascadeOnDelete();

                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();

                $table->decimal('system_quantity', 18, 3)->default(0);
                $table->decimal('counted_quantity', 18, 3)->nullable();
                $table->decimal('variance_quantity', 18, 3)->default(0);

                $table->decimal('average_cost', 14, 4)->default(0);
                $table->decimal('variance_value', 14, 2)->default(0);

                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(
                    ['stock_count_session_id', 'product_id', 'product_variant_id'],
                    'stock_count_unique_product_variant'
                );

                $table->index(['product_id', 'product_variant_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_count_lines');
        Schema::connection('tenant')->dropIfExists('stock_count_sessions');
    }
};
