<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Support\SubmissionKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * W3/W4 S4 — the one place a Goods Receipt is checked and posted, shared by the Goods Receipt screen and the purchase
 * workspace. Moved from GoodsReceiptController::store without changing what it checks, saves or posts.
 */
final class GoodsReceiptService
{
    public function __construct(private readonly PurchasingService $purchasing) {}

    /** The rules the Goods Receipt screen has always used. */
    public static function rules(): array
    {
        return [
            'branch_id'                    => 'required|exists:tenant.branches,id',
            'supplier_id'                  => 'required|exists:tenant.suppliers,id',
            'purchase_order_id'            => 'nullable|exists:tenant.purchase_orders,id',
            'receipt_date'                 => 'required|date',
            'notes'                        => 'nullable|string|max:1000',
            // GRN-EXTRA-CHARGES-1: cartage, labour, unloading — a charge, not a product.
            'extra_charges'                => 'nullable|numeric|min:0',
            'extra_charges_note'           => 'nullable|string|max:255',
            'lines'                        => 'required|array|min:1',
            'lines.*.product_id'           => 'required|exists:tenant.products,id',
            'lines.*.product_variant_id'   => 'nullable|exists:tenant.product_variants,id',
            'lines.*.purchase_order_line_id' => 'nullable|exists:tenant.purchase_order_lines,id',
            'lines.*.batch_no'             => 'nullable|string|max:100',
            'lines.*.expiry_date'          => 'nullable|date',
            'lines.*.quantity_received'    => 'required|numeric|min:0.001',
            'lines.*.unit_cost'            => 'required|numeric|min:0',
            'lines.*.discount_amount'      => 'nullable|numeric|min:0',
            'lines.*.tax_amount'           => 'nullable|numeric|min:0',
            'lines.*.notes'                => 'nullable|string|max:500',
            // PURCHASING-V2-1: the pack the price was typed in — for screens only; stock reads the line.
            'lines.*.pack_unit_id'         => 'nullable|integer',
            'lines.*.pack_factor'          => 'nullable|integer|min:1',
            'lines.*.pack_size'            => 'nullable|numeric|min:0',
            'lines.*.base_quantity'        => 'nullable|numeric|min:0',
            'lines.*.entry_json'           => 'nullable|array',
        ];
    }

    /**
     * Check a posted receipt form. Writes nothing.
     *
     * @return array{input: array, data: array, lines: \Illuminate\Support\Collection, products: \Illuminate\Support\Collection}
     *         `input` is the form as checked (the new entry area's packs already turned into today's lines) — what the
     *         form key's fingerprint is taken of, as before.
     * @throws ValidationException
     */
    public function prepare(array $input): array
    {
        // PURCHASING-V2-1: the new entry area posts packs, bonus and MM/YY; the server turns them into
        // today's lines (tablets, net cost per tablet) before anything else reads them. The old screen
        // does not send _entry_v2 and is untouched.
        if (filter_var($input['_entry_v2'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $input['lines'] = \App\Support\PackLines::toLines((array) ($input['lines'] ?? []), 'quantity_received', true, (int) ($input['branch_id'] ?? 0),
                ['type' => $input['bill_disc_type'] ?? null, 'value' => $input['bill_disc'] ?? null]);
        }

        // Drop fully-blank rows before validation (robust even if client JS is bypassed).
        $input['lines'] = collect($input['lines'] ?? [])
            ->filter(fn ($l) => ! empty($l['product_id'] ?? null))
            ->values()
            ->all();

        $data = Validator::make($input, self::rules())->validate();
        // W2-C: each quantity exact once (3 decimals; counted items whole where switched on) before stock and the bill.
        $data['lines'] = \App\Support\LineQuantity::prepareStockLines($data['lines'], 'quantity_received');

        $validLines = collect($data['lines'])->filter(fn ($l) => ! empty($l['product_id']));
        if ($validLines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'At least one product line is required.']);
        }

        // Conditional batch/expiry: required only when the selected product tracks them.
        $productMap = Product::whereIn('id', $validLines->pluck('product_id'))->get()->keyBy('id');
        foreach ($validLines->values() as $i => $line) {
            $product = $productMap[$line['product_id']] ?? null;
            if (! $product) {
                continue;
            }
            // GRN-NON-STOCK-1 (owner, 6 Oct): a purchasable product that does not track stock — a
            // drink whose inventory has not been started — is RECEIVED as a purchase only. It posts,
            // nothing enters stock (postGrn skips it), and its bill books it to cost, not inventory.
            // Until 6 Oct this was refused here (GRN-PICKER-GUARD-1), which left the purchase with
            // nowhere to go: a bill can only be built from a GRN. Batch/expiry mean nothing without
            // stock, so they are not asked for.
            if (! $product->is_stock_tracked) {
                continue;
            }
            if ($product->requires_batch && empty($line['batch_no'])) {
                throw ValidationException::withMessages([
                    "lines.$i.batch_no" => "Batch number is required for {$product->name}.",
                ]);
            }
            if ($product->has_expiry && empty($line['expiry_date'])) {
                throw ValidationException::withMessages([
                    "lines.$i.expiry_date" => "Expiry date is required for {$product->name}.",
                ]);
            }
        }

        return ['input' => $input, 'data' => $data, 'lines' => $validLines, 'products' => $productMap];
    }

