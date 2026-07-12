<?php

namespace App\Services\Finance;

use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\CustomerPayment;
use App\Models\Tenant\ExpenseVoucher;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Translates operational finance events into balanced double-entry journals (FIN-7).
 *
 * Every method is idempotent (via JournalService source keys) and SAFE: it returns
 * null and reports the problem rather than throwing, so a missing account or unmapped
 * event can never break the operational flow that triggered it.
 */
class JournalPostingService
{
    public function __construct(private JournalService $journal) {}

    /** Dr expense accounts / Cr cash-bank. Returns null if no cash/bank CoA link. */
    public function postExpenseVoucher(ExpenseVoucher $voucher, ?int $userId = null): ?JournalEntry
    {
        try {
            if (! $voucher->isPosted()) {
                return null;
            }

            $voucher->loadMissing(['lines', 'cashBankAccount']);

            $creditAccountId = $voucher->cashBankAccount?->account_id;
            if (! $creditAccountId) {
                report(new \RuntimeException("Expense voucher {$voucher->voucher_no} cash/bank account has no linked CoA account; journal skipped."));
                return null;
            }

            $lines = [];
            $fallbackExpenseId = $this->journal->accountId('6800'); // Miscellaneous Expense

            foreach ($voucher->lines as $line) {
                $lines[] = [
                    'account_id'  => $line->account_id ?: $fallbackExpenseId,
                    'branch_id'   => $voucher->branch_id,
                    'description' => $line->description ?: 'Expense',
                    'debit'       => (float) $line->line_total,
                    'credit'      => 0,
                ];
            }

            $lines[] = [
                'account_id'  => $creditAccountId,
                'branch_id'   => $voucher->branch_id,
                'description' => 'Paid from ' . ($voucher->cashBankAccount->name ?? 'cash/bank'),
                'debit'       => 0,
                'credit'      => (float) $voucher->total_amount,
            ];

            return $this->journal->post(
                'expense_voucher',
                $voucher->id,
                $voucher->voucher_no,
                'Expense voucher ' . $voucher->voucher_no,
                ($voucher->payment_date ?? $voucher->expense_date)?->toDateString() ?? now()->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /** Dr Accounts Payable / Cr cash-bank. Null if no cash/bank account on the payment. */
    public function postSupplierPayment(SupplierPayment $payment, ?int $userId = null): ?JournalEntry
    {
        try {
            if (! $payment->cash_bank_account_id) {
                return null; // no cash/bank source — nothing to journal
            }

            $creditAccountId = $this->cashBankCoaId($payment->cash_bank_account_id);
            if (! $creditAccountId) {
                return null;
            }

            $lines = [
                ['account_code' => '2100', 'branch_id' => $payment->branch_id, 'description' => 'Accounts Payable', 'debit' => (float) $payment->amount, 'credit' => 0],
                ['account_id' => $creditAccountId, 'branch_id' => $payment->branch_id, 'description' => 'Supplier payment ' . $payment->payment_no, 'debit' => 0, 'credit' => (float) $payment->amount],
            ];

            return $this->journal->post(
                'supplier_payment',
                $payment->id,
                $payment->payment_no,
                'Supplier payment ' . $payment->payment_no,
                $payment->payment_date?->toDateString() ?? now()->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /** Dr cash-bank / Cr Accounts Receivable. Null if no cash/bank account on the payment. */
    public function postCustomerPayment(CustomerPayment $payment, ?int $userId = null): ?JournalEntry
    {
        try {
            if (! $payment->cash_bank_account_id) {
                return null;
            }

            $debitAccountId = $this->cashBankCoaId($payment->cash_bank_account_id);
            if (! $debitAccountId) {
                return null;
            }

            $lines = [
                ['account_id' => $debitAccountId, 'branch_id' => $payment->branch_id, 'description' => 'Customer payment ' . $payment->payment_no, 'debit' => (float) $payment->amount, 'credit' => 0],
                ['account_code' => '1300', 'branch_id' => $payment->branch_id, 'description' => 'Accounts Receivable', 'debit' => 0, 'credit' => (float) $payment->amount],
            ];

            return $this->journal->post(
                'customer_payment',
                $payment->id,
                $payment->payment_no,
                'Customer payment ' . $payment->payment_no,
                $payment->payment_date?->toDateString() ?? now()->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * Dr cash/bank (by payment-method mapping) / Cr sales revenue + tax + service + tips,
     * with sales discount as a contra-income debit, for a fully-paid POS sale (FIN-7B).
     *
     * Revenue is computed as the balancing figure so the entry always balances.
     * COGS is deferred (see FIN-7C). Returns null for non-paid sales or credit sales.
     */
    public function postPaidSale(SalesOrder $sale, ?int $userId = null): ?JournalEntry
    {
        try {
            $grand = round((float) $sale->grand_total, 4);
            if ($grand <= 0) {
                return null;
            }

            // Must be fully paid; never journal a sale that still has a receivable.
            $balanceDue = round((float) ($sale->balance_due ?? 0), 4);
            $fullyPaid = $sale->payment_status === 'paid'
                || ($balanceDue <= 0 && (float) $sale->paid_amount + 0.01 >= $grand);
            if (! $fullyPaid) {
                return null;
            }

            // If this sale was already journaled as a credit sale, don't also post it as paid.
            if ($this->journal->findPostedForSource('sales_order_credit', $sale->id)) {
                return null;
            }

            $sale->loadMissing(['payments.method.cashBankAccount', 'lines']);

            $discount = round((float) ($sale->discount_amount ?? 0), 4);
            $tax      = round((float) ($sale->tax_amount ?? 0), 4);
            $service  = round((float) ($sale->service_charge_amount ?? 0), 4);
            $tip      = round((float) ($sale->tip_amount ?? 0), 4);

            $undepositedId = $this->journal->accountId('1500');

            // ── Debit side: allocate grand_total across payment methods' mapped accounts ──
            $lines = [];
            $remaining = $grand;
            foreach ($sale->payments as $payment) {
                if ($remaining <= 0) {
                    break;
                }
                $applied = min(round((float) $payment->amount, 4), $remaining);
                if ($applied <= 0) {
                    continue;
                }
                $accountId = $payment->method?->cashBankAccount?->account_id ?? $undepositedId;
                $lines[] = [
                    'account_id'  => $accountId,
                    'branch_id'   => $sale->branch_id,
                    'description' => 'Sale receipt (' . ($payment->method?->name ?? 'payment') . ')',
                    'debit'       => $applied,
                    'credit'      => 0,
                ];
                $remaining -= $applied;
            }
            if (empty($lines)) {
                $lines[] = ['account_id' => $undepositedId, 'branch_id' => $sale->branch_id, 'description' => 'Sale proceeds', 'debit' => $grand, 'credit' => 0];
                $remaining = 0.0;
            } elseif ($remaining > 0.0001) {
                $lines[] = ['account_id' => $undepositedId, 'branch_id' => $sale->branch_id, 'description' => 'Sale proceeds (unallocated)', 'debit' => round($remaining, 4), 'credit' => 0];
                $remaining = 0.0;
            }

            $revenueCode = in_array($sale->order_type, ['dine_in', 'takeaway', 'delivery'], true) ? '4120' : '4110';
            $revenue = round($grand + $discount - $tax - $service - $tip, 4);

            if ($revenue > 0) {
                if ($discount > 0) {
                    $lines[] = ['account_code' => '4200', 'branch_id' => $sale->branch_id, 'description' => 'Sales discount', 'debit' => $discount, 'credit' => 0];
                }
                if ($tax > 0) {
                    $lines[] = ['account_code' => '2200', 'branch_id' => $sale->branch_id, 'description' => 'Sales tax payable', 'debit' => 0, 'credit' => $tax];
                }
                if ($service > 0) {
                    $lines[] = ['account_code' => '4130', 'branch_id' => $sale->branch_id, 'description' => 'Service charge', 'debit' => 0, 'credit' => $service];
                }
                if ($tip > 0) {
                    $lines[] = ['account_code' => '4140', 'branch_id' => $sale->branch_id, 'description' => 'Tips', 'debit' => 0, 'credit' => $tip];
                }
                $lines[] = ['account_code' => $revenueCode, 'branch_id' => $sale->branch_id, 'description' => 'Sales revenue ' . $sale->sale_no, 'debit' => 0, 'credit' => $revenue];
            } else {
                // Pathological (revenue computes <= 0): keep it simple and balanced.
                $lines[] = ['account_code' => $revenueCode, 'branch_id' => $sale->branch_id, 'description' => 'Sales revenue ' . $sale->sale_no, 'debit' => 0, 'credit' => $grand];
            }

            // COGS (FIN-7C): Dr 5100 Product COGS / Cr 1400 Inventory Asset — an
            // independently balanced pair, so the whole entry stays balanced.
            // BUG-001 FIX: recipe-based lines already moved stock through ingredient
            // consumption (postOutFefo on each ingredient), so their cost_total must
            // NOT credit 1400 again. Instead credit 5200 Recipe/Ingredient COGS for
            // those lines so the GL stays reconciled with the stock ledger.
            $stockItemCogs  = 0.0;
            $recipeCogs     = 0.0;
            foreach ($sale->lines as $line) {
                $method = $line->product?->inventory_consumption_method ?? 'stock_item';
                if ($method === 'recipe') {
                    $recipeCogs += (float) $line->cost_total;
                } else {
                    $stockItemCogs += (float) $line->cost_total;
                }
            }
            $stockItemCogs = round($stockItemCogs, 4);
            $recipeCogs    = round($recipeCogs, 4);

            if ($stockItemCogs > 0) {
                $lines[] = ['account_code' => '5100', 'branch_id' => $sale->branch_id, 'description' => 'COGS ' . $sale->sale_no, 'debit' => $stockItemCogs, 'credit' => 0];
                $lines[] = ['account_code' => '1400', 'branch_id' => $sale->branch_id, 'description' => 'Inventory reduction ' . $sale->sale_no, 'debit' => 0, 'credit' => $stockItemCogs];
            }
            if ($recipeCogs > 0) {
                // Recipe ingredient cost: stock was already moved by individual ingredient
                // postOutFefo calls. We post Dr 5200 / Cr 5200 as a COGS reclassification
                // — actually Dr 5200 Recipe COGS / Cr 5100 Product COGS to keep the P&L
                // accurate without touching 1400 again.
                $lines[] = ['account_code' => '5200', 'branch_id' => $sale->branch_id, 'description' => 'Recipe COGS ' . $sale->sale_no, 'debit' => $recipeCogs, 'credit' => 0];
                $lines[] = ['account_code' => '5100', 'branch_id' => $sale->branch_id, 'description' => 'Recipe COGS transfer ' . $sale->sale_no, 'debit' => 0, 'credit' => $recipeCogs];
            }

            return $this->journal->post(
                'sales_order_paid',
                $sale->id,
                $sale->sale_no,
                'Paid sale ' . $sale->sale_no,
                ($sale->sale_date ?? now())->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /** Dr Accounts Receivable / Cr Sales Revenue for the credit (unpaid) portion of a sale. */
    public function postCreditSale(SalesOrder $sale, ?int $userId = null): ?JournalEntry
    {
        try {
            if (! $sale->customer_id) {
                return null;
            }

            $amount = round((float) $sale->balance_due, 4);
            if ($amount <= 0) {
                return null;
            }

            $revenueCode = in_array($sale->order_type, ['dine_in', 'takeaway', 'delivery'], true) ? '4120' : '4110';

            $lines = [
                ['account_code' => '1300', 'branch_id' => $sale->branch_id, 'description' => 'Accounts Receivable', 'debit' => $amount, 'credit' => 0],
                ['account_code' => $revenueCode, 'branch_id' => $sale->branch_id, 'description' => 'Credit sale ' . $sale->sale_no, 'debit' => 0, 'credit' => $amount],
            ];

            return $this->journal->post(
                'sales_order_credit',
                $sale->id,
                $sale->sale_no,
                'Credit sale ' . $sale->sale_no,
                ($sale->sale_date ?? now())->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * Reverse a sale for a return/refund (FIN-7C):
     *   Dr Sales Revenue (subtotal) + Dr Sales Tax Payable (tax)  / Cr cash-bank (grand_total)
     *   plus COGS reversal Dr Inventory / Cr COGS for the returned cost (restocked goods).
     */
    public function postSalesReturn(SalesReturn $return, ?int $userId = null): ?JournalEntry
    {
        try {
            $grand = round((float) $return->grand_total, 4);
            if ($grand <= 0) {
                return null;
            }

            $subtotal = round((float) $return->subtotal, 4);
            $tax      = round((float) $return->tax_amount, 4);

            $return->loadMissing(['order', 'lines.orderLine']);

            $revenueCode = in_array($return->order?->order_type, ['dine_in', 'takeaway', 'delivery'], true) ? '4120' : '4110';

            // BUG-027 FIX (GL): resolve refund account from original sale payment method
            // mapping instead of hardcoded CASH-MAIN/BANK-MAIN codes which break for any
            // tenant with differently named cash/bank accounts.
            $return->loadMissing(['order.payments.method']);
            $creditAccountId = null;

            if ($return->order) {
                foreach ($return->order->payments as $payment) {
                    $methodType = $payment->method?->method_type;
                    $matches = match ($return->refund_method) {
                        'cash'          => $methodType === 'cash',
                        'bank_transfer' => $methodType === 'bank_transfer',
                        default         => false,
                    };
                    if ($matches && $payment->method?->cashBankAccount?->account_id) {
                        $creditAccountId = $payment->method->cashBankAccount->account_id;
                        break;
                    }
                }
            }

            // Fallback: find any default cash/bank account of the right type.
            if (! $creditAccountId) {
                $accountType = $return->refund_method === 'cash' ? 'cash' : 'bank';
                $creditAccountId = CashBankAccount::where('account_type', $accountType)
                    ->where('is_active', true)
                    ->where('is_default', true)
                    ->value('account_id');
            }

            // Final fallback: Undeposited Funds (1500).
            if (! $creditAccountId) {
                $creditAccountId = $this->journal->accountId('1500');
            }

            $lines = [];
            if ($subtotal > 0) {
                $lines[] = ['account_code' => $revenueCode, 'branch_id' => $return->branch_id, 'description' => 'Sales return ' . $return->return_no, 'debit' => $subtotal, 'credit' => 0];
            }
            if ($tax > 0) {
                $lines[] = ['account_code' => '2200', 'branch_id' => $return->branch_id, 'description' => 'Sales tax reversal', 'debit' => $tax, 'credit' => 0];
            }
            $lines[] = ['account_id' => $creditAccountId, 'branch_id' => $return->branch_id, 'description' => 'Refund ' . $return->return_no, 'debit' => 0, 'credit' => $grand];

            // COGS reversal — returned goods go back into inventory (balanced pair).
            $cogs = 0.0;
            foreach ($return->lines as $line) {
                $cogs += (float) $line->quantity * (float) ($line->orderLine?->unit_cost ?? 0);
            }
            $cogs = round($cogs, 4);
            if ($cogs > 0) {
                $lines[] = ['account_code' => '1400', 'branch_id' => $return->branch_id, 'description' => 'Inventory restock ' . $return->return_no, 'debit' => $cogs, 'credit' => 0];
                $lines[] = ['account_code' => '5100', 'branch_id' => $return->branch_id, 'description' => 'COGS reversal ' . $return->return_no, 'debit' => 0, 'credit' => $cogs];
            }

            return $this->journal->post(
                'sales_return',
                $return->id,
                $return->return_no,
                'Sales return ' . $return->return_no,
                ($return->return_date ?? now())->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * Operational cash/bank movement for a fully-paid POS sale (FIN-7C): one IN
     * transaction per sale payment (mapped account), bumping current_balance.
     * Idempotent per sale_payment id; skips payments without a mapped account.
     */
    public function postSalesCashBankMovement(SalesOrder $sale, ?int $userId = null): void
    {
        try {
            $sale->loadMissing('payments.method');

            foreach ($sale->payments as $payment) {
                $cashBankAccountId = $payment->method?->cash_bank_account_id;
                if (! $cashBankAccountId) {
                    continue;
                }

                // BUG-002 FIX: wrap in a tenant transaction so lockForUpdate is effective.
                DB::connection('tenant')->transaction(function () use ($payment, $cashBankAccountId, $userId, $sale) {
                    $exists = CashBankAccountTransaction::query()
                        ->where('reference_type', 'sale_payment')
                        ->where('reference_id', $payment->id)
                        ->where('transaction_type', 'sales_payment')
                        ->exists();
                    if ($exists) {
                        return;
                    }

                    $cash = CashBankAccount::whereKey($cashBankAccountId)->lockForUpdate()->first();
                    if (! $cash) {
                        return;
                    }

                    $newBalance = (float) $cash->current_balance + (float) $payment->amount;

                    CashBankAccountTransaction::create([
                        'cash_bank_account_id' => $cash->id,
                        'transaction_date'     => ($sale->sale_date ?? now())->toDateString(),
                        'direction'            => 'in',
                        'amount'               => $payment->amount,
                        'balance_after'        => $newBalance,
                        'transaction_type'     => 'sales_payment',
                        'reference_type'       => 'sale_payment',
                        'reference_id'         => $payment->id,
                        'notes'                => 'Sale receipt ' . $sale->sale_no,
                        'created_by_user_id'   => $userId,
                    ]);

                    $cash->update(['current_balance' => $newBalance]);
                });
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Operational cash/bank OUT movement for a sales return refund (FIN-7C). Idempotent. */
    public function postSalesReturnCashBankMovement(SalesReturn $return, ?int $userId = null): void
    {
        try {
            $amount = round((float) $return->grand_total, 4);
            if ($amount <= 0 || ! $return->refund_method) {
                return;
            }

            // BUG-027 FIX: look up the cash/bank account from the original sale's
            // payment method mapping rather than using hardcoded 'CASH-MAIN'/'BANK-MAIN'
            // codes which break for any tenant with differently named accounts.
            $return->loadMissing(['order.payments.method']);

            $cashBankAccountId = null;

            if ($return->order) {
                foreach ($return->order->payments as $payment) {
                    $methodType = $payment->method?->method_type;
                    $matches = match ($return->refund_method) {
                        'cash'          => $methodType === 'cash',
                        'bank_transfer' => $methodType === 'bank_transfer',
                        default         => false,
                    };
                    if ($matches && $payment->method?->cash_bank_account_id) {
                        $cashBankAccountId = $payment->method->cash_bank_account_id;
                        break;
                    }
                }
            }

            // Fallback: query by account_type if original payment had no mapping.
            if (! $cashBankAccountId) {
                $accountType = $return->refund_method === 'cash' ? 'cash' : 'bank';
                $cashBankAccountId = \App\Models\Tenant\CashBankAccount::where('account_type', $accountType)
                    ->where('is_active', true)
                    ->where('is_default', true)
                    ->value('id');
            }

            if (! $cashBankAccountId) {
                return; // no mappable account — skip silently
            }

            // Idempotency guard.
            $exists = CashBankAccountTransaction::query()
                ->where('reference_type', 'sales_return')
                ->where('reference_id', $return->id)
                ->where('transaction_type', 'sales_return_refund')
                ->exists();
            if ($exists) {
                return;
            }

            DB::connection('tenant')->transaction(function () use ($cashBankAccountId, $return, $amount, $userId) {
                $cash = CashBankAccount::whereKey($cashBankAccountId)->lockForUpdate()->first();
                if (! $cash) {
                    return;
                }

                $newBalance = (float) $cash->current_balance - $amount;

                CashBankAccountTransaction::create([
                    'cash_bank_account_id' => $cash->id,
                    'transaction_date'     => ($return->return_date ?? now())->toDateString(),
                    'direction'            => 'out',
                    'amount'               => $amount,
                    'balance_after'        => $newBalance,
                    'transaction_type'     => 'sales_return_refund',
                    'reference_type'       => 'sales_return',
                    'reference_id'         => $return->id,
                    'notes'                => 'Refund ' . $return->return_no,
                    'created_by_user_id'   => $userId,
                ]);

                $cash->update(['current_balance' => $newBalance]);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Dr Inventory Asset / Cr Accounts Payable for a posted purchase bill.
     * NOT auto-hooked yet (demo bills aren't posted via a clean lifecycle) — kept
     * for FIN-7B / manual posting.
     */
    public function postPurchaseBill(PurchaseBill $bill, ?int $userId = null): ?JournalEntry
    {
        try {
            $amount = round((float) $bill->grand_total, 4);
            if ($amount <= 0) {
                return null;
            }

            $lines = [
                ['account_code' => '1400', 'branch_id' => $bill->branch_id, 'description' => 'Inventory Asset', 'debit' => $amount, 'credit' => 0],
                ['account_code' => '2100', 'branch_id' => $bill->branch_id, 'description' => 'Purchase bill ' . $bill->bill_no, 'debit' => 0, 'credit' => $amount],
            ];

            return $this->journal->post(
                'purchase_bill',
                $bill->id,
                $bill->bill_no,
                'Purchase bill ' . $bill->bill_no,
                $bill->bill_date?->toDateString() ?? now()->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * PURCHASE-RETURNS-1 — GL for a posted supplier purchase return: the exact
     * mirror of postPurchaseBill. Goods go back, so the supplier liability and
     * the inventory asset both shrink:
     *   Dr 2100 Accounts Payable / Cr 1400 Inventory Asset.
     * Idempotent per (purchase_return, id); never throws (safe-catch style).
     */
    public function postPurchaseReturn(\App\Models\Tenant\PurchaseReturn $return, ?int $userId = null): ?JournalEntry
    {
        try {
            $amount = round((float) $return->grand_total, 4);
            if ($amount <= 0) {
                return null;
            }

            $lines = [
                ['account_code' => '2100', 'branch_id' => $return->branch_id, 'description' => 'Purchase return ' . $return->return_no, 'debit' => $amount, 'credit' => 0],
                ['account_code' => '1400', 'branch_id' => $return->branch_id, 'description' => 'Inventory Asset', 'debit' => 0, 'credit' => $amount],
            ];

            return $this->journal->post(
                'purchase_return',
                $return->id,
                $return->return_no,
                'Purchase return ' . $return->return_no,
                $return->return_date?->toDateString() ?? now()->toDateString(),
                $lines,
                $userId
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /** Reverse the posted journal for an event (used by void flows). Idempotent; safe. */
    public function reverseForSource(string $sourceType, int $sourceId, string $reason, ?int $userId = null): ?JournalEntry
    {
        try {
            $entry = $this->journal->findPostedForSource($sourceType, $sourceId);
            if (! $entry) {
                return null;
            }

            return $this->journal->reverse($entry, $reason, $userId);
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    private function cashBankCoaId(int $cashBankAccountId): ?int
    {
        return CashBankAccount::whereKey($cashBankAccountId)->value('account_id');
    }
}
