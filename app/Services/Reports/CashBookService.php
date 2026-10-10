<?php

namespace App\Services\Reports;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CASH-BOOK-REPORT-1 — the cash book: for each cash drawer / bank, what came in, what went out,
 * and what was left, day by day.
 *
 * Asked for by Kashif Kitchen (Bilal, 10 Oct) to check the cash register he is entering against
 * the system. Reads `cash_bank_account_transactions` — every receipt and payment that moved money
 * through a cash drawer or bank writes one row there.
 *
 * Balances are ADDED UP FROM THE ROWS: opening = the account's opening balance + every row before
 * the period. The stored `current_balance` is not used — on kashifkitchen it sits 502,500 above its
 * own rows (test rows deleted before go-live), and a cash book must agree with its own lines.
 */
class CashBookService
{
    public const PER_PAGE = 200;

    /** Accounts offered in the filter: active ones, plus inactive ones that still have rows. */
    public function accounts(?array $branchIds): Collection
    {
        return DB::connection('tenant')->table('cash_bank_accounts as a')
            ->when($branchIds, fn ($q) => $q->where(fn ($w) => $w->whereNull('a.branch_id')->orWhereIn('a.branch_id', $branchIds)))
            ->where(fn ($q) => $q->where('a.is_active', true)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('cash_bank_account_transactions as t')->whereColumn('t.cash_bank_account_id', 'a.id')))
            ->orderByRaw("FIELD(a.account_type, 'cash', 'bank', 'card', 'wallet') = 0, FIELD(a.account_type, 'cash', 'bank', 'card', 'wallet')")
            ->orderBy('a.name')
            ->get(['a.id', 'a.code', 'a.name', 'a.account_type', 'a.bank_name', 'a.branch_id', 'a.opening_balance', 'a.current_balance', 'a.is_active']);
    }

    /**
     * Per account: opening, in, out, closing for the period, and the stored balance for comparison.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(Collection $accounts, string $from, string $to): array
    {
        $ids = $accounts->pluck('id')->all();
        $rows = DB::connection('tenant')->table('cash_bank_account_transactions')
            ->whereIn('cash_bank_account_id', $ids ?: [0])
            ->where('transaction_date', '<=', $to)
            ->groupBy('cash_bank_account_id')
            ->selectRaw("cash_bank_account_id,
                SUM(CASE WHEN transaction_date < ? THEN (CASE WHEN direction = 'in' THEN amount ELSE -amount END) ELSE 0 END) AS before_net,
                SUM(CASE WHEN transaction_date >= ? AND direction = 'in'  THEN amount ELSE 0 END) AS in_amt,
                SUM(CASE WHEN transaction_date >= ? AND direction = 'out' THEN amount ELSE 0 END) AS out_amt,
                SUM(CASE WHEN transaction_date >= ? THEN 1 ELSE 0 END) AS rows_in_period", [$from, $from, $from, $from])
            ->get()->keyBy('cash_bank_account_id');
        $allTime = DB::connection('tenant')->table('cash_bank_account_transactions')
            ->whereIn('cash_bank_account_id', $ids ?: [0])
            ->groupBy('cash_bank_account_id')
            ->selectRaw("cash_bank_account_id, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS net")
            ->pluck('net', 'cash_bank_account_id');

        $out = [];
        foreach ($accounts as $a) {
            $r = $rows[$a->id] ?? null;
            $opening = round((float) $a->opening_balance + (float) ($r->before_net ?? 0), 2);
            $in = round((float) ($r->in_amt ?? 0), 2);
            $outAmt = round((float) ($r->out_amt ?? 0), 2);
            $fromRows = round((float) $a->opening_balance + (float) ($allTime[$a->id] ?? 0), 2);
            $out[$a->id] = [
                'account'  => $a,
                'opening'  => $opening,
                'in'       => $in,
                'out'      => $outAmt,
                'closing'  => round($opening + $in - $outAmt, 2),
                'entries'  => (int) ($r->rows_in_period ?? 0),
                // The balance the Cash & Bank screen shows vs the one its own rows add up to (today).
                'stored'   => round((float) $a->current_balance, 2),
                'from_rows' => $fromRows,
            ];
        }

        return $out;
    }

    /**
     * Every row of the period, oldest first per account, with the balance after it. The running
     * balance is worked out over ALL the account's rows, then filtered — so "only money in" still
     * shows the real balance, and page 3 starts where page 2 ended.
     */
    public function entries(Collection $accounts, string $from, string $to, string $direction, int $page, bool $all = false)
    {
        $ids = $accounts->pluck('id')->all() ?: [0];
        $openingBalances = $accounts->pluck('opening_balance', 'id');

        $running = DB::connection('tenant')->table('cash_bank_account_transactions as t')
            ->whereIn('t.cash_bank_account_id', $ids)
            ->where('t.transaction_date', '<=', $to)
            ->selectRaw("t.id, t.cash_bank_account_id, t.transaction_date, t.direction, t.amount, t.transaction_type,
                t.reference_type, t.reference_id, t.notes, t.created_at,
                SUM(CASE WHEN t.direction = 'in' THEN t.amount ELSE -t.amount END)
                    OVER (PARTITION BY t.cash_bank_account_id ORDER BY t.transaction_date, t.id) AS net_to_date");

        $query = DB::connection('tenant')->query()->fromSub($running, 'r')
            ->where('r.transaction_date', '>=', $from)
            ->when($direction !== 'all', fn ($q) => $q->where('r.direction', $direction))
            ->orderByRaw('FIELD(r.cash_bank_account_id, ' . implode(',', array_map('intval', $ids)) . ')')
            ->orderBy('r.transaction_date')
            ->orderBy('r.id');

        $map = function ($r) use ($openingBalances) {
            $r->balance = round((float) ($openingBalances[$r->cash_bank_account_id] ?? 0) + (float) $r->net_to_date, 2);

            return $r;
        };

        if ($all) {
            return $this->describe($query->get()->map($map));
        }

        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);
        $paginator->setCollection($this->describe($paginator->getCollection()->map($map)));

        return $paginator;
    }

    /**
     * Day by day per account: opening, in, out, closing — one line a day (restaurants write
     * 250–390 rows a day, so this is the readable view for them).
     *
     * @return array<int, array<int, array<string, mixed>>>  account id => days
     */
    public function daily(array $summary, string $from, string $to, string $direction): array
    {
        $ids = array_keys($summary) ?: [0];
        $days = DB::connection('tenant')->table('cash_bank_account_transactions')
            ->whereIn('cash_bank_account_id', $ids)
            ->whereBetween('transaction_date', [$from, $to])
            ->groupBy('cash_bank_account_id', 'transaction_date')
            ->orderBy('transaction_date')
            ->selectRaw("cash_bank_account_id, transaction_date,
                SUM(CASE WHEN direction = 'in'  THEN amount ELSE 0 END) AS in_amt,
                SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END) AS out_amt,
                COUNT(*) AS n")
            ->get()->groupBy('cash_bank_account_id');

        $out = [];
        foreach ($summary as $id => $s) {
            $balance = $s['opening'];
            foreach ($days[$id] ?? [] as $d) {
                $in = round((float) $d->in_amt, 2);
                $o = round((float) $d->out_amt, 2);
                $opening = $balance;
                $balance = round($balance + $in - $o, 2);
                if (($direction === 'in' && $in == 0.0) || ($direction === 'out' && $o == 0.0)) {
                    continue;
                }
                $out[$id][] = ['date' => (string) $d->transaction_date, 'opening' => $opening,
                    'in' => $in, 'out' => $o, 'closing' => $balance, 'entries' => (int) $d->n];
            }
        }

        return $out;
    }

    public const TYPE_LABELS = [
        'sales_payment' => 'Sale receipt', 'sales_return_refund' => 'Sale return refund',
        'customer_payment' => 'Customer receipt', 'customer_refund' => 'Customer refund',
        'expense_payment' => 'Expense', 'expense_void_reversal' => 'Expense voided (back)',
        'supplier_payment' => 'Supplier payment', 'manual_journal' => 'Manual journal',
        'opening_balance' => 'Opening balance', 'dept_handover_payout' => 'Department handover',
    ];

    /** Who the money came from / went to, and a link to the document — loaded per type, in bulk. */
    private function describe(Collection $rows): Collection
    {
        $c = DB::connection('tenant');
        $ids = fn (string ...$types) => $rows->whereIn('reference_type', $types)->pluck('reference_id')->filter()->unique()->values()->all();

        $sales = $c->table('sale_payments as sp')->join('sales_orders as o', 'o.id', '=', 'sp.sales_order_id')
            ->whereIn('sp.id', $ids('sale_payment') ?: [0])->get(['sp.id', 'o.id as order_id', 'o.sale_no', 'o.customer_name'])->keyBy('id');
        $returns = $c->table('sales_returns')->whereIn('id', $ids('sales_return', 'sales_return_delivery') ?: [0])->get(['id', 'return_no'])->keyBy('id');
        $expenses = $c->table('expense_vouchers as v')
            // The voucher's first line names what the money was for (category + description).
            ->leftJoin('expense_voucher_lines as l', fn ($j) => $j->on('l.expense_voucher_id', '=', 'v.id')
                ->whereRaw('l.id = (SELECT MIN(x.id) FROM expense_voucher_lines x WHERE x.expense_voucher_id = v.id)'))
            ->leftJoin('expense_categories as ec', 'ec.id', '=', 'l.expense_category_id')
            ->whereIn('v.id', $ids('expense_voucher') ?: [0])
            ->get(['v.id', 'v.voucher_no', 'v.payee_name', 'ec.name as category', 'l.description'])->keyBy('id');
        $supplierPayments = $c->table('supplier_payments as p')->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->whereIn('p.id', $ids('supplier_payment') ?: [0])->get(['p.id', 'p.payment_no', 's.name as supplier'])->keyBy('id');
        $advances = $c->table('catering_advances as a')->join('catering_events as e', 'e.id', '=', 'a.catering_event_id')
            ->whereIn('a.id', $ids('catering_advance') ?: [0])->get(['a.id', 'e.id as event_id', 'e.event_no', 'e.customer_name'])->keyBy('id');
        $refunds = $c->table('catering_refunds as r')->join('catering_events as e', 'e.id', '=', 'r.catering_event_id')
            ->whereIn('r.id', $ids('catering_refund') ?: [0])->get(['r.id', 'r.refund_no', 'e.id as event_id', 'e.event_no', 'e.customer_name'])->keyBy('id');
        $journals = $c->table('journal_entries')->whereIn('id', $ids('manual_journal') ?: [0])->get(['id', 'entry_no', 'description'])->keyBy('id');

        return $rows->map(function ($r) use ($sales, $returns, $expenses, $supplierPayments, $advances, $refunds, $journals) {
            [$ref, $party, $url, $route] = [null, null, null, null];
            $id = $r->reference_id;
            switch ($r->reference_type) {
                case 'sale_payment':
                    if ($s = $sales[$id] ?? null) {
                        [$ref, $party, $url, $route] = [$s->sale_no, $s->customer_name, '/sales-orders/' . $s->order_id, 'tenant.sales-orders.show'];
                    }
                    break;
                case 'sales_return':
                case 'sales_return_delivery':
                    if ($s = $returns[$id] ?? null) {
                        [$ref, $url, $route] = [$s->return_no, '/sales-returns/' . $s->id, 'tenant.sales-returns.show'];
                    }
                    break;
                case 'expense_voucher':
                    if ($e = $expenses[$id] ?? null) {
                        $what = trim(implode(' · ', array_filter([$e->category, $e->description])));
                        [$ref, $party, $url, $route] = [$e->voucher_no, trim(($e->payee_name ? $e->payee_name . ' — ' : '') . $what, ' —'),
                            '/finance/expenses/' . $e->id, 'tenant.finance.expenses.show'];
                    }
                    break;
                case 'supplier_payment':
                    if ($p = $supplierPayments[$id] ?? null) {
                        [$ref, $party, $url, $route] = [$p->payment_no, $p->supplier, '/supplier-payments/' . $p->id, 'tenant.supplier-payments.show'];
                    }
                    break;
                case 'catering_advance':
                    if ($a = $advances[$id] ?? null) {
                        [$ref, $party, $url, $route] = [$a->event_no, $a->customer_name, '/catering/events/' . $a->event_id, 'tenant.catering.events.show'];
                    }
                    break;
                case 'catering_refund':
                    if ($a = $refunds[$id] ?? null) {
                        [$ref, $party, $url, $route] = [$a->refund_no . ' · ' . $a->event_no, $a->customer_name, '/catering/events/' . $a->event_id, 'tenant.catering.events.show'];
                    }
                    break;
                case 'manual_journal':
                    if ($j = $journals[$id] ?? null) {
                        [$ref, $party, $url, $route] = [$j->entry_no, $j->description, '/finance/manual-journals/' . $j->id, 'tenant.finance.manual-journals.show'];
                    }
                    break;
            }
            $r->type_label = self::TYPE_LABELS[$r->transaction_type] ?? \Illuminate\Support\Str::headline((string) $r->transaction_type);
            $r->ref = $ref;
            $r->party = $party ?: $r->notes;
            $r->url = $url;
            $r->route = $route;

            return $r;
        });
    }
}
