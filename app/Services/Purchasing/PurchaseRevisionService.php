<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\GoodsReceiptRevision;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseBillLine;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierCreditAllocation;
use App\Services\Finance\JournalPostingService;
use App\Services\Finance\JournalService;
use App\Services\Inventory\InventoryService;
use App\Support\LineQuantity;
use App\Support\VoidDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * PURCHASE-EDIT-1 (3 Nov) — correct a posted receipt and its supplier invoice IN PLACE, or void them together.
 *
 * The client: "rate baad mein badalna ho to reverse na karna pade — GRN kholein, rate daalein, sab khud seedha ho jaye;
 * date ghalat ho to date badlein, entry sahi din par chali jaye." So the GRN and the invoice keep their numbers and the
 * person changes a quantity, a rate or a date; everything that hangs off them is corrected by its OWN entries, never
 * rewritten, and the change is listed on the receipt:
 *
 *  - stock      only the items whose quantity or landed cost changed: this receipt's entries for them are taken back
 *               out and the new quantities go in, TODAY (stock is never back-dated — VOID-ON-ORIGINAL-DATE-1), with
 *               the average cost restored as if the old entry had never come in;
 *  - invoice    lines re-copied from the receipt, totals recomputed the way the invoice was first made; on the running
 *               account, payments above the new total come off THIS invoice and stay "Not on any invoice" — never
 *               moved to another invoice silently (SUPPLIER-CREDIT-CLARITY-1);
 *  - ledger     the supplier ledger moves by the difference on the invoice's date, or — when the date changed — the old
 *               amount comes off on the old date and the new one goes on the new date;
 *  - GL         every journal still in force for the invoice is reversed on ITS OWN date and the corrected invoice is
 *               posted again on its date under its own source (purchase_bill_revision / revision id), because
 *               JournalService::post returns the old entry for the same source even after it was reversed.
 *
 * One transaction: a refusal or a journal that does not post leaves nothing behind. Locks receipt → invoice → supplier,
 * the order createFromReceipt takes them in.
 */
final class PurchaseRevisionService
{
    private const CENT = 0.005;

    public function __construct(
        private readonly PurchasingService $purchasing,
        private readonly InventoryService $inventory,
        private readonly JournalService $journals,
        private readonly JournalPostingService $journalPosting,
        private readonly SupplierRunningAccountService $running,
    ) {}

    /**
     * Why this receipt cannot be edited or voided here, in words for the person; null when it can. Reads only.
     * Each refusal is a case v1 does not correct by itself (plan §3), with what to do instead.
     */
    public function blockedReason(GoodsReceipt $grn): ?string
    {
        $db = DB::connection('tenant');
        $grn->loadMissing('lines');

        if ($grn->status !== 'posted') {
            return 'This receipt is voided.';
        }
        if ($grn->purchase_order_id) {
            return 'This receipt came from a purchase order, so it cannot be edited here. Use a Purchase Return.';
        }
        if ($grn->lines->contains(fn ($l) => $l->batch_no || $l->expiry_date)) {
            return 'This receipt has items with a batch or an expiry date, so it cannot be edited here. Use a Purchase Return.';
        }
        if ($db->table('purchase_returns')->where('goods_receipt_id', $grn->id)->where('status', '!=', 'cancelled')->exists()) {
            return 'Goods from this receipt were returned to the supplier, so it can no longer be edited.';
        }
        if ($db->table('journal_entries')->where('source_type', 'grn_line_stock_expense')->whereIn('source_id', $grn->lines->pluck('id'))->exists()) {
            return 'An earlier stock correction already covers this receipt, so it cannot be edited here.';
        }

        $bill = PurchaseBill::query()->where('goods_receipt_id', $grn->id)->first();
        if ($bill) {
            if ($bill->status === 'voided') {
                return 'Its supplier invoice is voided.';
            }
            if ($db->table('purchase_bill_return_nettings')->where('purchase_bill_id', $bill->id)->exists()) {
                return 'A return was deducted on its supplier invoice, so it can no longer be edited.';
            }
            // PURCHASE-EDIT-RECOST-1: a recost on the invoice (purchase_bill_recost) no longer refuses — reinvoice() undoes
            // it with the invoice's own journal, so the cost is counted once.
            if (! $this->running->enabled() && (float) $bill->amount_paid > self::CENT) {
                return 'A payment is on its supplier invoice. Void that payment first, then edit.';
            }
        }

        return null;
    }