    /**
     * Post a prepared receipt: the receipt and its lines, stock in, and its Purchase Order moved on. One transaction —
     * the caller's, when there is one. $key / $hash are the form key and the fingerprint of what it posted.
     */
    public function post(array $prepared, ?int $userId, ?string $key = null, ?string $hash = null): GoodsReceipt
    {
        ['data' => $data, 'lines' => $validLines, 'products' => $productMap] = $prepared;

        return DB::connection('tenant')->transaction(function () use ($data, $validLines, $userId, $productMap, $key, $hash) {
            $grn = $this->createNumbered([
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'branch_id'         => $data['branch_id'],
                'supplier_id'       => $data['supplier_id'],
                'receipt_date'      => $data['receipt_date'],
                'status'            => 'posted',
                'notes'             => $data['notes'] ?? null,
                'extra_charges'      => $data['extra_charges'] ?? 0,
                'extra_charges_note' => $data['extra_charges_note'] ?? null,
                'posted_by_user_id' => $userId,
                'posted_at'         => now(),
            ] + ($key ? ['submission_key' => $key, 'submission_hash' => $hash] : []));

            foreach ($validLines as $line) {
                GoodsReceiptLine::create([
                    'goods_receipt_id'        => $grn->id,
                    'purchase_order_line_id'  => $line['purchase_order_line_id'] ?? null,
                    'product_id'              => $line['product_id'],
                    'product_variant_id'      => $line['product_variant_id'] ?? null,
                    'batch_no'                => $line['batch_no'] ?? null,
                    'expiry_date'             => $line['expiry_date'] ?? null,
                    'quantity_received'       => $line['quantity_received'],
                    'unit_cost'               => $line['unit_cost'],
                    'discount_amount'         => $line['discount_amount'] ?? 0,
                    'tax_amount'              => $line['tax_amount'] ?? 0,
                    'notes'                   => $line['notes'] ?? null,
                    // Frozen at receipt: the bill and any return follow THIS, not the product's flag later.
                    'affects_stock'           => (bool) ($productMap[$line['product_id']]->is_stock_tracked ?? true),
                    'pack_unit_id'            => $line['pack_unit_id'] ?? null,
                    'pack_factor'             => $line['pack_factor'] ?? null,
                ] + \App\Support\CounterPacks::sizeColumns('goods_receipt_lines', $line['pack_size'] ?? null) + [
                    'base_quantity'           => $line['base_quantity'] ?? null,
                    'entry_json'              => $line['entry_json'] ?? null,
                ]);
            }

            $grn->load('lines.product', 'lines.variant', 'branch');
            $this->purchasing->postGrn($grn, $userId);

            if ($grn->purchase_order_id) {
                $po = PurchaseOrder::with('lines')->find($grn->purchase_order_id);
                // W3/W4 S0: a partly received order moves on too — it used to stay "partially received" for ever,
                // because only an approved order was looked at again.
                if ($po && in_array($po->status, ['approved', 'partially_received'], true)) {
                    // BUG-062 FIX: only set 'received' when ALL PO lines are fully received.
                    // Compare total ordered vs total received across ALL GRNs for this PO.
                    $totalOrdered  = (float) $po->lines->sum('quantity_ordered');
                    $totalReceived = GoodsReceiptLine::query()
                        ->join('goods_receipts', 'goods_receipt_lines.goods_receipt_id', '=', 'goods_receipts.id')
                        ->where('goods_receipts.purchase_order_id', $po->id)
                        ->where('goods_receipts.status', 'posted')
                        // PURCHASING-V2-1: a bonus line (free, zero cost, no order line) was never ordered.
                        // Only the new screen writes base_quantity, so older lines count exactly as before.
                        ->where(fn ($q) => $q->whereNull('goods_receipt_lines.base_quantity')
                            ->orWhereNotNull('goods_receipt_lines.purchase_order_line_id')
                            ->orWhere('goods_receipt_lines.unit_cost', '>', 0))
                        ->sum('goods_receipt_lines.quantity_received');

                    $newPoStatus = ($totalOrdered > 0 && $totalReceived >= $totalOrdered)
                        ? 'received'
                        : 'partially_received';

                    $po->update(['status' => $newPoStatus]);
                }
            }

            return $grn;
        });
    }

    /**
     * W3/W4 S0: the receipt row, numbered. "GRN-<second>-<3 digits>" can clash when two receipts are made in the same
     * second; a number already taken draws another (as supplier invoices do) instead of failing the post. A clash on
     * anything else — the form key — is not retried.
     */
    private function createNumbered(array $attributes): GoodsReceipt
    {
        for ($try = 1; ; $try++) {
            $no = $this->purchasing->nextGrnNo();
            try {
                return GoodsReceipt::create(['grn_no' => $no] + $attributes);
            } catch (QueryException $e) {
                if ($try >= 5 || ! SubmissionKey::duplicateValue($e, $no)) {
                    throw $e;
                }
            }
        }
    }
}
