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

        return view('tenant.purchase-returns.create', [
            'goodsReceipt' => $goodsReceipt,
            'returnable'   => $returnable,
            'branches'     => Branch::where('status', 'active')->orderBy('name')->get(),
            'suppliers'    => Supplier::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'reasonCodes'  => PurchaseReturn::REASON_CODES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        try {
            $return = $this->service->createDraft(
                $data,
                $request->input('lines', []),
                auth('tenant')->id()
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['return' => $e->getMessage()]);
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
