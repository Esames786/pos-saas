<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'modifiers')) {
            Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
                $table->json('modifiers')->nullable()->after('kitchen_note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('sales_order_lines', 'modifiers')) {
            Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
                $table->dropColumn('modifiers');
            });
        }
    }
};