    /**
     * Change the receipt (and its invoice). $input: revision_no (what the form was opened at), receipt_date, notes,
     * extra_charges, extra_charges_note, and — with an invoice — bill_date, supplier_invoice_no; lines keyed by line id:
     * [quantity, unit_cost]. A quantity of 0 takes the line off. Every line of the receipt must be sent.
     *
     * @throws ValidationException|RuntimeException
     */
    public function edit(GoodsReceipt $grn, array $input, ?int $userId): GoodsReceiptRevision
    {
        $grn->loadMissing('lines.product');
        $hasBill = PurchaseBill::query()->where('goods_receipt_id', $grn->id)->exists();

        $data = Validator::make($input, [
            'revision_no'         => 'required|integer|min:0',
            'receipt_date'        => 'required|date',
            'bill_date'           => $hasBill ? 'required|date' : 'nullable',
            'supplier_invoice_no' => 'nullable|string|max:100',
            'notes'               => 'nullable|string|max:1000',
            'extra_charges'       => 'nullable|numeric|min:0',
            'extra_charges_note'  => 'nullable|string|max:255',
            'lines'               => 'required|array',
            'lines.*.quantity'    => 'required|numeric|min:0',
            'lines.*.unit_cost'   => 'required|numeric|min:0',
        ], [], ['receipt_date' => 'receipt date', 'bill_date' => 'invoice date'])->validate();

        $sent = array_map('intval', array_keys($data['lines']));
        $missing = $grn->lines->pluck('id')->diff($sent);
        if ($missing->isNotEmpty() || count($sent) !== $grn->lines->count()) {
            throw ValidationException::withMessages(['lines' => 'The receipt changed while you were editing it. Open it again and redo your change.']);
        }

        // Each quantity exact once, the way the receipt itself is checked (3 decimals; whole numbers where switched on).
        $rows = [];
        foreach ($grn->lines as $line) {
            $rows[$line->id] = ['product_id' => $line->product_id, 'quantity' => $data['lines'][$line->id]['quantity']];
        }
        $rows = LineQuantity::prepareStockLines($rows, 'quantity');

        $lines = [];
        foreach ($grn->lines as $line) {
            $lines[$line->id] = [
                'quantity'  => round((float) $rows[$line->id]['quantity'], 3),
                'unit_cost' => round((float) $data['lines'][$line->id]['unit_cost'], 4),
            ];
        }
        if (collect($lines)->every(fn ($l) => $l['quantity'] <= 0)) {
            throw ValidationException::withMessages(['lines' => 'Every line is at 0. To cancel the whole receipt, use Void instead.']);
        }

        $new = [
            'receipt_date'        => Carbon::parse($data['receipt_date'])->toDateString(),
            'bill_date'           => $hasBill ? Carbon::parse($data['bill_date'])->toDateString() : null,
            'supplier_invoice_no' => isset($data['supplier_invoice_no']) && trim((string) $data['supplier_invoice_no']) !== '' ? trim((string) $data['supplier_invoice_no']) : null,
            'notes'               => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'extra_charges'       => round((float) ($data['extra_charges'] ?? 0), 4),
            'extra_charges_note'  => isset($data['extra_charges_note']) && trim((string) $data['extra_charges_note']) !== '' ? trim((string) $data['extra_charges_note']) : null,
            'lines'               => $lines,
        ];

        return $this->run($grn, GoodsReceiptRevision::EDIT, (int) $data['revision_no'], $new, $userId);
    }

