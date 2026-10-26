<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierCreditAllocation;
use App\Models\Tenant\SupplierPayment;
use Illuminate\Support\Facades\DB;

/**
 * SUPPLIER-RUNNING-ACCOUNT-1 — a supplier paid on account.
 *
 * Kashif Kitchen pays suppliers in general amounts, not bill by bill, and sometimes ahead of the
 * goods. The books already handle that — a payment is Dr 2100 / Cr cash whatever it pays — but the
 * BILLS did not: a payment without a bill left every bill open, so the bill list and the payables
 * aging said more was owed than the ledger and the GL (FAISAL BEEF COUNTER: 117,600 more).
 *
 * With the tenant's switch ON, every credit settles bills: the bill it was made against first (up
 * to what that bill still owes), then the supplier's oldest open bills. What is left is an advance,
 * and the next bill posted for that supplier takes it first. Each step is a
 * supplier_credit_allocations row, so a bill's amount_paid is always the sum of its rows.
 *
 * Journals, the supplier ledger and cash/bank are never touched here.
 * Callers run inside the transaction that posted the supplier ledger, which holds the supplier row
 * locked — so two credits for one supplier cannot allocate the same bill at once.
 */
class SupplierRunningAccountService
{
    private const CENT = 0.00005;   // money is kept to 4 places; below this is rounding, not money

    public function enabled(): bool
    {
        return PurchasingSetting::supplierRunningAccount();
    }

    /**
     * Settle bills with a credit. Returns what is left over — the supplier's advance from it.
     */
    public function settleCredit(int $supplierId, string $sourceType, int $sourceId, float $amount, ?int $preferredBillId = null): float
    {
        Supplier::whereKey($supplierId)->lockForUpdate()->first();
        $left = round($amount, 4);

        foreach ($this->openBills($supplierId, $preferredBillId) as $bill) {
            if ($left <= self::CENT) {
                break;
            }
            $take = min($left, (float) $bill->balance_due);
            $this->allocate($sourceType, $sourceId, $bill, $take);
            $left = round($left - $take, 4);
        }

        return max(0.0, $left);
    }

    /**
     * A new bill first uses up credit the supplier already has, oldest credit first.
     * Returns how much of the bill that credit paid.
     */
    public function applyCreditToBill(PurchaseBill $bill): float
    {
        $supplier = Supplier::whereKey($bill->supplier_id)->lockForUpdate()->first();
        $paid = 0.0;

        // Only what the LEDGER says we had paid ahead may pay this bill. Unallocated credit can be
        // larger: part of a payment may have gone to an opening balance or a journal debit, which no
        // bill carries (prod, khatribiryani "Kashif kitchen": 365,310 unallocated, 197,310 real
        // advance, 168,000 opening). The ledger already holds this bill, so the advance before it is
        // grand_total − current_balance.
        $advance = max(0.0, round((float) $bill->grand_total - (float) ($supplier?->current_balance ?? 0), 4));

        foreach ($this->unallocatedCredits((int) $bill->supplier_id) as $credit) {
            $bill->refresh();
            $due = (float) $bill->balance_due;
            $room = round($advance - $paid, 4);
            if ($due <= self::CENT || $room <= self::CENT) {
                break;
            }
            $take = min($credit['remaining'], $due, $room);
            $this->allocate($credit['type'], $credit['id'], $bill, $take);
            $paid = round($paid + $take, 4);
        }

        return $paid;
    }

