<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-FIN-B — WIP cost-accumulation + Finished-Goods cost-capture columns.
 *
 * INFRASTRUCTURE ONLY. A FUTURE posting phase will accumulate material cost into
 * WIP (accumulated_cost / costed_quantity / average_unit_cost) and store the
 * applied WIP cost on FG receipts (unit_cost / total_cost). This migration only
 * adds the columns — nothing is calculated or posted here. Defaults keep existing
 * rows valued at zero/null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('wip_jobs', 'accumulated_cost')) {
            Schema::connection('tenant')->table('wip_jobs', function (Blueprint $t) {
                $t->decimal('accumulated_cost', 18, 4)->default(0);
                $t->decimal('costed_quantity', 18, 4)->default(0);
                $t->decimal('average_unit_cost', 18, 4)->nullable();
            });
        }

        if (! Schema::connection('tenant')->hasColumn('finished_good_receipts', 'unit_cost')) {
            Schema::connection('tenant')->table('finished_good_receipts', function (Blueprint $t) {
                $t->decimal('unit_cost', 18, 4)->nullable();
                $t->decimal('total_cost', 18, 4)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('wip_jobs', 'accumulated_cost')) {
            Schema::connection('tenant')->table('wip_jobs', function (Blueprint $t) {
                $t->dropColumn(['accumulated_cost', 'costed_quantity', 'average_unit_cost']);
            });
        }

        if (Schema::connection('tenant')->hasColumn('finished_good_receipts', 'unit_cost')) {
            Schema::connection('tenant')->table('finished_good_receipts', function (Blueprint $t) {
                $t->dropColumn(['unit_cost', 'total_cost']);
            });
        }
    }
};
