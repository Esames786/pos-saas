<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-SLICE-2: Material Rate Book + estimate cost snapshots.
 *
 * catering_material_rates is the CATERING COMMERCIAL costing rate — a
 * versioned, effective-dated quote-rate history (Raw Chicken 720/KG →
 * 800/KG). It NEVER writes inventory average cost, FEFO batch cost, or POS
 * selling prices; those authorities are untouched (spec §6).
 *
 * catering_cost_snapshots freeze a costing run (per-line ingredient
 * breakdown JSON) for a specific estimate version, so a sent quote's costing
 * basis stays auditable after rates move on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('catering_material_rates')) {
            Schema::connection('tenant')->create('catering_material_rates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->decimal('rate', 14, 4);
                // The unit the rate is quoted per (usually the product's stock unit).
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->date('effective_from');
                $table->string('note')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['product_id', 'effective_from'], 'catering_material_rates_product_date_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_cost_snapshots')) {
            Schema::connection('tenant')->create('catering_cost_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_estimate_id')->constrained('catering_estimates')->cascadeOnDelete();
                $table->json('breakdown'); // per-line: product, recipe, ingredients, rates, costs
                $table->decimal('total_material_cost', 14, 2)->default(0);
                $table->timestamp('computed_at');
                $table->foreignId('computed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('catering_estimate_id', 'catering_cost_snapshots_estimate_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('catering_cost_snapshots');
        Schema::connection('tenant')->dropIfExists('catering_material_rates');
    }
};
