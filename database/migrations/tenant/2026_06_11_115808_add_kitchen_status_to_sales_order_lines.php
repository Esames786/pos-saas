<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'kitchen_status')) {
                $table->string('kitchen_status', 30)
                    ->nullable()
                    ->default('pending')
                    ->after('kitchen_note');
            }

            if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'kitchen_started_at')) {
                $table->timestamp('kitchen_started_at')->nullable()->after('kitchen_status');
            }

            if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'kitchen_ready_at')) {
                $table->timestamp('kitchen_ready_at')->nullable()->after('kitchen_started_at');
            }

            if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'kitchen_completed_at')) {
                $table->timestamp('kitchen_completed_at')->nullable()->after('kitchen_ready_at');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
            foreach (['kitchen_completed_at', 'kitchen_ready_at', 'kitchen_started_at', 'kitchen_status'] as $col) {
                if (Schema::connection('tenant')->hasColumn('sales_order_lines', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
