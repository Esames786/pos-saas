<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-IDENTITY-FINAL-PROOF-1 — immutable canonical-reference SNAPSHOTS for cross-system safety.
 *
 * Executable evidence (HeldSaleController::store `$sale->lines()->delete()` on Add Round / held->pay;
 * SalesOrderController::store same on pay) proves that a sale's lines are DELETED and recreated on those
 * transitions — so a line's `line_uuid` is NOT preserved across a re-save, and the `nullOnDelete` foreign
 * keys `kot_batch_lines.sales_order_line_id` / `sales_order_line_cancellations.sales_order_line_id` are
 * actively NULLed. A KOT line or a cancellation would therefore lose its link to the originating sale line,
 * and numeric ids are useless across Cloud/Edge anyway.
 *
 * Fix (smallest evidence-based): capture the originating canonical identity as an IMMUTABLE snapshot ON the
 * KOT line / cancellation at creation time, so the historical event stays self-contained and cross-system
 * resolvable regardless of later line churn or divergent numeric ids. These are copies of another row's
 * canonical id (references), NOT the row's own identity, so they are nullable and NOT uniquely indexed.
 *
 * APPLIANCE-UPDATE-SAFE: additive nullable columns + best-effort idempotent backfill (copy from the still-
 * linked source row where the FK survives; historical rows whose source line was already deleted stay null).
 * Never truncates / migrate:fresh / reseeds; safely re-runnable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conn = DB::connection('tenant');

        if (Schema::connection('tenant')->hasTable('kot_batch_lines')
            && ! Schema::connection('tenant')->hasColumn('kot_batch_lines', 'source_line_uuid')) {
            Schema::connection('tenant')->table('kot_batch_lines', function (Blueprint $t) {
                // the originating sale line's line_uuid at send time (ULID); null for pre-existing history.
                $t->char('source_line_uuid', 26)->nullable()->after('sales_order_line_id');
                $t->index('source_line_uuid', 'kot_batch_lines_source_line_uuid_idx');
            });
            // best-effort backfill from the still-linked sale line
            $conn->statement(
                'UPDATE kot_batch_lines kbl JOIN sales_order_lines sol ON sol.id = kbl.sales_order_line_id '
                . 'SET kbl.source_line_uuid = sol.line_uuid WHERE kbl.source_line_uuid IS NULL AND sol.line_uuid IS NOT NULL'
            );
        }

        if (Schema::connection('tenant')->hasTable('sales_order_line_cancellations')) {
            $added = false;
            if (! Schema::connection('tenant')->hasColumn('sales_order_line_cancellations', 'source_line_uuid')) {
                Schema::connection('tenant')->table('sales_order_line_cancellations', function (Blueprint $t) {
                    $t->char('source_line_uuid', 26)->nullable()->after('sales_order_line_id');
                });
                $added = true;
            }
            if (! Schema::connection('tenant')->hasColumn('sales_order_line_cancellations', 'source_kot_event_uuid')) {
                Schema::connection('tenant')->table('sales_order_line_cancellations', function (Blueprint $t) {
                    // canonical form of kot_batch_id (the KOT batch this cancellation references) — cross-system resolvable.
                    $t->char('source_kot_event_uuid', 36)->nullable()->after('kot_batch_id');
                });
                $added = true;
            }
            if ($added) {
                $conn->statement(
                    'UPDATE sales_order_line_cancellations c JOIN sales_order_lines sol ON sol.id = c.sales_order_line_id '
                    . 'SET c.source_line_uuid = sol.line_uuid WHERE c.source_line_uuid IS NULL AND sol.line_uuid IS NOT NULL'
                );
                $conn->statement(
                    'UPDATE sales_order_line_cancellations c JOIN kot_batches kb ON kb.id = c.kot_batch_id '
                    . 'SET c.source_kot_event_uuid = kb.event_uuid WHERE c.source_kot_event_uuid IS NULL AND kb.event_uuid IS NOT NULL'
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('kot_batch_lines', 'source_line_uuid')) {
            Schema::connection('tenant')->table('kot_batch_lines', function (Blueprint $t) {
                $t->dropIndex('kot_batch_lines_source_line_uuid_idx');
                $t->dropColumn('source_line_uuid');
            });
        }
        foreach (['source_line_uuid', 'source_kot_event_uuid'] as $col) {
            if (Schema::connection('tenant')->hasColumn('sales_order_line_cancellations', $col)) {
                Schema::connection('tenant')->table('sales_order_line_cancellations', function (Blueprint $t) use ($col) {
                    $t->dropColumn($col);
                });
            }
        }
    }
};
