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
        $lineColumns = Schema::connection('tenant')->getColumnListing('sales_order_lines');

        Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) use ($lineColumns) {
            if (!in_array('kitchen_note', $lineColumns, true)) {
                $table->text('kitchen_note')->nullable()->after('line_total');
            }
            if (!in_array('kot_sent', $lineColumns, true)) {
                $table->boolean('kot_sent')->default(false)->after('kitchen_note');
            }
            if (!in_array('kot_sent_quantity', $lineColumns, true)) {
                $table->decimal('kot_sent_quantity', 18, 6)->default(0)->after('kot_sent');
            }
        });

        if (Schema::connection('tenant')->hasTable('print_jobs')) {
            DB::connection('tenant')
                ->table('print_jobs')
                ->where('print_status', 'pending')
                ->update(['print_status' => 'queued']);
        }
    }

    public function down(): void
    {
        // Keep columns for backward compatibility.
    }
};
