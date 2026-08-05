<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 — additive schema.
 *
 * A shift freezes its business_date + timezone at OPEN time; every sale created under
 * that shift inherits the shift's business_date, so crossing midnight never moves an
 * order to the next business day. Actual timestamps (sale_date/created_at) stay truthful.
 * users.timezone is an optional per-user display override (user -> branch -> Asia/Karachi).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('shifts', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('shifts', 'business_date')) {
                $table->date('business_date')->nullable()->after('status');
            }
            if (! Schema::connection('tenant')->hasColumn('shifts', 'timezone_name')) {
                $table->string('timezone_name', 64)->nullable()->after('business_date');
            }
        });

        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('sales_orders', 'business_date')) {
                $table->date('business_date')->nullable()->after('sale_date');
                $table->index(['branch_id', 'business_date'], 'sales_orders_branch_business_date_idx');
            }
        });

        Schema::connection('tenant')->table('users', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('users', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('locale');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('sales_orders', 'business_date')) {
                $table->dropIndex('sales_orders_branch_business_date_idx');
                $table->dropColumn('business_date');
            }
        });

        Schema::connection('tenant')->table('shifts', function (Blueprint $table) {
            foreach (['business_date', 'timezone_name'] as $col) {
                if (Schema::connection('tenant')->hasColumn('shifts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::connection('tenant')->table('users', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('users', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};
