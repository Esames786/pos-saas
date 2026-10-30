<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\SupplierPayment;
use App\Services\Finance\SupplierPayableService;
use App\Support\ScreenAccess;
use App\Support\SubmissionKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * W3/W4 S4 — one Post for a purchase: the steps the user chose, through the same services the Goods Receipt, Supplier
 * Invoice and Payments screens use, in ONE transaction. Any refusal or failure in any step rolls all of them back; the
 * answer names the step.
 *
 *   start = new       Goods arriving now: a Goods Receipt, then — if chosen — its Supplier Invoice, then — if chosen — a
 *                     Payment against that invoice. "Receive now, invoice later" is new + no invoice.
 *   start = received  Goods already received: the Supplier Invoice of a posted receipt that has none (its stock is never
 *                     received again), and — if chosen — a Payment.
 *
 * The server decides each step: the user's permission for that step's own screen (and the plan's module) is checked on
 * every Post, before anything is written. A payment pays this invoice only: its amount is checked against the invoice's
 * balance as the server holds it — after Supplier Credit, read again inside this transaction under a lock — never the
 * figure the screen showed. Paying more is refused; an advance belongs to Payments.
 *
 * The same form posted twice (a double click, or a retry after a lost answer) returns the documents of the first Post:
 * for a new delivery the receipt carries the form key (goods_receipts.submission_key, unique); for goods already
 * received, one receipt can only ever have one invoice.
 */
final class PurchaseWorkspaceService
{
    public const STEP_PERMISSIONS = [
        'receipt' => 'tenant.goods-receipts.store',
        'invoice' => 'tenant.purchase-bills.store',
        'payment' => 'tenant.supplier-payments.store',
    ];

    private const STEP_WORDS = ['receipt' => 'the Goods Receipt', 'invoice' => 'the Supplier Invoice', 'payment' => 'the Payment'];

    public function __construct(
        private readonly GoodsReceiptService $receipts,
        private readonly PurchaseBillService $bills,
        private readonly SupplierPayableService $payables,
    ) {}

    /** The steps a Post asks for. @return array{receipt: bool, invoice: bool, payment: bool} */
    public static function steps(array $input): array
    {
        $received = ($input['start'] ?? 'new') === 'received';

        return [
            'receipt' => ! $received,
            'invoice' => $received || filter_var($input['record_invoice'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'payment' => filter_var($input['pay'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** Which steps this user may do here — the screen shows only those; post() checks them again. */
    public static function allowedSteps(): array
    {
        return collect(self::STEP_PERMISSIONS)->map(fn ($perm) => ScreenAccess::allows($perm))->all();
    }

    /**
     * @return array{already: bool, documents: array<int, array{kind: string, number: string, url: string}>}
     * @throws ValidationException  keyed by the step: the receipt's own fields as the Goods Receipt screen names them
     *                              ("lines.2.price"), then "invoice.*", "payment.*"; "step" says which part failed
     */
    public function post(array $input, ?int $userId, bool $mayReallocate): array
    {
        $steps = self::steps($input);
        if (! in_array($input['start'] ?? 'new', ['new', 'received'], true)) {
            $this->refuse('receipt', 'start', 'Choose "Goods arriving now" or "Goods already received".');
        }
        if ($steps['payment'] && ! $steps['invoice']) {
            $this->refuse('payment', 'payment', 'A payment here pays this purchase\'s Supplier Invoice, so record the invoice too — or pay an advance under Payments.');
        }
        foreach (array_keys(array_filter($steps)) as $step) {
            if (! ScreenAccess::allows(self::STEP_PERMISSIONS[$step])) {
                $this->refuse($step, $step, 'You cannot record ' . self::STEP_WORDS[$step] . ' (your role does not include it). Nothing was saved — ask the owner, or leave that step out.');
            }
        }

        // Every step's own checks first, so a mistake anywhere is reported before anything is written.
        $prepared = $steps['receipt'] ? $this->receipts->prepare($input) : null;
        $grn = $steps['receipt'] ? null : $this->receiptWaiting($input['goods_receipt_id'] ?? null);
        $invoice = $steps['invoice'] ? $this->invoiceInput($input, $grn) : null;
        $payment = $steps['payment'] ? $this->paymentInput($input) : null;

        // A repeat of a Post that made a receipt gets that receipt's documents.
        $key = $steps['receipt'] ? SubmissionKey::usable($input[SubmissionKey::FIELD] ?? null, 'goods_receipts') : null;
        $hash = $key ? SubmissionKey::hash(Arr::except($prepared['input'], ['_token', SubmissionKey::FIELD])) : null;
        if ($key && ($existing = GoodsReceipt::query()->where('submission_key', $key)->first())) {
            return $this->already($existing, hash_equals((string) $existing->submission_hash, (string) $hash));
        }

        try {
            return DB::connection('tenant')->transaction(function () use ($steps, $prepared, $grn, $invoice, $payment, $userId, $mayReallocate, $key, $hash) {
                $docs = [];
                if ($steps['receipt']) {
                    $grn = $this->step('receipt', fn () => $this->receipts->post($prepared, $userId, $key, $hash));
                    $docs[] = ['kind' => 'Goods Receipt', 'number' => $grn->grn_no, 'url' => url('/goods-receipts/' . $grn->id)];
                }
                if ($steps['invoice']) {
                    $bill = $this->step('invoice', fn () => $this->bills->createFromReceipt($grn, $invoice, $userId, $mayReallocate));
                    $docs[] = ['kind' => 'Supplier Invoice', 'number' => $bill->bill_no, 'url' => url('/purchase-bills/' . $bill->id)];
                }
                if ($steps['payment']) {
                    $paid = $this->step('payment', fn () => $this->pay($bill, $payment, $userId));
                    $docs[] = ['kind' => 'Payment', 'number' => $paid->payment_no, 'url' => url('/supplier-payments/' . $paid->id)];
                }

                return ['already' => false, 'documents' => $docs];
            });
        } catch (QueryException $e) {
            // Two posts of one form raced past the look above: the unique key let only one through.
            if ($key && SubmissionKey::isDuplicate($e, $key) && ($existing = GoodsReceipt::query()->where('submission_key', $key)->first())) {
                return $this->already($existing, hash_equals((string) $existing->submission_hash, (string) $hash));
            }
            throw $e;
        }
    }

    /**
     * The server's own balance of the invoice just made — after Supplier Credit — read again under a lock inside the
     * Post's transaction. The screen's figure is only a suggestion.
     */
    private function pay(PurchaseBill $bill, array $payment, ?int $userId): SupplierPayment
    {
        $bill = PurchaseBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
        $due = round((float) $bill->balance_due, 2);
        $amount = round((float) $payment['amount'], 2);
        if ($due < 0.01) {
            throw ValidationException::withMessages(['amount' => 'Nothing is left to pay on this invoice — Supplier Credit already paid it. Leave the payment out.']);
        }
        if ($amount > $due) {
            throw ValidationException::withMessages(['amount' => 'That is more than this invoice still owes: Rs ' . number_format($due, 2)
                . ' after Supplier Credit. Pay up to Rs ' . number_format($due, 2) . ' here — an advance is recorded under Payments.']);
        }

        return $this->payables->recordPayment([
            'supplier_id'          => $bill->supplier_id,
            'branch_id'            => $bill->branch_id,
            'purchase_bill_id'     => $bill->id,
            'cash_bank_account_id' => $payment['cash_bank_account_id'],
            'payment_date'         => $payment['payment_date'],
            'amount'               => $amount,
            'payment_method'       => $payment['payment_method'],
            'reference_no'         => $payment['reference_no'] ?? null,
            'notes'                => $payment['notes'] ?? null,
        ], $userId);
    }

    /** A posted receipt that still waits for its invoice — the only kind "goods already received" may use. */
    private function receiptWaiting($id): GoodsReceipt
    {
        $grn = $id ? GoodsReceipt::query()->find((int) $id) : null;
        if (! $grn) {
            $this->refuse('invoice', 'goods_receipt_id', 'Choose the goods receipt this invoice is for.');
        }
        if ($grn->status !== 'posted') {
            $this->refuse('invoice', 'goods_receipt_id', 'Goods receipt ' . $grn->grn_no . ' was voided, so no invoice can be recorded for it.');
        }
        if ($bill = PurchaseBill::query()->where('goods_receipt_id', $grn->id)->first()) {
            $this->refuse('invoice', 'goods_receipt_id', 'Goods receipt ' . $grn->grn_no . ' already has its invoice (' . $bill->bill_no . '). Nothing more was posted.');
        }

        return $grn;
    }

    /** The Supplier Invoice screen's rules. The invoice total comes from the goods; the bill discount is already in them. */
    private function invoiceInput(array $input, ?GoodsReceipt $grn): array
    {
        $in = (array) ($input['invoice'] ?? []);
        try {
            $data = Validator::make($in, [
                'supplier_invoice_no'  => 'nullable|string|max:100',
                'bill_date'            => 'required|date',
                'due_date'             => 'nullable|date|after_or_equal:bill_date',
                'tax_amount'           => 'nullable|numeric|min:0',
                'notes'                => 'nullable|string|max:1000',
                'returns_on_invoice'   => 'nullable|in:' . PurchaseBillService::SEPARATE . ',' . PurchaseBillService::NETTED,
                'netted_amounts'       => 'nullable|array',
                'netted_amounts.*'     => 'nullable|numeric|min:0',
                'confirm_reallocation' => 'nullable|boolean',
            ], [
                'due_date.after_or_equal' => 'The due date cannot be before the invoice date.',
                'netted_amounts.*.numeric' => 'The amount deducted must be a number.',
            ], ['bill_date' => 'invoice date', 'supplier_invoice_no' => 'supplier\'s invoice number', 'tax_amount' => 'tax'])->validate();
        } catch (ValidationException $e) {
            throw $this->under('invoice', $e);
        }

        // Decision (23 Oct): the configured tax treatment stays; the workspace adds no discount of its own.
        return ['discount_amount' => 0] + $data;
    }

    private function paymentInput(array $input): array
    {
        $in = (array) ($input['payment'] ?? []);
        try {
            return Validator::make($in, [
                'amount'               => 'required|numeric|min:0.01',
                'cash_bank_account_id' => ['required', Rule::exists('tenant.cash_bank_accounts', 'id')->where('is_active', true)],
                'payment_date'         => 'required|date',
                'payment_method'       => 'required|in:cash,bank_transfer,cheque,card,other',
                'reference_no'         => 'nullable|string|max:100',
                'notes'                => 'nullable|string|max:1000',
            ], [], ['cash_bank_account_id' => 'paid from', 'amount' => 'amount to pay'])->validate();
        } catch (ValidationException $e) {
            throw $this->under('payment', $e);
        }
    }

    /** Run one step; its refusal or failure comes back named after the step, and rolls the whole Post back. */
    private function step(string $step, callable $run)
    {
        try {
            return $run();
        } catch (ValidationException $e) {
            throw $this->under($step, $e);
        } catch (RuntimeException $e) {
            $this->refuse($step, $step, $e->getMessage());
        }
    }

    /** The receipt's own fields keep their names (the entry area marks its rows by them); the others go under the step. */
    private function under(string $step, ValidationException $e): ValidationException
    {
        $errors = [];
        foreach ($e->errors() as $k => $m) {
            $errors[$step === 'receipt' || str_starts_with($k, $step) ? $k : $step . '.' . $k] = $m;
        }
        $out = ValidationException::withMessages($errors + ['step' => [$step]]);

        return $out;
    }

    private function refuse(string $step, string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message, 'step' => [$step]]);
    }

    /**
     * This form already posted. The same lines → its documents (nothing posted twice). Other lines under the same key
     * → refused, so pressing Post again makes a second purchase on purpose.
     */
    private function already(GoodsReceipt $grn, bool $same): array
    {
        if (! $same) {
            throw ValidationException::withMessages(['receipt' => 'This form was already posted as goods receipt ' . $grn->grn_no
                . '. Nothing more was posted — press Post again to record another purchase with these lines.', 'step' => ['receipt']]);
        }
        $docs = [['kind' => 'Goods Receipt', 'number' => $grn->grn_no, 'url' => url('/goods-receipts/' . $grn->id)]];
        if ($bill = PurchaseBill::query()->where('goods_receipt_id', $grn->id)->first()) {
            $docs[] = ['kind' => 'Supplier Invoice', 'number' => $bill->bill_no, 'url' => url('/purchase-bills/' . $bill->id)];
            if ($paid = SupplierPayment::query()->where('purchase_bill_id', $bill->id)->orderBy('id')->first()) {
                $docs[] = ['kind' => 'Payment', 'number' => $paid->payment_no, 'url' => url('/supplier-payments/' . $paid->id)];
            }
        }

        return ['already' => true, 'documents' => $docs];
    }
}
