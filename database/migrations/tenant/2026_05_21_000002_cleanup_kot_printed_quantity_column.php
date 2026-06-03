<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('sales_order_lines', 'kot_printed_quantity')) {
            Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
                $table->dropColumn('kot_printed_quantity');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'kot_printed_quantity')) {
            Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
                $table->decimal('kot_printed_quantity', 10, 4)->default(0)->after('kot_sent_quantity');
            });
        }
    }
};
