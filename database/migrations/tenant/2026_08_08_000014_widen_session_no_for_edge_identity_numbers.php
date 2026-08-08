<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-LOCAL-POS-1 (restaurant layer) — widen restaurant_table_sessions.session_no varchar(30→64).
 *
 * Branch Server table sessions derive the display number from the frozen canonical identity
 * ('TS-{branch}-{session_uuid ULID}' ≈ 32+ chars), same convention as Edge sale_no. WIDENING only —
 * no data change, Cloud formats ('TS-YmdHis-###') unaffected, idempotent + appliance-update-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('restaurant_table_sessions', 'session_no')) {
            return;
        }
        $col = DB::connection('tenant')->selectOne(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['restaurant_table_sessions', 'session_no']
        );
        if ($col !== null && (int) $col->len < 64) {
            DB::connection('tenant')->statement('ALTER TABLE restaurant_table_sessions MODIFY session_no VARCHAR(64) NOT NULL');
        }
    }

    public function down(): void
    {
        // widening is non-destructive; narrowing back could truncate data — deliberately a no-op.
    }
};
