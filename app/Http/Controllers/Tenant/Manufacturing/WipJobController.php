<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Concerns\GeneratesSequentialCode;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ManufacturingCustomer;
use App\Models\Tenant\MaterialRequisition;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductionOrder;
use App\Models\Tenant\Unit;
use App\Models\Tenant\WipJob;
use App\Models\Tenant\WipJobLine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Work in Process / WIP job tracking (MANUF-5) — tracking/planning only.
 *
 * A WIP job tracks an in-process production run (planned vs started vs completed,
 * progress %, and a snapshot of the material lines from the MRC). It does NOT
 * deduct raw-material stock, reserve inventory, post WIP/finished-goods, compute
 * COGS, or create GL journals.
 */
class WipJobController extends Controller
{
    use GeneratesSequentialCode;

    public function index(Request $request)
    {
        $query = WipJob::query()
            ->with(['productionOrder', 'manufacturingCustomer', 'branch', 'finishedProduct']);

        if ($request->filled('q')) {
            $s = trim($request->q);
            $query->where(function ($w) use ($s) {
                $w->where('wip_no', 'like', "%{$s}%")
                  ->orWhereHas('productionOrder', fn ($p) => $p->where('order_no', 'like', "%{$s}%"))
                  ->orWhereHas('manufacturingCustomer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('finishedProduct', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('status') && in_array($request->status, WipJob::STATUSES, true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('start_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('start_date', '<=', $request->date_to);
        }

        $jobs = $query->orderByDesc('start_date')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return view('tenant.manufacturing.wip.index', [
            'jobs'     => $jobs,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'filters'  => $request->only(['q', 'status', 'branch_id', 'date_from', 'date_to']),
            'statuses' => WipJob::STATUSES,
        ]);
    }

    public function create(Request $request)
    {
        $prefill = $this->buildPrefill($request);

        return view('tenant.manufacturing.wip.create', $this->formData() + [
            'job'                     => null,
            'title'                   => 'Create WIP Job',
            'nextNo'                  => $this->nextWipNo(),
            'prefill'                 => $prefill['header'],
            'prefillLines'            => $prefill['lines'],
            'selectedOrder'           => $prefill['order_option'],
            'selectedMrc'             => $prefill['mrc_option'],
            'selectedCustomer'        => $prefill['customer_option'],
            'selectedFinishedProduct' => $prefill['finished_option'],
            'componentOptions'        => $prefill['component_options'],
            'warning'                 => $prefill['warning'],
        ]);
    }

    public function store(Request $request)
    {
        if (empty(trim($request->input('wip_no', '')))) {
            $request->merge(['wip_no' => $this->nextWipNo()]);
        }

        $data = $this->validateHeader($request);
        $this->validateLines($request);

        $job = WipJob::create(array_merge($data, ['created_by_user_id' => auth('tenant')->id()]));
        $job->recalculateProgress();
        $job->save();

        $this->syncLines($job, $request->input('lines', []));

        return redirect(url('/manufacturing/wip/' . $job->id))->with('status', 'WIP job created.');
    }

    public function show(WipJob $wipJob)
    {
        $wipJob->load([
            'productionOrder', 'materialRequisition', 'manufacturingCustomer', 'branch',
            'finishedProduct', 'createdBy', 'lines.componentProduct', 'lines.unit',
        ]);

        return view('tenant.manufacturing.wip.show', ['job' => $wipJob]);
    }

    public function edit(WipJob $wipJob)
    {
        $wipJob->load(['lines.componentProduct', 'productionOrder', 'materialRequisition', 'finishedProduct', 'manufacturingCustomer']);

        return view('tenant.manufacturing.wip.edit', $this->formData() + [
            'job'                     => $wipJob,
            'title'                   => 'Edit WIP: ' . $wipJob->wip_no,
            'nextNo'                  => null,
            'prefill'                 => [],
            'prefillLines'            => [],
            'selectedOrder'           => $this->orderOption($wipJob->production_order_id),
            'selectedMrc'             => $this->mrcOption($wipJob->material_requisition_id),
            'selectedCustomer'        => $this->customerOption($wipJob->manufacturing_customer_id),
            'selectedFinishedProduct' => $this->productOption($wipJob->finished_product_id),
            'componentOptions'        => $this->componentOptionsMap($wipJob),
            'warning'                 => null,
        ]);
    }

    public function update(Request $request, WipJob $wipJob)
    {
        $data = $this->validateHeader($request, $wipJob);
        $this->validateLines($request);

        $wipJob->update($data);
        $wipJob->recalculateProgress();
        $wipJob->save();

        $this->syncLines($wipJob, $request->input('lines', []));

        return redirect(url('/manufacturing/wip/' . $wipJob->id))->with('status', 'WIP job updated.');
    }

    public function destroy(WipJob $wipJob)
    {
        $wipJob->update(['status' => 'cancelled']);

        return redirect(url('/manufacturing/wip'))->with('status', 'WIP job cancelled.');
    }

    // ── Validation ──────────────────────────────────────────────────────────

    private function validateHeader(Request $request, ?WipJob $job = null): array
    {
        $data = $request->validate([
            'wip_no'                    => ['nullable', 'string', 'max:50',
                                           Rule::unique('wip_jobs', 'wip_no')->ignore($job?->id)],
            'production_order_id'       => ['required', 'integer', 'exists:production_orders,id'],
            'material_requisition_id'   => ['nullable', 'integer', 'exists:material_requisitions,id'],
            'manufacturing_customer_id' => ['nullable', 'integer', 'exists:manufacturing_customers,id'],
            'branch_id'                 => ['required', 'integer', 'exists:branches,id'],
            'finished_product_id'       => ['required', 'integer', 'exists:products,id'],
            'planned_quantity'          => ['required', 'numeric', 'min:0.0001'],
            'started_quantity'          => ['nullable', 'numeric', 'min:0'],
            'completed_quantity'        => ['nullable', 'numeric', 'min:0'],
            'start_date'                => ['required', 'date'],
            'target_date'               => ['nullable', 'date', 'after_or_equal:start_date'],
            'status'                    => ['required', Rule::in(WipJob::STATUSES)],
            'priority'                  => ['nullable', Rule::in(WipJob::PRIORITIES)],
            'progress_percent'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'                     => ['nullable', 'string', 'max:3000'],
        ]);

        $planned   = (float) $data['planned_quantity'];
        $started   = (float) ($data['started_quantity'] ?? 0);
        $completed = (float) ($data['completed_quantity'] ?? 0);

        if ($started > $planned) {
            throw ValidationException::withMessages(['started_quantity' => 'Started quantity cannot exceed planned quantity.']);
        }
        if ($completed > $planned) {
            throw ValidationException::withMessages(['completed_quantity' => 'Completed quantity cannot exceed planned quantity.']);
        }

        return $data;
    }

    private function validateLines(Request $request): void
    {
        $request->validate([
            'lines'                                 => ['nullable', 'array'],
            'lines.*.material_requisition_line_id'  => ['nullable', 'integer', 'exists:material_requisition_lines,id'],
            'lines.*.component_product_id'          => ['required', 'integer', 'exists:products,id'],
            'lines.*.unit_id'                       => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.required_quantity'             => ['required', 'numeric', 'min:0'],
            'lines.*.issued_quantity'               => ['nullable', 'numeric', 'min:0'],
            'lines.*.consumed_quantity'             => ['nullable', 'numeric', 'min:0'],
            'lines.*.remaining_quantity'            => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes'                         => ['nullable', 'string', 'max:500'],
        ]);

        $lines = $request->input('lines', []);

        $ids = collect($lines)->pluck('component_product_id');
        if ($ids->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages(['lines' => 'Duplicate component products are not allowed. Combine them into one line.']);
        }

        foreach ($lines as $i => $line) {
            $issued   = (float) ($line['issued_quantity'] ?? 0);
            $consumed = (float) ($line['consumed_quantity'] ?? 0);
            if ($consumed > $issued) {
                throw ValidationException::withMessages([
                    "lines.{$i}.consumed_quantity" => 'Consumed quantity cannot exceed issued quantity.',
                ]);
            }
        }
    }

    private function syncLines(WipJob $job, array $lines): void
    {
        $job->lines()->delete();

        foreach (array_values($lines) as $i => $line) {
            $required = (float) ($line['required_quantity'] ?? 0);
            $consumed = (float) ($line['consumed_quantity'] ?? 0);

            WipJobLine::create([
                'wip_job_id'                   => $job->id,
                'material_requisition_line_id' => $line['material_requisition_line_id'] ?? null,
                'component_product_id'         => $line['component_product_id'],
                'unit_id'                      => $line['unit_id'] ?: null,
                'required_quantity'            => $required,
                'issued_quantity'              => $line['issued_quantity'] ?? 0,
                'consumed_quantity'            => $consumed,
                // remaining is derived from required vs consumed (never stored negative).
                'remaining_quantity'           => max(0, $required - $consumed),
                'sort_order'                   => $line['sort_order'] ?? $i,
                'notes'                        => $line['notes'] ?? null,
            ]);
        }
    }

    // ── Generate-from prefill ────────────────────────────────────────────────

    private function buildPrefill(Request $request): array
    {
        $empty = [
            'header' => [], 'lines' => [], 'order_option' => null, 'mrc_option' => null,
            'customer_option' => null, 'finished_option' => null, 'component_options' => [], 'warning' => null,
        ];

        $mrcId = $request->input('material_requisition_id');
        $poId  = $request->input('production_order_id');

        // Prefer MRC (more specific): gives header from its PO + material lines from the MRC.
        if ($mrcId) {
            $mrc = MaterialRequisition::with(['lines.componentProduct', 'productionOrder.product'])->find($mrcId);
            if (! $mrc) {
                return $empty;
            }
            $order = $mrc->productionOrder;

            return [
                'header' => array_filter([
                    'material_requisition_id'   => $mrc->id,
                    'production_order_id'       => $mrc->production_order_id,
                    'manufacturing_customer_id' => $mrc->manufacturing_customer_id ?: $order?->manufacturing_customer_id,
                    'branch_id'                 => $mrc->branch_id ?: $order?->branch_id,
                    'finished_product_id'       => $order?->product_id,
                    'planned_quantity'          => $order?->planned_quantity,
                    'target_date'               => ($order?->due_date ?: $mrc->required_date)?->toDateString(),
                    'priority'                  => $mrc->priority ?: $order?->priority,
                ], fn ($v) => $v !== null),
                'lines'             => $this->linesFromMrc($mrc),
                'order_option'      => $this->orderOption($mrc->production_order_id),
                'mrc_option'        => $this->mrcOption($mrc->id),
                'customer_option'   => $this->customerOption($mrc->manufacturing_customer_id ?: $order?->manufacturing_customer_id),
                'finished_option'   => $this->productOption($order?->product_id),
                'component_options' => $this->componentOptionsFromMrc($mrc),
                'warning'           => null,
            ];
        }

        if ($poId) {
            $order = ProductionOrder::with('product')->find($poId);
            if (! $order) {
                return $empty;
            }

            // Latest non-cancelled MRC for this production order (if any) supplies lines.
            $mrc = MaterialRequisition::with(['lines.componentProduct'])
                ->where('production_order_id', $order->id)
                ->where('status', '!=', 'cancelled')
                ->orderByDesc('id')
                ->first();

            return [
                'header' => array_filter([
                    'production_order_id'       => $order->id,
                    'material_requisition_id'   => $mrc?->id,
                    'manufacturing_customer_id' => $order->manufacturing_customer_id,
                    'branch_id'                 => $order->branch_id,
                    'finished_product_id'       => $order->product_id,
                    'planned_quantity'          => $order->planned_quantity,
                    'target_date'               => $order->due_date?->toDateString(),
                    'priority'                  => $order->priority,
                ], fn ($v) => $v !== null),
                'lines'             => $mrc ? $this->linesFromMrc($mrc) : [],
                'order_option'      => $this->orderOption($order->id),
                'mrc_option'        => $this->mrcOption($mrc?->id),
                'customer_option'   => $this->customerOption($order->manufacturing_customer_id),
                'finished_option'   => $this->productOption($order->product_id),
                'component_options' => $mrc ? $this->componentOptionsFromMrc($mrc) : [],
                'warning'           => $mrc ? null : 'No material requisition found for this production order. Material lines were not prefilled — you can add them manually.',
            ];
        }

        return $empty;
    }

    private function linesFromMrc(MaterialRequisition $mrc): array
    {
        $lines = [];
        foreach ($mrc->lines as $i => $ml) {
            $required = (float) $ml->required_quantity;
            $issued   = (float) $ml->issued_quantity;
            $lines[] = [
                'material_requisition_line_id' => $ml->id,
                'component_product_id'         => $ml->component_product_id,
                'unit_id'                      => $ml->unit_id,
                'required_quantity'            => $required,
                'issued_quantity'              => $issued,
                'consumed_quantity'            => 0,
                'remaining_quantity'           => $required,
                'sort_order'                   => $i,
                'notes'                        => null,
            ];
        }
        return $lines;
    }

    private function componentOptionsFromMrc(MaterialRequisition $mrc): array
    {
        $map = [];
        foreach ($mrc->lines as $ml) {
            if ($ml->componentProduct) {
                $p = $ml->componentProduct;
                $map[$ml->component_product_id] = $p->sku ? $p->sku . ' — ' . $p->name : $p->name;
            }
        }
        return $map;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formData(): array
    {
        return [
            'units'      => Unit::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'branches'   => Branch::orderBy('name')->get(['id', 'name']),
            'statuses'   => WipJob::STATUSES,
            'priorities' => WipJob::PRIORITIES,
        ];
    }

    private function nextWipNo(): string
    {
        return $this->nextSequentialCode(WipJob::class, 'wip_no', 'WIP-', 6);
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

    private function mrcOption($id): ?array
    {
        if (! $id) {
            return null;
        }
        $m = MaterialRequisition::with('productionOrder:id,order_no')->find($id);
        if (! $m) {
            return null;
        }
        return ['id' => $m->id, 'text' => $m->mrc_no . ($m->productionOrder ? ' — ' . $m->productionOrder->order_no : '')];
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

    /** Map product_id => "SKU — Name" for components referenced by old() input or saved lines. */
    private function componentOptionsMap(?WipJob $job): array
    {
        $ids = collect(old('lines', []))->pluck('component_product_id')
            ->merge($job?->lines->pluck('component_product_id') ?? collect())
            ->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Product::whereIn('id', $ids)->get(['id', 'sku', 'name'])
            ->mapWithKeys(fn (Product $p) => [$p->id => ($p->sku ? $p->sku . ' — ' . $p->name : $p->name)])
            ->all();
    }
}
