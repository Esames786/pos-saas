<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

/**
 * TRIAL-BALANCE-PERIOD-1 part 2 — the parties that make up a control account, with the same four
 * figures as the account (Opening · Debit · Credit · Balance).
 *
 *   Accounts Payable (2100 + children) → supplier: the line's own supplier_id, else the source
 *                                         document's (bill, payment, return, supplier opening)
 *   2300 Customer Advances, 1300 A/R   → booking "EV-… · customer", via JournalEventResolver
 *   cash/bank control accounts         → the cash/bank account on the source document (catering
 *                                         receipts and refunds, expense vouchers, supplier payments,
 *                                         manual-journal cash lines)
 *   expense heads used by vouchers     → expense category "code · name"
 *
 * RULE: party lines always add up to their account. What cannot be attributed goes to an explicit
 * "Unassigned" line — it is never dropped. Built from the same lines, filters and date split as
 * FinancialExportService::trialBalancePeriod(), in one grouped query plus one batched lookup per
 * source table, whatever the number of rows.
 */
class TrialBalancePartyService
{
    private const UNASSIGNED = '~unassigned';
    private const NO_CASH = '~no-cash';

    private const CASH_SOURCES = [
        'catering_advance'       => 'catering_advances',
        'catering_settlement'    => 'catering_advances',
        'catering_split_receipt' => 'catering_advances',
        'catering_refund'        => 'catering_refunds',
        'catering_split_refund'  => 'catering_refunds',
        'expense_voucher'        => 'expense_vouchers',
        'supplier_payment'       => 'supplier_payments',
    ];

    private const SUPPLIER_SOURCES = [
        'purchase_bill'    => 'purchase_bills',
        'supplier_payment' => 'supplier_payments',
        'purchase_return'  => 'purchase_returns',
    ];

    public function __construct(
        private readonly SupplierPayableService $payables,
        private readonly JournalEventResolver $events,
    ) {}

