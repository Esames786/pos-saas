<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-FIN-C — manufacturing consumption posting (Dr WIP / Cr Raw Material).
 *
 * Adds the two stock movement types used when issuing material to production, and
 * the actual-cost columns the posting service stamps on each consumption line at
 * posting time (the existing estimated_* columns stay for planning).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    private const ENUM_WITH = "'opening_stock','adjustment_in','adjustment_out','wastage','transfer_in','transfer_out','purchase','purchase_return','sale','sale_return','recipe_consumption','production_in','manufacturing_material_issue','manufacturing_material_issue_reversal'";

    private const ENUM_BASE = "'opening_stock','adjustment_in','adjustment_out','wastage','transfer_in','transfer_out','purchase','purchase_return','sale','sale_return','recipe_consumption','production_in'";

    public function up(): void
    {
        $sm = Schema::connection('tenant');

        DB::connection('tenant')->statement(
            'ALTER TABLE stock_ledgers MODIFY COLUMN movement_type ENUM(' . self::ENUM_WITH . ')'
        );

        $sm->table('manufacturing_consumption_lines', function (Blueprint $t) use ($sm) {
            if (! $sm->hasColumn('manufacturing_consumption_lines', 'actual_unit_cost')) {
                $t->decimal('actual_unit_cost', 18, 4)->nullable()->after('estimated_total_value');
            }
            if (! $sm->hasColumn('manufacturing_consumption_lines', 'actual_total_cost')) {
                $t->decimal('actual_total_cost', 18, 4)->nullable()->after('actual_unit_cost');
            }
            if (! $sm->hasColumn('manufacturing_consumption_lines', 'posted_quantity')) {
                $t->decimal('posted_quantity', 18, 4)->nullable()->after('actual_total_cost');
            }
        });
    }

    public function down(): void
    {
        $sm = Schema::connection('tenant');

        $sm->table('manufacturing_consumption_lines', function (Blueprint $t) use ($sm) {
            foreach (['posted_quantity', 'actual_total_cost', 'actual_unit_cost'] as $col) {
                if ($sm->hasColumn('manufacturing_consumption_lines', $col)) {
                    $t->dropColumn($col);
                }
            }
        });

        // Only revert the enum if no rows use the new types (avoid truncation).
        $inUse = DB::connection('tenant')->table('stock_ledgers')
            ->whereIn('movement_type', ['manufacturing_material_issue', 'manufacturing_material_issue_reversal'])
            ->exists();

        if (! $inUse) {
            DB::connection('tenant')->statement(
                'ALTER TABLE stock_ledgers MODIFY COLUMN movement_type ENUM(' . self::ENUM_BASE . ')'
            );
        }
    }
};
