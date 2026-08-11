<?php

namespace App\Support;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Shift;
use App\Models\Tenant\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 — one canonical clock/timezone/business-date contract,
 * reused by Cloud now and by the future Offline Edge Branch Server.
 *
 * DB timestamps are stored UTC (config/app.php timezone = UTC). All timezone conversion and
 * business-date derivation happens here, at the application boundary.
 *
 * TWO explicit timezone concepts (they are NOT the same):
 *   - BUSINESS timezone  = branch -> Asia/Karachi. Anchors shift + business_date. A user's
 *     personal display override must NEVER move a shift's business_date.
 *   - DISPLAY timezone   = user -> branch -> Asia/Karachi. For portal/dashboard timestamps only.
 *
 * NOTE ON TENANT-DEFAULT TIMEZONE: this codebase has NO general tenant-settings mechanism and the
 * tenant record lives in the master DB, so a tenant-default timezone column is intentionally NOT
 * added here (would be an invasive master change). The BRANCH timezone is the organization anchor
 * (default Asia/Karachi). If a tenant-settings layer is added later, slot it between branch and the
 * hard default in both hierarchies below.
 */
class TenantClock
{
    public const DEFAULT_TIMEZONE = 'Asia/Karachi';

    /**
     * BUSINESS timezone — anchors shifts and business_date. branch -> Asia/Karachi.
     * Never influenced by a user's display override.
     */
    public function businessTimezone(?Branch $branch = null): string
    {
        $branch ??= $this->currentBranch();

        return $this->normalize($branch?->timezone) ?? self::DEFAULT_TIMEZONE;
    }

    /**
     * DISPLAY timezone — portal/dashboard timestamps only. user -> branch -> Asia/Karachi.
     * A user in London may VIEW timestamps in London while the Karachi shift's business_date
     * stays Karachi.
     */
    public function displayTimezone(?User $user = null, ?Branch $branch = null): string
    {
        $user ??= Auth::guard('tenant')->user();
        $branch ??= $this->currentBranch();

        return $this->normalize($user?->timezone)
            ?? $this->normalize($branch?->timezone)
            ?? self::DEFAULT_TIMEZONE;
    }

    /**
     * Canonical current-branch resolution so callers don't have to pass Branch everywhere:
     * request branch_id (POS) -> authenticated user's default branch -> first active branch.
     */
    public function currentBranch(): ?Branch
    {
        $branchId = null;
        if (function_exists('request') && request()) {
            $branchId = request()->input('branch_id');
        }
        $branchId = $branchId ?: Auth::guard('tenant')->user()?->default_branch_id;

        if ($branchId) {
            return Branch::find($branchId);
        }

        return Branch::where('status', 'active')->orderBy('name')->first();
    }

    /** Current time in the DISPLAY timezone (or a given one). */
    public function now(?string $timezone = null): Carbon
    {
        return Carbon::now($timezone ?: $this->displayTimezone());
    }

    /** Format a stored (UTC) timestamp in the DISPLAY timezone (or a given one). */
    public function format($timestamp, string $format = 'd M Y H:i', ?string $timezone = null): string
    {
        if (empty($timestamp)) {
            return '';
        }

        return Carbon::parse($timestamp)->timezone($timezone ?: $this->displayTimezone())->format($format);
    }

    /**
     * The timezone a SALE was operationally recorded in: its FROZEN shift timezone first, then its
     * branch, then the default. Identical to the rule the printed receipt uses, so a screen and the
     * paper in the customer's hand can never disagree — and re-pointing a branch's timezone later
     * can never rewrite what an old order appears to have happened at.
     *
     * Timestamps are stored in UTC (app.timezone=UTC). Formatting one without this conversion
     * prints UTC to a Karachi cashier — five hours adrift on a screen that sits next to a clock.
     */
    public function saleTimezone($sale): string
    {
        return $this->normalize($sale?->shift?->timezone_name)
            ?? $this->normalize($sale?->branch?->timezone)
            ?? self::DEFAULT_TIMEZONE;
    }

    /** Format a sale's timestamp in the timezone that sale was recorded in. */
    public function formatSale($sale, string $format = 'Y-m-d H:i', $timestamp = null): string
    {
        return $this->format(
            $timestamp ?? $sale?->sale_date ?? $sale?->created_at,
            $format,
            $this->saleTimezone($sale)
        );
    }

    /**
     * The business (calendar) date at a moment, in a BUSINESS timezone — used to stamp a shift
     * when it OPENS. e.g. 2026-08-05 20:00 Karachi -> "2026-08-05"; 2026-08-06 00:01 Karachi ->
     * "2026-08-06". Frozen on the shift; never recomputed.
     */
    public function businessDateForOpening(?string $timezone = null, $at = null): string
    {
        $moment = $at ? Carbon::parse($at) : Carbon::now();

        return $moment->timezone($timezone ?: self::DEFAULT_TIMEZONE)->toDateString();
    }

    /**
     * The business date a NEW sale/check should inherit: the owning shift's frozen business_date;
     * otherwise the current business-tz calendar date. NEVER derived from the sale timestamp, so
     * crossing midnight keeps the shift's date. (Add Round / split inherit the ORIGINAL check's
     * business_date at their own call sites — this only covers a fresh creation.)
     */
    public function businessDateForSale(?Shift $shift, ?Branch $branch = null): string
    {
        if ($shift && $shift->business_date) {
            return Carbon::parse($shift->business_date)->toDateString();
        }

        return $this->businessDateForOpening($this->businessTimezone($branch));
    }

    /**
     * SHIFT-POS-INTEGRATION-CLOSURE-1: the CURRENT business (calendar) date for a branch, in its
     * business timezone. This is what an operational "Today" must use — NEVER Laravel's today(),
     * which is UTC and can be a day behind for e.g. Asia/Karachi just after midnight.
     */
    public function currentBusinessDate(?Branch $branch = null): string
    {
        return $this->businessDateForOpening($this->businessTimezone($branch));
    }

    /**
     * The current business date for every active branch, keyed by branch id. A multi-branch
     * dashboard aggregating "Today" uses this so each branch contributes its OWN current business
     * day (branches in different timezones can legitimately be on different calendar days).
     *
     * @return array<int,string> branch_id => Y-m-d
     */
    public function currentBusinessDatesByBranch(): array
    {
        return Branch::where('status', 'active')->get()
            ->mapWithKeys(fn (Branch $b) => [$b->id => $this->currentBusinessDate($b)])
            ->all();
    }

    /** True if the given string is a valid IANA timezone identifier (rejects numeric offsets). */
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
