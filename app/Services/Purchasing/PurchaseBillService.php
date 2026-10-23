<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseBillLine;
use App\Models\Tenant\PurchaseBillReturnNetting;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\SupplierCreditAllocation;
use App\Models\Tenant\SupplierCreditReallocation;
use App\Services\Finance\JournalPostingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The supplier's invoice for one goods receipt — moved here from PurchaseBillController::store (unchanged for a receipt
 * with no returns), plus SUPPLIER-NET-BILL-1 (owner, 21 Oct): record the ACTUAL invoice.
 *
 * Goods returned from the receipt BEFORE its invoice have already credited the supplier (ledger, GL, and the running
 * account). The supplier then invoices one of two ways, and the screen asks which — nothing pre-picked:
 *
 *   separate  full invoice, before returns (1,000): the return stays a supplier credit and pays it once → 800 due
 *   netted    net invoice, returns already deducted (800, or 900 when the supplier took only 100 of a 200 return off):
 *             each deducted amount is a netting row, a matching supplier-ledger debit and GL entry (the mirror of the
 *             return, scaled), so it is never deducted a second time → 800 due, not 600
 *
 * Everything posts in ONE transaction, the GL journals included: if a journal cannot post, nothing is saved and the
 * person sees why (review, 21 Oct). One receipt has one invoice, even when two posts arrive at once.
 */
class PurchaseBillService
{
    public const SEPARATE = 'separate';

    public const NETTED = 'netted';

    private const CENT = 0.005;

    public function __construct(
        private readonly PurchasingService $purchasing,
        private readonly SupplierRunningAccountService $runningAccount,
    ) {}

    /**
     * Returns of this receipt whose money credit is whole and not taken off any invoice — what the screen asks about.
     *
     * @return list<array{id:int, return_no:string, date:string, amount:float}>
     */
    public function returnsToAsk(GoodsReceipt $grn): array
    {
        return $this->eligibleReturns((int) $grn->id, false)
            ->map(fn (PurchaseReturn $r) => ['id' => (int) $r->id, 'return_no' => (string) $r->return_no,
                'date' => (string) \Illuminate\Support\Carbon::parse($r->return_date)->toDateString(), 'amount' => round((float) $r->grand_total, 2)])
            ->values()->all();
    }

    /**
     * What the screen shows before anything is posted — by the same rules createFromReceipt() posts with: the received
     * value, the returns it asks about (each with its unused credit and the other invoices its credit already pays, in
     * the order they would give it back), the moves and block for a full deduction, and what the running account needs
     * to work out the supplier credit the invoice will use for any deducted amount.
     *
     * @return array<string, mixed>
     */
    public function preview(GoodsReceipt $grn): array
    {
        $grn->loadMissing('lines');
        $goods = round((float) $grn->lines->sum(fn ($l) => $l->quantity_received * $l->unit_cost), 4);
        $charges = round((float) ($grn->extra_charges ?? 0), 4);
        $returns = $this->eligibleReturns((int) $grn->id, false);
        $allocations = collect($this->allocationsOf($returns->pluck('id')->all(), false));

        $list = $returns->map(function (PurchaseReturn $r) use ($allocations) {
            $mine = $allocations->where('return_id', (int) $r->id)->values();

            return [
                'id' => (int) $r->id, 'return_no' => (string) $r->return_no,
                'date' => (string) \Illuminate\Support\Carbon::parse($r->return_date)->toDateString(),
                'amount' => round((float) $r->grand_total, 2),
                'unused' => round(max(0.0, (float) $r->grand_total - (float) $mine->sum('amount')), 2),
                'allocations' => $mine->all(),
            ];
        })->values()->all();
        $full = $returns->mapWithKeys(fn ($r) => [(int) $r->id => round((float) $r->grand_total, 4)])->all();
        $moves = collect($this->planMoves($returns, $full, $allocations));

        $credit = ['enabled' => $this->runningAccount->enabled(), 'balance' => 0.0, 'unallocated' => 0.0, 'separate' => 0.0, 'netted' => 0.0];
        if ($credit['enabled']) {
            $credit['balance'] = round((float) (\App\Models\Tenant\Supplier::query()->whereKey($grn->supplier_id)->value('current_balance') ?? 0), 2);
            $credit['unallocated'] = round((float) collect($this->runningAccount->unallocatedCredits((int) $grn->supplier_id))->sum('remaining'), 2);
            $credit['separate'] = round(max(0.0, min(-$credit['balance'], $credit['unallocated'])), 2);
            $credit['netted'] = round(max(0.0, min(-($credit['balance'] + array_sum($full)),
                $credit['unallocated'] - array_sum(array_column($list, 'unused')))), 2);
        }

        return ['goods' => $goods, 'charges' => $charges, 'received' => round($goods + $charges, 4), 'returns' => $list,
            'moves' => $moves->all(), 'blocked' => $this->blockedReason($moves), 'credit' => $credit];
    }

