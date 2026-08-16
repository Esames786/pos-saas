<?php

namespace App\Services\Catering;

use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringRefund;
use App\Models\Tenant\PaymentMethod;
use App\Services\Finance\JournalPostingService;
use Illuminate\Support\Facades\DB;

/**
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 — handing money back, as one operation.
 *
 * Built on the same shape as CateringAdvanceService, deliberately, because it is
 * the same operation in the other direction: the refund record, the ledger entry
 * and the cash/bank movement are one atomic act. A posting failure rolls all
 * three back, so a refund row can never exist without its journal, and money can
 * never leave the books without leaving the drawer.
 *
 * What this never does is edit the receipt it settles. The original advance and
 * its journal entry stay exactly as posted, and the refund stands beside them.
 * Two entries that both happened is the truth; one entry quietly rewritten is
 * not, and would leave nothing to audit.
 *
 * The booking row is locked for the duration, so two operators refunding the
 * same credit at the same instant queue rather than both passing the limit
 * check. The one-time submission token guards the resubmitted browser form; this
 * guards genuinely concurrent requests.
 */
class CateringRefundService
{
    public function __construct(
        private readonly JournalPostingService $journalPosting,
        private readonly CateringNumberService $numbers,
    ) {}

    /**
     * @param  array{amount: float|string, refund_date: string, reason: string, payment_method_id?: int|null, reference?: string|null}  $data
     */
    public function record(CateringEvent $event, array $data, ?int $userId = null): CateringRefund
    {
        return DB::connection('tenant')->transaction(function () use ($event, $data, $userId) {
            // Serialise refunds against this booking. Held until commit, so the
            // limit check below sees every refund that has actually landed.
            CateringEvent::whereKey($event->id)->lockForUpdate()->first();

            $cashBankAccountId = null;
            if (! empty($data['payment_method_id'])) {
                $cashBankAccountId = PaymentMethod::whereKey($data['payment_method_id'])->value('cash_bank_account_id');
            }

            // The refundable-amount cap fires inside create() (model guard), so
            // it holds for every caller, not just this one.
            $refund = CateringRefund::create([
                'refund_no' => $this->numbers->nextRefundNo(),
                'catering_event_id' => $event->id,
                'amount' => $data['amount'],
                'refund_date' => $data['refund_date'],
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'cash_bank_account_id' => $cashBankAccountId,
                'reference' => $data['reference'] ?? null,
                'reason' => $data['reason'],
                'refunded_by_user_id' => $userId,
            ]);
            $refund->setRelation('event', $event);

            // GL — throws on failure/conflict, rolling the refund row back.
            $entry = $this->journalPosting->postCateringRefund($refund, $userId);
            $refund->forceFill(['journal_entry_id' => $entry->id, 'gl_posted_at' => now()])->save();

            if ($cashBankAccountId) {
                $this->postCashBankMovement($refund, $userId);
            }

            return $refund->fresh();
        });
    }

    /** Money-out transaction + balance reduction, mirroring the receipt path. */
    private function postCashBankMovement(CateringRefund $refund, ?int $userId): void
    {
        $exists = CashBankAccountTransaction::query()
            ->where('reference_type', 'catering_refund')
            ->where('reference_id', $refund->id)
            ->exists();
        if ($exists) {
            return;
        }

        $account = CashBankAccount::whereKey($refund->cash_bank_account_id)->lockForUpdate()->firstOrFail();
        $newBalance = (float) $account->current_balance - (float) $refund->amount;

        CashBankAccountTransaction::create([
            'cash_bank_account_id' => $account->id,
            'transaction_date' => $refund->refund_date?->toDateString() ?? now()->toDateString(),
            'direction' => 'out',
            'amount' => $refund->amount,
            'balance_after' => $newBalance,
            'transaction_type' => 'customer_refund',
            'reference_type' => 'catering_refund',
            'reference_id' => $refund->id,
            'notes' => 'Catering refund '.$refund->refund_no.' '.($refund->event?->event_no ?? ''),
            'created_by_user_id' => $userId,
        ]);

        $account->update(['current_balance' => $newBalance]);
    }
}
