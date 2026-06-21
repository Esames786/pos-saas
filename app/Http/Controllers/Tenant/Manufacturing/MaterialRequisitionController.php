<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Concerns\GeneratesSequentialCode;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ManufacturingBom;
use App\Models\Tenant\MaterialRequisition;
use App\Models\Tenant\MaterialRequisitionLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductionOrder;
use App\Models\Tenant\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Material Requisition / MRC (MANUF-4) — request/planning only.
 *
 * An MRC lists the raw materials/components needed for a production run, derived
 * from the finished product's active BOM. It does NOT deduct stock, post a stock
 * ledger entry, post WIP/finished-goods, compute COGS, or create GL journals.
 */
class MaterialRequisitionController extends Controller
{
    use GeneratesSequentialCode;

    public function index(Request $request)
    {
        $query = MaterialRequisition::query()
            ->with(['productionOrder', 'manufacturingCustomer', 'branch'])
            ->withCount('lines');

        if ($request->filled('q')) {
            $s = trim($request->q);
            $query->where(function ($w) use ($s) {
                $w->where('mrc_no', 'like', "%{$s}%")
                  ->orWhereHas('productionOrder', fn ($p) => $p->where('order_no', 'like', "%{$s}%"))
                  ->orWhereHas('manufacturingCustomer', fn ($c) => $c->where('name', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('status') && in_array($request->status, MaterialRequisition::STATUSES, true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('request_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('request_date', '<=', $request->date_to);
        }

        $requisitions = $query->orderByDesc('request_date')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return view('tenant.manufacturing.material-requisitions.index', [
            'requisitions' => $requisitions,
            'branches'     => Branch::orderBy('name')->get(['id', 'name']),
            'filters'      => $request->only(['q', 'status', 'branch_id', 'date_from', 'date_to']),
            'statuses'     => MaterialRequisition::STATUSES,
        ]);
    }

    public function create(Request $request)
    {
        $prefill = $this->prefillFromProductionOrder($request->input('production_order_id'));

        return view('tenant.manufacturing.material-requisitions.create', $this->formData() + [
            'requisition'      => null,
            'title'            => 'Create Material Requisition',
            'nextNo'           => $this->nextMrcNo(),
            'prefill'          => $prefill['header'],
            'prefillLines'     => $prefill['lines'],
            'selectedOrder'    => $prefill['order_option'],
            'selectedCustomer' => $prefill['customer_option'],
            'componentOptions' => $prefill['component_options'],
            'bomWarning'       => $prefill['warning'],
        ]);
    }

    public function store(Request $request)
    {
        if (empty(trim($request->input('mrc_no', '')))) {
            $request->merge(['mrc_no' => $this->nextMrcNo()]);
        }

        $data = $this->validateHeader($request);
        $this->validateLines($request);

        $requisition = MaterialRequisition::create(array_merge(
            $data,
            ['created_by_user_id' => auth('tenant')->id()]
        ));

        $this->syncLines($requisition, $request->input('lines', []));

        return redirect(url('/manufacturing/material-requisitions/' . $requisition->id))
            ->with('status', 'Material requisition created.');
    }

    public function show(MaterialRequisition $materialRequisition)
    {
        $materialRequisition->load([
            'productionOrder.product', 'manufacturingCustomer', 'branch', 'createdBy',
            'lines.componentProduct', 'lines.unit',
        ]);

        return view('tenant.manufacturing.material-requisitions.show', [
            'requisition' => $materialRequisition,
        ]);
    }

    public function edit(MaterialRequisition $materialRequisition)
    {
        $materialRequisition->load(['lines.componentProduct', 'manufacturingCustomer', 'productionOrder.product']);

        return view('tenant.manufacturing.material-requisitions.edit', $this->formData() + [
            'requisition'      => $materialRequisition,
            'title'            => 'Edit MRC: ' . $materialRequisition->mrc_no,
            'nextNo'           => null,
            'prefill'          => [],
            'prefillLines'     => [],
            'selectedOrder'    => $this->orderOption($materialRequisition->production_order_id),
            'selectedCustomer' => $this->customerOption($materialRequisition->manufacturing_customer_id),
            'componentOptions' => $this->componentOptionsMap($materialRequisition),
            'bomWarning'       => null,
        ]);
    }

    public function update(Request $request, MaterialRequisition $materialRequisition)
    {
        $data = $this->validateHeader($request, $materialRequisition);
        $this->validateLines($request);

        $materialRequisition->update($data);
        $this->syncLines($materialRequisition, $request->input('lines', []));

        return redirect(url('/manufacturing/material-requisitions/' . $materialRequisition->id))
            ->with('status', 'Material requisition updated.');
    }

    public function destroy(MaterialRequisition $materialRequisition)
    {
        $materialRequisition->update(['status' => 'cancelled']);

        return redirect(url('/manufacturing/material-requisitions'))
            ->with('status', 'Material requisition cancelled.');
    }

    // ── Validation ──────────────────────────────────────────────────────────

    private function validateHeader(Request $request, ?MaterialRequisition $mrc = null): array
    {
        return $request->validate([
            'mrc_no'                    => ['nullable', 'string', 'max:50',
                                           Rule::unique('material_requisitions', 'mrc_no')->ignore($mrc?->id)],
            'production_order_id'       => ['nullable', 'integer', 'exists:production_orders,id'],
            'manufacturing_customer_id' => ['nullable', 'integer', 'exists:manufacturing_customers,id'],
            'branch_id'                 => ['required', 'integer', 'exists:branches,id'],
            'request_date'              => ['required', 'date'],
            'required_date'             => ['nullable', 'date', 'after_or_equal:request_date'],
            'status'                    => ['required', Rule::in(MaterialRequisition::STATUSES)],
            'priority'                  => ['nullable', Rule::in(MaterialRequisition::PRIORITIES)],
            'notes'                     => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function validateLines(Request $request): void
    {
        $request->validate([
            'lines'                        => ['required', 'array', 'min:1'],
            'lines.*.component_product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.unit_id'              => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.required_quantity'    => ['required', 'numeric', 'min:0.0001'],
            'lines.*.issued_quantity'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.wastage_percent'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.source_bom_line_id'   => ['nullable', 'integer', 'exists:manufacturing_bom_lines,id'],
            'lines.*.notes'                => ['nullable', 'string', 'max:500'],
        ]);

        $lines = $request->input('lines', []);

        // Duplicate components are not allowed (merge into one line instead).
        $ids = collect($lines)->pluck('component_product_id');
        if ($ids->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages([
                'lines' => 'Duplicate component products are not allowed. Combine them into one line.',
            ]);
        }

        // Issued cannot exceed required on any line.
        foreach ($lines as $i => $line) {
            $required = (float) ($line['required_quantity'] ?? 0);
            $issued   = (float) ($line['issued_quantity'] ?? 0);
            if ($issued > $required) {
                throw ValidationException::withMessages([
                    "lines.{$i}.issued_quantity" => 'Issued quantity cannot exceed required quantity.',
                ]);
            }
        }
    }

    private function syncLines(MaterialRequisition $mrc, array $lines): void
    {
        $mrc->lines()->delete();

        foreach (array_values($lines) as $i => $line) {
            MaterialRequisitionLine::create([
                'material_requisition_id' => $mrc->id,
                'component_product_id'    => $line['component_product_id'],
                'unit_id'                 => $line['unit_id'] ?: null,
                'required_quantity'       => $line['required_quantity'],
                'issued_quantity'         => $line['issued_quantity'] ?? 0,
                'wastage_percent'         => $line['wastage_percent'] ?? 0,
                'source_bom_line_id'      => $line['source_bom_line_id'] ?? null,
                'sort_order'              => $line['sort_order'] ?? $i,
                'notes'                   => $line['notes'] ?? null,
            ]);
        }
    }

    // ── Generate-from-production-order prefill ───────────────────────────────

    /**
     * Build header + line prefill from a production order and its active BOM.
     * Returns empty prefill (with optional warning) when not applicable.
     */
    private function prefillFromProductionOrder($productionOrderId): array
    {
        $empty = [
            'header' => [], 'lines' => [], 'order_option' => null,
            'customer_option' => null, 'component_options' => [], 'warning' => null,
        ];

        if (! $productionOrderId) {
            return $empty;
        }

        $order = ProductionOrder::with('product')->find($productionOrderId);
        if (! $order) {
            return $empty;
        }

        $header = [
            'production_order_id'       => $order->id,
            'manufacturing_customer_id' => $order->manufacturing_customer_id,
            'branch_id'                 => $order->branch_id,
            'required_date'             => $order->due_date?->toDateString(),
            'priority'                  => $order->priority,
        ];

        $bom = ManufacturingBom::active()
            ->where('finished_product_id', $order->product_id)
            ->with(['lines.componentProduct'])
            ->orderByDesc('id')
            ->first();

        if (! $bom) {
            return array_merge($empty, [
                'header'          => $header,
                'order_option'    => $this->orderOption($order->id),
                'customer_option' => $this->customerOption($order->manufacturing_customer_id),
                'warning'         => 'No active BOM found for this finished product. You can still create the MRC manually.',
            ]);
        }

        $output = (float) ($bom->output_quantity ?: 1);
        $factor = $output > 0 ? ((float) $order->planned_quantity / $output) : 0;

        $lines            = [];
        $componentOptions = [];
        foreach ($bom->lines as $i => $bl) {
            $base     = $factor * (float) $bl->quantity;
            $required = round($base * (1 + ((float) $bl->wastage_percent / 100)), 4);

            $lines[] = [
                'component_product_id' => $bl->component_product_id,
                'unit_id'              => $bl->unit_id,
                'required_quantity'    => $required,
                'issued_quantity'      => 0,
                'wastage_percent'      => (float) $bl->wastage_percent,
                'source_bom_line_id'   => $bl->id,
                'sort_order'           => $i,
                'notes'                => null,
            ];

            if ($bl->componentProduct) {
                $p = $bl->componentProduct;
                $componentOptions[$bl->component_product_id] = $p->sku ? $p->sku . ' — ' . $p->name : $p->name;
            }
        }

        return [
            'header'            => $header,
            'lines'             => $lines,
            'order_option'      => $this->orderOption($order->id),
            'customer_option'   => $this->customerOption($order->manufacturing_customer_id),
            'component_options' => $componentOptions,
            'warning'           => null,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formData(): array
    {
        return [
            'units'      => Unit::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'branches'   => Branch::orderBy('name')->get(['id', 'name']),
            'statuses'   => MaterialRequisition::STATUSES,
            'priorities' => MaterialRequisition::PRIORITIES,
        ];
    }

    private function nextMrcNo(): string
    {
        return $this->nextSequentialCode(MaterialRequisition::class, 'mrc_no', 'MRC-', 6);
    }

    private function orderOption($id): ?array
    {
        if (! $id) {
            return null;
        }
        $o = ProductionOrder::with('product:id,sku,name')->find($id);
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
        $c = \App\Models\Tenant\ManufacturingCustomer::find($id, ['id', 'code', 'name']);
        return $c ? ['id' => $c->id, 'text' => ($c->code ? $c->code . ' — ' . $c->name : $c->name)] : null;
    }

    /** Map product_id => "SKU — Name" for components referenced by old() input or saved lines. */
    private function componentOptionsMap(?MaterialRequisition $mrc): array
    {
        $ids = collect(old('lines', []))->pluck('component_product_id')
            ->merge($mrc?->lines->pluck('component_product_id') ?? collect())
            ->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Product::whereIn('id', $ids)->get(['id', 'sku', 'name'])
            ->mapWithKeys(fn (Product $p) => [$p->id => ($p->sku ? $p->sku . ' — ' . $p->name : $p->name)])
            ->all();
    }
}
