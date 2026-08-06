<?php

use App\Support\TenantClock;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 (backfill; HARDEN-1 timezone-correct) — populate business_date on
 * existing rows so historical data is queryable by business date. Best-effort, idempotent (only
 * touches NULL business_date), additive.
 *
 * Timezone rule (HARDEN-1 fix): the actual timestamps are stored in UTC (app tz = UTC), so a UTC
 * `DATE()` is WRONG for a business date near midnight (a Karachi sale at 20:30 UTC is 01:30 next
 * day local). We therefore render each anchor timestamp in the row's BRANCH business timezone
 * before taking the date:
 *   - shifts: opened_at in the branch business timezone.
 *   - sales_orders WITH a shift: the frozen shift business_date (authoritative, no tz math).
 *   - sales_orders WITHOUT a shift: sale_date converted from UTC to the branch business timezone.
 *   - restaurant_table_sessions: the owning shift's business_date if bound, else opened_at converted
 *     to the branch business timezone.
 *
 * Where a branch has no usable IANA timezone the platform default (Asia/Karachi) is used; that
 * fallback is a documented best-effort, not a precision claim.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $branchTz = $this->branchTimezones();

        // 1) Shifts — opened_at in the branch business timezone.
        if (Schema::connection('tenant')->hasColumn('shifts', 'business_date')) {
            DB::connection('tenant')->table('shifts')
                ->whereNull('business_date')
                ->orderBy('id')
                ->each(function ($shift) use ($branchTz) {
                    $tz = $this->tzFor($branchTz, $shift->branch_id);
                    $anchor = $shift->opened_at ?? $shift->created_at ?? now();
                    DB::connection('tenant')->table('shifts')->where('id', $shift->id)->update([
                        'business_date' => Carbon::parse($anchor, 'UTC')->timezone($tz)->toDateString(),
                        'timezone_name' => $shift->timezone_name ?? $tz,
                    ]);
                });
        }

        // 2) Sales WITH a shift — inherit the frozen shift business_date (no tz math needed).
        if (Schema::connection('tenant')->hasColumn('sales_orders', 'business_date')) {
            DB::connection('tenant')->statement(
                'UPDATE sales_orders so
                 JOIN shifts sh ON sh.id = so.shift_id
                 SET so.business_date = sh.business_date
                 WHERE so.business_date IS NULL AND so.shift_id IS NOT NULL AND sh.business_date IS NOT NULL'
            );

            // 3) Sales WITHOUT a shift — convert sale_date (UTC) to the branch business timezone.
            DB::connection('tenant')->table('sales_orders')
                ->whereNull('business_date')
                ->whereNotNull('sale_date')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($branchTz) {
                    foreach ($rows as $sale) {
                        $tz = $this->tzFor($branchTz, $sale->branch_id);
                        DB::connection('tenant')->table('sales_orders')->where('id', $sale->id)->update([
                            'business_date' => Carbon::parse($sale->sale_date, 'UTC')->timezone($tz)->toDateString(),
                        ]);
                    }
                });
        }

        // 4) Table sessions — owning shift's date if bound, else opened_at in the branch tz.
        if (Schema::connection('tenant')->hasColumn('restaurant_table_sessions', 'business_date')) {
            $hasShiftBinding = Schema::connection('tenant')->hasColumn('restaurant_table_sessions', 'opened_shift_id');
            if ($hasShiftBinding) {
                DB::connection('tenant')->statement(
                    'UPDATE restaurant_table_sessions rts
                     JOIN shifts sh ON sh.id = rts.opened_shift_id
                     SET rts.business_date = sh.business_date
                     WHERE rts.business_date IS NULL AND sh.business_date IS NOT NULL'
                );
            }

            DB::connection('tenant')->table('restaurant_table_sessions')
                ->whereNull('business_date')
                ->whereNotNull('opened_at')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($branchTz) {
                    foreach ($rows as $session) {
                        $tz = $this->tzFor($branchTz, $session->branch_id);
                        DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $session->id)->update([
                            'business_date' => Carbon::parse($session->opened_at, 'UTC')->timezone($tz)->toDateString(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Non-reversible data backfill — leaving the values in place is safe and intended.
    }

    private function branchTimezones(): array
    {
        return Schema::connection('tenant')->hasColumn('branches', 'timezone')
            ? DB::connection('tenant')->table('branches')->pluck('timezone', 'id')->all()
            : [];
    }

    private function tzFor(array $branchTz, $branchId): string
    {
        $tz = $branchTz[$branchId] ?? TenantClock::DEFAULT_TIMEZONE;

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : TenantClock::DEFAULT_TIMEZONE;
    }
};
