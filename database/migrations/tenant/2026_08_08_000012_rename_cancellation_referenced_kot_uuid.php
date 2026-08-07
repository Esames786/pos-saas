<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-IDENTITY-FREEZE-CLOSURE-1 — rename the cancellation KOT reference to a semantically-accurate name.
 *
 * The prior column `source_kot_event_uuid` (added in 2026_08_08_000011) implied the ORIGINAL sending KOT
 * batch. Executable evidence proved otherwise: `sales_order_line_cancellations.kot_batch_id` references the
 * CANCEL KOT batch that the cancellation workflow creates (queueCancellationKot), NOT the original send. The
 * value is simply the canonical `event_uuid` form of THIS cancellation row's `kot_batch_id`. Renamed to
 * `referenced_kot_event_uuid` before any sync code depends on the misleading name.
 *
 * The originating KOT relationship is NOT modelled as a single "original event": a sale line may appear in
 * one or MANY historical KOT batches across rounds. Future sync resolves historical KOT-line membership via
 * `kot_batch_lines.source_line_uuid` + `kot_batches.event_uuid`, not a fabricated singular original_kot_uuid.
 *
 * Safe forward migration (ce2b4ee already deployed): ADDITIVE-copy-then-drop, not renameColumn (no
 * doctrine/dbal dependency, no rewrite risk). Existing values are preserved; never truncates / migrate:fresh /
 * reseeds; idempotent + safely re-runnable. On a fresh Edge db-init, 000011 adds source_kot_event_uuid and
 * this migration renames it — the final schema carries only `referenced_kot_event_uuid`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = 'sales_order_line_cancellations';
        if (! Schema::connection('tenant')->hasTable($table)) {
            return;
        }

        if (! Schema::connection('tenant')->hasColumn($table, 'referenced_kot_event_uuid')) {
            Schema::connection('tenant')->table($table, function (Blueprint $t) {
                $t->char('referenced_kot_event_uuid', 36)->nullable()->after('kot_batch_id');
            });
        }

        // copy any existing values from the old column (idempotent — only where new is still NULL).
        if (Schema::connection('tenant')->hasColumn($table, 'source_kot_event_uuid')) {
            DB::connection('tenant')->statement(
                "UPDATE `{$table}` SET referenced_kot_event_uuid = source_kot_event_uuid "
                . 'WHERE referenced_kot_event_uuid IS NULL AND source_kot_event_uuid IS NOT NULL'
            );
            Schema::connection('tenant')->table($table, function (Blueprint $t) {
                $t->dropColumn('source_kot_event_uuid');
            });
        }
    }

    public function down(): void
    {
        $table = 'sales_order_line_cancellations';
        if (! Schema::connection('tenant')->hasColumn($table, 'source_kot_event_uuid')
            && Schema::connection('tenant')->hasColumn($table, 'referenced_kot_event_uuid')) {
            Schema::connection('tenant')->table($table, function (Blueprint $t) {
                $t->char('source_kot_event_uuid', 36)->nullable()->after('kot_batch_id');
            });
            DB::connection('tenant')->statement(
                "UPDATE `{$table}` SET source_kot_event_uuid = referenced_kot_event_uuid WHERE source_kot_event_uuid IS NULL"
            );
            Schema::connection('tenant')->table($table, function (Blueprint $t) {
                $t->dropColumn('referenced_kot_event_uuid');
            });
        }
    }
};
