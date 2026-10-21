<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseBillLine;
use App\Models\Tenant\PurchaseBillReturnNetting;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\SupplierCreditAllocation;
use App\Services\Finance\JournalPostingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The supplier's bill for one goods receipt — moved here from PurchaseBillController::store (unchanged for a receipt
 * with no returns), plus SUPPLIER-NET-BILL-1 (owner, 21 Oct): record the ACTUAL invoice.
 *
 * Goods returned from the receipt BEFORE its bill have already credited the supplier (ledger, GL, and the running
 * account). The supplier then bills one of two ways, and the bill asks which — nothing pre-picked:
 *
 *   separate  the invoice is for everything received (1,000); the return stays a credit and settles it once → 800 due
 *   netted    the invoice is already less the return (800); the return's credit is spent on it — a netting row,
 *             a matching supplier-ledger debit and GL entry (the mirror of the return), its allocations to other
 *             bills taken back — so it is never taken off a second time → 800 due, not 600
 *
 * One receipt has one bill, even when two posts arrive at once: the receipt row is locked and checked again inside.
 */
class PurchaseBillService
{
    public const SEPARATE = 'separate';

    public const NETTED = 'netted';

    public function __construct(
        private readonly PurchasingService $purchasing,
        private readonly SupplierRunningAccountService $runningAccount,
    ) {}

    /**
     * Returns of this receipt whose money credit is whole and not taken off any invoice — what the bill asks about.
     *
     * @return list<array{id:int, return_no:string, date:string, amount:float}>
     */
    public function returnsToAsk(GoodsReceipt $grn): array
    {
        return $this->eligibleReturns((int) $grn->id, false)
            ->map(fn (PurchaseReturn $r) => ['id' => (int) $r->id, 'return_no' => (string) $r->return_no,
                'date' => (string) \Illuminate\Support\Carbon::parse($r->return_date)->toDateString(), 'amount' => round((float) $r->grand_total, 2)])
            ->values()->all();
    }

