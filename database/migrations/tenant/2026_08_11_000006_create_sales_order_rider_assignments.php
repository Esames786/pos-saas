<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('sales_order_rider_assignments')) {
            return;
        }

        Schema::connection('tenant')->create('sales_order_rider_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('from_delivery_rider_id')->nullable()->constrained('delivery_riders')->nullOnDelete();
            $table->foreignId('to_delivery_rider_id')->nullable()->constrained('delivery_riders')->nullOnDelete();
            $table->string('from_rider_name')->nullable();
            $table->string('to_rider_name');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_name')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at');

            $table->index(['sales_order_id', 'created_at'], 'sale_rider_assignment_history_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sales_order_rider_assignments');
    }
};
