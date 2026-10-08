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
        Supplier::whereKey($bill->supplier_id)->lockForUpdate()->first();
        $paid = 0.0;

        foreach ($this->unallocatedCredits((int) $bill->supplier_id) as $credit) {
            $bill->refresh();
            $due = (float) $bill->balance_due;
            if ($due <= self::CENT) {
                break;
            }
            $take = min($credit['remaining'], $due);
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
        foreach (SupplierPayment::where('supplier_id', $supplierId)->get(['id', 'payment_date', 'amount']) as $p) {
            $credits[] = ['type' => SupplierCreditAllocation::SOURCE_PAYMENT, 'id' => (int) $p->id,
                'date' => (string) $p->payment_date?->toDateString(), 'amount' => (float) $p->amount];
        }
        foreach (PurchaseReturn::where('supplier_id', $supplierId)->where('status', 'posted')->get(['id', 'return_date', 'grand_total']) as $r) {
            $credits[] = ['type' => SupplierCreditAllocation::SOURCE_RETURN, 'id' => (int) $r->id,
                'date' => (string) \Illuminate\Support\Carbon::parse($r->return_date)->toDateString(), 'amount' => (float) $r->grand_total];
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
    private function openBills(int $supplierId, ?int $preferredBillId)
    {
        return PurchaseBill::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', ['posted', 'partial'])
            ->where('balance_due', '>', self::CENT)
            ->orderByRaw('id = ? DESC', [(int) $preferredBillId])
            ->orderBy('bill_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
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

    /** "Advance 50,000.00 (Dr)" for a supplier we have paid ahead; the plain amount otherwise. */
    public static function balanceLabel(float $balance): string
    {
        return $balance < -self::CENT
            ? 'Advance ' . number_format(abs($balance), 2) . ' (Dr)'
            : number_format($balance, 2);
    }
}
