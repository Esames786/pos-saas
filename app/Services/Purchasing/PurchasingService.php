<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierLedger;
use App\Models\Tenant\SupplierPayment;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;

class PurchasingService
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    public function postSupplierLedger(
        Supplier $supplier,
        string $entryType,
        string $direction,
        float $amount,
        string $referenceType,
        int $referenceId,
        string $referenceNo,
        ?string $notes = null,
        ?int $userId = null
    ): SupplierLedger {
        // BUG-042 FIX: lock the supplier row before read-modify-write so concurrent
        // GRN postings and payments cannot corrupt the running balance.
        return DB::connection('tenant')->transaction(function () use (
            $supplier, $entryType, $direction, $amount,
            $referenceType, $referenceId, $referenceNo, $notes, $userId
        ) {
            $supplier = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            $balance = $direction === 'debit'
                ? $supplier->current_balance + $amount
                : $supplier->current_balance - $amount;

            $ledger = SupplierLedger::create([
                'supplier_id'        => $supplier->id,
                'entry_type'         => $entryType,
                'direction'          => $direction,
                'amount'             => $amount,
                'balance_after'      => $balance,
                'reference_type'     => $referenceType,
                'reference_id'       => $referenceId,
                'reference_no'       => $referenceNo,
                'notes'              => $notes,
                'created_by_user_id' => $userId,
            ]);

            $supplier->update(['current_balance' => $balance]);

            return $ledger;
        });
    }

    public function postGrn(GoodsReceipt $grn, ?int $userId = null): void
    {
        foreach ($grn->lines as $line) {
            $product = $line->product;
            $variant = $line->variant;
            $branch  = $grn->branch;

            $this->inventoryService->postIn(
                $branch,
                $product,
                $variant,
                (float) $line->quantity_received,
                (float) $line->unit_cost,
                'purchase',
                GoodsReceipt::class,
                $grn->id,
                $grn->grn_no,
                $line->batch_no,
                $line->expiry_date?->toDateString(),
                $line->notes,
                $userId
            );
        }
    }

    public function postBill(PurchaseBill $bill, ?int $userId = null): void
    {
        $this->postBillOperational($bill, $userId);
        // GL journal outside — safe + idempotent (JournalPostingService catches/reports).
        app(\App\Services\Finance\JournalPostingService::class)->postPurchaseBill($bill, $userId);
    }

    /**
     * BUG-044 FIX — operational-only bill posting (supplier ledger).
     * Called inside a DB transaction; GL journal is intentionally excluded so
     * a GL failure never rolls back the operational bill creation.
     */
    public function postBillOperational(PurchaseBill $bill, ?int $userId = null): void
    {
        $this->postSupplierLedger(
            $bill->supplier,
            'purchase_bill',
            'debit',
            (float) $bill->grand_total,
            PurchaseBill::class,
            $bill->id,
            $bill->bill_no,
            $bill->notes,
            $userId
        );
    }

    public function postPayment(SupplierPayment $payment, ?int $userId = null): void
    {
        $supplier = $payment->supplier;

        $this->postSupplierLedger(
            $supplier,
            'payment',
            'credit',
            (float) $payment->amount,
            SupplierPayment::class,
            $payment->id,
            $payment->payment_no,
            $payment->notes,
            $userId
        );

        if ($payment->purchase_bill_id) {
            $bill = PurchaseBill::find($payment->purchase_bill_id);
            if ($bill) {
                $newPaid = $bill->amount_paid + $payment->amount;
                $newBalance = max(0, $bill->grand_total - $newPaid);
                $status = $newBalance <= 0 ? 'paid' : 'partial';
                $bill->update([
                    'amount_paid' => $newPaid,
                    'balance_due' => $newBalance,
                    'status'      => $status,
                ]);
            }
        }
    }

    public function nextPoNo(): string
    {
        return 'PO-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    public function nextGrnNo(): string
    {
        return 'GRN-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    public function nextBillNo(): string
    {
        return 'BILL-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    public function nextPaymentNo(): string
    {
        return 'PAY-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }
}
