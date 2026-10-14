<?php

namespace App\Services\OnlineOrdering;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Shift;
use App\Models\Tenant\WebOrderRoute;
use App\Models\Tenant\WebSetting;
use App\Support\TenantClock;
use Carbon\CarbonImmutable;

/**
 * ONLINE-ORDERING-1 — website abhi order le sakti hai ya nahi, aur kyun.
 *
 * Teen sharten (plan §4.4): (1) Website Settings me "on"; (2) waqt ke andar (band hone ka waqt khulne se
 * pehle ho = aadhi raat paar, 12:00 → 04:00); (3) us order type ka terminal YA backup khula ho (khuli
 * shift) — warna order aaye aur kahin ghanti na baje. Teeno me se aik bhi na ho to website menu dikhati
 * hai magar order nahi leti, aur saaf batati hai kab khulegi.
 */
class WebsiteAvailability
{
    public function __construct(private readonly TenantClock $clock) {}

    /**
     * @return array{open: bool, reason: ?string, message: ?string, opens_at: ?string,
     *               branches: array<int, array{delivery: bool, pickup: bool}>}
     */
    public function status(WebSetting $settings, ?CarbonImmutable $now = null): array
    {
        $branch = $settings->default_branch_id ? Branch::find($settings->default_branch_id) : Branch::where('status', 'active')->orderBy('id')->first();
        $now = ($now ?? CarbonImmutable::now())->setTimezone($this->clock->businessTimezone($branch));
        $hours = $settings->hours ?: WebSetting::defaultHours();

        $branches = $this->staffed();
        $base = ['branches' => $branches, 'opens_at' => null];

        if (! $settings->is_enabled) {
            return $base + ['open' => false, 'reason' => 'disabled', 'message' => 'Online ordering is not available right now.'];
        }

        if (! $this->withinHours($hours, $now)) {
            $next = $this->nextOpening($hours, $now);

            return ['open' => false, 'reason' => 'hours', 'opens_at' => $next,
                'message' => $settings->closed_message ?: ('We are closed right now.'.($next ? ' We open at '.$next.'.' : '')),
                'branches' => $branches];
        }

        if (! collect($branches)->contains(fn ($b) => $b['delivery'] || $b['pickup'])) {
            return $base + ['open' => false, 'reason' => 'no_staff', 'message' => 'We are not taking online orders right now. Please try again shortly.'];
        }

        return $base + ['open' => true, 'reason' => null, 'message' => null];
    }

    /** @param  array<string, array{open: ?string, close: ?string, closed: bool}>  $hours */
    public function withinHours(array $hours, CarbonImmutable $now): bool
    {
        $t = $now->format('H:i');
        $today = $hours[$this->dayKey($now)] ?? null;
        $yesterday = $hours[$this->dayKey($now->subDay())] ?? null;

        if ($today && empty($today['closed']) && $today['open'] && $today['close']) {
            if ($today['open'] < $today['close'] ? ($t >= $today['open'] && $t < $today['close']) : $t >= $today['open']) {
                return true;
            }
        }

        // Kal ki raat ka hissa: kal 12:00 → aaj 04:00.
        if ($yesterday && empty($yesterday['closed']) && $yesterday['open'] && $yesterday['close']
            && $yesterday['close'] <= $yesterday['open'] && $t < $yesterday['close']) {
            return true;
        }

        return false;
    }

    /** Agla khulne ka waqt, "12:00 PM" (aaj) ya "Monday 12:00 PM". */
    public function nextOpening(array $hours, CarbonImmutable $now): ?string
    {
        for ($d = 0; $d <= 7; $d++) {
            $day = $now->addDays($d);
            $w = $hours[$this->dayKey($day)] ?? null;
            if (! $w || ! empty($w['closed']) || ! $w['open']) {
                continue;
            }
            [$h, $m] = array_map('intval', explode(':', $w['open']));
            $at = $day->setTime($h, $m);
            if ($at->greaterThan($now)) {
                return $d === 0 ? $at->format('g:i A') : $at->format('l g:i A');
            }
        }

        return null;
    }

    /**
     * Har branch par Delivery / Pick-Up ke liye koi khula terminal (asal ya backup) hai? Pick-Up ka
     * terminal khaali = "Delivery wala hi".
     *
     * @return array<int, array{delivery: bool, pickup: bool}>
     */
    public function staffed(): array
    {
        $routes = WebOrderRoute::all()->groupBy('branch_id');
        $openTerminals = Shift::where('status', 'open')->pluck('terminal_id')->filter()->map(fn ($id) => (int) $id)->all();
        $isOpen = fn (?int $a, ?int $b) => ($a && in_array($a, $openTerminals, true)) || ($b && in_array($b, $openTerminals, true));

        $out = [];
        foreach ($routes as $branchId => $rows) {
            $delivery = $rows->firstWhere('order_type', 'delivery');
            $pickup = $rows->firstWhere('order_type', 'takeaway');
            $pickupMain = $pickup?->terminal_id ?: $delivery?->terminal_id;
            $pickupBackup = $pickup?->terminal_id ? $pickup->backup_terminal_id : $delivery?->backup_terminal_id;

            $out[(int) $branchId] = [
                'delivery' => $delivery !== null && $isOpen($delivery->terminal_id, $delivery->backup_terminal_id),
                'pickup' => $isOpen($pickupMain, $pickupBackup),
            ];
        }

        return $out;
    }

    private function dayKey(CarbonImmutable $d): string
    {
        return strtolower($d->format('D'));   // mon, tue, …
    }
}
