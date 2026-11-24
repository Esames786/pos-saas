<?php

namespace App\Services\Edge;

use App\Models\Tenant\EdgeInboundSaleIngestion;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SWITCHOVER AUTO-RECONCILE (24 Nov 2026) — the owner's read-only "what did the Cloud fix by itself" list.
 *
 * Source: the sync registry (edge_inbound_sale_ingestions) — every automatic fix travels in the stored ACK under
 * `continuity.reconcile` (EdgeInboundSaleIngestionService), and a sale that still needs a person is a terminal continuity
 * conflict. Nothing here writes; a failure returns an empty list so the settings page always renders.
 */
class EdgeSwitchoverReportService
{
    /**
     * @return list<array{at: ?Carbon, sale_no: ?string, branch_id: int, text: string, needs_person: bool}>
     */
    public function recent(int $days = 30, int $limit = 100): array
    {
        try {
            $rows = EdgeInboundSaleIngestion::query()
                ->whereIn('status', [EdgeInboundSaleIngestion::STATUS_APPLIED, EdgeInboundSaleIngestion::STATUS_CONFLICT])
                ->where('ingested_at', '>=', now()->subDays($days))
                ->orderByDesc('ingested_at')->limit(1000)
                ->get(['sale_uuid', 'branch_id', 'status', 'failure_code', 'last_error', 'official_sale_no', 'ack_payload', 'ingested_at']);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $ack = (array) ($row->ack_payload ?? []);
            $saleNo = $row->official_sale_no ?: ($ack['official_sale_no'] ?? null);
            if ($row->status === EdgeInboundSaleIngestion::STATUS_CONFLICT) {
                if (! in_array((string) $row->failure_code, EdgeInboundSaleIngestionService::CONTINUITY_CONFLICT_CODES, true)) {
                    continue;
                }
                $out[] = ['at' => $row->ingested_at, 'sale_no' => $saleNo, 'branch_id' => (int) $row->branch_id, 'needs_person' => true,
                    'text' => 'Needs a person: ' . ((string) ($ack['message'] ?? $row->last_error ?? $row->failure_code))];
                continue;
            }
            foreach ((array) ($ack['continuity']['reconcile'] ?? []) as $fix) {
                $out[] = ['at' => $row->ingested_at, 'sale_no' => $saleNo, 'branch_id' => (int) $row->branch_id, 'needs_person' => false,
                    'text' => $this->describe((array) $fix)];
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_slice($out, 0, $limit);
    }

    /** One plain sentence per automatic fix (owner-facing). */
    public function describe(array $fix): string
    {
        $money = fn ($v) => 'Rs ' . number_format((float) $v, 2);

        return match ((string) ($fix['action'] ?? '')) {
            'merged_after_online_change' => 'The online bill changed just before the internet went off; the offline bill had all of it, so they were merged.',
            'online_items_not_on_offline_bill' => 'Items added online just before the internet went off were not on the paid offline bill and were removed: '
                . collect((array) ($fix['removed_lines'] ?? []))->map(fn ($l) => ($l['product_name'] ?? '?') . ' x' . rtrim(rtrim(number_format((float) ($l['quantity'] ?? 0), 3, '.', ''), '0'), '.'))->implode(', ')
                . ' (' . $money($fix['removed_amount'] ?? 0) . ' not charged).',
            'already_paid_online_not_posted' => 'This bill was already paid online (' . $money($fix['online_total'] ?? 0) . '), so the offline payment of '
                . $money($fix['offline_paid'] ?? 0) . ' was not recorded again. Check that counter\'s cash count.',
            'cancelled_online_sold_offline' => 'The bill was cancelled online just before the internet went off but was sold offline; it is recorded as sold.',
            'online_order_missing_posted_new' => 'The online bill no longer existed; the paid offline bill was recorded as a new sale.',
            'continued_shift_not_used' => 'The online shift could not be continued (' . ($fix['reason'] ?? 'not found') . '); the sale was recorded without a shift.',
            'replaced_online_bill' => 'Old unpaid online bill ' . ($fix['sale_no'] ?? '?') . ' (' . $money($fix['online_total'] ?? 0) . ') was re-punched offline and closed as replaced.',
            'online_bill_left_open' => 'Online bill ' . ($fix['sale_no'] ?? '?') . ' (' . $money($fix['online_total'] ?? 0) . ') on the same table was left open: '
                . ($fix['reason'] ?? 'different items') . '. Settle or cancel it on the POS.',
            default => 'Automatic switchover fix: ' . (string) ($fix['action'] ?? 'unknown'),
        };
    }
}
