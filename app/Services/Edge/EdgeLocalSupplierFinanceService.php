<?php

namespace App\Services\Edge;

use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Support\EdgeRuntime;
use App\Support\EdgeUserAuthz;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * OFFLINE EDGE — F2: the appliance's supplier-finance operator authority (Branch Server only).
 *
 * Online is the specification: Suppliers → Supplier Ledger → Record Payment (supplier, current payable, Against Bill
 * OPTIONAL, Pay From Cash/Bank REQUIRED, method, amount, reference, notes) and the General Journal whose Accounts
 * Payable lines name a real supplier. The appliance:
 *   - validates against the FRESH warm projection (fail closed when not current — never a guessed payable),
 *   - prevents a payable below the canonical boundary (supplier advances are unsupported → no negative AP),
 *   - records a LOCAL OPERATIONAL event (PENDING SYNC), the provisional payable / cash effect exactly once,
 *   - queues the immutable event in the shared outbox for the Cloud to post OFFICIALLY exactly once.
 * It never posts AP / GL / cash-bank itself, and never offers a ledger-only payment (no cash/bank effect).
 */
class EdgeLocalSupplierFinanceService
{
    public const PERM_LEDGER = 'tenant.suppliers.ledger';
    public const PERM_PAYMENT = 'tenant.supplier-payments.store';
    public const PERM_JOURNAL = 'tenant.finance.manual-journals.store';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeAuthorityService $authority,
        private readonly EdgeSupplierFinanceCacheService $cache,
        private readonly EdgeSupplierFinanceEnvelopeBuilder $envelopes,
        private readonly EdgeSyncOutboxService $outbox,
    ) {
    }

    // ── read side ────────────────────────────────────────────────────────────────────────────────────────────────

    public function options(User $user): array
    {
        $meta = $this->context->requireCurrent();
        $branch = Branch::on('tenant')->find((int) $meta->branch_id);
        $rules = $this->cache->rules();
        $offline = (array) $rules['offline_payment_methods'];

        return [
            'branch' => ['id' => (int) $meta->branch_id, 'name' => $branch?->name],
            'today' => now()->toDateString(),
            'freshness' => $this->cache->freshness(),
            'rules' => $rules,
            'payment_methods' => array_map(fn ($m) => ['code' => $m, 'label' => self::methodLabel($m), 'offline_allowed' => in_array($m, $offline, true)], (array) $rules['payment_methods']),
            'permissions' => $this->permissionsFor($user),
            'suppliers' => $this->cache->suppliers(),
            'cash_bank_accounts' => $this->cache->cashBankAccounts(true),
            'recent_events' => $this->cache->recentEvents(null, 30),
            'local_mode' => $this->localMutationAllowed(),
        ];
    }

    public function ledger(int $cloudSupplierId): array
    {
        $position = $this->cache->position($cloudSupplierId);
        if (! $position) {
            throw ValidationException::withMessages(['supplier' => 'This supplier is not in the branch server\'s supplier list.']);
        }
        $ledger = $this->cache->ledger($cloudSupplierId);

        return [
            'supplier' => $position,
            'open_bills' => $this->cache->openBills($cloudSupplierId),
            'rows' => $ledger['rows'],
            'official_count' => $ledger['official_count'],
            'provisional_count' => $ledger['provisional_count'],
            'freshness' => $this->cache->freshness(),
        ];
    }

    public function journalOptions(User $user): array
    {
        $meta = $this->context->requireCurrent();

        return [
            'branch' => ['id' => (int) $meta->branch_id],
            'today' => now()->toDateString(),
            'freshness' => $this->cache->freshness(),
            'rules' => $this->cache->rules(),
            'permissions' => $this->permissionsFor($user),
            'accounts' => $this->cache->accounts(true),
            'ap_account_ids' => $this->cache->apAccountIds(),
            'cash_bank_accounts' => $this->cache->cashBankAccounts(true),
            'suppliers' => $this->cache->suppliers(true),
            'recent_events' => $this->cache->recentEvents(EdgeSupplierFinanceEnvelopeBuilder::EVENT_AP_JOURNAL, 30),
            'local_mode' => $this->localMutationAllowed(),
        ];
    }

    public function event(string $eventUuid): array
    {
        $view = $this->cache->event($eventUuid);
        if (! $view) {
            throw ValidationException::withMessages(['event' => 'No such supplier-finance event on this branch server.']);
        }

        return $view;
    }

    public function permissionsFor(User $user): array
    {
        return [
            'can_view_ledger' => (bool) ($user->can(self::PERM_LEDGER) || $user->can(self::PERM_PAYMENT)),
            'can_pay' => (bool) $user->can(self::PERM_PAYMENT),
            'can_journal' => (bool) $user->can(self::PERM_JOURNAL),
        ];
    }

    // ── SUPPLIER_PAYMENT ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array $data cloud_supplier_id, cloud_cash_bank_account_id?, cloud_bill_id?, payment_date?, amount, payment_method,
     *                    reference_no?, bank_name?, account_no?, transaction_ref?, cheque_no?, cheque_date?, notes?
     */
    public function recordPayment(array $data, User $user, ?int $terminalId = null): array
    {
        $meta = $this->guardMutation($user, self::PERM_PAYMENT);
        $branchId = (int) $meta->branch_id;
        $rules = $this->cache->rules();

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount < 0.01) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount paid (at least 0.01).']);
        }
        $method = (string) ($data['payment_method'] ?? '');
        if (! in_array($method, (array) $rules['payment_methods'], true)) {
            throw ValidationException::withMessages(['payment_method' => 'Select how the supplier was paid.']);
        }
        if (! in_array($method, (array) $rules['offline_payment_methods'], true)) {
            throw ValidationException::withMessages(['payment_method' => 'A ' . self::methodLabel($method) . ' payment to a supplier needs the Online POS (the provider must authorise it) — offline you can record cash, bank transfer, cheque or other.']);
        }
        // CASH_BANK_REQUIRED — a supplier payment without a cash/bank effect is not a thing (Online: required, active).
        $cbId = (int) ($data['cloud_cash_bank_account_id'] ?? 0);
        if ($cbId <= 0) {
            throw ValidationException::withMessages(['cloud_cash_bank_account_id' => 'Choose the Cash/Bank account this payment is paid from. A supplier payment always moves cash or bank — a ledger-only payment is not possible.']);
        }
        $paymentDate = $this->dateOr($data['payment_date'] ?? null, 'payment_date');
        $chequeDate = ! empty($data['cheque_date']) ? $this->dateOr($data['cheque_date'], 'cheque_date') : null;

        return DB::connection('tenant')->transaction(function () use ($data, $user, $terminalId, $meta, $branchId, $amount, $method, $cbId, $paymentDate, $chequeDate, $rules) {
            // Two terminals paying the same supplier serialise HERE, as the FIRST statement of the transaction: the row
            // lock is taken before any consistent read establishes a snapshot, and the position below is read with
            // locking reads — so the second terminal sees exactly what the first one left behind.
            $supplier = $this->cache->supplier((int) ($data['cloud_supplier_id'] ?? 0), lock: true);
            if (! $supplier) {
                throw ValidationException::withMessages(['cloud_supplier_id' => 'This supplier is not in the branch server\'s supplier list.']);
            }

            // FRESHNESS GATE — inside the transaction, against the projection we are about to read.
            $fresh = $this->cache->freshness();
            if (! $fresh['ok']) {
                throw ValidationException::withMessages(['supplier' => 'Supplier finance information is not current on this branch server — record this payment on the Online POS or ask a supervisor. (' . implode('; ', $fresh['reasons']) . ')']);
            }
            if ((string) $supplier->status !== 'active') {
                throw ValidationException::withMessages(['cloud_supplier_id' => 'This supplier is inactive — payments to inactive suppliers are refused (as Online).']);
            }
            $cb = $this->cache->cashBankAccount($cbId);
            if (! $cb || ! (bool) $cb->is_active) {
                throw ValidationException::withMessages(['cloud_cash_bank_account_id' => 'Choose an ACTIVE Cash/Bank account to pay from.']);
            }
            if ($cb->coa_account_id === null) {
                throw ValidationException::withMessages(['cloud_cash_bank_account_id' => 'This Cash/Bank account is not mapped to a chart-of-accounts account, so the payment could not be posted to the general ledger. Choose a mapped account.']);
            }

            $bill = null;
            $billId = (int) ($data['cloud_bill_id'] ?? 0);
            if ($billId > 0) {
                $bills = collect($this->cache->openBills((int) $supplier->cloud_supplier_id, lock: true))->keyBy('cloud_bill_id');
                $bill = $bills[$billId] ?? null;
                if (! $bill) {
                    throw ValidationException::withMessages(['cloud_bill_id' => 'That Purchase Bill is not an open bill of this supplier on this branch server — pay on account (no specific bill) or choose an open bill.']);
                }
                if ($amount - (float) $bill['available'] > 0.005) {
                    throw ValidationException::withMessages(['amount' => 'Bill ' . $bill['bill_no'] . ' has ' . number_format((float) $bill['available'], 2) . ' outstanding (after pending payments); pay at most that against this bill, or pay on account.']);
                }
            }

            // SUPPLIER ADVANCE — unsupported by the canonical chart: never let the payable cross zero, fail closed before any mutation.
            $position = $this->cache->position((int) $supplier->cloud_supplier_id, lock: true);
            $available = (float) $position['available_payable'];
            if (! $rules['supplier_advance_supported'] && $amount - $available > 0.005) {
                throw ValidationException::withMessages(['amount' => 'This would leave ' . $supplier->name . ' with a negative payable (an advance of ' . number_format($amount - $available, 2) . '), and supplier advances are not supported by the chart of accounts. Nothing was saved. The available payable is ' . number_format(max(0, $available), 2) . ($position['pending_events'] > 0 ? ' (after ' . $position['pending_events'] . ' pending local event(s))' : '') . '.']);
            }

            $eventUuid = (string) Str::ulid();
            $payment = [
                'cloud_supplier_id' => (int) $supplier->cloud_supplier_id, 'supplier_code' => (string) $supplier->code, 'supplier_name' => (string) $supplier->name,
                'payment_date' => $paymentDate, 'amount' => $amount,
                'cloud_cash_bank_account_id' => (int) $cb->cloud_cash_bank_account_id, 'cash_bank_code' => (string) $cb->code, 'cash_bank_name' => (string) $cb->name,
                'payment_method' => $method,
                'reference_no' => self::str($data['reference_no'] ?? null, 100), 'bank_name' => self::str($data['bank_name'] ?? null, 100), 'account_no' => self::str($data['account_no'] ?? null, 100),
                'transaction_ref' => self::str($data['transaction_ref'] ?? null, 100), 'cheque_no' => self::str($data['cheque_no'] ?? null, 100), 'cheque_date' => $chequeDate,
                'cloud_bill_id' => $bill ? (int) $bill['cloud_bill_id'] : null, 'bill_no' => $bill ? (string) $bill['bill_no'] : null,
                'notes' => self::str($data['notes'] ?? null, 1000),
            ];
            $envelope = $this->envelopes->buildPayment($meta, $eventUuid, $payment, $this->actor($user, $terminalId), $fresh);

            $this->cache->recordEvent([
                'event_uuid' => $eventUuid, 'event_type' => EdgeSupplierFinanceEnvelopeBuilder::EVENT_PAYMENT, 'branch_id' => $branchId, 'terminal_id' => $terminalId, 'user_id' => (int) $user->id,
                'business_date' => $paymentDate, 'description' => $payment['notes'], 'reference_no' => $payment['reference_no'], 'amount' => $amount,
                'payload' => $payment, 'envelope_schema_version' => $envelope['envelope_schema_version'], 'content_hash' => $envelope['content_hash'], 'finance_watermark' => $fresh['watermark'],
            ], [[
                'cloud_supplier_id' => (int) $supplier->cloud_supplier_id, 'cloud_cash_bank_account_id' => (int) $cb->cloud_cash_bank_account_id,
                'cloud_bill_id' => $bill ? (int) $bill['cloud_bill_id'] : null, 'payable_delta' => -$amount, 'cash_delta' => -$amount,
            ]]);
            $this->outbox->createForFinanceEvent($envelope);

            return $this->cache->event($eventUuid) + ['position' => $this->cache->position((int) $supplier->cloud_supplier_id)];
        });
    }

    // ── SUPPLIER_AP_JOURNAL_ADJUSTMENT ───────────────────────────────────────────────────────────────────────────

    /**
     * @param array $data entry_date?, description, reference_no?, lines[] {cloud_account_id, cloud_cash_bank_account_id?, cloud_supplier_id?, description?, debit?, credit?}
     */
    public function postApJournal(array $data, User $user, ?int $terminalId = null): array
    {
        $meta = $this->guardMutation($user, self::PERM_JOURNAL);
        $branchId = (int) $meta->branch_id;
        $rules = $this->cache->rules();
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 500) {
            throw ValidationException::withMessages(['description' => 'Enter a description / memo for the journal (max 500 characters).']);
        }
        $entryDate = $this->dateOr($data['entry_date'] ?? null, 'entry_date');
        $rawLines = array_values(array_filter((array) ($data['lines'] ?? []), fn ($l) => round((float) ($l['debit'] ?? 0), 4) > 0 || round((float) ($l['credit'] ?? 0), 4) > 0));
        if (count($rawLines) < 2) {
            throw ValidationException::withMessages(['lines' => 'A journal needs at least two lines with amounts.']);
        }

        return DB::connection('tenant')->transaction(function () use ($data, $user, $terminalId, $meta, $branchId, $rules, $description, $entryDate, $rawLines) {
            // Serialise on every supplier the journal names BEFORE any consistent read (same discipline as a payment).
            $namedSuppliers = collect($rawLines)->pluck('cloud_supplier_id')->filter()->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
            foreach ($namedSuppliers as $sid) {
                $this->cache->supplier($sid, lock: true);
            }
            $fresh = $this->cache->freshness();
            if (! $fresh['ok']) {
                throw ValidationException::withMessages(['lines' => 'Chart of accounts / supplier information is not current on this branch server — post this journal on the Online POS or ask a supervisor. (' . implode('; ', $fresh['reasons']) . ')']);
            }
            $apIds = $this->cache->apAccountIds();
            $lines = [];
            $payableBySupplier = [];   // cloud_supplier_id → delta (credit AP = +payable, debit AP = −payable)
            $cashByAccount = [];       // cloud_cash_bank_account_id → delta (debit = +cash, credit = −cash)
            foreach ($rawLines as $i => $l) {
                $debit = round((float) ($l['debit'] ?? 0), 4);
                $credit = round((float) ($l['credit'] ?? 0), 4);
                if ($debit > 0 && $credit > 0) {
                    throw ValidationException::withMessages(["lines.$i.debit" => 'A line cannot have both a debit and a credit.']);
                }
                $account = $this->cache->account((int) ($l['cloud_account_id'] ?? 0));
                if (! $account || ! (bool) $account->is_active) {
                    throw ValidationException::withMessages(["lines.$i.cloud_account_id" => 'Choose an active account from the chart for every line.']);
                }
                $isAp = in_array((int) $account->cloud_account_id, $apIds, true);
                $supplierId = (int) ($l['cloud_supplier_id'] ?? 0);
                $supplier = null;
                if ($isAp) {
                    // The canonical rule: an Accounts Payable line MUST name its supplier (the subledger mirrors it).
                    $supplier = $supplierId > 0 ? $this->cache->supplier($supplierId) : null;
                    if (! $supplier) {
                        throw ValidationException::withMessages(["lines.$i.cloud_supplier_id" => 'This line posts to Accounts Payable (' . $account->code . '), so it must name the supplier it belongs to — otherwise the supplier ledger and the AP control account drift apart.']);
                    }
                    if ((string) $supplier->status !== 'active') {
                        throw ValidationException::withMessages(["lines.$i.cloud_supplier_id" => 'The supplier on this Accounts Payable line is inactive.']);
                    }
                    $payableBySupplier[$supplierId] = ($payableBySupplier[$supplierId] ?? 0.0) + ($credit > 0 ? $credit : -$debit);
                } elseif ($supplierId > 0) {
                    throw ValidationException::withMessages(["lines.$i.cloud_supplier_id" => 'Only an Accounts Payable line carries a supplier — account ' . $account->code . ' is not Accounts Payable.']);
                }
                $cbId = (int) ($l['cloud_cash_bank_account_id'] ?? 0);
                $cb = null;
                if ($cbId > 0) {
                    $cb = $this->cache->cashBankAccount($cbId);
                    if (! $cb || ! (bool) $cb->is_active) {
                        throw ValidationException::withMessages(["lines.$i.cloud_cash_bank_account_id" => 'Choose an active Cash/Bank account, or leave the Cash/Bank cell empty.']);
                    }
                    if ((int) ($cb->coa_account_id ?? 0) !== (int) $account->cloud_account_id) {
                        throw ValidationException::withMessages(["lines.$i.cloud_cash_bank_account_id" => 'Cash/Bank account ' . $cb->code . ' is mapped to chart account ' . ($cb->coa_code ?: '—') . ', not ' . $account->code . ' — a cash/bank line must post to the account it is mapped to. This combination needs the Online POS.']);
                    }
                    $cashByAccount[$cbId] = ($cashByAccount[$cbId] ?? 0.0) + ($debit > 0 ? $debit : -$credit);
                }
                $lines[] = [
                    'line_uuid' => (string) Str::ulid(), 'cloud_account_id' => (int) $account->cloud_account_id, 'account_code' => (string) $account->code, 'account_name' => (string) $account->name,
                    'cloud_cash_bank_account_id' => $cb ? (int) $cb->cloud_cash_bank_account_id : null, 'cash_bank_code' => $cb?->code,
                    'cloud_supplier_id' => $supplier ? (int) $supplier->cloud_supplier_id : null, 'supplier_name' => $supplier?->name,
                    'description' => self::str($l['description'] ?? null, 255), 'debit' => $debit, 'credit' => $credit,
                ];
            }
            $totalDebit = round(array_sum(array_column($lines, 'debit')), 4);
            $totalCredit = round(array_sum(array_column($lines, 'credit')), 4);
            if ($totalDebit <= 0 || abs($totalDebit - $totalCredit) > 0.00005) {
                throw ValidationException::withMessages(['lines' => "The journal is not balanced: debit " . number_format($totalDebit, 2) . ' ≠ credit ' . number_format($totalCredit, 2) . '.']);
            }

            // Lock every named supplier (ordered) and enforce the payable boundary on net reductions.
            ksort($payableBySupplier);
            $positions = [];
            foreach ($payableBySupplier as $sid => $delta) {
                $locked = $this->cache->supplier((int) $sid, lock: true);
                $position = $this->cache->position((int) $sid, lock: true);
                if ($delta < 0 && ! $rules['supplier_advance_supported'] && (-$delta) - (float) $position['available_payable'] > 0.005) {
                    throw ValidationException::withMessages(['lines' => 'This journal would leave ' . $locked->name . ' with a negative payable (an advance of ' . number_format((-$delta) - (float) $position['available_payable'], 2) . '), and supplier advances are not supported by the chart of accounts. Nothing was saved. The available payable is ' . number_format(max(0, (float) $position['available_payable']), 2) . '.']);
                }
                $positions[(int) $sid] = $position;
            }

            $eventUuid = (string) Str::ulid();
            $journal = ['entry_date' => $entryDate, 'description' => $description, 'reference_no' => self::str($data['reference_no'] ?? null, 100), 'lines' => $lines];
            $envelope = $this->envelopes->buildApJournal($meta, $eventUuid, $journal, $this->actor($user, $terminalId), $fresh);

            $effects = [];
            foreach ($payableBySupplier as $sid => $delta) {
                $effects[] = ['cloud_supplier_id' => (int) $sid, 'payable_delta' => $delta];
            }
            foreach ($cashByAccount as $cbId => $delta) {
                $effects[] = ['cloud_cash_bank_account_id' => (int) $cbId, 'cash_delta' => $delta];
            }
            $this->cache->recordEvent([
                'event_uuid' => $eventUuid, 'event_type' => EdgeSupplierFinanceEnvelopeBuilder::EVENT_AP_JOURNAL, 'branch_id' => $branchId, 'terminal_id' => $terminalId, 'user_id' => (int) $user->id,
                'business_date' => $entryDate, 'description' => $description, 'reference_no' => $journal['reference_no'], 'amount' => $totalDebit,
                'payload' => $journal + ['totals' => ['debit' => $totalDebit, 'credit' => $totalCredit]], 'envelope_schema_version' => $envelope['envelope_schema_version'],
                'content_hash' => $envelope['content_hash'], 'finance_watermark' => $fresh['watermark'],
            ], $effects);
            $this->outbox->createForFinanceEvent($envelope);

            return $this->cache->event($eventUuid) + ['positions' => array_map(fn ($sid) => $this->cache->position((int) $sid), array_keys($payableBySupplier))];
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function guardMutation(User $user, string $permission): \App\Models\Edge\EdgeLocalMeta
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Offline supplier finance runs on the Branch Server.');
        }
        $this->authority->assertLocalMutationAllowed();
        $meta = $this->context->requireCurrent();
        if (! EdgeUserAuthz::mayOperateBranch($user, (int) $meta->branch_id)) {
            throw new RuntimeException('This user is not authorized on this branch.');
        }
        if (! $user->can($permission)) {
            throw new RuntimeException("You are not allowed to do this ({$permission}).");
        }

        return $meta;
    }

    private function localMutationAllowed(): bool
    {
        try {
            $this->authority->assertLocalMutationAllowed();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function actor(User $user, ?int $terminalId): array
    {
        return ['user_id' => (int) $user->id, 'employee_code' => $user->employee_code ?? null, 'terminal_id' => $terminalId];
    }

    private function dateOr(mixed $value, string $field): string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return now()->toDateString();
        }
        try {
            return Carbon::parse($s)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Enter a valid date.']);
        }
    }

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque', 'card' => 'Card', 'other' => 'Other', default => ucfirst(str_replace('_', ' ', $method)),
        };
    }

    private static function str(mixed $v, int $max): ?string
    {
        $s = $v === null ? '' : trim((string) $v);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
