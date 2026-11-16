<?php

namespace App\Services\Edge\MigrationSubstitutes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Edge execution of canonical 2026_11_02_000002_order_note_toggle_default_off (KOT-ORDER-NOTE-1 (2), c51b2fe7).
 *
 * Owner decision 16 Nov 2026 (item 3): "EXISTING-ROW REWRITE NOT APPROVED. New layouts may default OFF, but existing
 * explicit operator ON settings must be preserved."
 *
 * Canonical body = ALTER receipt_layout_settings MODIFY show_order_note DEFAULT 0  +  UPDATE every row to 0.
 * This body      = the ALTER only. No row is read or written: a layout an operator switched ON stays ON, one switched
 * OFF stays OFF, and a layout created after the upgrade starts OFF (the new default). down() is the canonical down()
 * (default back to 1, rows untouched) — identical, because the canonical down() never touched rows either.
 *
 * CLOUD REFRESH REMAINS AUTHORITATIVE (owner, 16 Nov 2026): on an appliance receipt_layout_settings is a bootstrap
 * section whose whole rows the Cloud config refresh upserts, so after this upgrade the value of every layout row
 * follows the Cloud (Cloud = truth). The substitute prevents Edge's OWN upgrade from overwriting operator preferences;
 * it does not undo Cloud-side changes.
 *
 * Owner contract (approval 16 Nov 2026, night): 1 canonical file unchanged; 2 Edge executes only the default change;
 * 3 no bulk UPDATE of existing rows; 4 existing ON stays ON; 5 existing OFF stays OFF; 6 new layouts default OFF;
 * 7 the canonical identity is written to the migration ledger only after this body succeeded (EdgeLocalSchemaUpgrader);
 * 8 RUN_EDGE_SUBSTITUTE visible in dry-run / plan hash / package audit / upgrade output; 9 a repeat upgrade never
 * re-applies it (recorded name); 10 an unexpected mapping or schema mismatch fails closed (manifest validation +
 * runtime identity check + the precondition in up()).
 */
final class OrderNoteToggleDefaultOnly implements EdgeMigrationSubstitute
{
    public const MIGRATION = '2026_11_02_000002_order_note_toggle_default_off';

    public static function forMigration(): string
    {
        return self::MIGRATION;
    }

    public static function why(): string
    {
        return 'default-only: show_order_note DEFAULT 0 for layouts created from now on; existing rows (operator ON/OFF choices) are not rewritten (owner decision 16 Nov 2026)';
    }

    public function up(): void
    {
        // Fail closed (owner contract item 10): the column arrives with 2026_11_02_000001_add_order_note_toggle, which the plan
        // runs first in name order; if it is not there the schema is not what this substitute was written for — stop, record
        // nothing (the ledger entry is written only after a successful execution).
        $s = Schema::connection('tenant');
        if (! $s->hasTable('receipt_layout_settings') || ! $s->hasColumn('receipt_layout_settings', 'show_order_note')) {
            throw new RuntimeException('SUBSTITUTE_PRECONDITION_FAILED: receipt_layout_settings.show_order_note is missing — '
                . self::MIGRATION . ' expects 2026_11_02_000001_add_order_note_toggle to have run; nothing was changed.');
        }
        $s->table('receipt_layout_settings', function (Blueprint $table) {
            $table->boolean('show_order_note')->default(false)->change();
        });
    }

    public function down(): void
    {
        $s = Schema::connection('tenant');
        if (! $s->hasColumn('receipt_layout_settings', 'show_order_note')) {
            return;
        }
        $s->table('receipt_layout_settings', function (Blueprint $table) {
            $table->boolean('show_order_note')->default(true)->change();
        });
    }
}
