<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-LOCAL-POS-1 (§19) — the smallest durable state that marks a sale as offline-origin and awaiting Cloud
 * reconciliation. NOT a sync engine — just enough for a future sync sprint to DISCOVER local sales and the
 * epoch they were created under. The canonical cross-system identities (sale_uuid, line_uuid, payment_uuid,
 * shift_uuid, KOT event_uuid) plus business_date are already present from EDGE-IDENTITY.
 *
 *   - `edge_sync_state`       : NULL for a Cloud-posted sale; 'pending' for a Branch-Server local sale that
 *                               has NOT yet been reconciled by Cloud. Kept a plain nullable string (no enum)
 *                               so future sync states are additive without a column rewrite.
 *   - `edge_activation_epoch` : the appliance activation_epoch the local sale was created under (from
 *                               EdgeBranchContext) — lets Cloud reject stale-generation submissions at sync.
 *
 * Additive nullable columns; APPLIANCE-UPDATE-SAFE (never wipes; forward-only; re-runnable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('sales_orders')) {
            return;
        }
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $t) {
            if (! Schema::connection('tenant')->hasColumn('sales_orders', 'edge_sync_state')) {
                $t->string('edge_sync_state', 20)->nullable()->index();
            }
            if (! Schema::connection('tenant')->hasColumn('sales_orders', 'edge_activation_epoch')) {
                $t->unsignedInteger('edge_activation_epoch')->nullable();
            }
            // H8: distinct from Cloud `inventory_posted` (= official FEFO/inventory posting done). This means
            // ONLY that the appliance decremented PROVISIONAL operational quantity. Cloud must still run its
            // authoritative inventory posting at sync — it must never see inventory_posted=true and skip it.
            if (! Schema::connection('tenant')->hasColumn('sales_orders', 'edge_operational_stock_posted')) {
                $t->boolean('edge_operational_stock_posted')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $t) {
            foreach (['edge_sync_state', 'edge_activation_epoch'] as $col) {
                if (Schema::connection('tenant')->hasColumn('sales_orders', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
