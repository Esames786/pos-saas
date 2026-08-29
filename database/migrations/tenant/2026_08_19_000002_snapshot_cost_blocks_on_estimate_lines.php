<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-LINE-SNAPSHOT-1 — a quotation remembers how it was priced.
 *
 * A booking line for a cost-block dish currently stores one number: the rate. If
 * the dish's blocks are edited afterwards — chicken re-rated, a ratio corrected,
 * making raised — nothing on the sent quotation can say what it was priced from.
 * The customer's copy and the system's explanation quietly disagree.
 *
 * So each block is copied onto the line at the moment it is quoted. The snapshot
 * carries what the customer is charged, how much material the kitchen was
 * expected to draw, and what that material cost at the time — the three numbers
 * this design keeps apart everywhere else, kept apart here too.
 *
 * It also carries the EVENT quantity separately from the ratio-derived default.
 * A wedding that needs three kilos of chicken where the ratio says two and a
 * half is a fact about that booking, not a correction to the dish, and it must
 * not follow the operator home to every other quotation.
 *
 * On the line itself:
 *
 *   calculated_rate       what the blocks add up to, per unit of dish
 *   rate                  what is actually being quoted (unchanged meaning)
 *   rate_override_reason  why those two differ, when they do
 *   lump_sum_amount       one-off charges belonging to THIS line
 *
 * Lump sums live on the line rather than on the document because a quotation can
 * hold several: a live counter on one line and packing on another are two
 * charges with two reasons, and collapsing them into the document's single
 * other-charge field loses which is which. The document's own other-charge stays
 * exactly what it was — a manual, whole-quotation adjustment.
 *
 * Additive. Existing lines have no snapshot and no calculated rate, and behave
 * precisely as they do today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('catering_estimate_lines')) {
            return;
        }

        Schema::connection('tenant')->table('catering_estimate_lines', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('catering_estimate_lines', 'calculated_rate')) {
                // NULL means "not priced from blocks" — a recipe line, a
                // free-text line, or anything quoted before this existed.
                $table->decimal('calculated_rate', 14, 2)->nullable()->after('rate');
            }
            if (! Schema::connection('tenant')->hasColumn('catering_estimate_lines', 'rate_override_reason')) {
                $table->string('rate_override_reason', 255)->nullable()->after('calculated_rate');
            }
            if (! Schema::connection('tenant')->hasColumn('catering_estimate_lines', 'lump_sum_amount')) {
                $table->decimal('lump_sum_amount', 14, 2)->default(0)->after('amount');
            }
        });

        if (Schema::connection('tenant')->hasTable('catering_estimate_line_cost_blocks')) {
            return;
        }

        Schema::connection('tenant')->create('catering_estimate_line_cost_blocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('catering_estimate_line_id');
            $table->foreign('catering_estimate_line_id', 'celcb_line_fk')
                ->references('id')->on('catering_estimate_lines')
                ->cascadeOnDelete();

            // Where it came from, for reference only. If the master block is
            // later deleted this becomes null and the snapshot still explains
            // the quotation — which is the entire point of copying it.
            $table->unsignedBigInteger('source_block_id')->nullable();

            $table->string('label', 120);
            $table->string('block_type', 20);          // material | charge
            $table->string('charge_basis', 20);        // per_unit | lump_sum
            $table->string('rate_basis', 20);          // per_dish_unit | per_material_unit
            $table->decimal('rate', 14, 4);

            // Material side. Null throughout for a charge block.
            $table->unsignedBigInteger('material_product_id')->nullable();
            $table->string('material_name', 255)->nullable();
            $table->string('unit_code', 50)->nullable();
            $table->decimal('quantity_per_unit', 12, 4)->nullable();

            // What the ratio said, and what this event actually settled on. Kept
            // apart so "reset to default" has something to reset TO.
            $table->decimal('default_material_qty', 14, 4)->nullable();
            $table->decimal('event_material_qty', 14, 4)->nullable();
            $table->boolean('is_overridden')->default(false);

            // What it cost at the time, from the Material Rate Book. Null when
            // the material had no rate — never zero, which would read as free.
            $table->decimal('material_rate_at_quote', 14, 4)->nullable();
            $table->decimal('material_cost', 14, 4)->nullable();

            // What the customer is charged for this part of the line.
            $table->decimal('amount', 14, 2)->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['catering_estimate_line_id', 'sort_order'], 'celcb_line_order_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('catering_estimate_line_cost_blocks');

        if (! Schema::connection('tenant')->hasTable('catering_estimate_lines')) {
            return;
        }

        Schema::connection('tenant')->table('catering_estimate_lines', function (Blueprint $table) {
            foreach (['calculated_rate', 'rate_override_reason', 'lump_sum_amount'] as $column) {
                if (Schema::connection('tenant')->hasColumn('catering_estimate_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