    /**
     * @param  array{bill_date:string, due_date?:?string, supplier_invoice_no?:?string, discount_amount?:mixed,
     *               tax_amount?:mixed, notes?:?string, returns_on_invoice?:?string}  $data
     */
    public function createFromReceipt(GoodsReceipt $grn, array $data, ?int $userId = null): PurchaseBill
    {
        $choice = $data['returns_on_invoice'] ?? null;
        if ($choice !== null && ! in_array($choice, [self::SEPARATE, self::NETTED], true)) {
            throw ValidationException::withMessages(['returns_on_invoice' => 'Choose how the invoice treats the returns.']);
        }

        $bill = DB::connection('tenant')->transaction(function () use ($grn, $data, $choice, $userId) {
            // One receipt, one bill: two posts at once queue here; the second finds the first.
            $grn = GoodsReceipt::query()->whereKey($grn->id)->lockForUpdate()->firstOrFail();
            if (PurchaseBill::query()->where('goods_receipt_id', $grn->id)->exists()) {
                throw new RuntimeException('A bill already exists for this receipt.');
            }
            $grn->load(['lines', 'supplier', 'branch']);

            $returns = $this->eligibleReturns((int) $grn->id, true);
            if ($returns->isNotEmpty() && $choice === null) {
                throw ValidationException::withMessages(['returns_on_invoice' => 'Goods from this receipt went back before its bill ('
                    . $returns->map(fn ($r) => $r->return_no . ': ' . number_format((float) $r->grand_total, 2))->implode(', ')
                    . '). Say whether the supplier\'s invoice is for everything received, or already less the returns.']);
            }
            if ($returns->isEmpty() && $choice === self::NETTED) {
                throw ValidationException::withMessages(['returns_on_invoice' => 'Nothing went back from this receipt before its bill — the invoice cannot be "already less the returns".']);
            }
            $netted = $choice === self::NETTED ? $returns : collect();

            $subtotal = $grn->lines->sum(fn ($l) => $l->quantity_received * $l->unit_cost);
            // GRN-BILL-CHARGES-1: the receipt's cartage / labour is part of what the supplier charged.
            $charges = round((float) ($grn->extra_charges ?? 0), 4);
            $discount = (float) ($data['discount_amount'] ?? 0);
            $tax = (float) ($data['tax_amount'] ?? 0);
            $less = round((float) $netted->sum('grand_total'), 4);
            $grandTotal = round($subtotal + $charges - $discount + $tax - $less, 4);
            if ($netted->isNotEmpty() && $grandTotal < 0) {
                throw ValidationException::withMessages(['returns_on_invoice' => 'The returns are more than this invoice — check the returns or the discount.']);
            }

            $bill = PurchaseBill::create([
                'bill_no'             => $this->purchasing->nextBillNo(),
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'supplier_id'         => $grn->supplier_id,
                'branch_id'           => $grn->branch_id,
                'purchase_order_id'   => $grn->purchase_order_id,
                'goods_receipt_id'    => $grn->id,
                'bill_date'           => $data['bill_date'],
                'due_date'            => $data['due_date'] ?? null,
                'status'              => 'posted',
                'subtotal'            => $subtotal,
                'extra_charges'       => $charges,
                'discount_total'      => $discount,
                'tax_total'           => $tax,
                'grand_total'         => $grandTotal,
                'amount_paid'         => 0,
                'balance_due'         => $grandTotal,
                'notes'               => $data['notes'] ?? null,
                'posted_by_user_id'   => $userId,
                'posted_at'           => now(),
            ]);
            foreach ($grn->lines as $line) {
                PurchaseBillLine::create([
                    'purchase_bill_id'   => $bill->id,
                    'product_id'         => $line->product_id,
                    'product_variant_id' => $line->product_variant_id,
                    'quantity'           => $line->quantity_received,
                    'unit_cost'          => $line->unit_cost,
                    'discount_amount'    => 0,
                    'tax_amount'         => 0,
                    'line_total'         => $line->quantity_received * $line->unit_cost,
                ]);
            }

            // SUPPLIER-NET-BILL-1 — BEFORE the bill's own ledger row: the bill then takes no credit it already includes.
            foreach ($netted as $return) {
                $this->runningAccount->releaseCredit(SupplierCreditAllocation::SOURCE_RETURN, (int) $return->id);
                PurchaseBillReturnNetting::create([
                    'purchase_bill_id'   => $bill->id,
                    'purchase_return_id' => $return->id,
                    'supplier_id'        => $grn->supplier_id,
                    'amount'             => round((float) $return->grand_total, 4),
                    'created_by'         => $userId,
                ]);
                $this->purchasing->postSupplierLedger($grn->supplier, 'return_netted', 'debit', round((float) $return->grand_total, 4),
                    PurchaseBill::class, (int) $bill->id, (string) $bill->bill_no,
                    'Return ' . $return->return_no . ' already taken off this invoice', $userId);
            }

            $bill->load('supplier');
            // BUG-044: the supplier ledger inside the transaction; the GL after it (idempotent, never throws).
            $this->purchasing->postBillOperational($bill, $userId);

            return $bill;
        });

        $journal = app(JournalPostingService::class);
        $journal->postPurchaseBill($bill, $userId);
        foreach (PurchaseBillReturnNetting::query()->where('purchase_bill_id', $bill->id)->get() as $netting) {
            if ($entry = $journal->postPurchaseBillReturnNetting($netting, $userId)) {
                $netting->update(['journal_entry_id' => $entry->id]);
            }
        }

        return $bill->fresh();
    }

    /** Posted returns of the receipt with money credited, not yet taken off an invoice; locked when asked. */
    private function eligibleReturns(int $grnId, bool $lock): Collection
    {
        $query = PurchaseReturn::query()->where('goods_receipt_id', $grnId)->where('status', 'posted')
            ->where('grand_total', '>', 0.00005)->orderBy('id');
        if (Schema::connection('tenant')->hasTable('purchase_bill_return_nettings')) {
            $query->whereNotIn('id', PurchaseBillReturnNetting::query()->select('purchase_return_id'));
        }

        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }
}
