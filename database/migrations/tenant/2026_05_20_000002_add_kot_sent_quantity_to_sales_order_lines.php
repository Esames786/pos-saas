<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'kot_sent_quantity')) {
            Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
                $table->decimal('kot_sent_quantity', 10, 3)->default(0)->after('kot_sent');
            });
        }

        // Backfill: lines already marked kot_sent=true were fully sent at their current qty
        DB::connection('tenant')
            ->table('sales_order_lines')
            ->where('kot_sent', true)
            ->where('kot_sent_quantity', 0)
            ->update(['kot_sent_quantity' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('kot_sent_quantity');
        });
    }
};
