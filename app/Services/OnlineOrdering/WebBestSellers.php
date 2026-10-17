<?php

namespace App\Services\OnlineOrdering;

use App\Services\Reports\SalesReportEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE-ORDERING-1 — "Popular Items" asli bikri se (malik ne chuna, 15 Oct): pichhle 7 din me is branch par
 * sab se zyada bikne wale cards — counter aur website dono ke paid orders. Haath se lagaye "Popular" ki
 * zaroorat nahi. Bikri na ho (naya tenant) to haath wale hi.
 *
 * Deal = header line (combo_header) ki ginti; deal ke andar ki cheezein (component) nahi gini jatin.
 */
class WebBestSellers
{
    public const DAYS = 7;

    public const TOP = 8;

    /** @return array<string, float> 'product:ID' / 'combo:ID' => bikri ki ginti */
    public function sold(int $branchId): array
    {
        $key = 'web:best:'.(app()->bound('tenant') ? app('tenant')->id : 0).':'.$branchId;
        try {
            if (is_array($hit = Cache::get($key))) {
                return $hit;
            }
        } catch (\Throwable) {
        }

        $rows = DB::connection('tenant')->table('sales_order_lines as l')
            ->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->where('o.branch_id', $branchId)
            ->whereIn('o.status', SalesReportEngine::POPULATION)
            ->whereRaw('COALESCE(o.business_date, DATE(o.sale_date)) >= ?', [now()->subDays(self::DAYS)->toDateString()])
            ->whereIn('l.line_kind', ['standard', 'combo_header'])
            ->groupBy('l.line_kind', 'l.product_id', 'l.combo_id')
            ->selectRaw('l.line_kind, l.product_id, l.combo_id, SUM(l.quantity) as qty')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $k = $r->line_kind === 'combo_header' ? 'combo:'.$r->combo_id : 'product:'.$r->product_id;
            $out[$k] = ($out[$k] ?? 0) + (float) $r->qty;
        }
        try {
            Cache::put($key, $out, now()->addMinutes(30));
        } catch (\Throwable) {
        }

        return $out;
    }

    /**
     * Menu par "popular" asli bikri se: top cards popular (rank ke saath), baqi nahi. Bikri hi na ho to
     * menu waisa hi (haath wale popular).
     *
     * @param  list<array<string, mixed>>  $menu  WebMenuService::menu()
     * @return list<array<string, mixed>>
     */
    public function apply(array $menu, int $branchId): array
    {
        $sold = $this->sold($branchId);
        if ($sold === []) {
            return $menu;
        }

        $scores = [];
        foreach ($menu as $ci => $cat) {
            foreach ($cat['items'] as $ii => $card) {
                $score = 0.0;
                foreach ($card['options'] as $o) {
                    $score += $sold[$o['kind'].':'.$o['pos_id']] ?? 0;
                }
                if ($score > 0) {
                    $scores[$ci.':'.$ii] = $score;
                }
            }
        }
        if ($scores === []) {
            return $menu;
        }
        arsort($scores);
        $rank = array_flip(array_slice(array_keys($scores), 0, self::TOP));
        // Kam cheezein biki hon to haath se chuni "Popular" se poora karo — bikne wali hamesha pehle.
        foreach ($menu as $ci => $cat) {
            foreach ($cat['items'] as $ii => $card) {
                if (count($rank) < self::TOP && ! empty($card['popular']) && ! isset($rank[$ci.':'.$ii])) {
                    $rank[$ci.':'.$ii] = count($rank);
                }
            }
        }

        foreach ($menu as $ci => $cat) {
            foreach ($cat['items'] as $ii => $card) {
                $r = $rank[$ci.':'.$ii] ?? null;
                $menu[$ci]['items'][$ii]['popular'] = $r !== null;
                $menu[$ci]['items'][$ii]['popular_rank'] = $r;
            }
        }

        return $menu;
    }
}