    /**
     * The supplier's credits with something not yet allocated, oldest first.
     *
     * @return array<int, array{type: string, id: int, date: string, remaining: float}>
     */
    public function unallocatedCredits(int $supplierId): array
    {
        $allocated = SupplierCreditAllocation::query()
            ->where('supplier_id', $supplierId)
            ->selectRaw('source_type, source_id, SUM(amount) AS total')
            ->groupBy('source_type', 'source_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->source_type . ':' . $r->source_id => (float) $r->total]);

        $credits = [];
        // SUPPLIER-PAYMENT-VOID-1: ulti ki gayi payment credit nahi rahi — us ka paisa drawer me wapas aa chuka.
        foreach (SupplierPayment::where('supplier_id', $supplierId)->whereNull('voided_at')->get(['id', 'payment_date', 'amount']) as $p) {
            $credits[] = ['type' => SupplierCreditAllocation::SOURCE_PAYMENT, 'id' => (int) $p->id,
                'date' => (string) $p->payment_date?->toDateString(), 'amount' => (float) $p->amount];
        }
        // SUPPLIER-NET-BILL-1: a return an invoice already takes off is spent — it is never offered again.
        $netted = \Illuminate\Support\Facades\Schema::connection('tenant')->hasTable('purchase_bill_return_nettings')
            ? \App\Models\Tenant\PurchaseBillReturnNetting::where('supplier_id', $supplierId)->pluck('amount', 'purchase_return_id')
            : collect();
        foreach (PurchaseReturn::where('supplier_id', $supplierId)->where('status', 'posted')->get(['id', 'return_date', 'grand_total']) as $r) {
            $credits[] = ['type' => SupplierCreditAllocation::SOURCE_RETURN, 'id' => (int) $r->id,
                'date' => (string) \Illuminate\Support\Carbon::parse($r->return_date)->toDateString(),
                'amount' => (float) $r->grand_total - (float) ($netted[$r->id] ?? 0)];
        }

        $open = [];
        foreach ($credits as $c) {
            $remaining = round($c['amount'] - ($allocated[$c['type'] . ':' . $c['id']] ?? 0.0), 4);
            if ($remaining > self::CENT) {
                $open[] = ['type' => $c['type'], 'id' => $c['id'], 'date' => $c['date'], 'remaining' => $remaining];
            }
        }
        usort($open, fn ($a, $b) => [$a['date'], $a['type'], $a['id']] <=> [$b['date'], $b['type'], $b['id']]);

        return $open;
    }

    /** Open bills to settle: the preferred one first, then oldest by bill date. Locked for this transaction. */
    private function openBills(int $supplierId, ?int $preferredBillId, bool $lock = true)
    {
        $query = PurchaseBill::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', ['posted', 'partial'])
            ->where('balance_due', '>', self::CENT)
            ->orderByRaw('id = ? DESC', [(int) $preferredBillId])
            ->orderBy('bill_date')
            ->orderBy('id');

        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }

    /**
     * SUPPLIER-CREDIT-CLARITY-1 — what settleCredit() would do with this payment, written nowhere: the bills it pays, in
     * the order it pays them, and the advance left over. The payment screen shows this list before Save and the server
     * posts only when it is still the same list (a payment or an invoice in between changes it).
     *
     * @return array{lines: list<array{bill_id:int, bill_no:string, amount:float}>, advance: float}
     */
    public function planSettle(int $supplierId, float $amount, ?int $preferredBillId = null): array
    {
        $left = round($amount, 4);
        $lines = [];
        foreach ($this->openBills($supplierId, $preferredBillId, false) as $bill) {
            if ($left <= self::CENT) {
                break;
            }
            $take = round(min($left, (float) $bill->balance_due), 4);
            $lines[] = ['bill_id' => (int) $bill->id, 'bill_no' => (string) $bill->bill_no, 'amount' => round($take, 2)];
            $left = round($left - $take, 4);
        }

        return ['lines' => $lines, 'advance' => round(max(0.0, $left), 2)];
    }

    /**
     * SUPPLIER-CREDIT-CLARITY-1 — the supplier's unused credit, oldest first (the order a new invoice uses it), each named
     * as people know it: the payment or return number and its date. The invoice review lists these.
     *
     * @return list<array{type:string, id:int, label:string, date:string, remaining:float}>
     */
    public function creditSources(int $supplierId): array
    {
        $credits = $this->unallocatedCredits($supplierId);
        $ids = fn (string $type) => array_column(array_filter($credits, fn ($c) => $c['type'] === $type), 'id');
        $payments = SupplierPayment::whereIn('id', $ids(SupplierCreditAllocation::SOURCE_PAYMENT))->pluck('payment_no', 'id');
        $returns = PurchaseReturn::whereIn('id', $ids(SupplierCreditAllocation::SOURCE_RETURN))->pluck('return_no', 'id');

        return array_map(fn ($c) => $c + ['label' => $c['type'] === SupplierCreditAllocation::SOURCE_PAYMENT
            ? 'Payment ' . ($payments[$c['id']] ?? '#' . $c['id']) : 'Return ' . ($returns[$c['id']] ?? '#' . $c['id'])], $credits);
    }

    /**
     * SUPPLIER-CREDIT-CLARITY-1 — what paid this invoice: each payment or return with the part of it on this invoice,
     * oldest first. The invoice page lists these (its amount paid is their sum).
     *
     * @return list<array{type:string, id:int, label:string, date:string, amount:float}>
     */
    public function paidBy(PurchaseBill $bill): array
    {
        $rows = SupplierCreditAllocation::where('purchase_bill_id', $bill->id)
            ->selectRaw('source_type, source_id, SUM(amount) AS total')->groupBy('source_type', 'source_id')->get();
        $ids = fn (string $type) => $rows->where('source_type', $type)->pluck('source_id')->all();
        $payments = SupplierPayment::whereIn('id', $ids(SupplierCreditAllocation::SOURCE_PAYMENT))->get(['id', 'payment_no', 'payment_date'])->keyBy('id');
        $returns = PurchaseReturn::whereIn('id', $ids(SupplierCreditAllocation::SOURCE_RETURN))->get(['id', 'return_no', 'return_date'])->keyBy('id');

        $out = [];
        foreach ($rows as $r) {
            if ((float) $r->total <= self::CENT) {
                continue;
            }
            $pay = $r->source_type === SupplierCreditAllocation::SOURCE_PAYMENT;
            $doc = $pay ? $payments->get($r->source_id) : $returns->get($r->source_id);
            $date = $doc ? ($pay ? $doc->payment_date : $doc->return_date) : null;
            $out[] = ['type' => (string) $r->source_type, 'id' => (int) $r->source_id,
                'label' => $pay ? 'Payment ' . ($doc->payment_no ?? '#' . $r->source_id) : 'Return ' . ($doc->return_no ?? '#' . $r->source_id),
                'date' => $date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : '', 'amount' => round((float) $r->total, 2)];
        }
        usort($out, fn ($a, $b) => [$a['date'], $a['type'], $a['id']] <=> [$b['date'], $b['type'], $b['id']]);

        return $out;
    }

    /**
     * SUPPLIER-CREDIT-CLARITY-1 — the invoices one payment paid, and how much on each, in bill order.
     *
     * @return list<array{bill_id:int, bill_no:string, bill_date:string, amount:float}>
     */
    public function paymentPaid(SupplierPayment $payment): array
    {
        return SupplierCreditAllocation::where('source_type', SupplierCreditAllocation::SOURCE_PAYMENT)->where('source_id', $payment->id)
            ->selectRaw('purchase_bill_id, SUM(amount) AS total')->groupBy('purchase_bill_id')->get()
            ->filter(fn ($r) => (float) $r->total > self::CENT)
            ->map(function ($r) {
                $bill = PurchaseBill::find($r->purchase_bill_id, ['id', 'bill_no', 'bill_date']);

                return ['bill_id' => (int) $r->purchase_bill_id, 'bill_no' => (string) ($bill->bill_no ?? '#' . $r->purchase_bill_id),
                    'bill_date' => (string) $bill?->bill_date?->toDateString(), 'amount' => round((float) $r->total, 2)];
            })
            ->sortBy(fn ($l) => [$l['bill_date'], $l['bill_id']])->values()->all();
    }

    /**
     * SUPPLIER-CREDIT-CLARITY-1 — move part of what a payment or return pays on one invoice to another invoice of the same
     * supplier: the first owes that much again, the second that much less. The supplier's balance, its ledger, cash and
     * the GL do not change — allocations only say which invoice the money settled. Each move is recorded.
     */
    public function moveAllocation(PurchaseBill $from, string $sourceType, int $sourceId, PurchaseBill $to, float $amount, ?int $userId, string $reason): void
    {
        $amount = round($amount, 4);
        DB::connection('tenant')->transaction(function () use ($from, $sourceType, $sourceId, $to, $amount, $userId, $reason) {
            Supplier::whereKey($from->supplier_id)->lockForUpdate()->first();
            $from = PurchaseBill::query()->whereKey($from->id)->lockForUpdate()->firstOrFail();
            $to = PurchaseBill::query()->whereKey($to->id)->lockForUpdate()->firstOrFail();

            if ($amount <= self::CENT) {
                throw new \RuntimeException('Enter an amount to move.');
            }
            if ($from->id === $to->id) {
                throw new \RuntimeException('Choose a different invoice to move it to.');
            }
            if ((int) $to->supplier_id !== (int) $from->supplier_id) {
                throw new \RuntimeException('The other invoice is for a different supplier.');
            }
            if (! in_array($to->status, ['posted', 'partial'], true) || (float) $to->balance_due + self::CENT < $amount) {
                throw new \RuntimeException($to->bill_no . ' owes ' . number_format((float) $to->balance_due, 2) . ' — less than ' . number_format($amount, 2) . '. Nothing was moved.');
            }
            $here = round((float) SupplierCreditAllocation::where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('purchase_bill_id', $from->id)->sum('amount'), 4);
            if ($here + self::CENT < $amount) {
                throw new \RuntimeException('This pays only ' . number_format($here, 2) . ' on ' . $from->bill_no . '. Nothing was moved.');
            }

            $this->releaseCreditFrom($sourceType, $sourceId, [$from->id => $amount]);
            $this->allocate($sourceType, $sourceId, $to, $amount);
            DB::connection('tenant')->table('supplier_credit_moves')->insert([
                'supplier_id' => $from->supplier_id, 'source_type' => $sourceType, 'source_id' => $sourceId,
                'from_purchase_bill_id' => $from->id, 'to_purchase_bill_id' => $to->id, 'amount' => $amount,
                'reason' => mb_substr($reason, 0, 255), 'moved_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function allocate(string $sourceType, int $sourceId, PurchaseBill $bill, float $amount): void
    {
        $amount = round($amount, 4);
        if ($amount <= self::CENT) {
            return;
        }

        SupplierCreditAllocation::create([
            'supplier_id'      => $bill->supplier_id,
            'source_type'      => $sourceType,
            'source_id'        => $sourceId,
            'purchase_bill_id' => $bill->id,
            'amount'           => $amount,
        ]);
        $this->recomputeBill($bill);
    }

    /**
     * SUPPLIER-NET-BILL-1 — take a credit back off the bills it settled, because its source is now spent elsewhere (a
     * return the supplier's next invoice already takes off). A return made before its own bill settles the supplier's
     * OLDEST open bills; the supplier never credited those, so they owe that amount again. Allocations are this
     * account's working rows (a bill's paid = their sum), not posted documents: the return itself is never touched.
     * Called inside the transaction that holds the supplier row.
     */
    public function releaseCredit(string $sourceType, int $sourceId): void
    {
        $rows = SupplierCreditAllocation::query()->where('source_type', $sourceType)->where('source_id', $sourceId);
        $billIds = (clone $rows)->pluck('purchase_bill_id')->unique()->sort()->values();
        if ($billIds->isEmpty()) {
            return;
        }
        $bills = PurchaseBill::query()->whereIn('id', $billIds)->orderBy('id')->lockForUpdate()->get();
        $rows->delete();
        foreach ($bills as $bill) {
            $this->recomputeBill($bill);
        }
    }

    /**
     * SUPPLIER-NET-BILL-1 (partial deduction) — take back only PART of a credit: the given amount off each given bill,
     * newest allocation row first; whatever stays allocated keeps settling that bill. Called inside the transaction that
     * holds the supplier row, with the bills already locked by the caller.
     *
     * @param  array<int, float>  $amountsByBill  purchase bill id => amount to take back from it
     */
    public function releaseCreditFrom(string $sourceType, int $sourceId, array $amountsByBill): void
    {
        foreach ($amountsByBill as $billId => $amount) {
            $left = round((float) $amount, 4);
            $rows = SupplierCreditAllocation::query()->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('purchase_bill_id', $billId)->orderByDesc('id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                if ($left <= self::CENT) {
                    break;
                }
                $take = min($left, round((float) $row->amount, 4));
                $rest = round((float) $row->amount - $take, 4);
                $rest > self::CENT ? $row->update(['amount' => $rest]) : $row->delete();
                $left = round($left - $take, 4);
            }
            if ($left > self::CENT) {
                throw new \RuntimeException('Supplier credit to take back is more than this bill holds — nothing was saved.');
            }
            if ($bill = PurchaseBill::query()->whereKey($billId)->first()) {
                $this->recomputeBill($bill);
            }
        }
    }

    /** A bill's paid amount is the sum of its allocations — the one rule every bill follows here. */
    public function recomputeBill(PurchaseBill $bill): void
    {
        $paid = round((float) SupplierCreditAllocation::where('purchase_bill_id', $bill->id)->sum('amount'), 4);
        $due = max(0.0, round((float) $bill->grand_total - $paid, 4));

        $bill->update([
            'amount_paid' => $paid,
            'balance_due' => $due,
            'status'      => $due <= self::CENT ? 'paid' : ($paid > self::CENT ? 'partial' : 'posted'),
        ]);
    }

    /**
     * "Advance 50,000.00 (Dr)" for a supplier we have paid ahead; "32,770.00 Cr" for what we owe
     * (SUPPLIER-LEDGER-DRCR-LABELS-1 — a creditor's balance says which side it is on); "0.00" when settled.
     */
    public static function balanceLabel(float $balance): string
    {
        if ($balance < -self::CENT) {
            return 'Advance ' . number_format(abs($balance), 2) . ' (Dr)';
        }

        return $balance > self::CENT ? number_format($balance, 2) . ' Cr' : number_format(0, 2);
    }
}
