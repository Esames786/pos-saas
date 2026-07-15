<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Concerns\GeneratesSequentialCode;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ManufacturingConsumptionLine;
use App\Models\Tenant\ManufacturingConsumptionRecord;
use App\Models\Tenant\ManufacturingCustomer;
use App\Models\Tenant\MaterialRequisition;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductionOrder;
use App\Models\Tenant\ManufacturingPostingSetting;
use App\Models\Tenant\Unit;
use App\Models\Tenant\WipJob;
use App\Services\Manufacturing\ManufacturingPostingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manufacturing Consumption / component usage tracking (MANUF-9) — tracking only.
 *
 * Records planned vs consumed material quantities (with wastage and variance)
 * from a WIP job, a material requisition, or manually. It does NOT deduct/adjust
 * inventory, write a stock ledger entry, post raw-material issue, post material
 * consumption / WIP variance accounting, compute COGS, or create GL journals. It
 * does NOT mutate WIP lines, MRC lines, or any related record's status.
 */
class ManufacturingConsumptionController extends Controller
{
    use GeneratesSequentialCode;

    public function index(Request $request)
    {
        $query = ManufacturingConsumptionRecord::query()
            ->with(['wipJob', 'materialRequisition', 'productionOrder', 'manufacturingCustomer', 'branch']);

        if ($request->filled('q')) {
            $s = trim($request->q);
            $query->where(function ($w) use ($s) {
                $w->where('consumption_no', 'like', "%{$s}%")
                  ->orWhereHas('wipJob', fn ($j) => $j->where('wip_no', 'like', "%{$s}%"))
                  ->orWhereHas('materialRequisition', fn ($m) => $m->where('mrc_no', 'like', "%{$s}%"))
                  ->orWhereHas('productionOrder', fn ($p) => $p->where('order_no', 'like', "%{$s}%"))
                  ->orWhereHas('manufacturingCustomer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('lines.componentProduct', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
            });
        }

        foreach (['status' => ManufacturingConsumptionRecord::STATUSES, 'source_type' => ManufacturingConsumptionRecord::SOURCE_TYPES,
                  'consumption_type' => ManufacturingConsumptionRecord::CONSUMPTION_TYPES] as $field => $allowed) {
            if ($request->filled($field) && in_array($request->input($field), $allowed, true)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('consumption_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('consumption_date', '<=', $request->date_to);
        }

        $records = $query->orderByDesc('consumption_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('tenant.manufacturing.consumption.index', [
            'records'          => $records,
            'branches'         => Branch::orderBy('name')->get(['id', 'name']),
            'filters'          => $request->only(['q', 'status', 'source_type', 'consumption_type', 'branch_id', 'date_from', 'date_to']),
            'statuses'         => ManufacturingConsumptionRecord::STATUSES,
            'sourceTypes'      => ManufacturingConsumptionRecord::SOURCE_TYPES,
            'consumptionTypes' => ManufacturingConsumptionRecord::CONSUMPTION_TYPES,
        ]);
    }

    public function create(Request $request)
    {
        $prefill = $this->buildPrefill($request);

        return view('tenant.manufacturing.consumption.create', $this->formData() + [
            'record'           => null,
            'title'            => 'Record Consumption',
            'nextNo'           => $this->nextConsumptionNo(),
            'prefill'          => $prefill['header'],
            'prefillLines'     => $prefill['lines'],
            'selectedWip'      => $prefill['wip_option'],
            'selectedMrc'      => $prefill['mrc_option'],
            'selectedOrder'    => $prefill['order_option'],
            'selectedCustomer' => $prefill['customer_option'],
            'productOptions'   => $prefill['product_options'],
            'warning'          => $prefill['warning'],
        ]);
    }

    public function store(Request $request)
    {
        if (empty(trim($request->input('consumption_no', '')))) {
            $request->merge(['consumption_no' => $this->nextConsumptionNo()]);
        }

        $data  = $this->validateHeader($request);
        $lines = $this->validateLines($request);
        $data  = $this->applyLineTotals($data, $lines);

        $record = ManufacturingConsumptionRecord::create(array_merge($data, ['created_by_user_id' => auth('tenant')->id()]));
        $this->syncLines($record, $lines);

        return redirect(url('/manufacturing/consumption/' . $record->id))->with('status', 'Consumption record created.');
    }

    public function show(ManufacturingConsumptionRecord $manufacturingConsumptionRecord, ManufacturingPostingService $posting)
    {
        $manufacturingConsumptionRecord->load([
            'wipJob', 'materialRequisition', 'productionOrder', 'manufacturingCustomer', 'branch',
            'createdBy', 'lines.componentProduct', 'lines.unit',
        ]);

        $settings = $posting->settings($manufacturingConsumptionRecord->branch_id);

        return view('tenant.manufacturing.consumption.show', [
            'record'        => $manufacturingConsumptionRecord,
            'postingReady'  => (bool) ($settings && $settings->canPost()),
            'postingReason' => $this->postingBlockReason($settings),
            'postedTotal'   => (float) $manufacturingConsumptionRecord->lines->sum('actual_total_cost'),
        ]);
    }

    /** Human-readable reason posting is unavailable (null when it is ready). */
    private function postingBlockReason(?ManufacturingPostingSetting $settings): ?string
    {
        if (! $settings) {
            return 'Manufacturing posting settings are not configured.';
        }
        if (! $settings->is_enabled) {
            return 'Manufacturing posting is disabled in settings.';
        }
        if (! $settings->isComplete()) {
            return 'Posting settings are incomplete — required accounts are not mapped.';
        }

        return null;
    }

    public function edit(ManufacturingConsumptionRecord $manufacturingConsumptionRecord)
    {
        if (! $manufacturingConsumptionRecord->isUnposted()) {
            return redirect(url('/manufacturing/consumption/' . $manufacturingConsumptionRecord->id))
                ->withErrors(['posting' => 'Posted or reversed consumption records are immutable. Reverse the posting before creating a correcting record.']);
        }

        $manufacturingConsumptionRecord->load(['lines.componentProduct', 'wipJob', 'materialRequisition', 'productionOrder', 'manufacturingCustomer']);

        return view('tenant.manufacturing.consumption.edit', $this->formData() + [
            'record'           => $manufacturingConsumptionRecord,
            'title'            => 'Edit Consumption: ' . $manufacturingConsumptionRecord->consumption_no,
            'nextNo'           => null,
            'prefill'          => [],
            'prefillLines'     => [],
            'selectedWip'      => $this->wipOption($manufacturingConsumptionRecord->wip_job_id),
            'selectedMrc'      => $this->mrcOption($manufacturingConsumptionRecord->material_requisition_id),
            'selectedOrder'    => $this->orderOption($manufacturingConsumptionRecord->production_order_id),
            'selectedCustomer' => $this->customerOption($manufacturingConsumptionRecord->manufacturing_customer_id),
            'productOptions'   => $this->productOptionsMap($manufacturingConsumptionRecord),
            'warning'          => null,
        ]);
    }

    public function update(Request $request, ManufacturingConsumptionRecord $manufacturingConsumptionRecord)
    {
        if (! $manufacturingConsumptionRecord->isUnposted()) {
            return redirect(url('/manufacturing/consumption/' . $manufacturingConsumptionRecord->id))
                ->withErrors(['posting' => 'Posted or reversed consumption records cannot be edited.']);
        }

        $data  = $this->validateHeader($request, $manufacturingConsumptionRecord);
        $lines = $this->validateLines($request);
        $data  = $this->applyLineTotals($data, $lines);

        $manufacturingConsumptionRecord->update($data);
        $this->syncLines($manufacturingConsumptionRecord, $lines);

        return redirect(url('/manufacturing/consumption/' . $manufacturingConsumptionRecord->id))->with('status', 'Consumption record updated.');
    }

    public function destroy(ManufacturingConsumptionRecord $manufacturingConsumptionRecord)
    {
        if (! $manufacturingConsumptionRecord->isUnposted()) {
            return redirect(url('/manufacturing/consumption/' . $manufacturingConsumptionRecord->id))
                ->withErrors(['posting' => 'Posted or reversed consumption records cannot be cancelled.']);
        }

        $manufacturingConsumptionRecord->update(['status' => 'cancelled']);

        return redirect(url('/manufacturing/consumption'))->with('status', 'Consumption record cancelled.');
    }

    // ── Validation ──────────────────────────────────────────────────────────

    private function validateHeader(Request $request, ?ManufacturingConsumptionRecord $cons = null): array
    {
        return $request->validate([
            'consumption_no'              => ['nullable', 'string', 'max:50',
                                             Rule::unique('manufacturing_consumption_records', 'consumption_no')->ignore($cons?->id)],
            'consumption_date'            => ['required', 'date'],
            'source_type'                 => ['nullable', Rule::in(ManufacturingConsumptionRecord::SOURCE_TYPES)],
            'wip_job_id'                  => ['nullable', 'integer', 'exists:wip_jobs,id'],
            'material_requisition_id'     => ['nullable', 'integer', 'exists:material_requisitions,id'],
            'production_order_id'         => ['nullable', 'integer', 'exists:production_orders,id'],
            'manufacturing_customer_id'   => ['nullable', 'integer', 'exists:manufacturing_customers,id'],
            'branch_id'                   => ['required', 'integer', 'exists:branches,id'],
            'status'                      => ['required', Rule::in(ManufacturingConsumptionRecord::STATUSES)],
            'consumption_type'            => ['required', Rule::in(ManufacturingConsumptionRecord::CONSUMPTION_TYPES)],
            'issue_reference'             => ['nullable', 'string', 'max:100'],
            'total_planned_quantity'      => ['required', 'numeric', 'min:0'],
            'total_consumed_quantity'     => ['required', 'numeric', 'min:0'],
            'total_wastage_quantity'      => ['nullable', 'numeric', 'min:0'],
            'total_variance_quantity'     => ['nullable', 'numeric'],
            'estimated_consumption_value' => ['nullable', 'numeric', 'min:0'],
            'notes'                       => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function validateLines(Request $request): array
    {
        $request->validate([
            'lines'                                => ['nullable', 'array'],
            'lines.*.wip_job_line_id'              => ['nullable', 'integer', 'exists:wip_job_lines,id'],
            'lines.*.material_requisition_line_id' => ['nullable', 'integer', 'exists:material_requisition_lines,id'],
            'lines.*.component_product_id'         => ['required', 'integer', 'exists:products,id'],
            'lines.*.unit_id'                      => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.planned_quantity'             => ['nullable', 'numeric', 'min:0'],
            'lines.*.consumed_quantity'            => ['required', 'numeric', 'min:0.0001'],
            'lines.*.wastage_quantity'             => ['nullable', 'numeric', 'min:0'],
            'lines.*.estimated_unit_cost'          => ['nullable', 'numeric', 'min:0'],
            'lines.*.estimated_total_value'        => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_no'                     => ['nullable', 'string', 'max:80'],
            'lines.*.lot_no'                       => ['nullable', 'string', 'max:80'],
            'lines.*.notes'                        => ['nullable', 'string', 'max:500'],
        ]);

        $lines = $request->input('lines', []);

        foreach ($lines as $i => $line) {
            $consumed = (float) ($line['consumed_quantity'] ?? 0);
            $wastage  = (float) ($line['wastage_quantity'] ?? 0);
            if ($wastage > $consumed + 0.00005) {
                throw ValidationException::withMessages([
                    "lines.{$i}.wastage_quantity" => 'Wastage quantity cannot exceed consumed quantity.',
                ]);
            }
        }

        return $lines;
    }

    /** When lines exist, header totals are auto-calculated from the lines. */
    private function applyLineTotals(array $data, array $lines): array
    {
        if (empty($lines)) {
            // Manual header: derive variance from header planned/consumed.
            $data['total_variance_quantity'] = round((float) ($data['total_consumed_quantity'] ?? 0) - (float) ($data['total_planned_quantity'] ?? 0), 4);
            return $data;
        }

        $col = fn (string $k) => collect($lines)->sum(fn ($l) => (float) ($l[$k] ?? 0));

        $planned  = round($col('planned_quantity'), 4);
        $consumed = round($col('consumed_quantity'), 4);

        $data['total_planned_quantity']  = $planned;
        $data['total_consumed_quantity'] = $consumed;
        $data['total_wastage_quantity']  = round($col('wastage_quantity'), 4);
        $data['total_variance_quantity'] = round($consumed - $planned, 4);
        $est = round($col('estimated_total_value'), 4);
        $data['estimated_consumption_value'] = $est > 0 ? $est : ($data['estimated_consumption_value'] ?? null);

        return $data;
    }

    private function syncLines(ManufacturingConsumptionRecord $record, array $lines): void
    {
        $record->lines()->delete();

        foreach (array_values($lines) as $i => $line) {
            $planned  = (float) ($line['planned_quantity'] ?? 0);
            $consumed = (float) ($line['consumed_quantity'] ?? 0);

            ManufacturingConsumptionLine::create([
                'manufacturing_consumption_record_id' => $record->id,
                'wip_job_line_id'                     => $line['wip_job_line_id'] ?? null,
                'material_requisition_line_id'        => $line['material_requisition_line_id'] ?? null,
                'component_product_id'                => $line['component_product_id'],
                'unit_id'                             => $line['unit_id'] ?: null,
                'planned_quantity'                    => $planned,
                'consumed_quantity'                   => $consumed,
                'wastage_quantity'                    => $line['wastage_quantity'] ?? 0,
                // variance is derived (consumed − planned), never trusted from input.
                'variance_quantity'                   => round($consumed - $planned, 4),
                'estimated_unit_cost'                 => $line['estimated_unit_cost'] ?? null,
                'estimated_total_value'               => $line['estimated_total_value'] ?? null,
                'batch_no'                            => $line['batch_no'] ?? null,
                'lot_no'                              => $line['lot_no'] ?? null,
                'notes'                               => $line['notes'] ?? null,
                'sort_order'                          => $line['sort_order'] ?? $i,
            ]);
        }
    }

    // ── Generate-from prefill ────────────────────────────────────────────────

    private function buildPrefill(Request $request): array
    {
        $empty = [
            'header' => [], 'lines' => [], 'wip_option' => null, 'mrc_option' => null,
            'order_option' => null, 'customer_option' => null, 'product_options' => [], 'warning' => null,
        ];

        $wipId = $request->input('wip_job_id');
        $mrcId = $request->input('material_requisition_id');

        // Prefer WIP source (it carries MRC linkage on its lines).
        if ($wipId) {
            $wip = WipJob::with(['lines.componentProduct'])->find($wipId);
            if (! $wip) {
                return $empty;
            }

            $productOptions = [];
            $lines = [];
            foreach ($wip->lines as $i => $wl) {
                $planned = (float) $wl->required_quantity;
                $lines[] = [
                    'wip_job_line_id'              => $wl->id,
                    'material_requisition_line_id' => $wl->material_requisition_line_id,
                    'component_product_id'         => $wl->component_product_id,
                    'unit_id'                      => $wl->unit_id,
                    'planned_quantity'             => $planned,
                    'consumed_quantity'            => 0,
                    'wastage_quantity'             => 0,
                    'sort_order'                   => $i,
                    '_text'                        => $wl->componentProduct
                        ? ($wl->componentProduct->sku ? $wl->componentProduct->sku . ' — ' . $wl->componentProduct->name : $wl->componentProduct->name)
                        : null,
                ];
                if ($wl->componentProduct) {
                    $productOptions[$wl->component_product_id] = $lines[$i]['_text'];
                }
            }

            return [
                'header' => array_filter([
                    'source_type'               => 'wip',
                    'wip_job_id'                => $wip->id,
                    'material_requisition_id'   => $wip->material_requisition_id,
                    'production_order_id'       => $wip->production_order_id,
                    'manufacturing_customer_id' => $wip->manufacturing_customer_id,
                    'branch_id'                 => $wip->branch_id,
                    'consumption_type'          => 'production_usage',
                ], fn ($v) => $v !== null),
                'lines'           => $lines,
                'wip_option'      => $this->wipOption($wip->id),
                'mrc_option'      => $this->mrcOption($wip->material_requisition_id),
                'order_option'    => $this->orderOption($wip->production_order_id),
                'customer_option' => $this->customerOption($wip->manufacturing_customer_id),
                'product_options' => $productOptions,
                'warning'         => $wip->lines->isEmpty() ? 'This WIP job has no material lines. You can add consumption lines manually.' : null,
            ];
        }

        if ($mrcId) {
            $mrc = MaterialRequisition::with(['lines.componentProduct'])->find($mrcId);
            if (! $mrc) {
                return $empty;
            }

            $productOptions = [];
            $lines = [];
            foreach ($mrc->lines as $i => $ml) {
                $planned = (float) $ml->required_quantity;
                $lines[] = [
                    'material_requisition_line_id' => $ml->id,
                    'component_product_id'         => $ml->component_product_id,
                    'unit_id'                      => $ml->unit_id,
                    'planned_quantity'             => $planned,
                    'consumed_quantity'            => 0,
                    'wastage_quantity'             => 0,
                    'sort_order'                   => $i,
                    '_text'                        => $ml->componentProduct
                        ? ($ml->componentProduct->sku ? $ml->componentProduct->sku . ' — ' . $ml->componentProduct->name : $ml->componentProduct->name)
                        : null,
                ];
                if ($ml->componentProduct) {
                    $productOptions[$ml->component_product_id] = $lines[$i]['_text'];
                }
            }

            return [
                'header' => array_filter([
                    'source_type'               => 'material_requisition',
                    'material_requisition_id'   => $mrc->id,
                    'production_order_id'       => $mrc->production_order_id,
                    'manufacturing_customer_id' => $mrc->manufacturing_customer_id,
                    'branch_id'                 => $mrc->branch_id,
                    'consumption_type'          => 'production_usage',
                ], fn ($v) => $v !== null),
                'lines'           => $lines,
                'wip_option'      => null,
                'mrc_option'      => $this->mrcOption($mrc->id),
                'order_option'    => $this->orderOption($mrc->production_order_id),
                'customer_option' => $this->customerOption($mrc->manufacturing_customer_id),
                'product_options' => $productOptions,
                'warning'         => $mrc->lines->isEmpty() ? 'This requisition has no component lines. You can add consumption lines manually.' : null,
            ];
        }

        return $empty;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formData(): array
    {
        return [
            'units'            => Unit::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'branches'         => Branch::orderBy('name')->get(['id', 'name']),
            'statuses'         => ManufacturingConsumptionRecord::STATUSES,
            'sourceTypes'      => ManufacturingConsumptionRecord::SOURCE_TYPES,
            'consumptionTypes' => ManufacturingConsumptionRecord::CONSUMPTION_TYPES,
        ];
    }

    private function nextConsumptionNo(): string
    {
        return $this->nextSequentialCode(ManufacturingConsumptionRecord::class, 'consumption_no', 'CONS-', 6);
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
        $c = ManufacturingCustomer::find($id, ['id', 'code', 'name']);
        return $c ? ['id' => $c->id, 'text' => ($c->code ? $c->code . ' — ' . $c->name : $c->name)] : null;
    }

    /** Map product_id => "SKU — Name" for component products referenced by old() or saved lines. */
    private function productOptionsMap(?ManufacturingConsumptionRecord $record): array
    {
        $ids = collect(old('lines', []))->pluck('component_product_id')
            ->merge($record?->lines->pluck('component_product_id') ?? collect())
            ->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Product::whereIn('id', $ids)->get(['id', 'sku', 'name'])
            ->mapWithKeys(fn (Product $p) => [$p->id => ($p->sku ? $p->sku . ' — ' . $p->name : $p->name)])
            ->all();
    }
}
