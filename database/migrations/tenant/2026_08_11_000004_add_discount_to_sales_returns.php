<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('sales_returns', function (Blueprint $table) {
            $table->decimal('discount_amount', 14, 2)->default(0)->after('subtotal');
        });

        Schema::connection('tenant')->table('sales_return_lines', function (Blueprint $table) {
            $table->decimal('discount_amount', 14, 2)->default(0)->after('unit_price');
        });

        // Historical return line_total excluded tax while grand_total included it.
        // Normalize the line meaning to the final refunded line value.
        DB::connection('tenant')->table('sales_return_lines')
            ->where('tax_amount', '!=', 0)
            ->update(['line_total' => DB::raw('line_total + tax_amount')]);
    }

    public function down(): void
    {
        DB::connection('tenant')->table('sales_return_lines')
            ->where('tax_amount', '!=', 0)
            ->update(['line_total' => DB::raw('line_total - tax_amount')]);

        Schema::connection('tenant')->table('sales_return_lines', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });

        Schema::connection('tenant')->table('sales_returns', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
