<?php

namespace App\Services\Finance;

use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerLedger;
use App\Models\Tenant\CustomerPayment;
use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operational customer receivables (FIN-6).
 *
 * Debit raises a customer's receivable (they owe more); credit lowers it (they paid).
 * Records a customer_ledgers subledger + optional cash/bank movement on payment.
 * This is NOT General Ledger journal posting (that arrives in FIN-7).
 */
class CustomerReceivableService
{
    public function __construct(private JournalPostingService $journalPosting) {}

    /**
     * Post a credit (unpaid/partial) sale to the customer's receivable.
     * No-op for fully paid sales or sales without a customer. Idempotent.
     */
    public function recordCreditSale(SalesOrder $sale, ?int $userId = null): SalesOrder
    {
        if (! $sale->customer_id) {
            return $sale;
        }

        $sale = DB::connection('tenant')->transaction(function () use ($sale, $userId) {
            $sale = SalesOrder::whereKey($sale->id)->lockForUpdate()->firstOrFail();

            $due = round((float) $sale->grand_total - (float) $sale->paid_amount, 4);

            if ($due <= 0) {
                $sale->update(['balance_due' => 0, 'payment_status' => 'paid']);
                return $sale->fresh();
            }

            $dueDate = $sale->due_date;
            if (! $dueDate) {
                $customer = Customer::find($sale->customer_id);
                $days = (int) ($customer?->credit_days ?? 7);
                $dueDate = Carbon::parse($sale->sale_date ?? now())->addDays($days)->toDateString();
            }

            $sale->update([
                'balance_due'    => $due,
                'payment_status' => ((float) $sale->paid_amount) > 0 ? 'partial' : 'unpaid',
                'due_date'       => $dueDate,
            ]);

            // Idempotency: one "sale" receivable ledger row per sales order.
            $exists = CustomerLedger::query()
                ->where('reference_type', 'sales_order')
                ->where('reference_id', $sale->id)
                ->where('entry_type', 'sale')
                ->exists();

            if (! $exists) {
                $customer = Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail();
                $newBalance = (float) $customer->current_balance + $due;

                CustomerLedger::create([
                    'customer_id'        => $customer->id,
                    'branch_id'          => $sale->branch_id,
                    'entry_date'         => Carbon::parse($sale->sale_date ?? now())->toDateString(),
                    'entry_type'         => 'sale',
                    'direction'          => 'debit',
                    'amount'             => $due,
                    'balance_after'      => $newBalance,
                    'reference_type'     => 'sales_order',
                    'reference_id'       => $sale->id,
                    'reference_no'       => $sale->sale_no,
                    'notes'              => 'Credit sale ' . $sale->sale_no,
                    'created_by_user_id' => $userId,
                ]);

                $customer->update(['current_balance' => $newBalance]);
            }

            return $sale->fresh();
        });

        // GL journal (FIN-7): Dr AR / Cr Sales Revenue for the credit portion. Safe + idempotent.
        $this->journalPosting->postCreditSale($sale, $userId);

        return $sale;
    }

    /**
     * Record a customer payment: allocate to a sales order (if linked), lower the
     * customer receivable, write a payment ledger row, and (optionally) raise a
     * cash/bank balance. Idempotent on ledger + cash/bank rows.
     */
    public function recordPayment(array $data, ?int $userId = null): CustomerPayment
    {
        $payment = DB::connection('tenant')->transaction(function () use ($data, $userId) {
            $payment = CustomerPayment::create([
                ...$data,
                'payment_no'        => $this->nextPaymentNo(),
                'posted_by_user_id' => $userId,
            ]);

            $amount = (float) $payment->amount;

            // Allocate to the linked sales order, if any.
            if ($payment->sales_order_id) {
                $sale = SalesOrder::whereKey($payment->sales_order_id)->lockForUpdate()->first();
                if ($sale) {
                    $newPaid = (float) $sale->paid_amount + $amount;
                    $newDue  = max(0, round((float) $sale->grand_total - $newPaid, 4));
                    $sale->update([
                        'paid_amount'    => $newPaid,
                        'balance_due'    => $newDue,
                        'payment_status' => $newDue <= 0 ? 'paid' : 'partial',
                    ]);
                }
            }

            // Customer receivable ledger (credit) + balance.
            $customer = Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
            $newBalance = (float) $customer->current_balance - $amount;

            CustomerLedger::create([
                'customer_id'        => $customer->id,
                'branch_id'          => $payment->branch_id,
                'entry_date'         => $payment->payment_date?->toDateString() ?? now()->toDateString(),
                'entry_type'         => 'payment',
                'direction'          => 'credit',
                'amount'             => $amount,
                'balance_after'      => $newBalance,
                'reference_type'     => 'customer_payment',
                'reference_id'       => $payment->id,
                'reference_no'       => $payment->payment_no,
                'notes'              => 'Customer payment ' . $payment->payment_no,
                'created_by_user_id' => $userId,
            ]);

            $customer->update(['current_balance' => $newBalance]);

            if (! empty($data['cash_bank_account_id'])) {
                $this->postCashBankTransaction($payment, $userId);
            }

            return $payment->fresh();
        });

        // GL journal (FIN-7): Dr cash/bank / Cr AR — only when deposited to a cash/bank account. Safe + idempotent.
        $this->journalPosting->postCustomerPayment($payment, $userId);

        return $payment;
    }

