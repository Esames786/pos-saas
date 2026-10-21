<?php

namespace App\Services\Finance;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierLedger;
use App\Models\Tenant\SupplierPayment;
use App\Services\Purchasing\PurchasingService;
use App\Support\VoidDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
    use \App\Services\Concerns\ResolvesBranchIds;

    /** Accounts Payable ka control account. Iski POORI NASL AP mani jaati hai — dekho apAccountIds(). */
    private const AP_CODE = '2100';

    public function __construct(
        private PurchasingService $purchasing,
        private JournalPostingService $journalPosting,
    ) {}

    /**
     * Record a supplier payment end to end — EK transaction mein, sab kuch ya kuch bhi nahi.
     *
     * Pehle GL journal is transaction ke BAHAR post hota tha: pehle `DB::transaction()` payment
     * row + subledger + cash/bank likhta, phir uske BAAD `postSupplierPayment()` chalta. Us
     * shakl mein GL fail hone par baqi teen pehle se commit ho chuke hote the — yani "supplier
     * ledger hil gaya, GL nahi". Ab chaaron ek hi transaction ke andar hain:
     *
     *   1. payment row
     *   2. supplier subledger + bill (mojooda PurchasingService — wohi authority)
     *   3. cash/bank money-out
     *   4. GL journal (Dr 2100 AP / Cr chuna hua cash-bank)
     *
     * GL layer jaan-boojh kar fail-SOFT hai (uska docblock: "returns null and reports the problem
     * rather than throwing") taake koi gum account operational flow na toray. Wo naram-mizaji
     * purchase bill ke liye theek hai, magar payment ke liye NAHI: bina GL ke payment ka matlab
     * hai AP control aur subledger ka farq. Is liye yahan `null` ko NAKAAMI mana jata hai aur
     * poora transaction palat jata hai.
     *
     * Purchase bill / purchase return ka raasta ACHHOOT hai — wahan BUG-044 ka bartaao waisa hi.
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
            // Purchase bill ki zaroorat NAHI — postPayment() bill ko sirf `if ($payment->purchase_bill_id)`
            // ke andar chhoota hai, is liye khuli balance par seedha payment pehle se chalta hai.
            $this->purchasing->postPayment($payment, $userId);

            if (! empty($data['cash_bank_account_id'])) {
                $this->postCashBankTransaction($payment, $userId);
            }

            // GL — ab ANDAR. null = nakaami, kyunke bina GL ke AP control subledger se hat jata hai.
            $entry = $this->journalPosting->postSupplierPayment($payment, $userId);

            if (! $entry) {
                throw new RuntimeException(
                    'Supplier payment could not be posted to the general ledger, so nothing was saved. '
                    . 'Choose an active cash/bank account that is mapped to a chart-of-accounts account.'
                );
            }

            // Advance/overpayment ka darwaza band — postPayment() ne supplier row par lockForUpdate()
            // liya hua hai aur wo lock is transaction ke commit tak humare paas rehta hai, is liye ye
            // padhna race-safe hai: do saath chalte payment dono is shart se guzar nahi sakte.
            $this->assertNoSupplierAdvance((int) $payment->supplier_id);

            return $payment;
        });
    }

    /**
     * SUPPLIER-PAYMENT-VOID-1 — ek supplier payment ULTI karo. Amount edit nahi hoti; ghalat payment
     * ulti hoti hai aur sahi payment nayi darj hoti hai — catering receipt aur expense ki tarah.
     *
     * recordPayment() ke chaaron hisse, ulte, EK transaction me — sab ya kuch nahi:
     *
     *   1. GL           asal entry (Dr 2100 / Cr cash) ki reversal — aaj ki tareekh par. GL na mile
     *                   to ruk jao: bina GL ke ulti karna AP control ko subledger se hata deta.
     *   2. subledger    'payment_void' satar, direction 'debit' (aaina: payable WAPAS barha).
     *   3. bills        running account ON: is payment ki allocations hatao aur un bills ka paid
     *                   dobara jodo. OFF: jis bill par thi us ka amount_paid utna kam.
     *   4. cash/bank    paisa drawer me WAPAS ('in'), aaj ki tareekh par.
     *
     * Payment ka row rehta hai; `voided_at` us par nishan hai. Do bar dabane par doosra reversal nahi
     * banta — row ka taala aur `voided_at` ki jaanch pehle hoti hai.
     *
     * VOID-ON-ORIGINAL-DATE-1 — `$dateMode` = VoidDate::ORIGINAL: GL reversal aur cash wapsi payment ki
     * APNI tareekh par (ghalat entry usi din mit jati hai). TODAY (default): aaj, pehle ki tarah.
     * Supplier subledger me kaarobari tareekh ka khana hi nahi — wahan sirf waqt darj hota hai.
     */
    public function voidPayment(SupplierPayment $payment, string $reason, ?int $userId = null, string $dateMode = VoidDate::TODAY): SupplierPayment
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new RuntimeException('Write why this payment is being voided (at least 3 characters) — it becomes part of the record.');
        }

        return DB::connection('tenant')->transaction(function () use ($payment, $reason, $userId, $dateMode) {
            $locked = SupplierPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->voided_at !== null) {
                throw new RuntimeException('Payment ' . $locked->payment_no . ' has already been voided.');
            }

            $supplier = Supplier::whereKey($locked->supplier_id)->lockForUpdate()->firstOrFail();
            $amount = (float) $locked->amount;
            $note = 'Void of supplier payment ' . $locked->payment_no . ' — ' . $reason;

            // 1. GL
            $entry = JournalEntry::where('source_type', 'supplier_payment')
                ->where('source_id', $locked->id)
                ->where('is_reversal', false)
                ->first();
            if (! $entry) {
                throw new RuntimeException('Payment ' . $locked->payment_no . ' has no general-ledger entry, so it cannot be '
                    . 'voided safely. Nothing was changed.');
            }
            $reversal = app(JournalService::class)->reverse($entry, $note, $userId, VoidDate::resolve($dateMode, $entry->entry_date));

            // 2. Supplier subledger — payable wapas barha.
            $this->purchasing->postSupplierLedger(
                $supplier, 'payment_void', 'debit', $amount,
                SupplierPayment::class, (int) $locked->id, (string) $locked->payment_no, $note, $userId
            );

            // 3. Bills
            $this->releaseBills($locked);

            // 4. Cash/bank — sirf tab jab payment ne waqai drawer se paisa nikala tha.
            if ($locked->cash_bank_account_id) {
                $paidOutRow = CashBankAccountTransaction::query()
                    ->where('reference_type', 'supplier_payment')->where('reference_id', $locked->id)
                    ->where('transaction_type', 'supplier_payment')->first();
                $paidOut = $paidOutRow !== null;
                $alreadyBack = CashBankAccountTransaction::query()
                    ->where('reference_type', 'supplier_payment')->where('reference_id', $locked->id)
                    ->where('transaction_type', 'supplier_payment_void_reversal')->exists();

                if ($paidOut && ! $alreadyBack) {
                    $cash = CashBankAccount::whereKey($locked->cash_bank_account_id)->lockForUpdate()->firstOrFail();
                    $newBalance = (float) $cash->current_balance + $amount;

                    CashBankAccountTransaction::create([
                        'cash_bank_account_id' => $cash->id,
                        // Money comes back on the day it left when the payment was a wrong entry.
                        'transaction_date'     => VoidDate::resolve($dateMode, $paidOutRow->transaction_date ?? $locked->payment_date),
                        'direction'            => 'in',
                        'amount'               => $locked->amount,
                        'balance_after'        => $newBalance,
                        'transaction_type'     => 'supplier_payment_void_reversal',
                        'reference_type'       => 'supplier_payment',
                        'reference_id'         => $locked->id,
                        'notes'                => $note,
                        'created_by_user_id'   => $userId,
                    ]);
                    $cash->update(['current_balance' => $newBalance]);
                }
            }

            $locked->forceFill([
                'voided_at'             => now(),
                'voided_by_user_id'     => $userId,
                'void_reason'           => $reason,
                'void_journal_entry_id' => $reversal->id,
            ])->save();

            return $locked;
        });
    }

    /** Ulti payment ne jo bills chukaye thay, unhein wapas khol do. */
    private function releaseBills(SupplierPayment $payment): void
    {
        $running = app(\App\Services\Purchasing\SupplierRunningAccountService::class);
        $allocations = \App\Models\Tenant\SupplierCreditAllocation::query()
            ->where('source_type', \App\Models\Tenant\SupplierCreditAllocation::SOURCE_PAYMENT)
            ->where('source_id', $payment->id)
            ->get(['id', 'purchase_bill_id']);

        // Running account: bill ka paid uski allocations ka jod hai — inhein hatao aur dobara jodo.
        if ($allocations->isNotEmpty()) {
            \App\Models\Tenant\SupplierCreditAllocation::whereIn('id', $allocations->pluck('id'))->delete();
            foreach (PurchaseBill::whereIn('id', $allocations->pluck('purchase_bill_id')->unique())->lockForUpdate()->get() as $bill) {
                $running->recomputeBill($bill);
            }

            return;
        }

        // Purana tareeqa (switch OFF): payment ne seedha apne bill ka amount_paid barhaya tha. ON par
        // allocation na hone ka matlab hai bill par kuch baqi nahi tha — wahan kuch nahi lautana.
        if ($running->enabled() || ! $payment->purchase_bill_id) {
            return;
        }

        $bill = PurchaseBill::whereKey($payment->purchase_bill_id)->lockForUpdate()->first();
        if (! $bill || ! in_array($bill->status, ['posted', 'partial', 'paid'], true)) {
            return;
        }

        $paid = max(0.0, round((float) $bill->amount_paid - (float) $payment->amount, 4));
        $due = max(0.0, round((float) $bill->grand_total - $paid, 4));
        $bill->update([
            'amount_paid' => $paid,
            'balance_due' => $due,
            'status'      => $due <= 0.00005 ? 'paid' : ($paid > 0.00005 ? 'partial' : 'posted'),
        ]);
    }

    /**
     * Supplier advance / overpayment par FAIL CLOSED.
     *
     * Is system mein supplier advance ka koi nizam mojood NAHI hai — COA mein `2300 Customer
     * Advances` hai, magar supplier ke liye koi account nahi, aur poore app mein ek bhi jagah
     * supplier advance ki logic nahi. Phir bhi `postSupplierLedger()` credit par
     * `balance - amount` karta hai bina farsh ke, to overpayment chupke se manfi payable bana
     * deta tha — yani ek accounting jo system mein hai hi nahi.
     *
     * Andaze se accounting ijaad karne se behtar hai saaf mana kar dena. Jab owner supplier
     * advance ka account aur usool tay kar dega, tab ye guard uski jagah chala jayega.
     *
     * Ye guard sirf direct payment aur journal adjustment par lagta hai. `postSupplierLedger()`
     * par NAHI — purchase return bhi credit karta hai, aur wahan guard lagana purchase-return ka
     * bartaao badal deta.
     */
    private function assertNoSupplierAdvance(int $supplierId): void
    {
        // SUPPLIER-RUNNING-ACCOUNT-1: the owner decided (8 Oct, kashifkitchen) — on account, the
        // advance kept in 2100, no separate account. Their tenant's switch lets the balance go below
        // zero; every other tenant keeps this refusal word for word.
        if (\App\Models\Tenant\PurchasingSetting::supplierRunningAccount()) {
            return;
        }

        $balance = (float) Supplier::whereKey($supplierId)->value('current_balance');

        // Paisa 4 decimal par rakha jata hai; epsilon rounding ko manfi na parhne de.
        if ($balance < -0.0001) {
            throw new RuntimeException(
                'This would leave the supplier with a negative payable (an advance of '
                . number_format(abs($balance), 2) . '), and supplier advances are not supported '
                . 'by the chart of accounts. Nothing was saved. Reduce the amount to the '
                . 'outstanding balance, or ask the owner to set up a supplier-advance account first.'
            );
        }
    }

    /**
     * Accounts Payable ke account ids — `2100` aur uski POORI NASL.
     *
     * Sirf `2100` par pehchan-na kaafi nahi tha: `accounts` table mein `parent_id` mojood hai,
     * is liye tenant kal `2100` ke neeche `2101 Local Suppliers` bana sakta hai — aur us par
     * post karke supplier ki shart se bach jata. Nasl poori li jaati hai.
     *
     * @return array<int, int>
     */
    public function apAccountIds(): array
    {
        $root = Account::where('code', self::AP_CODE)->first(['id']);

        if (! $root) {
            return [];
        }

        $ids      = [(int) $root->id];
        $frontier = $ids;

        // Gehrai mehdood rakhi hai — ek galat parent_id (khud par ishara) warna hamesha ghumata.
        for ($depth = 0; $depth < 10 && $frontier; $depth++) {
            $frontier = Account::whereIn('parent_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $ids = array_merge($ids, $frontier);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Manual journal ki AP satrein supplier subledger mein utaro — aaina bilkul barabar.
     *
     * AP credit-normal hai aur supplier ka `current_balance` "hum supplier ka kitna dete hain"
     * ginta hai, is liye aaina ULTA hota hai:
     *
     *   journal `Cr 2100`  -> payable barha  -> supplier DEBIT
     *   journal `Dr 2100`  -> payable ghata  -> supplier CREDIT
     *
     * Subledger usi ek choke point se likha jata hai (`PurchasingService::postSupplierLedger`),
     * jo supplier row par `lockForUpdate()` leta hai — is liye running balance concurrency mein
     * bhi theek rehta hai (BUG-042). Koi doosra supplier accounting engine nahi banaya.
     *
     * @return int kitni satrein utrin
     */
    public function mirrorApLinesToSupplierLedger(JournalEntry $entry, ?int $userId = null): int
    {
        $apIds = $this->apAccountIds();

        if (! $apIds) {
            return 0;
        }

        // Idempotent — ek journal entry ka aaina sirf EK BAR utarta hai.
        //
        // Ye zaroori hai kyunke `JournalService::post()` khud idempotent hai: wohi
        // (source_type, source_id) dobara aane par NAYI entry nahi banata, MOJOODA laut-ta hai.
        // Aur `ManualJournalController::nextManualJournalId()` = max(source_id) + 1 hai, is liye
        // do saath chalte manual journal ek hi id ginn sakte hain — doosre ko pehli hi entry
        // milti, aur bina is guard ke uska subledger DOBARA chadh jata: GL par ek satar, subledger
        // par do, aur AP control se farq. Wohi drift jo is poore kaam ne rokna tha.
        $alreadyMirrored = SupplierLedger::where('reference_type', JournalEntry::class)
            ->where('reference_id', $entry->id)
            ->exists();

        if ($alreadyMirrored) {
            return 0;
        }

        $entry->loadMissing('lines');
        $mirrored = 0;

        foreach ($entry->lines as $line) {
            if ($line->counterparty_type !== 'supplier' || ! $line->supplier_id) {
                continue;
            }

            if (! in_array((int) $line->account_id, $apIds, true)) {
                continue;
            }

            $debit  = round((float) $line->debit, 4);
            $credit = round((float) $line->credit, 4);

            $amount    = $credit > 0 ? $credit : $debit;
            $direction = $credit > 0 ? 'debit' : 'credit';   // aaina — dekho docblock

            if ($amount <= 0) {
                continue;
            }

            $supplier = Supplier::whereKey($line->supplier_id)->firstOrFail();

            $this->purchasing->postSupplierLedger(
                $supplier,
                $entry->is_reversal ? 'journal_reversal' : 'journal_adjustment',
                $direction,
                $amount,
                JournalEntry::class,
                (int) $entry->id,
                (string) $entry->entry_no,
                $line->description ?: $entry->description,
                $userId
            );

            // Journal adjustment bhi advance nahi bana sakta — wohi usool jo payment par hai.
            if ($direction === 'credit') {
                $this->assertNoSupplierAdvance((int) $line->supplier_id);
            }

            $mirrored++;
        }

        return $mirrored;
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

        $branchIds = $this->resolveBranchIds($filters);

        $bills = PurchaseBill::query()
            ->with('supplier')
            ->whereIn('status', $statuses)
            ->where('balance_due', '>', 0)
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
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
