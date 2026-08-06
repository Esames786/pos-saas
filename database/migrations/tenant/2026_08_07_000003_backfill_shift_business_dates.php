<?php

use App\Support\TenantClock;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 — backfill business_date on existing rows so historical data is
 * queryable by business date the same way new data is. Best-effort and idempotent (only touches
 * NULL business_date). Additive: never rewrites a value already set.
 *
 *   - shifts: business_date + timezone_name from opened_at rendered in the branch business timezone.
 *   - sales_orders: the frozen shift business_date when linked; otherwise the UTC sale_date (legacy
 *     pre-shift sales have no better anchor).
 *   - restaurant_table_sessions: the UTC opened_at date.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('shifts', 'business_date')) {
            $default = TenantClock::DEFAULT_TIMEZONE;

            // Branch timezone lookup (small table); fall back to the platform default.
            $branchTz = Schema::connection('tenant')->hasColumn('branches', 'timezone')
                ? DB::connection('tenant')->table('branches')->pluck('timezone', 'id')->all()
                : [];

            DB::connection('tenant')->table('shifts')
                ->whereNull('business_date')
                ->orderBy('id')
                ->each(function ($shift) use ($branchTz, $default) {
                    $tz = $branchTz[$shift->branch_id] ?? $default;
                    if (! in_array($tz, timezone_identifiers_list(), true)) {
                        $tz = $default;
                    }
                    $anchor = $shift->opened_at ?? $shift->created_at ?? now();

                    DB::connection('tenant')->table('shifts')->where('id', $shift->id)->update([
                        'business_date' => Carbon::parse($anchor)->timezone($tz)->toDateString(),
                        'timezone_name' => $shift->timezone_name ?? $tz,
                    ]);
                });
        }

        if (Schema::connection('tenant')->hasColumn('sales_orders', 'business_date')) {
            // Authoritative when linked to a shift; else the stored sale_date (UTC) as a fallback.
            DB::connection('tenant')->statement(
                'UPDATE sales_orders so
                 LEFT JOIN shifts sh ON sh.id = so.shift_id
                 SET so.business_date = COALESCE(sh.business_date, DATE(so.sale_date))
                 WHERE so.business_date IS NULL'
            );
        }

        if (Schema::connection('tenant')->hasColumn('restaurant_table_sessions', 'business_date')) {
            DB::connection('tenant')->statement(
                'UPDATE restaurant_table_sessions
                 SET business_date = DATE(opened_at)
                 WHERE business_date IS NULL AND opened_at IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        // Non-reversible data backfill — leaving the values in place is safe and intended.
    }
};