    /**
     * @param  list<int>  $accountIds  the accounts on the trial balance being drawn
     * @return array<int, list<array<string,mixed>>> account id => party rows
     */
    public function partiesFor(string $from, string $to, ?array $branchIds, array $accountIds): array
    {
        $conn = DB::connection('tenant');
        $accountIds = array_map('intval', $accountIds);

        $ap = array_values(array_intersect($this->payables->apAccountIds(), $accountIds));
        $event = array_values(array_diff(array_intersect($conn->table('accounts')->whereIn('code', ['2300', '1300'])->pluck('id')->map(fn ($i) => (int) $i)->all(), $accountIds), $ap));
        $cash = array_values(array_diff(array_intersect($conn->table('cash_bank_accounts')->whereNotNull('account_id')->distinct()->pluck('account_id')->map(fn ($i) => (int) $i)->all(), $accountIds), $ap, $event));
        $expense = array_values(array_diff(array_intersect($conn->table('expense_voucher_lines')->whereNotNull('account_id')->distinct()->pluck('account_id')->map(fn ($i) => (int) $i)->all(), $accountIds), $ap, $event, $cash));

        $kindOf = [];
        foreach (['ap' => $ap, 'event' => $event, 'cash' => $cash, 'expense' => $expense] as $kind => $ids) {
            foreach ($ids as $id) {
                $kindOf[$id] = $kind;
            }
        }
        if (! $kindOf) {
            return [];
        }

        // One row per (account, entry, line supplier): every line of an entry falls on one side of
        // From, so a group is wholly opening or wholly period.
        $groups = $conn->table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')
            ->whereDate('e.entry_date', '<=', $to)
            ->whereIn('l.account_id', array_keys($kindOf))
            ->when($branchIds, fn ($q) => $q->where(fn ($q2) => $q2->whereIn('l.branch_id', $branchIds)->orWhereNull('l.branch_id')))
            ->groupBy('l.account_id', 'e.id', 'e.source_type', 'e.source_id', 'e.reversed_entry_id', 'e.is_reversal', 'l.supplier_id')
            ->selectRaw('l.account_id, e.id AS entry_id, e.source_type, e.source_id, e.reversed_entry_id, e.is_reversal, l.supplier_id,
                         MAX(CASE WHEN DATE(e.entry_date) < ? THEN 1 ELSE 0 END) AS is_opening,
                         COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit', [$from])
            ->get()
            ->map(function ($g) {
                $g->base = preg_replace('/_reversal$/', '', (string) $g->source_type);

                return $g;
            });

        $sourceIds = fn (array $types) => $groups->filter(fn ($g) => in_array($g->base, $types, true) && $g->source_id)
            ->pluck('source_id')->unique()->values()->all();

        // Batched lookups — one query per source table.
        $supplierOf = [];
        foreach (self::SUPPLIER_SOURCES as $type => $table) {
            $ids = $sourceIds([$type]);
            $supplierOf[$type] = $ids ? $conn->table($table)->whereIn('id', $ids)->pluck('supplier_id', 'id')->all() : [];
        }
        $cashOf = [];
        foreach (array_unique(self::CASH_SOURCES) as $table) {
            $types = array_keys(array_filter(self::CASH_SOURCES, fn ($t) => $t === $table));
            $ids = $sourceIds($types);
            $cashOf[$table] = $ids ? $conn->table($table)->whereIn('id', $ids)->pluck('cash_bank_account_id', 'id')->all() : [];
        }
        $cashAccounts = $conn->table('cash_bank_accounts')->get(['id', 'name', 'account_id'])->keyBy('id');
        $supplierNames = $conn->table('suppliers')->pluck('name', 'id')->all();

        $eventFor = $this->events->forEntries($groups->map(fn ($g, $i) => (object) [
            'id' => $i, 'source_type' => $g->source_type, 'source_id' => $g->source_id,
        ])->values());

        $manualEntryIds = $groups->filter(fn ($g) => $g->base === 'manual_journal')
            ->map(fn ($g) => (int) ($g->is_reversal && $g->reversed_entry_id ? $g->reversed_entry_id : $g->entry_id))->unique()->values()->all();
        $manualTxns = $manualEntryIds
            ? $conn->table('cash_bank_account_transactions')->where('reference_type', 'manual_journal')->whereIn('reference_id', $manualEntryIds)
                ->get(['reference_id', 'cash_bank_account_id', 'direction', 'amount'])->groupBy('reference_id')
            : collect();

        $voucherIds = $sourceIds(['expense_voucher']);
        $voucherLines = $voucherIds
            ? $conn->table('expense_voucher_lines as vl')->leftJoin('expense_categories as c', 'c.id', '=', 'vl.expense_category_id')
                ->whereIn('vl.expense_voucher_id', $voucherIds)
                ->get(['vl.expense_voucher_id', 'vl.account_id', 'vl.expense_category_id', 'vl.line_total', 'c.code', 'c.name'])
            : collect();

        $buckets = [];
        $add = function (int $account, string $key, string $label, bool $opening, float $debit, float $credit) use (&$buckets) {
            $b = &$buckets[$account][$key];
            $b ??= ['key' => $key, 'label' => $label, 'od' => 0.0, 'oc' => 0.0, 'pd' => 0.0, 'pc' => 0.0];
            if ($opening) {
                $b['od'] += $debit;
                $b['oc'] += $credit;
            } else {
                $b['pd'] += $debit;
                $b['pc'] += $credit;
            }
        };

        foreach ($groups as $i => $g) {
            $account = (int) $g->account_id;
            $opening = (bool) $g->is_opening;
            $debit = (float) $g->debit;
            $credit = (float) $g->credit;

            switch ($kindOf[$account]) {
                case 'ap':
                    $sid = $g->supplier_id
                        ?: ($g->base === 'supplier_opening_balance' ? $g->source_id
                            : (isset(self::SUPPLIER_SOURCES[$g->base]) ? ($supplierOf[$g->base][$g->source_id] ?? null) : null));
                    if ($sid && isset($supplierNames[$sid])) {
                        $add($account, 'supplier:' . $sid, $supplierNames[$sid], $opening, $debit, $credit);
                    } else {
                        $add($account, self::UNASSIGNED, 'Unassigned', $opening, $debit, $credit);
                    }
                    break;

                case 'event':
                    $ev = $eventFor[$i] ?? null;
                    if ($ev) {
                        $add($account, 'event:' . $ev['event_id'], trim($ev['event_no'] . ' · ' . ($ev['customer_name'] ?? '')), $opening, $debit, $credit);
                    } else {
                        $add($account, self::UNASSIGNED, 'Unassigned', $opening, $debit, $credit);
                    }
                    break;

                case 'cash':
                    if ($g->base === 'manual_journal') {
                        $original = (int) ($g->is_reversal && $g->reversed_entry_id ? $g->reversed_entry_id : $g->entry_id);
                        $leftD = $debit;
                        $leftC = $credit;
                        foreach ($manualTxns[$original] ?? [] as $t) {
                            $acct = $cashAccounts[$t->cash_bank_account_id] ?? null;
                            if (! $acct || (int) $acct->account_id !== $account) {
                                continue;
                            }
                            $in = ($t->direction === 'in') !== (bool) $g->is_reversal;   // a reversal flips it
                            $amt = (float) $t->amount;
                            $d = $in ? min($amt, $leftD) : 0.0;
                            $c = $in ? 0.0 : min($amt, $leftC);
                            $leftD -= $d;
                            $leftC -= $c;
                            $add($account, 'cash:' . $acct->id, $acct->name, $opening, $d, $c);
                        }
                        if ($leftD > 0.00005 || $leftC > 0.00005) {
                            $add($account, self::UNASSIGNED, 'Unassigned', $opening, $leftD, $leftC);
                        }
                        break;
                    }
                    $table = self::CASH_SOURCES[$g->base] ?? null;
                    $found = $table && array_key_exists($g->source_id, $cashOf[$table] ?? []);
                    $cid = $found ? $cashOf[$table][$g->source_id] : null;
                    if ($cid && isset($cashAccounts[$cid])) {
                        $add($account, 'cash:' . $cid, $cashAccounts[$cid]->name, $opening, $debit, $credit);
                    } elseif ($found && in_array($table, ['catering_advances', 'catering_refunds'], true)) {
                        $add($account, self::NO_CASH, 'No cash/bank account on the receipt', $opening, $debit, $credit);
                    } else {
                        $add($account, self::UNASSIGNED, 'Unassigned', $opening, $debit, $credit);
                    }
                    break;

                case 'expense':
                    $parts = $g->base === 'expense_voucher'
                        ? $voucherLines->where('expense_voucher_id', $g->source_id)->where('account_id', $account)
                            ->groupBy('expense_category_id')
                            ->map(fn ($ls) => ['label' => trim(($ls->first()->code ?? '') . ' · ' . ($ls->first()->name ?? 'Uncategorised'), ' ·'),
                                               'weight' => (float) $ls->sum('line_total')])
                            ->filter(fn ($p) => $p['weight'] > 0)
                        : collect();
                    $total = $parts->sum('weight');
                    if ($total <= 0) {
                        $add($account, self::UNASSIGNED, 'Unassigned', $opening, $debit, $credit);
                        break;
                    }
                    // Split by the voucher's own lines on this account; the last share takes the
                    // remainder so the parts add back to the account exactly.
                    $leftD = $debit;
                    $leftC = $credit;
                    $n = $parts->count();
                    $k = 0;
                    foreach ($parts as $catId => $p) {
                        $k++;
                        $d = $k === $n ? $leftD : round($debit * $p['weight'] / $total, 4);
                        $c = $k === $n ? $leftC : round($credit * $p['weight'] / $total, 4);
                        $leftD -= $d;
                        $leftC -= $c;
                        $add($account, 'category:' . $catId, $p['label'], $opening, $d, $c);
                    }
                    break;
            }
        }

        $out = [];
        foreach ($buckets as $account => $parties) {
            $rows = [];
            foreach ($parties as $b) {
                $openNet = $b['od'] - $b['oc'];
                $closeNet = $openNet + $b['pd'] - $b['pc'];
                if (round($openNet, 4) === 0.0 && round($b['pd'], 4) === 0.0 && round($b['pc'], 4) === 0.0 && round($closeNet, 4) === 0.0) {
                    continue;
                }
                $rows[] = [
                    'key'            => $b['key'],
                    'label'          => $b['label'],
                    'opening_debit'  => max($openNet, 0.0),
                    'opening_credit' => max(-$openNet, 0.0),
                    'period_debit'   => $b['pd'],
                    'period_credit'  => $b['pc'],
                    'closing_debit'  => max($closeNet, 0.0),
                    'closing_credit' => max(-$closeNet, 0.0),
                ];
            }
            // By name (bookings sort by their EV number, which leads the label); the catch-alls last.
            usort($rows, fn ($a, $b) => [str_starts_with($a['key'], '~'), mb_strtolower($a['label'])]
                <=> [str_starts_with($b['key'], '~'), mb_strtolower($b['label'])]);
            $out[$account] = $rows;
        }

        return $out;
    }
}
