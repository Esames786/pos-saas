<?php

namespace App\Services\Finance;

use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\SupplierPayment;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supplier payable hardening (FIN-5).
 *
 * Wraps the existing PurchasingService payment posting (supplier ledger + bill
 * balance), which remains the authority for those. This service ADDS the optional
 * cash/bank movement (when a cash_bank_account is chosen) and provides AP aging.
 *
 * This is operational cash/bank history — NOT General Ledger journal posting.
 */
class SupplierPayableService
{
    public function __construct(private PurchasingService $purchasing) {}

    /**
     * Record a supplier payment end to end:
     *   1. create the payment row
     *   2. post supplier ledger + update the bill (existing PurchasingService)
     *   3. if a cash/bank account was chosen, write the cash/bank transaction
     */
    public function recordPayment(array $data, ?int $userId = null): SupplierPayment
    {
        return DB::connection('tenant')->transaction(function () use ($data, $userId) {
            $payment = SupplierPayment::create([
                ...$data,
                'payment_no'        => $this->purchasing->nextPaymentNo(),
                'posted_by_user_id' => $userId,
            ]);

            $payment->load('supplier');

            // Existing authority: supplier ledger (credit) + bill amount_paid/balance_due/status.
            $this->purchasing->postPayment($payment, $userId);

            if (! empty($data['cash_bank_account_id'])) {
                $this->postCashBankTransaction($payment, $userId);
            }

            return $payment;
        });
    }

    /**
     * Write the cash/bank "money out" transaction for a supplier payment and lower
     * the account balance. Idempotent — never creates a second transaction.
     */
    public function postCashBankTransaction(SupplierPayment $payment, ?int $userId = null): void
    {
        if (! $payment->cash_bank_account_id) {
            return;
        }

        $exists = CashBankAccountTransaction::query()
            ->where('reference_type', 'supplier_payment')
            ->where('reference_id', $payment->id)
            ->where('transaction_type', 'supplier_payment')
            ->exists();

        if ($exists) {
            return;
        }

        $cash = CashBankAccount::whereKey($payment->cash_bank_account_id)->lockForUpdate()->firstOrFail();

        $newBalance = (float) $cash->current_balance - (float) $payment->amount;

        CashBankAccountTransaction::create([
            'cash_bank_account_id' => $cash->id,
            'transaction_date'     => $payment->payment_date?->toDateString() ?? now()->toDateString(),
            'direction'            => 'out',
            'amount'               => $payment->amount,
            'balance_after'        => $newBalance,
            'transaction_type'     => 'supplier_payment',
            'reference_type'       => 'supplier_payment',
            'reference_id'         => $payment->id,
            'notes'                => 'Supplier payment ' . $payment->payment_no,
            'created_by_user_id'   => $userId,
        ]);

        $cash->update(['current_balance' => $newBalance]);
    }

    /**
     * Accounts-payable aging, grouped by supplier, from unpaid/partial purchase bills.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>, as_of: string}
     */
    public function aging(array $filters = []): array
    {
        $asOf = ! empty($filters['as_of_date'])
            ? Carbon::parse($filters['as_of_date'])->startOfDay()
            : now()->startOfDay();

        $statuses = match ($filters['status'] ?? 'all') {
            'unpaid' => ['posted'],
            'partial' => ['partial'],
            default  => ['posted', 'partial'],
        };

        $bills = PurchaseBill::query()
            ->with('supplier')
            ->whereIn('status', $statuses)
            ->where('balance_due', '>', 0)
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(! empty($filters['supplier_id']), fn ($q) => $q->where('supplier_id', $filters['supplier_id']))
            ->get();

        $rows = [];

        $blank = fn () => [
            'supplier_name' => null,
            'current'       => 0.0,
            'd1_30'         => 0.0,
            'd31_60'        => 0.0,
            'd61_90'        => 0.0,
            'd90_plus'      => 0.0,
            'total'         => 0.0,
        ];

        foreach ($bills as $bill) {
            $sid = $bill->supplier_id;
            if (! isset($rows[$sid])) {
                $rows[$sid] = $blank();
                $rows[$sid]['supplier_name'] = $bill->supplier?->name ?? ('Supplier #' . $sid);
            }

            $balance = (float) $bill->balance_due;

            // overdue days = asOf - due_date (positive when past due). No due date → current.
            $dueDate = $bill->due_date ? Carbon::parse($bill->due_date)->startOfDay() : null;
            $daysOverdue = $dueDate ? $dueDate->diffInDays($asOf, false) : 0;

            $bucket = match (true) {
                $daysOverdue <= 0  => 'current',
                $daysOverdue <= 30 => 'd1_30',
                $daysOverdue <= 60 => 'd31_60',
                $daysOverdue <= 90 => 'd61_90',
                default            => 'd90_plus',
            };

            $rows[$sid][$bucket] += $balance;
            $rows[$sid]['total'] += $balance;
        }

        $totals = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            }
        }

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'rows'   => array_values($rows),
            'totals' => $totals,
            'as_of'  => $asOf->toDateString(),
        ];
    }
}
