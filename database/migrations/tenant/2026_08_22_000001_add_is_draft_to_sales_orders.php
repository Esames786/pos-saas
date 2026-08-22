<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS-DRAFT-1 — a held sale can be parked as a DRAFT: it behaves exactly like any
 * other held sale (recall, add items, preview, cancel — all identical) EXCEPT the
 * KOT is not sent to the kitchen. When the operator later saves it normally (Hold),
 * the KOT prints and the draft flag clears.
 *
 * We deliberately DO NOT reuse the existing `status = 'draft'` value: that status is
 * the transient pay-flow/finalization state (an order mid-payment, treated as an open
 * table order) and the POS recall list keys strictly on status='held'. A separate
 * additive flag keeps the order a normal held sale — so recall, pay, table-session and
 * every report stay untouched — while still marking it as a draft for display and for
 * the "skip KOT" decision. Additive, nullable-safe, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('sales_orders')
            && ! Schema::connection('tenant')->hasColumn('sales_orders', 'is_draft')) {
            Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
                $table->boolean('is_draft')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('sales_orders', 'is_draft')) {
            Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
                $table->dropColumn('is_draft');
            });
        }
    }
};
