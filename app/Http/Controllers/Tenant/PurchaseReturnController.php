<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\PurchaseReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * PURCHASE-RETURNS-1 — supplier purchase returns (completes the purchasing
 * cycle). Draft = no impact; Post = stock out + payable down; posted docs
 * are immutable.
 */
class PurchaseReturnController extends Controller
{
    public function __construct(private readonly PurchaseReturnService $service) {}

    public function index(Request $request)
    {
        $query = PurchaseReturn::query()
            ->with(['branch', 'supplier', 'goodsReceipt', 'postedBy'])
            ->withCount('lines')
            ->orderByDesc('id');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return view('tenant.purchase-returns.index', [
            'returns'   => $query->paginate(15)->withQueryString(),
            'branches'  => Branch::where('status', 'active')->orderBy('name')->get(),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request)
    {
        $goodsReceipt = null;

        if ($request->filled('goods_receipt_id')) {
            $goodsReceipt = GoodsReceipt::with(['supplier', 'branch', 'lines.product.unit', 'lines.variant'])
                ->find($request->input('goods_receipt_id'));
        }

        // Live returnable qty per GRN line (received − posted returns).
        $returnable = [];
        if ($goodsReceipt) {
            foreach ($goodsReceipt->lines as $line) {
                $returnable[$line->id] = $this->service->returnableForGrnLine($line);
            }
        }

        // PURCHASE-RETURN-V2: with "New purchase screens" on, the page is the entry area (packs, batch box,
        // from a GRN / without / both, the expiry sweep). Off = exactly as before.
        $entryV2 = \App\Support\TenantFeatures::on(\App\Support\TenantFeatures::PURCHASING_V2);
        $suppliers = $entryV2
            ? Supplier::where('status', 'active')->orderBy('name')->get()
            : Supplier::where('status', 'active')->orderBy('name')->get(['id', 'name']);

        return view('tenant.purchase-returns.create', [
            'goodsReceipt' => $goodsReceipt,
            'returnable'   => $returnable,
            'branches'     => Branch::where('status', 'active')->orderBy('name')->get(),
            'suppliers'    => $suppliers,
            'reasonCodes'  => PurchaseReturn::REASON_CODES,
            'entryV2'      => $entryV2,
            'supplierBox'  => $entryV2 ? \App\Support\SupplierBox::data($suppliers) : null,
            'grnLines'     => $entryV2 && $goodsReceipt ? self::grnLinesForEntry($goodsReceipt, $returnable) : [],
            'supplierGrns' => $entryV2 ? self::recentGrnsBySupplier() : [],
        ]);
    }

    /**
     * PURCHASE-RETURN-V2 — a GRN's lines as the entry area's "From the receipt" panel wants them: what can still
     * go back (in the product's unit), the GRN rate, and the batch it came in as (so it leaves from there).
     */
    private static function grnLinesForEntry(GoodsReceipt $grn, array $returnable): array
    {
        $out = [];
        foreach ($grn->lines as $line) {
            $left = (float) ($returnable[$line->id] ?? 0);
            if ($left <= 0.0005 || $line->affects_stock === false) {
                continue;
            }
            $batchId = $line->batch_no
                ? \App\Models\Tenant\InventoryBatch::where('branch_id', $grn->branch_id)->where('product_id', $line->product_id)->where('batch_no', $line->batch_no)
                    ->orderByRaw('product_variant_id <=> ? DESC', [$line->product_variant_id])->value('id')
                : null;
            // Never offer more than the batch holds now (part of it may be sold already, or returned without the receipt).
            $inBatch = $batchId ? (float) \App\Models\Tenant\StockBalance::where('inventory_batch_id', $batchId)->sum('quantity_on_hand') : null;
            $out[] = [
                'source_line_id'     => $line->id,
                'product_id'         => $line->product_id,
                'product_variant_id' => $line->product_variant_id,
                'due'                => $inBatch === null ? $left : max(0.0, min($left, $inBatch)),
                'sold_out'           => $inBatch !== null && $inBatch <= 0.0005,
                'unit_cost'          => (float) $line->unit_cost,
                'pack_unit_id'       => $line->pack_unit_id,
                'batch_id'           => $batchId,
                'batch_no'           => $line->batch_no,
                'expiry'             => $line->expiry_date?->format('m/y'),
                'free'               => (float) $line->unit_cost <= 0 && $line->base_quantity !== null,
                'name'               => $line->product?->name,
            ];
        }

        return $out;
    }

    /** The last posted GRNs of each supplier, for "Return from a receipt". */
    private static function recentGrnsBySupplier(): array
    {
        return GoodsReceipt::where('status', 'posted')->orderByDesc('receipt_date')->orderByDesc('id')->limit(400)
            ->get(['id', 'grn_no', 'supplier_id', 'branch_id', 'receipt_date'])
            ->groupBy('supplier_id')
            ->map(fn ($rows) => $rows->take(15)->map(fn ($g) => ['id' => $g->id, 'no' => $g->grn_no, 'date' => $g->receipt_date?->format('d M'), 'branch' => $g->branch_id])->values())
            ->all();
    }

    public function store(Request $request)
    {
        // PURCHASE-RETURN-V2: the new screen posts packs, the batch and a price per pack; ReturnLines turns them
        // into today's lines (base quantity, cost of one unit, the batch kept on the line). The old screen does
        // not send _entry_v2 and is untouched.
        if ($request->boolean('_entry_v2')) {
            $request->merge(['lines' => \App\Support\ReturnLines::toLines((array) $request->input('lines', []), (int) $request->input('branch_id'), $request->input('reason_code'))]);
        }

        $data = $this->validated($request);
        $return = null;

        try {
            $return = $this->service->createDraft(
                $data,
                $request->input('lines', []),
                auth('tenant')->id()
            );
            // "Post return" on the new screen: save and post in one go. If posting is refused, the draft it
            // just made goes too, so pressing Post again never leaves a second copy behind.
            if ($request->boolean('_entry_v2') && $request->boolean('_post')) {
                try {
                    $return = $this->service->post($return, auth('tenant')->id());
                } catch (RuntimeException $e) {
                    $return->lines()->delete();
                    $return->delete();
                    throw $e;
                }
            }
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'errors' => ['return' => [$e->getMessage()]]], 422);
            }

            return back()->withInput()->withErrors(['return' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $return->id, 'number' => $return->return_no, 'posted' => $return->isPosted(),
                'url' => url('/purchase-returns/' . $return->id)]);
        }

