<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('restaurant_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('tenant')->create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('restaurant_floor_id')->constrained('restaurant_floors')->cascadeOnDelete();
            $table->string('table_no', 20);
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->enum('status', ['available', 'occupied', 'reserved', 'bill_requested', 'cleaning', 'inactive'])
                ->default('available');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('tenant')->create('restaurant_waiters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('restaurant_table_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_no', 30)->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->foreignId('restaurant_waiter_id')->nullable()->constrained('restaurant_waiters')->nullOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('guest_count')->default(1);
            $table->enum('status', ['open', 'bill_requested', 'closed', 'cancelled'])->default('open');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            $table->foreignId('restaurant_floor_id')->nullable()->after('shift_id')
                ->constrained('restaurant_floors')->nullOnDelete();
            $table->foreignId('restaurant_table_id')->nullable()->after('restaurant_floor_id')
                ->constrained('restaurant_tables')->nullOnDelete();
            $table->foreignId('restaurant_table_session_id')->nullable()->after('restaurant_table_id')
                ->constrained('restaurant_table_sessions')->nullOnDelete();
            $table->foreignId('restaurant_waiter_id')->nullable()->after('restaurant_table_session_id')
                ->constrained('restaurant_waiters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restaurant_floor_id');
            $table->dropConstrainedForeignId('restaurant_table_id');
            $table->dropConstrainedForeignId('restaurant_table_session_id');
            $table->dropConstrainedForeignId('restaurant_waiter_id');
        });

        Schema::connection('tenant')->dropIfExists('restaurant_table_sessions');
        Schema::connection('tenant')->dropIfExists('restaurant_waiters');
        Schema::connection('tenant')->dropIfExists('restaurant_tables');
        Schema::connection('tenant')->dropIfExists('restaurant_floors');
    }
};
