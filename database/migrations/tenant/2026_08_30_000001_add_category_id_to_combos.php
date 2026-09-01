<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS-COMBO-CATEGORY-1: give a combo an OPTIONAL category so deals can be grouped into their own POS
 * tabs (Al-Faham / Midnight / Platters / Deals) — the same additive pattern products already use.
 * Nullable + nullOnDelete: every existing combo (null) keeps showing under the single "Deals" tab.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('combos', 'category_id')) {
            Schema::connection('tenant')->table('combos', function (Blueprint $table) {
                $table->foreignId('category_id')->nullable()->after('branch_id')
                    ->constrained('categories')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('combos', 'category_id')) {
            Schema::connection('tenant')->table('combos', function (Blueprint $table) {
                $table->dropConstrainedForeignId('category_id');
            });
        }
    }
};
