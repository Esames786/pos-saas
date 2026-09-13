<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Reports\SalesReportEngine;
use App\Services\Reports\SalesReportService;
use App\Support\TenantClock;
use Illuminate\Http\Request;

/**
 * SALES-ANALYTICS-1 — sales aur growth ka graph wala safha (Owner-only).
 *
 * ⚠️ YAHAN KOI NAYA HISAAB NAHI HAI. Har number `SalesReportService::dailyStats()` aur
 * `SalesReportEngine` se aata hai — wohi zariye jo Report Center aur dashboard istemal karte hain.
 *
 * Ye jaan-boojh kar hai. `dailyStats()` khud ek asli bug ke ilaj me bana tha
 * (DASHBOARD-7DAY-POPULATION-1): dashboard ka "Last 7 Days" card apni ALAG query chalata tha
 * (`status = paid` sirf, returns ghataye baghair), aur 1 Sep ko upar tile "Orders Today 295" aur
 * neeche row "291" dikha rahi thi — do din ka Rs 1,400 + 2,490 asli revenue bhi bahar reh gaya tha.
 *
 * Agar ye safha apni query likhta, to wohi bimari dobara hoti: owner ko graph par kuch aur, report
 * par kuch aur. Guard `SalesAnalyticsMySqlTest` isi baat par pehra deta hai — safhe ka jama usi
 * daur ke `overview()['net_sales']` se barabar hona chahiye.
 *
 * Doc: docs/plans/sales-analytics-dashboard-2026-09-13.md
 */
class SalesAnalyticsController extends Controller
{
    /** Lambe daur par din-ba-din chart parha nahi jata — itne din ke baad mahine par chale jao. */
    private const DAILY_MAX_DAYS = 70;

