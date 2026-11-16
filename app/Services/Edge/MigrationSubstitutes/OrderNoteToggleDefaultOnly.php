<?php

namespace App\Services\Edge\MigrationSubstitutes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
 * Note for the record: on an appliance receipt_layout_settings is a bootstrap section whose whole rows the Cloud
 * config refresh upserts, so after this upgrade the value of every layout row follows the Cloud (Cloud = truth) —
 * the substitute guarantees only that the UPGRADE itself changes no operator choice.
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
        $s = Schema::connection('tenant');
        if (! $s->hasColumn('receipt_layout_settings', 'show_order_note')) {
            return; // the column arrives with 2026_11_02_000001_add_order_note_toggle, which runs first
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
