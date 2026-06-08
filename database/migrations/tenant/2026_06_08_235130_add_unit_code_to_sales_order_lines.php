<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'unit_code')) {
            Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
                $table->string('unit_code', 20)->nullable()->after('variant_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('sales_order_lines', 'unit_code')) {
            Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
                $table->dropColumn('unit_code');
            });
        }
    }
};