    /** Write the cash/bank "money in" transaction for a customer payment. Idempotent. */
    public function postCashBankTransaction(CustomerPayment $payment, ?int $userId = null): void
    {
        if (! $payment->cash_bank_account_id) {
            return;
        }

        $exists = CashBankAccountTransaction::query()
            ->where('reference_type', 'customer_payment')
            ->where('reference_id', $payment->id)
            ->where('transaction_type', 'customer_payment')
            ->exists();

        if ($exists) {
            return;
        }

        $cash = CashBankAccount::whereKey($payment->cash_bank_account_id)->lockForUpdate()->firstOrFail();
        $newBalance = (float) $cash->current_balance + (float) $payment->amount;

        CashBankAccountTransaction::create([
            'cash_bank_account_id' => $cash->id,
            'transaction_date'     => $payment->payment_date?->toDateString() ?? now()->toDateString(),
            'direction'            => 'in',
            'amount'               => $payment->amount,
            'balance_after'        => $newBalance,
            'transaction_type'     => 'customer_payment',
            'reference_type'       => 'customer_payment',
            'reference_id'         => $payment->id,
            'notes'                => 'Customer payment ' . $payment->payment_no,
            'created_by_user_id'   => $userId,
        ]);

        $cash->update(['current_balance' => $newBalance]);
    }

    /**
     * Accounts-receivable aging grouped by customer, from unpaid/partial sales orders.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>, as_of: string}
     */
    public function aging(array $filters = []): array
    {
        $asOf = ! empty($filters['as_of_date'])
            ? Carbon::parse($filters['as_of_date'])->startOfDay()
            : now()->startOfDay();

        $statuses = match ($filters['status'] ?? 'all') {
            'unpaid'  => ['unpaid'],
            'partial' => ['partial'],
            default   => ['unpaid', 'partial'],
        };

        $orders = SalesOrder::query()
            ->with('customer')
            ->whereNotNull('customer_id')
            ->whereIn('payment_status', $statuses)
            ->where('balance_due', '>', 0)
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(! empty($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->get();

        $rows = [];
        $blank = fn () => ['customer_name' => null, 'current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];

        foreach ($orders as $order) {
            $cid = $order->customer_id;
            if (! isset($rows[$cid])) {
                $rows[$cid] = $blank();
                $rows[$cid]['customer_name'] = $order->customer?->name ?? ('Customer #' . $cid);
            }

            $balance = (float) $order->balance_due;

            $dueDate = $order->due_date ? Carbon::parse($order->due_date)->startOfDay() : null;
            $daysOverdue = $dueDate ? $dueDate->diffInDays($asOf, false) : 0;

            $bucket = match (true) {
                $daysOverdue <= 0  => 'current',
                $daysOverdue <= 30 => 'd1_30',
                $daysOverdue <= 60 => 'd31_60',
                $daysOverdue <= 90 => 'd61_90',
                default            => 'd90_plus',
            };

            $rows[$cid][$bucket] += $balance;
            $rows[$cid]['total'] += $balance;
        }

        $totals = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            }
        }

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return ['rows' => array_values($rows), 'totals' => $totals, 'as_of' => $asOf->toDateString()];
    }

    private function nextPaymentNo(): string
    {
        $prefix = 'CPY-' . now()->format('Ymd') . '-';
        $last = CustomerPayment::where('payment_no', 'like', $prefix . '%')->orderByDesc('payment_no')->value('payment_no');
        $seq = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
