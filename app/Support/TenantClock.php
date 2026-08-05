<?php

namespace App\Support;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Shift;
use App\Models\Tenant\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 — one canonical clock/timezone/business-date contract.
 *
 * DB timestamps are stored UTC (config/app.php timezone = UTC). All timezone conversion
 * and business-date derivation happens here, at the application boundary, so the whole
 * portal (and later the Offline Edge Branch Server) share the exact same semantics.
 *
 * Effective display timezone hierarchy: user override -> branch -> Asia/Karachi (IANA ids).
 * A shift freezes its business_date + timezone at OPEN; sales inherit the shift business_date.
 */
class TenantClock
{
    public const DEFAULT_TIMEZONE = 'Asia/Karachi';

    /**
     * Effective display timezone. Falls back user -> branch -> Asia/Karachi. Any value that
     * is not a valid IANA identifier is ignored (never a raw numeric offset like "UTC+5").
     */
    public function timezone(?User $user = null, ?Branch $branch = null): string
    {
        $user ??= Auth::guard('tenant')->user();

        return $this->normalize($user?->timezone)
            ?? $this->normalize($branch?->timezone)
            ?? self::DEFAULT_TIMEZONE;
    }

    /** Current time in the effective (or given) timezone. */
    public function now(?string $timezone = null): Carbon
    {
        return Carbon::now($timezone ?: $this->timezone());
    }

    /** Format a stored (UTC) timestamp in the effective (or given) timezone. */
    public function format($timestamp, string $format = 'd M Y H:i', ?string $timezone = null): string
    {
        if (empty($timestamp)) {
            return '';
        }

        return Carbon::parse($timestamp)->timezone($timezone ?: $this->timezone())->format($format);
    }

    /**
     * The business (calendar) date at a moment, in a timezone — used to stamp a shift when it
     * OPENS. e.g. opened 2026-08-05 20:00 Asia/Karachi -> "2026-08-05"; and 2026-08-06 00:01
     * Asia/Karachi -> "2026-08-06". The result is frozen on the shift and never recomputed.
     */
    public function businessDateForOpening(?string $timezone = null, $at = null): string
    {
        $moment = $at ? Carbon::parse($at) : Carbon::now();

        return $moment->timezone($timezone ?: self::DEFAULT_TIMEZONE)->toDateString();
    }

    /**
     * The business date a NEW sale should inherit: the active shift's frozen business_date if
     * present; otherwise (no shift context) the current calendar date in the branch timezone.
     * Never derived from the raw sale timestamp, so crossing midnight keeps the shift's date.
     */
    public function businessDateForSale(?Shift $shift, ?Branch $branch = null): string
    {
        if ($shift && $shift->business_date) {
            return Carbon::parse($shift->business_date)->toDateString();
        }

        return $this->businessDateForOpening($branch?->timezone);
    }

    /** True if the given string is a valid IANA timezone identifier. */
    public function normalize(?string $timezone): ?string
    {
        if (! $timezone) {
            return null;
        }

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : null;
    }

    /** IANA identifiers for a settings dropdown. */
    public function identifiers(): array
    {
        return timezone_identifiers_list();
    }
}
