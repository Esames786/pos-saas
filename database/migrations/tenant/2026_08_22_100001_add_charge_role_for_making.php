<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-MAKING-1 — an explicit identity for the Making charge.
 *
 * Bulk-adjusting Making (500 → 600 across the menu) needs to know WHICH charge
 * block is Making, and the domain had no answer except the label text. Keying
 * money on `label LIKE '%making%'` would miss every other spelling and hit
 * things it must not — the defect class the whole costing design forbids, and
 * why Phase E stopped at its design gate until this column existed.
 *
 *   charge_role = 'making'   this charge is the dish's Making charge
 *   charge_role = NULL       ordinary/general charge (Packing, Waiter,
 *                            Decoration, Setup, …) — or a material block,
 *                            which can never carry a role
 *
 * DELIBERATELY NO BACKFILL. Classification is an operator act on the Cost
 * Block screen, exactly like linking a block to the Commercial Rate Book —
 * nothing becomes eligible for a bulk Making change merely because this
 * migration ran. At most ONE active Making charge per product, enforced by
 * the write path (the schema stays additive; partial unique indexes on a
 * nullable role across soft states are more trap than guard).
 *
 * The same column goes on the estimate-line snapshot: a quotation remembers
 * whether a charge WAS the Making charge when it was priced, so a later
 * reclassification of the dish can never rewrite what an old document meant.
 *
 * Also: catering_commercial_rate_applications.material_product_id becomes
 * NULLABLE. Making applications are audited in the same book as commercial
 * rate applications — same actor/old/new/calculated shape — but Making has no
 * material, and writing a fake material id just to satisfy a NOT NULL would
 * be a lie in an audit table. Existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['catering_product_cost_blocks', 'catering_estimate_line_cost_blocks'] as $table) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                continue;
            }
            if (Schema::connection('tenant')->hasColumn($table, 'charge_role')) {
                continue;
            }

            Schema::connection('tenant')->table($table, function (Blueprint $blueprint) {
                $blueprint->string('charge_role', 20)->nullable()->after('commercial_rate_source');
            });
        }

        if (Schema::connection('tenant')->hasTable('catering_commercial_rate_applications')) {
            Schema::connection('tenant')->table('catering_commercial_rate_applications', function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('material_product_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['catering_product_cost_blocks', 'catering_estimate_line_cost_blocks'] as $table) {
            if (Schema::connection('tenant')->hasColumn($table, 'charge_role')) {
                Schema::connection('tenant')->table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('charge_role');
                });
            }
        }
        // material_product_id stays nullable on rollback: tightening it back
        // would fail on any Making audit rows written meanwhile, and an audit
        // that can hold more truth is not a defect to undo.
    }
};
