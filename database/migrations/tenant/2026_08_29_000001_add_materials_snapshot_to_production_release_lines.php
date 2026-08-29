<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-KITCHEN-MATERIALS-1 — the kitchen sheet prints what each dish takes.
 *
 * The customer's quotation already says "Chicken (Regular) 15 KG (us)" under
 * every line; the kitchen sheet said nothing, so the man cooking it had the
 * consolidated total at the foot of the page and no way to tell which dish it
 * belonged to.
 *
 * Snapshotted, not read live: a production release is frozen ON PURPOSE, and a
 * sheet already on the kitchen wall must not quietly change because someone
 * edited the quotation afterwards. Additive and nullable — releases frozen
 * before today keep printing exactly as they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('catering_production_release_lines', function (Blueprint $table) {
            $table->json('materials_snapshot')->nullable()->after('instructions');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('catering_production_release_lines', function (Blueprint $table) {
            $table->dropColumn('materials_snapshot');
        });
    }
};
