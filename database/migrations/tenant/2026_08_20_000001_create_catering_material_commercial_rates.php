<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-COMMERCIAL-RATE-1 — what a material is CHARGED at, kept apart
 * from what it COSTS.
 *
 * The Material Rate Book (catering_material_rates) says chicken costs the
 * business 80 a kilo. That is a purchasing fact and it must keep meaning only
 * that. What the customer is charged for chicken is a commercial decision — 100
 * a kilo, or 140 on a premium counter, or 90 in a wedding package — and it moves
 * for entirely different reasons.
 *
 * Conflating them is the mistake this whole costing design has been avoiding
 * everywhere else, so the commercial rate gets its own book rather than a second
 * column on the cost one.
 *
 *   catering_material_rates              what we pay      (internal, unchanged)
 *   catering_material_commercial_rates   what we charge   (this table)
 *
 * Effective-dated, like its cost counterpart: raising chicken to 120 records a
 * new row rather than overwriting the 100 that quotations were priced at. A
 * quotation from last month must stay explicable.
 *
 * HISTORY IS APPEND-ONLY, INCLUDING WITHIN A DAY. An earlier design put a unique
 * key on (product_id, effective_from), which quietly made the book lossy at the
 * one moment it matters most: chicken raised to 100 at nine in the morning and
 * to 120 at two in the afternoon would have left no trace that 100 was ever the
 * house rate — and a quotation applied against it at eleven would have become
 * unexplainable. Two rows on one date are not an ambiguity to be designed away;
 * they are two commercial decisions, and the later one is simply the current
 * one. "Current" resolves by effective_from, then by id, so insertion order
 * settles a same-day tie.
 *
 * The unit is REQUIRED. A rate of 120 means nothing until it says 120 per what,
 * and every downstream decision — whether a cost block may follow this rate,
 * whether a quotation may be repriced from it — is a comparison of units before
 * it is a comparison of numbers. Nullable here would push that check to the
 * point of arithmetic, which is exactly where dimensional nonsense (500 GM x 120
 * per KG) stops being catchable.
 *
 * This is MASTER data — a caterer's price list — so a transaction reset keeps
 * it, exactly as it keeps recipes and the cost book.
 */
return new class extends Migration
{
    private const TABLE = 'catering_material_commercial_rates';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable(self::TABLE)) {
            return;
        }

        Schema::connection('tenant')->create(self::TABLE, function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id');
            $table->foreign('product_id', 'cmcr_product_fk')
                ->references('id')->on('products')->cascadeOnDelete();

            $table->decimal('rate', 14, 4);

            // Not nullable: a charge rate without a unit cannot be safely
            // compared with the unit a cost block consumes the material in.
            $table->unsignedBigInteger('unit_id');
            $table->date('effective_from');

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // Deliberately NOT unique — see the note above. The book records
            // every commercial decision, including two on the same day.
            $table->index(['product_id', 'effective_from'], 'cmcr_product_date_idx');
            $table->index('effective_from', 'cmcr_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists(self::TABLE);
    }
};