        return redirect(url('/purchase-returns/' . $return->id))
            ->with('status', 'Draft return ' . $return->return_no . ' saved. Review, then Post to apply stock and payable impact.');
    }

    public function show(PurchaseReturn $purchaseReturn)
    {
        $purchaseReturn->load([
            'branch', 'supplier', 'goodsReceipt', 'journalEntry',
            'lines.product.unit', 'lines.variant', 'lines.sourceGrnLine',
            'postedBy', 'cancelledBy', 'createdBy',
        ]);

        return view('tenant.purchase-returns.show', ['return' => $purchaseReturn]);
    }

    public function edit(PurchaseReturn $purchaseReturn)
    {
        if (! $purchaseReturn->canEdit()) {
            return redirect(url('/purchase-returns/' . $purchaseReturn->id))
                ->withErrors(['return' => 'Only draft returns can be edited.']);
        }

        $purchaseReturn->load(['branch', 'supplier', 'goodsReceipt', 'lines.product.unit', 'lines.variant', 'lines.sourceGrnLine']);

        $returnable = [];
        foreach ($purchaseReturn->lines as $line) {
            if ($line->source_line_id && $line->sourceGrnLine) {
                $returnable[$line->id] = $this->service->returnableForGrnLine($line->sourceGrnLine, $purchaseReturn->id);
            }
        }

        return view('tenant.purchase-returns.edit', [
            'return'      => $purchaseReturn,
            'returnable'  => $returnable,
            'reasonCodes' => PurchaseReturn::REASON_CODES,
        ]);
    }

    public function update(Request $request, PurchaseReturn $purchaseReturn)
    {
        $data = $this->validated($request, forUpdate: true);

        try {
            $this->service->updateDraft($purchaseReturn, $data, $request->input('lines', []));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['return' => $e->getMessage()]);
        }

        return redirect(url('/purchase-returns/' . $purchaseReturn->id))
            ->with('status', 'Draft updated.');
    }

    public function post(PurchaseReturn $purchaseReturn)
    {
        try {
            $this->service->post($purchaseReturn, auth('tenant')->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        }

        return redirect(url('/purchase-returns/' . $purchaseReturn->id))
            ->with('status', 'Return posted — branch stock reduced and supplier payable decreased.');
    }

    public function cancel(PurchaseReturn $purchaseReturn)
    {
        try {
            $this->service->cancelDraft($purchaseReturn, auth('tenant')->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        }

        return back()->with('status', 'Draft return cancelled.');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function validated(Request $request, bool $forUpdate = false): array
    {
        $rules = [
            'return_date'          => ['required', 'date'],
            'reason_code'          => ['nullable', Rule::in(PurchaseReturn::REASON_CODES)],
            'notes'                => ['nullable', 'string'],
            'lines'                => ['required', 'array', 'min:1'],
            'lines.*.product_id'   => ['nullable', 'exists:products,id'],
            'lines.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'lines.*.source_line_id'     => ['nullable', 'exists:goods_receipt_lines,id'],
            'lines.*.quantity'     => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_cost'    => ['nullable', 'numeric', 'min:0'],
            'lines.*.reason_code'  => ['nullable', Rule::in(PurchaseReturn::REASON_CODES)],
            'lines.*.notes'        => ['nullable', 'string', 'max:500'],
        ];

        if (! $forUpdate) {
            $rules['branch_id']         = ['required', 'exists:branches,id'];
            $rules['supplier_id']       = ['required', 'exists:suppliers,id'];
            $rules['goods_receipt_id']  = ['nullable', 'exists:goods_receipts,id'];
            $rules['purchase_order_id'] = ['nullable', 'exists:purchase_orders,id'];
        }

        $data = $request->validate($rules, [
            'lines.required' => 'Add at least one product line.',
            'supplier_id.required' => 'Select the supplier the goods are being returned to.',
        ]);

        // Header-level reason required when no line has one.
        $lines = $request->input('lines', []);
        $anyLineReason = collect($lines)->contains(fn ($l) => ! empty($l['reason_code']));
        if (empty($data['reason_code']) && ! $anyLineReason) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'reason_code' => 'A return reason is required (header or per line).',
            ]);
        }

        // GRN must match supplier+branch when both given.
        if (! $forUpdate && ! empty($data['goods_receipt_id'])) {
            $grn = GoodsReceipt::find($data['goods_receipt_id']);
            if ($grn && ((int) $grn->supplier_id !== (int) $data['supplier_id'] || (int) $grn->branch_id !== (int) $data['branch_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'goods_receipt_id' => 'The selected receipt belongs to a different supplier or branch.',
                ]);
            }
        }

        return $data;
    }
}
