<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('customers', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('customers', 'opening_balance')) {
                $table->decimal('opening_balance', 15, 4)->default(0)->after('tax_number');
            }
            if (! Schema::connection('tenant')->hasColumn('customers', 'current_balance')) {
                $table->decimal('current_balance', 15, 4)->default(0)->after('opening_balance');
            }
            if (! Schema::connection('tenant')->hasColumn('customers', 'credit_limit')) {
                $table->decimal('credit_limit', 15, 4)->nullable()->after('current_balance');
            }
            if (! Schema::connection('tenant')->hasColumn('customers', 'credit_days')) {
                $table->unsignedInteger('credit_days')->nullable()->after('credit_limit');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('customers', function (Blueprint $table) {
            foreach (['opening_balance', 'current_balance', 'credit_limit', 'credit_days'] as $col) {
                if (Schema::connection('tenant')->hasColumn('customers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
