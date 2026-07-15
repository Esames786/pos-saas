<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEGATIVE-STOCK-SETTING-1B: per-branch opt-in for selling stock-out items.
 * Default OFF — every existing and new branch keeps today's insufficient-stock
 * blocking until an Owner/Admin explicitly enables it on the branch form.
 * Design: docs/audits/negative-stock-setting-design-2026-07.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('branches', 'allow_negative_stock')) {
                $table->boolean('allow_negative_stock')->default(false)->after('business_type');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('branches', 'allow_negative_stock')) {
                $table->dropColumn('allow_negative_stock');
            }
        });
    }
};
