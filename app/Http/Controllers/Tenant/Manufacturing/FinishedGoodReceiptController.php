<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Concerns\GeneratesSequentialCode;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\FinishedGoodReceipt;
use App\Models\Tenant\FinishedGoodReceiptLine;
use App\Models\Tenant\ManufacturingCustomer;
use App\Models\Tenant\Product;
use App\Models\Tenant\WipJob;
use App\Models\Tenant\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Finished Goods receipt / production-output tracking (MANUF-6) — tracking only.
 *
 * Records the finished quantity produced by a WIP job (received / accepted /
 * rejected / scrap, with optional batch/lot output lines). It does NOT increase
 * inventory, write a stock ledger entry, post WIP→FG accounting, compute COGS,
 * or create GL journals. Creating a receipt does not change WIP or production
 * order status.
 */
class FinishedGoodReceiptController extends Controller
{
    use GeneratesSequentialCode;

    public function index(Request $request)
    {
        $query = FinishedGoodReceipt::query()
            ->with(['wipJob', 'productionOrder', 'manufacturingCustomer', 'branch', 'finishedProduct']);

        if ($request->filled('q')) {
            $s = trim($request->q);
            $query->where(function ($w) use ($s) {
                $w->where('fg_no', 'like', "%{$s}%")
                  ->orWhereHas('wipJob', fn ($j) => $j->where('wip_no', 'like', "%{$s}%"))
                  ->orWhereHas('productionOrder', fn ($p) => $p->where('order_no', 'like', "%{$s}%"))
                  ->orWhereHas('manufacturingCustomer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('finishedProduct', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('status') && in_array($request->status, FinishedGoodReceipt::STATUSES, true)) {
            $query->where('status', $request->status);
        }
        if ($request->filled('quality_status') && in_array($request->quality_status, FinishedGoodReceipt::QUALITY_STATUSES, true)) {
            $query->where('quality_status', $request->quality_status);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('receipt_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('receipt_date', '<=', $request->date_to);
        }

        $receipts = $query->orderByDesc('receipt_date')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return view('tenant.manufacturing.finished-goods.index', [
            'receipts'        => $receipts,
            'branches'        => Branch::orderBy('name')->get(['id', 'name']),
            'filters'         => $request->only(['q', 'status', 'quality_status', 'branch_id', 'date_from', 'date_to']),
            'statuses'        => FinishedGoodReceipt::STATUSES,
            'qualityStatuses' => FinishedGoodReceipt::QUALITY_STATUSES,
        ]);
    }

    public function create(Request $request)
    {
        $prefill = $this->prefillFromWip($request->input('wip_job_id'));

        return view('tenant.manufacturing.finished-goods.create', $this->formData() + [
            'receipt'                 => null,
            'title'                   => 'Record Finished Goods',
            'nextNo'                  => $this->nextFgNo(),
            'prefill'                 => $prefill['header'],
            'prefillLines'            => $prefill['lines'],
            'selectedWip'             => $prefill['wip_option'],
            'selectedOrder'           => $prefill['order_option'],
            'selectedCustomer'        => $prefill['customer_option'],
            'selectedFinishedProduct' => $prefill['finished_option'],
            'productOptions'          => $prefill['product_options'],
        ]);
    }

    public function store(Request $request)
    {
        if (empty(trim($request->input('fg_no', '')))) {
            $request->merge(['fg_no' => $this->nextFgNo()]);
        }

        $data = $this->validateHeader($request);
        $this->validateLines($request, $data);

        $receipt = FinishedGoodReceipt::create(array_merge($data, ['created_by_user_id' => auth('tenant')->id()]));
        $this->syncLines($receipt, $request->input('lines', []));

        return redirect(url('/manufacturing/finished-goods/' . $receipt->id))->with('status', 'Finished goods receipt recorded.');
    }

    public function show(FinishedGoodReceipt $finishedGoodReceipt)
    {
        $finishedGoodReceipt->load([
            'wipJob', 'productionOrder', 'manufacturingCustomer', 'branch', 'finishedProduct',
            'createdBy', 'lines.finishedProduct', 'lines.unit',
        ]);

        // MFG-FIN-E: build posting readiness context for the view.
        $postingReady  = false;
        $postingReason = null;
        $wipAccumCost  = (float) ($finishedGoodReceipt->wipJob?->accumulated_cost ?? 0);
        $fgUnitCost    = 0.0;
        $fgAccountName = null;
        $wipAccountName = null;

        $settings = app(\App\Services\Manufacturing\ManufacturingPostingService::class)
            ->settings($finishedGoodReceipt->branch_id);

        if (! $settings || ! $settings->is_enabled) {
            $postingReason = 'Manufacturing posting is not enabled. Enable it under Manufacturing → Posting Settings.';
        } elseif (! $settings->isComplete()) {
            $postingReason = 'Posting settings incomplete — map all required accounts under Manufacturing → Posting Settings.';
        } elseif ((float) $finishedGoodReceipt->accepted_quantity <= 0) {
            $postingReason = 'Accepted quantity is zero. Set accepted quantity before posting.';
        } elseif (! $finishedGoodReceipt->finishedProduct?->is_stock_tracked) {
            $postingReason = 'Finished product is not stock-tracked. Enable stock tracking on the product.';
        } elseif ($wipAccumCost <= 0 && (float) ($finishedGoodReceipt->finishedProduct?->default_purchase_price ?? 0) <= 0) {
            $postingReason = 'No WIP accumulated cost and no purchase price on the product. Post related consumptions first or set a purchase price.';
        } else {
            $postingReady = true;
            $acceptedQty  = (float) $finishedGoodReceipt->accepted_quantity;
            $costBasisQty = $finishedGoodReceipt->wipJob && (float) $finishedGoodReceipt->wipJob->planned_quantity > 0
                ? (float) $finishedGoodReceipt->wipJob->planned_quantity
                : $acceptedQty;
            $fgUnitCost   = $wipAccumCost > 0 && $costBasisQty > 0
                ? round($wipAccumCost / $costBasisQty, 4)
                : (float) ($finishedGoodReceipt->finishedProduct?->default_purchase_price ?? 0);

            $fgAccountName  = $settings->finishedGoodsInventoryAccount?->name ?? '1430 Finished Goods';
            $wipAccountName = $settings->wipInventoryAccount?->name ?? '1420 WIP Inventory';
        }

        return view('tenant.manufacturing.finished-goods.show', [
            'receipt'        => $finishedGoodReceipt,
            'postingReady'   => $postingReady,
            'postingReason'  => $postingReason,
            'wipAccumCost'   => $wipAccumCost,
            'fgUnitCost'     => $fgUnitCost,
            'fgAccountName'  => $fgAccountName,
            'wipAccountName' => $wipAccountName,
        ]);
    }

    public function edit(FinishedGoodReceipt $finishedGoodReceipt)
    {
        $finishedGoodReceipt->load(['lines.finishedProduct', 'wipJob', 'productionOrder', 'manufacturingCustomer', 'finishedProduct']);

        return view('tenant.manufacturing.finished-goods.edit', $this->formData() + [
            'receipt'                 => $finishedGoodReceipt,
            'title'                   => 'Edit FG: ' . $finishedGoodReceipt->fg_no,
            'nextNo'                  => null,
            'prefill'                 => [],
            'prefillLines'            => [],
            'selectedWip'             => $this->wipOption($finishedGoodReceipt->wip_job_id),
            'selectedOrder'           => $this->orderOption($finishedGoodReceipt->production_order_id),
            'selectedCustomer'        => $this->customerOption($finishedGoodReceipt->manufacturing_customer_id),
            'selectedFinishedProduct' => $this->productOption($finishedGoodReceipt->finished_product_id),
            'productOptions'          => $this->productOptionsMap($finishedGoodReceipt),
        ]);
    }

    public function update(Request $request, FinishedGoodReceipt $finishedGoodReceipt)
    {
        $data = $this->validateHeader($request, $finishedGoodReceipt);
        $this->validateLines($request, $data);

        $finishedGoodReceipt->update($data);
        $this->syncLines($finishedGoodReceipt, $request->input('lines', []));

        return redirect(url('/manufacturing/finished-goods/' . $finishedGoodReceipt->id))->with('status', 'Finished goods receipt updated.');
    }

    public function destroy(FinishedGoodReceipt $finishedGoodReceipt)
    {
        $finishedGoodReceipt->update(['status' => 'cancelled']);

        return redirect(url('/manufacturing/finished-goods'))->with('status', 'Finished goods receipt cancelled.');
    }

    // ── Validation ──────────────────────────────────────────────────────────

    private function validateHeader(Request $request, ?FinishedGoodReceipt $fg = null): array
    {
        $data = $request->validate([
            'fg_no'                     => ['nullable', 'string', 'max:50',
                                           Rule::unique('finished_good_receipts', 'fg_no')->ignore($fg?->id)],
            'wip_job_id'                => ['required', 'integer', 'exists:wip_jobs,id'],
            'production_order_id'       => ['required', 'integer', 'exists:production_orders,id'],
            'manufacturing_customer_id' => ['nullable', 'integer', 'exists:manufacturing_customers,id'],
            'branch_id'                 => ['required', 'integer', 'exists:branches,id'],
            'finished_product_id'       => ['required', 'integer', 'exists:products,id'],
            'receipt_date'              => ['required', 'date'],
            'status'                    => ['required', Rule::in(FinishedGoodReceipt::STATUSES)],
            'quality_status'            => ['nullable', Rule::in(FinishedGoodReceipt::QUALITY_STATUSES)],
            'planned_quantity'          => ['required', 'numeric', 'min:0.0001'],
            'received_quantity'         => ['required', 'numeric', 'min:0.0001'],
            'accepted_quantity'         => ['nullable', 'numeric', 'min:0'],
            'rejected_quantity'         => ['nullable', 'numeric', 'min:0'],
            'scrap_quantity'            => ['nullable', 'numeric', 'min:0'],
            'priority'                  => ['nullable', Rule::in(FinishedGoodReceipt::PRIORITIES)],
            'notes'                     => ['nullable', 'string', 'max:3000'],
        ]);

        $received = (float) $data['received_quantity'];
        $disposed = (float) ($data['accepted_quantity'] ?? 0)
                  + (float) ($data['rejected_quantity'] ?? 0)
                  + (float) ($data['scrap_quantity'] ?? 0);

        if ($disposed > $received + 0.00005) {
            throw ValidationException::withMessages([
                'accepted_quantity' => 'Accepted + Rejected + Scrap cannot exceed Received quantity.',
            ]);
        }

        // Received cannot exceed the WIP job's planned quantity (you can't produce
        // more finished units than the job planned). WIP/PO statuses are not changed.
        $wip = WipJob::find($data['wip_job_id']);
        if ($wip && $received > (float) $wip->planned_quantity + 0.00005) {
            throw ValidationException::withMessages([
                'received_quantity' => 'Received quantity cannot exceed the WIP job planned quantity (' . rtrim(rtrim(number_format((float) $wip->planned_quantity, 4), '0'), '.') . ').',
            ]);
        }

        return $data;
    }

    private function validateLines(Request $request, array $header): void
    {
        $request->validate([
            'lines'                       => ['nullable', 'array'],
            'lines.*.finished_product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.unit_id'             => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.batch_no'            => ['nullable', 'string', 'max:80'],
            'lines.*.lot_no'              => ['nullable', 'string', 'max:80'],
            'lines.*.received_quantity'   => ['required', 'numeric', 'min:0.0001'],
            'lines.*.accepted_quantity'   => ['nullable', 'numeric', 'min:0'],
            'lines.*.rejected_quantity'   => ['nullable', 'numeric', 'min:0'],
            'lines.*.scrap_quantity'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.expiry_date'         => ['nullable', 'date'],
            'lines.*.notes'               => ['nullable', 'string', 'max:500'],
        ]);

        $lines = $request->input('lines', []);
        $sumReceived = 0.0;

        foreach ($lines as $i => $line) {
            $recv = (float) ($line['received_quantity'] ?? 0);
            $disp = (float) ($line['accepted_quantity'] ?? 0)
                  + (float) ($line['rejected_quantity'] ?? 0)
                  + (float) ($line['scrap_quantity'] ?? 0);
            if ($disp > $recv + 0.00005) {
                throw ValidationException::withMessages([
                    "lines.{$i}.accepted_quantity" => 'Line Accepted + Rejected + Scrap cannot exceed line Received.',
                ]);
            }
            $sumReceived += $recv;
        }

        // Output lines must not exceed the header received quantity.
        if (! empty($lines) && $sumReceived > (float) $header['received_quantity'] + 0.00005) {
            throw ValidationException::withMessages([
                'lines' => 'Total line Received quantity cannot exceed the header Received quantity.',
            ]);
        }
    }

    private function syncLines(FinishedGoodReceipt $receipt, array $lines): void
    {
        $receipt->lines()->delete();

        foreach (array_values($lines) as $i => $line) {
            FinishedGoodReceiptLine::create([
                'finished_good_receipt_id' => $receipt->id,
                'finished_product_id'      => $line['finished_product_id'],
                'unit_id'                  => $line['unit_id'] ?: null,
                'batch_no'                 => $line['batch_no'] ?? null,
                'lot_no'                   => $line['lot_no'] ?? null,
                'received_quantity'        => $line['received_quantity'],
                'accepted_quantity'        => $line['accepted_quantity'] ?? 0,
                'rejected_quantity'        => $line['rejected_quantity'] ?? 0,
                'scrap_quantity'           => $line['scrap_quantity'] ?? 0,
                'expiry_date'              => $line['expiry_date'] ?: null,
                'notes'                    => $line['notes'] ?? null,
                'sort_order'               => $line['sort_order'] ?? $i,
            ]);
        }
    }

    // ── Generate-from-WIP prefill ────────────────────────────────────────────

    private function prefillFromWip($wipJobId): array
    {
        $empty = [
            'header' => [], 'lines' => [], 'wip_option' => null, 'order_option' => null,
            'customer_option' => null, 'finished_option' => null, 'product_options' => [],
        ];

        if (! $wipJobId) {
            return $empty;
        }

        $wip = WipJob::with('finishedProduct')->find($wipJobId);
        if (! $wip) {
            return $empty;
        }

        $planned   = (float) $wip->planned_quantity;
        $remaining = max(0, $planned - (float) $wip->completed_quantity);
        $defaultReceived = $remaining > 0 ? $remaining : $planned;

        $header = array_filter([
            'wip_job_id'                => $wip->id,
            'production_order_id'       => $wip->production_order_id,
            'manufacturing_customer_id' => $wip->manufacturing_customer_id,
            'branch_id'                 => $wip->branch_id,
            'finished_product_id'       => $wip->finished_product_id,
            'planned_quantity'          => $planned,
            'received_quantity'         => $defaultReceived,
            'priority'                  => $wip->priority,
        ], fn ($v) => $v !== null);

        $fpText = $wip->finishedProduct
            ? ($wip->finishedProduct->sku ? $wip->finishedProduct->sku . ' — ' . $wip->finishedProduct->name : $wip->finishedProduct->name)
            : null;

        $lines = [[
            'finished_product_id' => $wip->finished_product_id,
            'unit_id'             => null,
            'batch_no'            => null,
            'lot_no'              => null,
            'received_quantity'   => $defaultReceived,
            'accepted_quantity'   => 0,
            'rejected_quantity'   => 0,
            'scrap_quantity'      => 0,
            'expiry_date'         => null,
            'sort_order'          => 0,
            '_text'               => $fpText,
        ]];

        return [
            'header'           => $header,
            'lines'            => $lines,
            'wip_option'       => $this->wipOption($wip->id),
            'order_option'     => $this->orderOption($wip->production_order_id),
            'customer_option'  => $this->customerOption($wip->manufacturing_customer_id),
            'finished_option'  => $this->productOption($wip->finished_product_id),
            'product_options'  => $wip->finished_product_id && $fpText ? [$wip->finished_product_id => $fpText] : [],
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formData(): array
    {
        return [
            'units'           => Unit::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'branches'        => Branch::orderBy('name')->get(['id', 'name']),
            'statuses'        => FinishedGoodReceipt::STATUSES,
            'qualityStatuses' => FinishedGoodReceipt::QUALITY_STATUSES,
            'priorities'      => FinishedGoodReceipt::PRIORITIES,
        ];
    }

    private function nextFgNo(): string
    {
        return $this->nextSequentialCode(FinishedGoodReceipt::class, 'fg_no', 'FG-', 6);
    }

    private function wipOption($id): ?array
    {
        if (! $id) {
            return null;
        }
        $w = WipJob::with('finishedProduct:id,sku,name')->find($id);
        if (! $w) {
            return null;
        }
        $label = $w->wip_no . ($w->finishedProduct ? ' — ' . ($w->finishedProduct->sku ? $w->finishedProduct->sku . ' ' : '') . $w->finishedProduct->name : '');
        return ['id' => $w->id, 'text' => $label];
    }

    private function orderOption($id): ?array
    {
        if (! $id) {
            return null;
        }
        $o = \App\Models\Tenant\ProductionOrder::with('product:id,sku,name')->find($id);
        if (! $o) {
            return null;
        }
        $label = $o->order_no . ($o->product ? ' — ' . ($o->product->sku ? $o->product->sku . ' ' : '') . $o->product->name : '');
        return ['id' => $o->id, 'text' => $label];
    }

    private function customerOption($id): ?array
    {
        if (! $id) {
            return null;
        }
        $c = ManufacturingCustomer::find($id, ['id', 'code', 'name']);
        return $c ? ['id' => $c->id, 'text' => ($c->code ? $c->code . ' — ' . $c->name : $c->name)] : null;
    }

    private function productOption($id): ?array
    {
        if (! $id) {
            return null;
        }
        $p = Product::find($id, ['id', 'sku', 'name']);
        return $p ? ['id' => $p->id, 'text' => ($p->sku ? $p->sku . ' — ' . $p->name : $p->name)] : null;
    }

    /** Map product_id => "SKU — Name" for output-line products referenced by old() or saved lines. */
    private function productOptionsMap(?FinishedGoodReceipt $fg): array
    {
        $ids = collect(old('lines', []))->pluck('finished_product_id')
            ->merge($fg?->lines->pluck('finished_product_id') ?? collect())
            ->merge([$fg?->finished_product_id])
            ->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Product::whereIn('id', $ids)->get(['id', 'sku', 'name'])
            ->mapWithKeys(fn (Product $p) => [$p->id => ($p->sku ? $p->sku . ' — ' . $p->name : $p->name)])
            ->all();
    }
}
