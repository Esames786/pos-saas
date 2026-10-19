<?php

namespace App\Services\Catering;

use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\CateringAdvance;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\PaymentMethod;
use App\Services\Finance\JournalPostingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

            // CATERING-OVERPAYMENT-1 — how much of this receipt is paying a bill
            // and how much the business is simply holding. Asked BEFORE the row
            // exists, from the one authority every screen shares.
            $amount = round((float) $data['amount'], 2);
            $outstanding = round((float) app(CateringFinancialPositionService::class)->position($event)['balance_due'], 2);
            $credit = round(max($amount - $outstanding, 0), 2);
            $settled = round($amount - $credit, 2);

            // The overpayment cap fires inside create() (model guard) unless the
            // caller has deliberately opened the door and recorded a reason.
            $advance = new CateringAdvance([
                'catering_event_id' => $event->id,
                'amount' => $amount,
                'received_date' => $data['received_date'],
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'credit_portion' => $credit,
                'overpayment_reason' => $data['overpayment_reason'] ?? null,
            ]);
            $advance->allowOverpayment = (bool) ($data['allow_overpayment'] ?? false);
            $advance->forceFill([
                'recorded_by_user_id' => $userId,
                'posting_type' => $isSettlement ? CateringAdvance::POSTING_SETTLEMENT : CateringAdvance::POSTING_ADVANCE,
                'cash_bank_account_id' => $cashBankAccountId,
            ])->save();
            $advance->setRelation('event', $event);

            // GL — throws on failure/conflict, rolling back the operational row.
            //
            // A receipt that both settles a bill and leaves credit has to be
            // posted as the two different things it is. Before an invoice exists
            // there is nothing to split: the whole receipt is already a customer
            // advance sitting in 2300.
            $entry = match (true) {
                $isSettlement && $credit > 0 => $this->journalPosting->postCateringSplitReceipt($advance, $settled, $credit, $userId),
                $isSettlement => $this->journalPosting->postCateringSettlement($advance, $userId),
                default => $this->journalPosting->postCateringAdvance($advance, $userId),
            };

            $advance->forceFill(['journal_entry_id' => $entry->id, 'gl_posted_at' => now()])->save();

            // Cash/bank movement for mapped real accounts (idempotent by reference).
            if ($cashBankAccountId) {
                $this->postCashBankMovement($advance, $userId);
            }

            return $advance->fresh();
        });
    }

    /**
     * CATERING-ADVANCE-VOID-1 — receipt ko ULTA karo.
     *
     * Malik: "client ne amount daalte hue ghalti kar di … wo kaise edit
     * hoga?" Jawab: amount EDIT nahi hota, receipt ULTI hoti hai aur sahi
     * receipt nayi darj hoti hai.
     *
     * Wajah ye hai ke receipt darj hote hi paisa HIL CHUKA hota hai — ek
     * journal entry ban chuki hoti hai aur cash/bank ka balance barh chuka
     * hota hai. Us adad ko chup chaap badal dena kitabon me ek adad aur
     * screen par doosra chhor deta, bina kisi nishani ke. Ulta karne se poora
     * trail qayam rehta hai: kya darj hua tha, kyun ulta kiya, aur kis ne.
     *
     * Tareeqa bilkul wohi hai jo `ExpenseService::void()` barson se chala
     * raha hai, IDEMPOTENCE samet: dobara chalane par doosra reversal nahi
     * banta. Ye ahem hai — do baar daba dena paisa dobara wapas kar deta.
     */
    public function void(CateringAdvance $advance, string $reason, ?int $userId = null): CateringAdvance
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Ulta karne ki wajah likhna lazmi hai — ye record ka hissa banti hai.');
        }

        $advance = DB::connection('tenant')->transaction(function () use ($advance, $reason, $userId) {
            $locked = CateringAdvance::withoutGlobalScope('notVoided')
                ->whereKey($advance->id)->lockForUpdate()->firstOrFail();

            if ($locked->voided_at !== null) {
                throw new RuntimeException('Ye receipt pehle hi ulti ki ja chuki hai.');
            }

            // INVOICE KE BAAD NAHI. Jaari shuda invoice apne andar ye rakam
            // jama kar chuki hoti hai aur wo document immutable hai — receipt
            // ulti kar dene se invoice ek aisi rakam ginti rehti jo ab mojood
            // hi nahi. Us surat me sahi raasta refund hai, void nahi.
            $event = CateringEvent::find($locked->catering_event_id);
            if ($event && $event->finalInvoice()->exists()) {
                throw new RuntimeException(
                    'Is booking ki final invoice ban chuki hai, is liye receipt ulti nahi ki ja sakti. '
                    .'Paisa wapas karna ho to Refund istemaal karein.'
                );
            }

            // Cash/bank wapas — IDEMPOTENT, bilkul ExpenseService ki tarah.
            if ($locked->cash_bank_account_id) {
                $alreadyReversed = CashBankAccountTransaction::query()
                    ->where('reference_type', 'catering_advance')
                    ->where('reference_id', $locked->id)
                    ->where('transaction_type', 'catering_advance_void_reversal')
                    ->exists();

                if (! $alreadyReversed) {
                    $cash = CashBankAccount::whereKey($locked->cash_bank_account_id)->lockForUpdate()->firstOrFail();
                    $newBalance = (float) $cash->current_balance - (float) $locked->amount;

                    CashBankAccountTransaction::create([
                        'cash_bank_account_id' => $cash->id,
                        'transaction_date' => now()->toDateString(),
                        'direction' => 'out',
                        'amount' => $locked->amount,
                        'balance_after' => $newBalance,
                        'transaction_type' => 'catering_advance_void_reversal',
                        'reference_type' => 'catering_advance',
                        'reference_id' => $locked->id,
                        'notes' => 'Void reversal for catering receipt #'.$locked->id.' — '.$reason,
                        'created_by_user_id' => $userId,
                    ]);

                    $cash->update(['current_balance' => $newBalance]);
                }
            }

            $locked->forceFill([
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by_user_id' => $userId,
            ])->save();

            return $locked;
        });

        // GL ulti — idempotent, aur transaction ke BAHAR bilkul us namoone ki
        // tarah jo expense voucher par chalta hai.
        $entry = $this->journalPosting->reverseForSource('catering_advance', $advance->id, $reason, $userId);
        if ($entry) {
            CateringAdvance::withoutGlobalScope('notVoided')
                ->whereKey($advance->id)->update(['void_journal_entry_id' => $entry->id]);
        }

        return CateringAdvance::withoutGlobalScope('notVoided')->findOrFail($advance->id);
    }

    /**
     * Sirf LABEL theek karo — reference aur notes.
     *
     * Ye void se alag hai aur jaan-boojh kar: in do khaanon ka ledger se koi
     * taluq nahi. Slip number ghalat likh dena ek likhai ki ghalti hai, aur
     * us ke liye poori receipt ulta kar ke paisa do baar hilana bemani hoga.
     *
     * Amount yahan se NAHI badalti, aur ye bhi jaan-boojh kar: us ke saath
     * paisa hilta hai.
     */
    public function updateReference(CateringAdvance $advance, ?string $reference, ?string $notes): CateringAdvance
    {
        return DB::connection('tenant')->transaction(function () use ($advance, $reference, $notes) {
            $locked = CateringAdvance::withoutGlobalScope('notVoided')
                ->whereKey($advance->id)->lockForUpdate()->firstOrFail();

            if ($locked->voided_at !== null) {
                throw new RuntimeException('Ulti hui receipt ab badli nahi ja sakti.');
            }

            $locked->forceFill([
                'reference' => $reference !== null && trim($reference) !== '' ? trim($reference) : null,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ])->save();

            return $locked->fresh();
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