    /**
     * @param  array{bill_date:string, due_date?:?string, supplier_invoice_no?:?string, discount_amount?:mixed,
     *               tax_amount?:mixed, notes?:?string, returns_on_invoice?:?string, netted_amounts?:array<int|string,mixed>,
     *               confirm_reallocation?:mixed}  $data
     * @param  bool  $mayReallocate  the person may move a credit off another invoice (owner: an authorized person only)
     */
    public function createFromReceipt(GoodsReceipt $grn, array $data, ?int $userId = null, bool $mayReallocate = false): PurchaseBill
    {
        $choice = $data['returns_on_invoice'] ?? null;
        if ($choice !== null && ! in_array($choice, [self::SEPARATE, self::NETTED], true)) {
            throw ValidationException::withMessages(['returns_on_invoice' => 'Choose how the supplier\'s invoice treats the returns.']);
        }

        return DB::connection('tenant')->transaction(function () use ($grn, $data, $choice, $userId, $mayReallocate) {
            // One receipt, one invoice: two posts at once queue here; the second finds the first.
            $grn = GoodsReceipt::query()->whereKey($grn->id)->lockForUpdate()->firstOrFail();
            // W3/W4 S0: a voided receipt brought nothing in, so nothing can be invoiced for it. Only the create
            // screen's list filtered these out; a crafted or stale post went through. Same lock as voidGrn().
            if ($grn->status !== 'posted') {
                throw new RuntimeException('This goods receipt was voided, so no invoice can be recorded for it.');
            }
            if (PurchaseBill::query()->where('goods_receipt_id', $grn->id)->exists()) {
                throw new RuntimeException('An invoice is already recorded for this goods receipt.');
            }
            $grn->load(['lines', 'supplier', 'branch']);

            $returns = $this->eligibleReturns((int) $grn->id, true);
            if ($returns->isNotEmpty() && $choice === null) {
                throw ValidationException::withMessages(['returns_on_invoice' => 'Goods from this receipt were returned before the invoice ('
                    . $returns->map(fn ($r) => $r->return_no . ': ' . number_format((float) $r->grand_total, 2))->implode(', ')
                    . '). Choose "Full invoice — before returns" or "Net invoice — returns already deducted".']);
            }
            if ($returns->isEmpty() && $choice === self::NETTED) {
                throw ValidationException::withMessages(['returns_on_invoice' => 'Nothing was returned from this receipt before the invoice, so it cannot be a net invoice.']);
            }
            $netted = $choice === self::NETTED ? $returns : collect();
            $amounts = $this->deductedAmounts($netted, (array) ($data['netted_amounts'] ?? []));

            // Owner, 21 Oct: a return credit that already pays ANOTHER invoice is never moved silently. A settled invoice is
            // never reopened here; an open one gives the credit back only on an authorized person's explicit confirmation.
            $moves = collect($this->planMoves($netted, $amounts, collect($this->allocationsOf($netted->pluck('id')->all(), true))));
            if ($reason = $this->blockedReason($moves)) {
                throw ValidationException::withMessages(['returns_on_invoice' => $reason]);
            }
            if ($moves->isNotEmpty() && ! filter_var($data['confirm_reallocation'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages(['confirm_reallocation' => $moves->map(fn ($m) => 'Rs ' . number_format($m['amount'], 2)
                    . ' of this credit will be moved off ' . $m['bill_no'] . ': its outstanding goes from ' . number_format($m['due_now'], 2)
                    . ' to ' . number_format($m['due_after'], 2))->implode('. ') . '. Tick the confirmation to post.']);
            }
            if ($moves->isNotEmpty() && ! $mayReallocate) {
                throw ValidationException::withMessages(['confirm_reallocation' => 'Only a person who can void supplier payments can move a credit between invoices. Ask them to post this invoice.']);
            }

            $subtotal = $grn->lines->sum(fn ($l) => $l->quantity_received * $l->unit_cost);
            // GRN-BILL-CHARGES-1: the receipt's cartage / labour is part of what the supplier charged.
            $charges = round((float) ($grn->extra_charges ?? 0), 4);
            $discount = (float) ($data['discount_amount'] ?? 0);
            $tax = (float) ($data['tax_amount'] ?? 0);
            $less = round(array_sum($amounts), 4);
            $grandTotal = round($subtotal + $charges - $discount + $tax - $less, 4);
            if ($netted->isNotEmpty() && $grandTotal < 0) {
                throw ValidationException::withMessages(['returns_on_invoice' => 'The returns deducted are more than this invoice — check the amounts or the discount.']);
            }

            $bill = $this->createNumbered([
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'supplier_id'         => $grn->supplier_id,
                'branch_id'           => $grn->branch_id,
                'purchase_order_id'   => $grn->purchase_order_id,
                'goods_receipt_id'    => $grn->id,
                'bill_date'           => $data['bill_date'],
                'due_date'            => $data['due_date'] ?? null,
                'status'              => 'posted',
                'subtotal'            => $subtotal,
                'extra_charges'       => $charges,
                'discount_total'      => $discount,
                'tax_total'           => $tax,
                'grand_total'         => $grandTotal,
                'amount_paid'         => 0,
                'balance_due'         => $grandTotal,
                'notes'               => $data['notes'] ?? null,
                'posted_by_user_id'   => $userId,
                'posted_at'           => now(),
            ]);
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
            }

            // SUPPLIER-NET-BILL-1 — BEFORE the invoice's own ledger row: it then takes no credit it already includes.
            $nettings = [];
            foreach ($netted as $return) {
                $mine = $moves->where('return_id', (int) $return->id);
                foreach ($mine as $m) {   // the audit trail, before the move
                    SupplierCreditReallocation::create([
                        'purchase_bill_id' => $bill->id, 'purchase_return_id' => $return->id, 'from_purchase_bill_id' => $m['bill_id'],
                        'amount' => $m['amount'], 'from_due_before' => $m['due_now'], 'from_due_after' => $m['due_after'],
                        'confirmed_by' => $userId, 'confirmed_at' => now(),
                    ]);
                }
                if ($mine->isNotEmpty()) {
                    $this->runningAccount->releaseCreditFrom(SupplierCreditAllocation::SOURCE_RETURN, (int) $return->id,
                        $mine->mapWithKeys(fn ($m) => [(int) $m['bill_id'] => (float) $m['amount']])->all());
                }
                $amount = $amounts[(int) $return->id];
                $nettings[] = PurchaseBillReturnNetting::create([
                    'purchase_bill_id'   => $bill->id,
                    'purchase_return_id' => $return->id,
                    'supplier_id'        => $grn->supplier_id,
                    'amount'             => $amount,
                    'created_by'         => $userId,
                ]);
                $this->purchasing->postSupplierLedger($grn->supplier, 'return_netted', 'debit', $amount,
                    PurchaseBill::class, (int) $bill->id, (string) $bill->bill_no,
                    'Return ' . $return->return_no . ' deducted on this invoice (' . number_format($amount, 2) . ')', $userId);
            }

            $bill->load('supplier');
            $this->purchasing->postBillOperational($bill, $userId);

            // Review, 21 Oct: the GL is part of the same transaction. A journal that does not post rolls everything back,
            // so an invoice is never shown as recorded while its accounting entry is missing.
            $journal = app(JournalPostingService::class);
            if ($grandTotal > 0 && ! $journal->postPurchaseBill($bill, $userId)) {
                throw new RuntimeException('The accounting entry for this invoice could not be posted, so nothing was saved. '
                    . 'Please try again; if it fails again, contact support.');
            }
            foreach ($nettings as $netting) {
                $entry = $journal->postPurchaseBillReturnNetting($netting, $userId);
                if (! $entry) {
                    throw new RuntimeException('The accounting entry for a deducted return could not be posted, so nothing was saved. '
                        . 'Please try again; if it fails again, contact support.');
                }
                $netting->update(['journal_entry_id' => $entry->id]);
            }

            return $bill->fresh();
        });
    }

    /**
     * The invoice row, numbered. "BILL-<second>-<3 digits>" clashes about 1 in 900 when two invoices are made in the same
     * second; a number already taken draws another (as returns do) instead of failing the post.
     */
    private function createNumbered(array $attributes): PurchaseBill
    {
        for ($try = 1; ; $try++) {
            $no = $this->purchasing->nextBillNo();
            try {
                return PurchaseBill::create(['bill_no' => $no] + $attributes);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($try >= 5 || ! \App\Support\SubmissionKey::duplicateValue($e, $no)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * What the supplier deducted for each return on this invoice: as typed (partial allowed), or the whole return.
     *
     * @return array<int, float> return id => amount
     */
    private function deductedAmounts(Collection $netted, array $typed): array
    {
        $out = [];
        foreach ($netted as $return) {
            $max = round((float) $return->grand_total, 4);
            $raw = $typed[$return->id] ?? $typed[(string) $return->id] ?? null;
            $amount = ($raw === null || $raw === '') ? $max : round((float) str_replace(',', '', (string) $raw), 4);
            if (! is_numeric(str_replace(',', '', (string) ($raw ?? $max))) || $amount <= 0 || $amount > $max + self::CENT) {
                throw ValidationException::withMessages(['netted_amounts.' . $return->id => 'The amount deducted for ' . $return->return_no
                    . ' must be more than 0 and not more than the return (' . number_format($max, 2) . ').']);
            }
            $out[(int) $return->id] = min($amount, $max);
        }

        return $out;
    }

    /**
     * Where each deducted amount comes from: the return's unused credit first, then — only for the rest — off the other
     * invoices its credit already pays: open ones first, newest first, a settled one last (and that one blocks).
     *
     * @param  array<int, float>  $amounts  return id => deducted
     * @return list<array{return_id:int, bill_id:int, bill_no:string, amount:float, due_now:float, due_after:float, settled:bool}>
     */
    private function planMoves(Collection $returns, array $amounts, Collection $allocations): array
    {
        $moves = [];
        foreach ($returns as $return) {
            $mine = $allocations->where('return_id', (int) $return->id);
            $unused = max(0.0, round((float) $return->grand_total - (float) $mine->sum('amount'), 4));
            $rest = round(($amounts[(int) $return->id] ?? 0.0) - $unused, 4);
            foreach ($mine->sortBy(fn ($a) => [$a['settled'] ? 1 : 0, -$a['bill_id']]) as $a) {
                if ($rest <= self::CENT) {
                    break;
                }
                $take = round(min($rest, (float) $a['amount']), 2);
                $moves[] = ['amount' => $take, 'due_after' => round($a['due_now'] + $take, 2)] + $a;
                $rest = round($rest - $take, 4);
            }
        }

        return $moves;
    }

    /**
     * The other invoices these returns' credit already pays, one row per (return, invoice): how much, that invoice's
     * outstanding now, and whether it is settled (fully paid). Invoices locked when asked.
     *
     * @param  list<int>  $returnIds
     * @return list<array{return_id:int, bill_id:int, bill_no:string, amount:float, due_now:float, due_after:float, settled:bool}>
     */
    private function allocationsOf(array $returnIds, bool $lock): array
    {
        if (! $returnIds) {
            return [];
        }
        $rows = SupplierCreditAllocation::query()->where('source_type', SupplierCreditAllocation::SOURCE_RETURN)->whereIn('source_id', $returnIds)
            ->selectRaw('source_id, purchase_bill_id, SUM(amount) AS amount')->groupBy('source_id', 'purchase_bill_id')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $billQuery = PurchaseBill::query()->whereIn('id', $rows->pluck('purchase_bill_id')->unique()->all())->orderBy('id');
        $bills = ($lock ? $billQuery->lockForUpdate() : $billQuery)->get()->keyBy('id');

        return $rows->map(function ($r) use ($bills) {
            $bill = $bills[$r->purchase_bill_id];
            $due = round((float) $bill->balance_due, 2);
            $amount = round((float) $r->amount, 2);

            return ['return_id' => (int) $r->source_id, 'bill_id' => (int) $bill->id, 'bill_no' => (string) $bill->bill_no, 'amount' => $amount,
                'due_now' => $due, 'due_after' => round($due + $amount, 2), 'settled' => $due <= self::CENT];
        })->values()->all();
    }

    /**
     * Why "Net invoice" cannot be used: the deduction would take credit back off an invoice that is now fully paid. It
     * never tells the person to record a full invoice instead — that is right only when the supplier's paper says so.
     */
    private function blockedReason(Collection $moves): ?string
    {
        $settled = $moves->firstWhere('settled', true);

        return $settled
            ? 'Net invoice is not available: Rs ' . number_format($settled['amount'], 2) . ' of this return\'s credit already paid '
                . $settled['bill_no'] . ', which is now fully paid, and a paid invoice is not reopened here.'
                . "\n• If the supplier's invoice lists all goods received (before returns), choose \"Full invoice — before returns\"."
                . "\n• If the supplier deducted this return on this invoice, do not record it as a full invoice: leave this goods receipt "
                . 'without an invoice (it stays in the list) and ask your accountant to resolve ' . $settled['bill_no'] . ' first.'
            : null;
    }

    /** Posted returns of the receipt with money credited, not yet deducted on an invoice; locked when asked. */
    private function eligibleReturns(int $grnId, bool $lock): Collection
    {
        $query = PurchaseReturn::query()->where('goods_receipt_id', $grnId)->where('status', 'posted')
            ->where('grand_total', '>', 0.00005)->orderBy('id');
        if (Schema::connection('tenant')->hasTable('purchase_bill_return_nettings')) {
            $query->whereNotIn('id', PurchaseBillReturnNetting::query()->select('purchase_return_id'));
        }

        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }
}
