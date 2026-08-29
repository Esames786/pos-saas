<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-COMMERCIAL-RATE-1 — where a material block's rate came from.
 *
 * Two legitimate answers, and the system has to know which:
 *
 *   manual           somebody typed this rate for this dish. A premium counter
 *                    charging 140 for chicken while the book says 120 is not
 *                    wrong; it is the point.
 *
 *   commercial_book  this dish follows the house commercial rate, and should be
 *                    OFFERED the new one when the book moves.
 *
 * Every existing row is manual, because that is what every existing row is: a
 * number a person entered. Nothing becomes eligible for a global rate change
 * merely by this migration running, which is the property that makes it safe.
 *
 * Crucially the block's own rate stays the APPLIED rate either way. A linked
 * block does not read today's book when a quotation opens — it keeps what was
 * applied, and the book only says what is now recommended. The gap between them
 * is what Rate Impact exists to show, and closing it is always a deliberate act.
 *
 * The same column goes on the estimate-line snapshot, so a quotation remembers
 * whether its price followed the house rate or was chosen for that customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['catering_product_cost_blocks', 'catering_estimate_line_cost_blocks'] as $table) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                continue;
            }
            if (Schema::connection('tenant')->hasColumn($table, 'commercial_rate_source')) {
                continue;
            }

            Schema::connection('tenant')->table($table, function (Blueprint $blueprint) {
                $blueprint->string('commercial_rate_source', 20)
                    ->default('manual')
                    ->after('rate_basis');
            });
        }
    }

    public function down(): void
    {
        foreach (['catering_product_cost_blocks', 'catering_estimate_line_cost_blocks'] as $table) {
            if (Schema::connection('tenant')->hasColumn($table, 'commercial_rate_source')) {
                Schema::connection('tenant')->table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('commercial_rate_source');
                });
            }
        }
    }
};
