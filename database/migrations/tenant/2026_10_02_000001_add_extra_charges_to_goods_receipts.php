<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GRN-EXTRA-CHARGES-1 — cartage and the like are a CHARGE, not a product.
 *
 * Without a home for them a counter books them as a product line, which is what happened here:
 * four GRNs carried "Spoon, qty 1, rate 400" to record cartage, inflating a real item's stock by
 * four and putting 1,600 of freight on the wrong thing.
 *
 * Default 0 on purpose: every receipt that already exists keeps the exact behaviour it has, and
 * a tenant that never enters a charge never sees a difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('goods_receipts', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('goods_receipts', 'extra_charges')) {
                $table->decimal('extra_charges', 14, 4)->default(0)->after('notes');
            }
            if (! Schema::connection('tenant')->hasColumn('goods_receipts', 'extra_charges_note')) {
                $table->string('extra_charges_note', 255)->nullable()->after('extra_charges');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('goods_receipts', function (Blueprint $table) {
            foreach (['extra_charges_note', 'extra_charges'] as $column) {
                if (Schema::connection('tenant')->hasColumn('goods_receipts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
