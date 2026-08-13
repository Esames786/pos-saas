<?php

namespace App\Services\Catering;

use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\CateringAdvance;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\PaymentMethod;
use App\Services\Finance\JournalPostingService;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-GO-LIVE-READINESS-1 (§5): advance recording is ONE atomic business
 * operation — operational CateringAdvance + cash/bank movement + GL posting in
 * a single tenant transaction. Any posting failure rolls the whole thing back;
 * an advance row can never exist without its journal.
 *
 * Deposit vs settlement is decided here, atomically: before the event's final
 * invoice exists a receipt is an ADVANCE (Dr cash-bank / Cr 2300); after it,
 * a SETTLEMENT (Dr cash-bank / Cr 1300). Cash/bank account resolves through
 * the payment method's cash_bank_account_id mapping (postPaidSale policy);
 * unmapped receipts post Dr 1500 Undeposited Funds with NO cash/bank movement.
 * Back-office catering receipts are NOT terminal/shift cash — shifts and
 * sale_payments are never touched.
 */
class CateringAdvanceService
{
    public function __construct(
        private readonly JournalPostingService $journalPosting,
    ) {}

    /**
     * @param  array{amount: float|string, received_date: string, payment_method_id?: int|null, reference?: string|null, notes?: string|null}  $data
     */
    public function record(CateringEvent $event, array $data, ?int $userId = null): CateringAdvance
    {
        return DB::connection('tenant')->transaction(function () use ($event, $data, $userId) {
            $cashBankAccountId = null;
            if (! empty($data['payment_method_id'])) {
                $cashBankAccountId = PaymentMethod::whereKey($data['payment_method_id'])->value('cash_bank_account_id');
            }

            $isSettlement = $event->finalInvoice()->exists();

            // The §4 overpayment cap fires inside create() (model guard).
            $advance = CateringAdvance::create([
                'catering_event_id' => $event->id,
                'amount' => $data['amount'],
                'received_date' => $data['received_date'],
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by_user_id' => $userId,
                'posting_type' => $isSettlement ? CateringAdvance::POSTING_SETTLEMENT : CateringAdvance::POSTING_ADVANCE,
                'cash_bank_account_id' => $cashBankAccountId,
            ]);
            $advance->setRelation('event', $event);

            // GL — throws on failure/conflict, rolling back the operational row.
            $entry = $isSettlement
                ? $this->journalPosting->postCateringSettlement($advance, $userId)
                : $this->journalPosting->postCateringAdvance($advance, $userId);

            $advance->forceFill(['journal_entry_id' => $entry->id, 'gl_posted_at' => now()])->save();

            // Cash/bank movement for mapped real accounts (idempotent by reference).
            if ($cashBankAccountId) {
                $this->postCashBankMovement($advance, $userId);
            }

            return $advance->fresh();
        });
    }

    /** Money-in transaction + balance bump (CustomerReceivableService pattern). */
    private function postCashBankMovement(CateringAdvance $advance, ?int $userId): void
    {
        $exists = CashBankAccountTransaction::query()
            ->where('reference_type', 'catering_advance')
            ->where('reference_id', $advance->id)
            ->exists();
        if ($exists) {
            return;
        }

        $account = CashBankAccount::whereKey($advance->cash_bank_account_id)->lockForUpdate()->firstOrFail();
        $newBalance = (float) $account->current_balance + (float) $advance->amount;

        CashBankAccountTransaction::create([
            'cash_bank_account_id' => $account->id,
            'transaction_date' => $advance->received_date?->toDateString() ?? now()->toDateString(),
            'direction' => 'in',
            'amount' => $advance->amount,
            'balance_after' => $newBalance,
            'transaction_type' => 'customer_payment',
            'reference_type' => 'catering_advance',
            'reference_id' => $advance->id,
            'notes' => 'Catering receipt '.($advance->event?->event_no ?? '').' '.($advance->reference ?? ''),
            'created_by_user_id' => $userId,
        ]);

        $account->update(['current_balance' => $newBalance]);
    }
}