    public function index(Request $request, SalesReportService $sales, SalesReportEngine $engine)
    {
        $branches       = Branch::where('status', 'active')->orderBy('name')->get();
        $selectedBranch = $request->integer('branch_id') ?: null;
        $scopeUser      = auth('tenant')->user();

        [$from, $to, $preset] = $this->resolveRange($request);

        // Pichla BARABAR daur — growth isi se nikalta hai.
        $days     = max(1, $this->daysBetween($from, $to));
        $prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' day'));

        // Order type ka filter. Khali = sab.
        // ⚠️ Operator ko sirf wohi types offer hoti hain jo wo khud chala sakta hai — warna ek
        // scoped user filter se wo hissa dekh leta jo baqi safhe us se chhupate hain.
        $allowedTypes = $scopeUser?->effectiveAllowedOrderTypes() ?: array_keys(\App\Models\Tenant\User::ORDER_TYPES);
        $orderType    = (string) $request->input('order_type', '');
        $orderType    = in_array($orderType, $allowedTypes, true) ? $orderType : null;

        $series     = $sales->dailyStats($from, $to, $selectedBranch, $scopeUser, $orderType);
        $prevSeries = $sales->dailyStats($prevFrom, $prevTo, $selectedBranch, $scopeUser, $orderType);

        // Har din ki qatar banao — jin dinon sale nahi hui wo bhi 0 par nazar aayen, warna chart
        // ka waqt ka paimana jhoot bolta hai (do door ke din barabar faasle par dikhte hain).
        $daily = $this->fillGaps($series, $from, $to);

        $filters = $engine->normalizeFilters([
            'date_from'  => $from,
            'date_to'    => $to,
            'branch_ids' => $selectedBranch ? [$selectedBranch] : [],
            // Wohi filter engine ko bhi — warna upar ke tiles ek daur ginte aur neeche ke
            // category/payment charts doosra.
            'order_type' => $orderType,
        ]);

        $overview = $engine->overview($filters);

        $thisNet = array_sum(array_column($series, 'net_sales'));
        $prevNet = array_sum(array_column($prevSeries, 'net_sales'));
        $thisOrd = array_sum(array_column($series, 'orders'));
        $prevOrd = array_sum(array_column($prevSeries, 'orders'));

        return view('tenant.reports.analytics', [
            'branches'       => $branches,
            'selectedBranch' => $selectedBranch,
            'orderType'      => $orderType,
            'orderTypeList'  => array_intersect_key(\App\Models\Tenant\User::ORDER_TYPES, array_flip($allowedTypes)),
            'preset'         => $preset,
            'from'           => $from,
            'to'             => $to,
            'prevFrom'       => $prevFrom,
            'prevTo'         => $prevTo,
            'days'           => $days,

            'daily'          => $daily,
            // Pichle daur ki qatar, USI tarteeb par (din 1, din 2, …) — taake moqabale wala chart
            // do alag tareekhon ko aamne saamne rakh sake. Lambai barabar hoti hai kyunke pichla
            // daur bhi barabar dinon ka hai.
            'prevDaily'      => array_values(array_column($this->fillGaps($prevSeries, $prevFrom, $prevTo), 'net_sales')),
            'monthly'        => $this->rollUpByMonth($series),
            // Lamba daur ho to chart mahine par — 180 din ke nuqte parhe nahi jaate.
            'granularity'    => $days > self::DAILY_MAX_DAYS ? 'month' : 'day',

            'overview'       => $overview,
            'categories'     => $this->topCategories($engine->byCategory($filters)),
            'orderTypes'     => $this->cleanDimension($engine->byOrderType($filters)),
            'payments'       => $overview['payments'] ?? [],

            'totals'         => [
                'net_sales' => $thisNet,
                'orders'    => $thisOrd,
                'aov'       => $thisOrd > 0 ? $thisNet / $thisOrd : 0.0,
            ],
            'growth'         => [
                'net_sales' => $this->growth($thisNet, $prevNet),
                'orders'    => $this->growth((float) $thisOrd, (float) $prevOrd),
                'prev_net'  => $prevNet,
                'prev_ord'  => $prevOrd,
                // Pichle daur me kuch bika hi nahi to moqabala BE-MAANI hai. `0%` ya `∞%` likhna
                // jhoot hota; safha "moqabale ka data nahi" kehta hai.
                'comparable' => $prevNet > 0 || $prevOrd > 0,
            ],
        ]);
    }

    /**
     * Growth nikalo.
     *
     * ⚠️ Pichla daur sifar ho to koi bhi faisd jhoot hai — 0 se 100 tak jana "∞%" nahi, aur "100%"
     * bhi nahi. `null` lauto; safha usay "moqabale ka data nahi" likhta hai.
     */
    private function growth(float $now, float $before): ?float
    {
        if ($before <= 0) {
            return null;
        }

        return round((($now - $before) / $before) * 100, 1);
    }

    /**
     * Daur hal karo.
     *
     * Aakhri din hamesha BUSINESS date hai, Laravel ki UTC `today()` nahi — Asia/Karachi me raat
     * 12 ke baad wo ek din peeche hota hai aur aaj ka poora din chart se ghayab ho jata.
     */
    private function resolveRange(Request $request): array
    {
        $today  = app(TenantClock::class)->currentBusinessDate();
        $preset = (string) $request->input('preset', '30d');

        if ($preset === 'custom') {
            $from = $this->safeDate($request->input('from'), date('Y-m-d', strtotime($today . ' -29 day')));
            $to   = $this->safeDate($request->input('to'), $today);

            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }

            return [$from, $to, 'custom'];
        }

        return match ($preset) {
            '7d'         => [date('Y-m-d', strtotime($today . ' -6 day')), $today, '7d'],
            '90d'        => [date('Y-m-d', strtotime($today . ' -89 day')), $today, '90d'],
            '6m'         => [date('Y-m-d', strtotime($today . ' -6 month')), $today, '6m'],
            '12m'        => [date('Y-m-d', strtotime($today . ' -12 month')), $today, '12m'],
            'this_month' => [date('Y-m-01', strtotime($today)), $today, 'this_month'],
            'last_month' => [
                date('Y-m-01', strtotime($today . ' -1 month')),
                date('Y-m-t', strtotime($today . ' -1 month')),
                'last_month',
            ],
            default      => [date('Y-m-d', strtotime($today . ' -29 day')), $today, '30d'],
        };
    }

    private function safeDate(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false
            ? $value
            : $fallback;
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1;
    }

    /** Khali din bhi qatar me — warna chart ka waqt ka paimana jhoot bolta hai. */
    private function fillGaps(array $series, string $from, string $to): array
    {
        $out = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $row     = $series[$d] ?? null;
            $out[$d] = [
                'orders'    => (int) ($row['orders'] ?? 0),
                'net_sales' => (float) ($row['net_sales'] ?? 0),
                'returns'   => (float) ($row['returns_amount'] ?? 0),
                // Din ka naam — restaurant ka karobar HAFTE ke din se chalta hai (jumma/hafta bhaari,
                // peer halka). Sirf tareekh dekh kar wo tarteeb nazar hi nahi aati.
                'label'     => date('D d M', strtotime($d)),
                'full'      => date('l, d M Y', strtotime($d)),
            ];
        }

        return $out;
    }

    /** Mahine ka jama — dinon ke natije se, PHP me. Koi doosri query nahi. */
    private function rollUpByMonth(array $series): array
    {
        $out = [];
        foreach ($series as $date => $row) {
            $key = substr((string) $date, 0, 7);
            $out[$key] ??= [
                'orders' => 0, 'net_sales' => 0.0, 'returns' => 0.0,
                'label'  => date('M Y', strtotime($date)),
                'full'   => date('F Y', strtotime($date)),
            ];
            $out[$key]['orders']    += (int) $row['orders'];
            $out[$key]['net_sales'] += (float) $row['net_sales'];
            $out[$key]['returns']   += (float) $row['returns_amount'];
        }
        ksort($out);

        return $out;
    }

    /** Sirf ROOT heads, top 8 — poora darakht chart par parha nahi jata. */
    private function topCategories(array $rows): array
    {
        $flat = [];
        foreach ($rows as $row) {
            $name = (string) ($row['category_name'] ?? $row['name'] ?? '—');
            $net  = (float) ($row['net'] ?? $row['net_sales'] ?? 0);
            if ($net <= 0) {
                continue;
            }
            $flat[$name] = ($flat[$name] ?? 0) + $net;
        }
        arsort($flat);

        return array_slice($flat, 0, 8, true);
    }

    /** Dimension report se sirf label + net, aur wo qatarein jin par kuch bika hi nahi wo bahar. */
    private function cleanDimension(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $net = (float) ($row['net_sales'] ?? 0);
            if ($net <= 0) {
                continue;
            }
            $out[(string) ($row['label'] ?? '—')] = $net;
        }
        arsort($out);

        return $out;
    }
}
