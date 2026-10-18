<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRODUCT-SCREEN-V2 — what a medical store asks about a medicine: its salt (generic), strength, and
 * whether it needs a prescription. Nullable / false by default, so every existing product reads as before.
 * The counter searches the salt too ("paracetamol" finds every brand).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');
        if (! $schema->hasColumn('products', 'generic_name')) {
            $schema->table('products', fn (Blueprint $t) => $t->string('generic_name', 190)->nullable()->after('name'));
        }
        if (! $schema->hasColumn('products', 'strength')) {
            $schema->table('products', fn (Blueprint $t) => $t->string('strength', 60)->nullable()->after('generic_name'));
        }
        if (! $schema->hasColumn('products', 'needs_prescription')) {
            $schema->table('products', fn (Blueprint $t) => $t->boolean('needs_prescription')->default(false)->after('strength'));
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');
        foreach (['needs_prescription', 'strength', 'generic_name'] as $col) {
            if ($schema->hasColumn('products', $col)) {
                $schema->table('products', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
