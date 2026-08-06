<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 — bind a restaurant table session to the shift that opened it,
 * so shift-close can deterministically block on open checks (instead of guessing via held orders),
 * and so the table's business_date is anchored to that shift.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('restaurant_table_sessions', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('restaurant_table_sessions', 'opened_shift_id')) {
                $table->foreignId('opened_shift_id')->nullable()->after('opened_by_user_id')
                    ->constrained('shifts')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('restaurant_table_sessions', 'business_date')) {
                $table->date('business_date')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('restaurant_table_sessions', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('restaurant_table_sessions', 'opened_shift_id')) {
                $table->dropConstrainedForeignId('opened_shift_id');
            }
            if (Schema::connection('tenant')->hasColumn('restaurant_table_sessions', 'business_date')) {
                $table->dropColumn('business_date');
            }
        });
    }
};