    /**
     * Void the receipt and its invoice together. The money comes off on the documents' own date (a wrong entry) or
     * today (VoidDate); the stock comes out today.
     */
    public function void(GoodsReceipt $grn, int $revisionNo, string $reason, string $dateMode, ?int $userId): GoodsReceiptRevision
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['void_reason' => 'Write why this purchase is being voided (at least 3 characters).']);
        }
        if (! in_array($dateMode, VoidDate::MODES, true)) {
            throw ValidationException::withMessages(['void_date_mode' => 'Choose whether to wipe it on its own date or reverse it today.']);
        }
        $grn->loadMissing('lines');

        return $this->run($grn, GoodsReceiptRevision::VOID, $revisionNo, ['lines' => $grn->lines->mapWithKeys(fn ($l) => [$l->id => ['quantity' => 0.0,
            'unit_cost' => round((float) $l->unit_cost, 4)]])->all()], $userId, $reason, $dateMode);
    }

    private function run(GoodsReceipt $grn, string $action, int $expectedRevision, array $new, ?int $userId, ?string $reason = null, ?string $dateMode = null): GoodsReceiptRevision
    {
        return DB::connection('tenant')->transaction(function () use ($grn, $action, $expectedRevision, $new, $userId, $reason, $dateMode) {
            $grn = GoodsReceipt::query()->whereKey($grn->id)->lockForUpdate()->firstOrFail();
            if ((int) $grn->revision_no !== $expectedRevision) {
                throw ValidationException::withMessages(['revision_no' => 'Someone else changed this receipt a moment ago. Open it again and redo your change.']);
            }
            $bill = PurchaseBill::query()->where('goods_receipt_id', $grn->id)->lockForUpdate()->first();
            if ($why = $this->blockedReason($grn)) {
                throw new RuntimeException($why);
            }
            Supplier::query()->whereKey($grn->supplier_id)->lockForUpdate()->firstOrFail();
            $grn->load(['lines.product', 'lines.variant', 'branch', 'supplier']);

            $isVoid = $action === GoodsReceiptRevision::VOID;
            $new += [
                'receipt_date'        => $grn->receipt_date?->toDateString(),
                'bill_date'           => $bill?->bill_date?->toDateString(),
                'supplier_invoice_no' => $bill?->supplier_invoice_no,
                'notes'               => $grn->notes,
                'extra_charges'       => $isVoid ? 0.0 : round((float) $grn->extra_charges, 4),
                'extra_charges_note'  => $grn->extra_charges_note,
            ];
            $before = $this->snapshot($grn, $bill);
            $changes = $this->describe($before, $new, $grn);
            if (! $isVoid && $changes === []) {
                throw ValidationException::withMessages(['lines' => 'Nothing was changed.']);
            }

            $revision = GoodsReceiptRevision::create([
                'goods_receipt_id'   => $grn->id,
                'purchase_bill_id'   => $bill?->id,
                'revision_no'        => (int) $grn->revision_no + 1,
                'action'             => $action,
                'before'             => $before,
                'after'              => [],
                'changes'            => $changes,
                'reason'             => $reason,
                'date_mode'          => $dateMode,
                'created_by_user_id' => $userId,
            ]);
            $label = $isVoid ? 'Purchase voided — ' . $reason : 'Purchase edited (change ' . $revision->revision_no . ')';

            $this->restock($grn, $before, $new, $isVoid, $label, $userId);

            $grn->forceFill([
                'receipt_date'       => $new['receipt_date'],
                'notes'              => $new['notes'],
                'extra_charges'      => $new['extra_charges'],
                'extra_charges_note' => $new['extra_charges_note'],
                'revision_no'        => $revision->revision_no,
            ] + ($isVoid ? ['status' => 'voided', 'voided_at' => now(), 'voided_by_user_id' => $userId, 'void_reason' => $reason] : []))->save();

            $reversed = [];
            $posted = null;
            $released = 0.0;
            $recostUndone = false;
            if ($bill) {
                [$reversed, $posted, $released, $recostUndone] = $this->reinvoice($grn->fresh('lines'), $bill, $before, $new, $revision, $isVoid, $label, $dateMode, $userId);
            }
            if ($released > self::CENT) {
                $changes[] = 'Rs ' . number_format($released, 2) . ' already paid came off this invoice and is now "Not on any invoice" for this supplier.';
            }
            if ($recostUndone) {
                $changes[] = 'The earlier cost correction on this invoice was undone with it, so its cost is counted once.';
            }

            $revision->update([
                'after'                => $this->snapshot($grn->fresh('lines.product'), $bill?->fresh()),
                'changes'              => $changes,
                'reversed_journal_ids' => $reversed,
                'journal_entry_id'     => $posted?->id,
            ]);

            return $revision->fresh();
        });
    }

    /**
     * Stock: only the items whose quantity or landed cost changed. Extra charges are spread over every line by value,
     * so with charges on the receipt any change of value re-costs every stocked item.
     */
    private function restock(GoodsReceipt $grn, array $before, array $new, bool $isVoid, string $label, ?int $userId): void
    {
        $old = collect($before['lines'])->keyBy('id');
        $changedLine = fn (int $id) => abs((float) $old[$id]['quantity'] - (float) $new['lines'][$id]['quantity']) > 0.0005
            || abs((float) $old[$id]['unit_cost'] - (float) $new['lines'][$id]['unit_cost']) > 0.00005;
        $anyChanged = $grn->lines->contains(fn ($l) => $changedLine((int) $l->id));
        $charges = (float) $before['extra_charges'] > 0 || (float) $new['extra_charges'] > 0;
        $all = $isVoid || ($charges && ($anyChanged || abs((float) $before['extra_charges'] - (float) $new['extra_charges']) > 0.00005));

        $key = fn ($productId, $variantId) => $productId . ':' . ($variantId ?? 0);
        $groups = $grn->lines->filter(fn ($l) => $l->affects_stock !== false)
            ->filter(fn ($l) => $all || $changedLine((int) $l->id))
            ->map(fn ($l) => $key($l->product_id, $l->product_variant_id))->unique()->values()->all();

        // 1. this receipt's entries for those items come back out, at the cost they came in at
        foreach (StockLedger::query()->where('reference_type', GoodsReceipt::class)->where('reference_id', $grn->id)
            ->where('direction', 'in')->whereNull('reversal_of_id')->orderBy('id')->get() as $entry) {
            if (in_array($key($entry->product_id, $entry->product_variant_id), $groups, true)) {
                $this->inventory->reverseReceiptEntry($entry, $grn->grn_no, $label, $userId, 'adjustment_out', restoreAverage: true,
                    doing: $isVoid ? 'voided' : 'changed');
            }
        }

        // 2. the lines become what was typed (a line at 0 goes)
        foreach ($grn->lines as $line) {
            $q = (float) $new['lines'][$line->id]['quantity'];
            $c = (float) $new['lines'][$line->id]['unit_cost'];
            if ($isVoid) {
                continue;                                     // a voided receipt keeps its lines as the record of what it was
            }
            if ($q <= 0) {
                $line->delete();
                continue;
            }
            if (! $changedLine((int) $line->id)) {
                continue;
            }
            $line->forceFill([
                'quantity_received' => $q,
                'unit_cost'         => $c,
                'base_quantity'     => $line->base_quantity !== null ? $q : null,
                'notes'             => $this->editedNote($line, $q, $c),
                // the "last deal" read back for this supplier is now the corrected price, per unit
                'entry_json'        => $line->entry_json !== null ? ['buy_pack' => 'base', 'pack' => $line->product?->unit?->name ?? 'unit',
                    'factor' => 1, 'price' => $c, 'disc_type' => null, 'disc' => 0, 'scheme' => null, 'free' => null] : null,
            ])->save();
        }
        if ($isVoid) {
            return;
        }

        // 3. the new quantities of those items go in, today, at their landed cost
        $grn->forceFill(['extra_charges' => $new['extra_charges']]);
        $grn->setRelation('lines', $grn->lines()->with(['product', 'variant'])->get());
        $landed = $this->purchasing->landedUnitCosts($grn);
        foreach ($grn->lines as $line) {
            if ($line->affects_stock === false || ! in_array($key($line->product_id, $line->product_variant_id), $groups, true)) {
                continue;
            }
            $this->inventory->postIn($grn->branch, $line->product, $line->variant, (float) $line->quantity_received,
                $landed[$line->id] ?? (float) $line->unit_cost, 'purchase', GoodsReceipt::class, $grn->id, $grn->grn_no,
                null, null, $label, $userId);
        }
    }

    /**
     * The invoice follows the receipt: lines, totals, what is paid on it, the supplier ledger and the GL.
     *
     * @return array{0: list<int>, 1: ?JournalEntry, 2: float, 3: bool} reversed journal ids, the journal posted, credit released, a recost undone
     */
    private function reinvoice(GoodsReceipt $grn, PurchaseBill $bill, array $before, array $new, GoodsReceiptRevision $revision,
        bool $isVoid, string $label, ?string $dateMode, ?int $userId): array
    {
        $oldTotal = round((float) $before['bill']['grand_total'], 4);
        $oldDate = (string) $before['bill']['bill_date'];

        PurchaseBillLine::query()->where('purchase_bill_id', $bill->id)->delete();
        $subtotal = 0.0;
        if (! $isVoid) {
            foreach ($grn->lines as $line) {
                PurchaseBillLine::create([
                    'purchase_bill_id'   => $bill->id,
                    'product_id'         => $line->product_id,
                    'product_variant_id' => $line->product_variant_id,
                    'quantity'           => $line->quantity_received,
                    'unit_cost'          => $line->unit_cost,
                    'discount_amount'    => 0,
                    'tax_amount'         => 0,
                    'line_total'         => $line->quantity_received * $line->unit_cost,
                ]);
                $subtotal += (float) $line->quantity_received * (float) $line->unit_cost;
            }
        }
        $subtotal = round($subtotal, 4);
        $discount = $isVoid ? 0.0 : round((float) $bill->discount_total, 4);
        $tax = $isVoid ? 0.0 : round((float) $bill->tax_total, 4);
        $newTotal = $isVoid ? 0.0 : round($subtotal + (float) $new['extra_charges'] - $discount + $tax, 4);
        if ($newTotal < 0) {
            throw ValidationException::withMessages(['lines' => 'The invoice would come below zero after its discount. Check the quantities and rates.']);
        }
        $newDate = $isVoid ? $oldDate : (string) $new['bill_date'];

        $bill->forceFill([
            'bill_date'           => $newDate,
            'supplier_invoice_no' => $new['supplier_invoice_no'],
            'subtotal'            => $subtotal,
            'extra_charges'       => $isVoid ? 0 : $new['extra_charges'],
            'discount_total'      => $discount,
            'tax_total'           => $tax,
            'grand_total'         => $newTotal,
        ])->save();

        // What is paid on it: on the running account, anything above the new total comes off THIS invoice (newest first)
        // and stays unallocated; with it off, a paid invoice never reaches here (blockedReason).
        $released = 0.0;
        if ($this->running->enabled()) {
            $allocated = round((float) SupplierCreditAllocation::query()->where('purchase_bill_id', $bill->id)->sum('amount'), 4);
            $excess = round($allocated - $newTotal, 4);
            foreach (SupplierCreditAllocation::query()->where('purchase_bill_id', $bill->id)->orderByDesc('id')->get() as $row) {
                if ($excess <= self::CENT) {
                    break;
                }
                $take = round(min($excess, (float) $row->amount), 4);
                $this->running->releaseCreditFrom($row->source_type, (int) $row->source_id, [$bill->id => $take]);
                $excess = round($excess - $take, 4);
                $released = round($released + $take, 4);
            }
            $this->running->recomputeBill($bill->fresh());
        } else {
            $bill->forceFill(['amount_paid' => 0, 'balance_due' => $newTotal, 'status' => 'posted'])->save();
        }
        if ($isVoid) {
            $bill->fresh()->forceFill(['status' => 'voided', 'amount_paid' => 0, 'balance_due' => 0, 'voided_at' => now(),
                'voided_by_user_id' => $userId, 'void_reason' => $revision->reason])->save();
        }

        // Supplier ledger: on the invoice's date by the difference; a moved date takes the old amount off on the old day.
        $supplier = $grn->supplier;
        $note = fn (string $what) => $label . ': ' . $what;
        if ($isVoid) {
            if ($oldTotal > self::CENT) {
                $this->purchasing->postSupplierLedger($supplier, 'purchase_bill_void', 'credit', $oldTotal, PurchaseBill::class, (int) $bill->id,
                    (string) $bill->bill_no, $note('invoice ' . number_format($oldTotal, 2) . ' taken off'), $userId,
                    VoidDate::resolve((string) $dateMode, $oldDate));
            }
        } elseif ($newDate === $oldDate) {
            $diff = round($newTotal - $oldTotal, 4);
            if (abs($diff) > self::CENT) {
                $this->purchasing->postSupplierLedger($supplier, 'purchase_bill_revision', $diff > 0 ? 'debit' : 'credit', abs($diff),
                    PurchaseBill::class, (int) $bill->id, (string) $bill->bill_no,
                    $note('invoice ' . number_format($oldTotal, 2) . ' → ' . number_format($newTotal, 2)), $userId, $newDate);
            }
        } else {
            if ($oldTotal > self::CENT) {
                $this->purchasing->postSupplierLedger($supplier, 'purchase_bill_revision', 'credit', $oldTotal, PurchaseBill::class, (int) $bill->id,
                    (string) $bill->bill_no, $note('moved to ' . Carbon::parse($newDate)->format('d M Y')), $userId, $oldDate);
            }
            if ($newTotal > self::CENT) {
                $this->purchasing->postSupplierLedger($supplier, 'purchase_bill_revision', 'debit', $newTotal, PurchaseBill::class, (int) $bill->id,
                    (string) $bill->bill_no, $note('moved from ' . Carbon::parse($oldDate)->format('d M Y')
                    . ($newTotal !== $oldTotal ? ', ' . number_format($oldTotal, 2) . ' → ' . number_format($newTotal, 2) : '')), $userId, $newDate);
            }
        }

        // GL: what is in force for the invoice is reversed on its own date (a void may choose today); the corrected
        // invoice is posted on its date under this revision.
        // PURCHASE-EDIT-RECOST-1: a recost of the invoice (finance:recost-nonstock-bills — Dr the category's head / Cr the
        // head first booked) is in force for it too. The corrected invoice posts on its lines' cost_account_id, which
        // that recost already pointed at the new head; left standing, the recost would count the cost twice there and
        // push the old head below zero. So it is reversed with the rest, on the same day.
        $revisionIds = GoodsReceiptRevision::query()->where('goods_receipt_id', $grn->id)->pluck('id');
        $inForce = JournalEntry::query()->where('is_reversal', false)->where('status', 'posted')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('source_type', 'purchase_bill')->where('source_id', $bill->id))
                ->orWhere(fn ($q) => $q->where('source_type', 'purchase_bill_revision')->whereIn('source_id', $revisionIds))
                ->orWhere(fn ($q) => $q->where('source_type', 'purchase_bill_recost')->where('source_id', $bill->id)))
            ->whereNotExists(fn ($q) => $q->from('journal_entries as r')->whereColumn('r.reversed_entry_id', 'journal_entries.id'))
            ->orderBy('id')->get();
        $reversed = [];
        foreach ($inForce as $entry) {
            $on = $isVoid ? VoidDate::resolve((string) $dateMode, $entry->entry_date) : Carbon::parse($entry->entry_date)->toDateString();
            $reversed[] = (int) $this->journals->reverse($entry, $label, $userId, $on)->id;
        }

        $posted = null;
        if (! $isVoid && $newTotal > 0) {
            $posted = $this->journalPosting->postPurchaseBillRevision($bill->fresh(), (int) $revision->id, $userId);
            if (! $posted) {
                throw new RuntimeException('The accounting entry for the corrected invoice could not be posted, so nothing was saved. '
                    . 'Please try again; if it fails again, contact support.');
            }
        }

        return [$reversed, $posted, $released, $inForce->contains('source_type', 'purchase_bill_recost')];
    }

    /** The receipt (and invoice) as numbers — the revision's before / after. */
    private function snapshot(GoodsReceipt $grn, ?PurchaseBill $bill): array
    {
        return [
            'status'             => $grn->status,
            'receipt_date'       => $grn->receipt_date?->toDateString(),
            'notes'              => $grn->notes,
            'extra_charges'      => round((float) $grn->extra_charges, 4),
            'extra_charges_note' => $grn->extra_charges_note,
            'lines'              => $grn->lines->map(fn ($l) => [
                'id'        => (int) $l->id,
                'product_id' => (int) $l->product_id,
                'name'      => $l->product?->name,
                'stock'     => $l->affects_stock !== false,
                'quantity'  => round((float) $l->quantity_received, 3),
                'unit_cost' => round((float) $l->unit_cost, 4),
            ])->values()->all(),
            'bill' => $bill ? [
                'id'                  => (int) $bill->id,
                'bill_no'             => $bill->bill_no,
                'status'              => $bill->status,
                'bill_date'           => $bill->bill_date?->toDateString(),
                'supplier_invoice_no' => $bill->supplier_invoice_no,
                'subtotal'            => round((float) $bill->subtotal, 4),
                'extra_charges'       => round((float) $bill->extra_charges, 4),
                'discount_total'      => round((float) $bill->discount_total, 4),
                'tax_total'           => round((float) $bill->tax_total, 4),
                'grand_total'         => round((float) $bill->grand_total, 4),
                'amount_paid'         => round((float) $bill->amount_paid, 4),
                'balance_due'         => round((float) $bill->balance_due, 4),
            ] : null,
        ];
    }

    /** What changed, in the words the GRN page lists. @return list<string> */
    private function describe(array $before, array $new, GoodsReceipt $grn): array
    {
        $out = [];
        $day = fn ($d) => $d ? Carbon::parse($d)->format('d M Y') : '—';
        $num = fn ($n, $dp) => rtrim(rtrim(number_format((float) $n, $dp), '0'), '.');

        if ($before['receipt_date'] !== $new['receipt_date']) {
            $out[] = 'Receipt date ' . $day($before['receipt_date']) . ' → ' . $day($new['receipt_date']);
        }
        if ($before['bill'] && $before['bill']['bill_date'] !== $new['bill_date']) {
            $out[] = 'Invoice date ' . $day($before['bill']['bill_date']) . ' → ' . $day($new['bill_date']);
        }
        if ($before['bill'] && (string) $before['bill']['supplier_invoice_no'] !== (string) $new['supplier_invoice_no']) {
            $out[] = 'Supplier invoice no. ' . ($before['bill']['supplier_invoice_no'] ?: '—') . ' → ' . ($new['supplier_invoice_no'] ?: '—');
        }
        foreach ($before['lines'] as $l) {
            $q = (float) $new['lines'][$l['id']]['quantity'];
            $c = (float) $new['lines'][$l['id']]['unit_cost'];
            $name = $l['name'] ?? 'Item';
            if ($q <= 0 && $l['quantity'] > 0) {
                $out[] = $name . ': taken off (was ' . $num($l['quantity'], 3) . ' × ' . $num($l['unit_cost'], 4) . ')';
                continue;
            }
            if (abs($q - $l['quantity']) > 0.0005) {
                $out[] = $name . ': quantity ' . $num($l['quantity'], 3) . ' → ' . $num($q, 3);
            }
            if (abs($c - $l['unit_cost']) > 0.00005) {
                $out[] = $name . ': rate ' . $num($l['unit_cost'], 4) . ' → ' . $num($c, 4);
            }
        }
        if (abs((float) $before['extra_charges'] - (float) $new['extra_charges']) > 0.00005) {
            $out[] = 'Extra charges ' . $num($before['extra_charges'], 2) . ' → ' . $num($new['extra_charges'], 2);
        }
        if ((string) $before['extra_charges_note'] !== (string) $new['extra_charges_note'] && (float) $new['extra_charges'] > 0) {
            $out[] = 'Extra charges note changed';
        }
        if ((string) $before['notes'] !== (string) $new['notes']) {
            $out[] = 'Notes changed';
        }
        if ($out !== [] && $before['bill']) {
            $total = 0.0;
            foreach ($before['lines'] as $l) {
                $total += (float) $new['lines'][$l['id']]['quantity'] * (float) $new['lines'][$l['id']]['unit_cost'];
            }
            $total = round($total + (float) $new['extra_charges'] - (float) $before['bill']['discount_total'] + (float) $before['bill']['tax_total'], 2);
            if (abs($total - (float) $before['bill']['grand_total']) > self::CENT) {
                $out[] = 'Invoice total ' . number_format((float) $before['bill']['grand_total'], 2) . ' → ' . number_format($total, 2);
            }
        }

        return $out;
    }

    private function editedNote(GoodsReceiptLine $line, float $qty, float $cost): string
    {
        $unit = $line->product?->unit?->name ?? '';
        $num = fn ($n, $dp) => rtrim(rtrim(number_format($n, $dp), '0'), '.');

        return trim($num($qty, 3) . ' ' . $unit) . ' @ ' . $num($cost, 4) . ($unit !== '' ? ' / ' . $unit : '') . ' (edited)';
    }
}
