<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Shift;
use App\Models\Tenant\User;
use App\Support\TenantClock;
use Carbon\Carbon;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 — the timezone contract: BUSINESS timezone anchors shifts and
 * ignores the user; DISPLAY timezone is a per-user portal preference and never touches business
 * dates. IANA-only; app timezone is UTC so stored timestamps stay canonical.
 */
class TenantClockTest extends MySqlTenantTestCase
{
    private function clock(): TenantClock
    {
        return app(TenantClock::class);
    }

    public function test_business_timezone_ignores_the_user_and_uses_the_branch(): void
    {
        // Even if the user prefers London for display, the branch's Karachi anchors the business day.
        $user   = new User(['timezone' => 'Europe/London']);
        $branch = new Branch(['timezone' => 'Asia/Karachi']);

        $this->assertSame('Asia/Karachi', $this->clock()->businessTimezone($branch));
        $this->assertSame('Europe/London', $this->clock()->displayTimezone($user, $branch));
    }

    public function test_display_timezone_falls_back_user_then_branch_then_default(): void
    {
        $branch = new Branch(['timezone' => 'Asia/Dubai']);

        // No user tz -> branch tz.
        $this->assertSame('Asia/Dubai', $this->clock()->displayTimezone(new User(), $branch));
        // No user tz, no branch tz -> platform default.
        $this->assertSame(TenantClock::DEFAULT_TIMEZONE, $this->clock()->displayTimezone(new User(), new Branch()));
        // User tz wins when set.
        $this->assertSame('America/New_York', $this->clock()->displayTimezone(new User(['timezone' => 'America/New_York']), $branch));
    }

    public function test_business_date_for_sale_falls_back_to_opening_when_no_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 20:00:00', 'UTC')); // 01:00 Asia/Karachi on 6 Aug
        try {
            $this->assertSame(
                '2026-08-06',
                $this->clock()->businessDateForSale(null, new Branch(['timezone' => 'Asia/Karachi']))
            );
            // With a shift, the frozen date wins regardless of now.
            $this->assertSame(
                '2026-08-01',
                $this->clock()->businessDateForSale(new Shift(['business_date' => '2026-08-01']))
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_business_date_for_opening_is_dst_aware(): void
    {
        // 2026-03-29 is the London spring-forward date (01:00 GMT -> 02:00 BST). Whichever side of
        // the DST jump this instant lands on, the local calendar date is unambiguously 29 Mar.
        $instant = Carbon::parse('2026-03-29 01:30:00', 'UTC');
        $this->assertSame('2026-03-29', $this->clock()->businessDateForOpening('Europe/London', $instant));

        // 2026-01-01 23:30 New York (UTC-5) is 2026-01-02 04:30 UTC — business day stays 1 Jan local.
        $nyInstant = Carbon::parse('2026-01-02 04:30:00', 'UTC');
        $this->assertSame('2026-01-01', $this->clock()->businessDateForOpening('America/New_York', $nyInstant));
    }

    public function test_normalize_accepts_iana_and_rejects_everything_else(): void
    {
        $this->assertSame('Asia/Karachi', $this->clock()->normalize('Asia/Karachi'));
        $this->assertSame('UTC', $this->clock()->normalize('UTC'));
        $this->assertNull($this->clock()->normalize('UTC+5'));
        $this->assertNull($this->clock()->normalize('+05:00'));
        $this->assertNull($this->clock()->normalize('Not/AZone'));
        $this->assertNull($this->clock()->normalize(''));
        $this->assertNull($this->clock()->normalize(null));
    }

    public function test_format_renders_a_utc_timestamp_in_a_display_timezone(): void
    {
        // 2026-08-05 19:00 UTC = 2026-08-06 00:00 Asia/Karachi (+5).
        $this->assertSame(
            '06 Aug 2026 00:00',
            $this->clock()->format('2026-08-05 19:00:00', 'd M Y H:i', 'Asia/Karachi')
        );
    }
}
