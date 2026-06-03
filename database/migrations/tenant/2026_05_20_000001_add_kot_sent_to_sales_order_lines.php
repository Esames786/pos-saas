<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'kot_sent')) {
            Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
                $table->boolean('kot_sent')->default(false)->after('returned_quantity');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('kot_sent');
        });
    }
};
