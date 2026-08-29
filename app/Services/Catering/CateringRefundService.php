<?php

namespace App\Services\Catering;

use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringRefund;
use App\Models\Tenant\PaymentMethod;
use App\Services\Finance\JournalPostingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 — handing money back, as one operation.
 *
 * Built on the same shape as CateringAdvanceService, deliberately, because it is
 * the same operation in the other direction: the refund record, the ledger entry
 * and the cash/bank movement are one atomic act. A posting failure rolls all
 * three back, so a refund row can never exist without its journal, and money can
 * never leave the books without leaving the drawer.
 *
 * That last clause used to be a claim rather than a fact. The cash/bank movement
 * sat behind `if ($cashBankAccountId)`, and the payment method was optional — so
 * a request naming no method, or an active method nobody had linked to an
 * account, produced a refund and a ledger entry with the drawer untouched. The
 * books would have said money left; the balance would have said it did not.
 *
 * The account is now resolved and proved BEFORE the first row is written, and
 * the movement is unconditional. There is deliberately no fallback: a receipt
 * may honestly arrive without a mapped account, but money leaving has to leave
 * from somewhere, and a system that cannot name where has no business paying it.
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

            // Resolved and proved BEFORE anything is written. A refund with no
            // real account behind it is refused here, so the failure costs
            // nothing rather than leaving a posting with no money movement.
            $account = $this->resolveMoneyOutAccount($data['payment_method_id'] ?? null);
            $cashBankAccountId = $account->id;

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

            // Unconditional. This is what makes the invariant structural rather
            // than a hope: every successful refund carries its drawer movement,
            // because the account was proved to exist before the row was written.
            $this->postCashBankMovement($refund, $account, $userId);

            return $refund->fresh();
        });
    }

    /**
     * The account the money physically leaves from — proved, not assumed.
     *
     * A receipt may honestly arrive without a mapped account: cash turns up, and
     * 1500 Undeposited Funds is the right answer until someone banks it. A REFUND
     * is the opposite. Money is leaving somewhere specific, and if the system
     * cannot name where, it has no business paying it out. There is no fallback
     * here on purpose.
     *
     * Every condition is checked before a single row is written, so a refusal
     * costs nothing at all.
     */
    private function resolveMoneyOutAccount(mixed $paymentMethodId): CashBankAccount
    {
        if (empty($paymentMethodId)) {
            throw new RuntimeException(
                'A refund needs to say where the money is leaving from — choose the cash or bank account it is paid out of.'
            );
        }

        $method = PaymentMethod::whereKey($paymentMethodId)->first();

        if (! $method) {
            throw new RuntimeException('That payment method does not exist, so the refund has nowhere to come from.');
        }

        if (! $method->is_active) {
            throw new RuntimeException(
                "'{$method->name}' is no longer in use, so money cannot be paid out through it. Choose an active method."
            );
        }

        if (! $method->cash_bank_account_id) {
            throw new RuntimeException(
                "'{$method->name}' is not linked to a cash or bank account, so there is no balance for the refund to "
                .'come out of. Link it under Finance first, or choose a method that is linked.'
            );
        }

        $account = CashBankAccount::whereKey($method->cash_bank_account_id)->first();

        if (! $account || ! $account->is_active) {
            throw new RuntimeException(
                "The cash/bank account behind '{$method->name}' is missing or closed, so it cannot pay a refund out."
            );
        }

        // Without a chart-of-accounts link the credit side of the entry has no
        // account to land on, and the posting would be unbalanced or nameless.
        if (! $account->account_id) {
            throw new RuntimeException(
                "'{$account->name}' is not mapped to a general-ledger account, so a refund from it could not be posted."
            );
        }

        return $account;
    }

    /** Money-out transaction + balance reduction, mirroring the receipt path. */
    private function postCashBankMovement(CateringRefund $refund, CashBankAccount $resolved, ?int $userId): void
    {
        $exists = CashBankAccountTransaction::query()
            ->where('reference_type', 'catering_refund')
            ->where('reference_id', $refund->id)
            ->exists();
        if ($exists) {
            return;
        }

        // Re-read under a lock rather than trusting the resolved copy: the
        // balance must be the one nobody else is mid-way through changing.
        $account = CashBankAccount::whereKey($resolved->id)->lockForUpdate()->firstOrFail();
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
