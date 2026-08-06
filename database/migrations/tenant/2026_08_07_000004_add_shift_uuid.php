<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-HARDEN-1 — stable cross-system identity for a shift.
 *
 * The local auto-increment `id` is NOT a safe identity to send to the cloud/Edge (it collides
 * across databases). Every shift gets an immutable ULID `shift_uuid`, generated at open; the future
 * Edge sale envelope carries THIS, never the local integer id. Added nullable + backfilled, then a
 * unique index — additive and safe on existing data.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('shifts', 'shift_uuid')) {
            Schema::connection('tenant')->table('shifts', function (Blueprint $table) {
                $table->char('shift_uuid', 26)->nullable()->after('id');
            });
        }

        // Backfill existing shifts with a deterministic-order ULID (oldest first) so historical
        // shifts get a stable, monotonic identity too.
        DB::connection('tenant')->table('shifts')
            ->whereNull('shift_uuid')
            ->orderBy('id')
            ->each(function ($shift) {
                DB::connection('tenant')->table('shifts')
                    ->where('id', $shift->id)
                    ->update(['shift_uuid' => (string) Str::ulid()]);
            });

        // Enforce uniqueness only once every row is populated.
        $indexes = collect(DB::connection('tenant')->select('SHOW INDEX FROM shifts'))
            ->pluck('Key_name')->all();
        if (! in_array('shifts_shift_uuid_unique', $indexes, true)) {
            Schema::connection('tenant')->table('shifts', function (Blueprint $table) {
                $table->unique('shift_uuid');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('shifts', 'shift_uuid')) {
            Schema::connection('tenant')->table('shifts', function (Blueprint $table) {
                $table->dropUnique('shifts_shift_uuid_unique');
                $table->dropColumn('shift_uuid');
            });
        }
    }
};
