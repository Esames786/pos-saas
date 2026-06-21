<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Concerns\GeneratesSequentialCode;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\FinishedGoodReceipt;
use App\Models\Tenant\ManufacturingCustomer;
use App\Models\Tenant\ManufacturingRejectionLine;
use App\Models\Tenant\ManufacturingRejectionRecord;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductionOrder;
use App\Models\Tenant\Unit;
use App\Models\Tenant\WipJob;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manufacturing Rejections / QC rejection tracking (MANUF-8) — tracking only.
 *
 * Records rejected quantity, defect reason, severity and disposition (rework /
 * scrap / accept-after-review / disposed) from a WIP job, a finished goods
 * receipt, or manually. It does NOT deduct/adjust inventory, write a stock
 * ledger entry, create a Scrap record, post rejection/rework expense, post WIP
 * variance, compute COGS, or create GL journals. It does not change WIP /
 * Finished Goods / Production Order status.
 */
class ManufacturingRejectionController extends Controller
{
    use GeneratesSequentialCode;

    public function index(Request $request)
    {
        $query = ManufacturingRejectionRecord::query()
            ->with(['wipJob', 'finishedGoodReceipt', 'productionOrder', 'manufacturingCustomer', 'branch']);

        if ($request->filled('q')) {
            $s = trim($request->q);
            $query->where(function ($w) use ($s) {
                $w->where('rejection_no', 'like', "%{$s}%")
                  ->orWhereHas('wipJob', fn ($j) => $j->where('wip_no', 'like', "%{$s}%"))
                  ->orWhereHas('finishedGoodReceipt', fn ($f) => $f->where('fg_no', 'like', "%{$s}%"))
                  ->orWhereHas('productionOrder', fn ($p) => $p->where('order_no', 'like', "%{$s}%"))
                  ->orWhereHas('manufacturingCustomer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('lines.product', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
            });
        }

        foreach (['status' => ManufacturingRejectionRecord::STATUSES, 'source_type' => ManufacturingRejectionRecord::SOURCE_TYPES,
                  'rejection_type' => ManufacturingRejectionRecord::REJECTION_TYPES, 'severity' => ManufacturingRejectionRecord::SEVERITIES,
                  'disposition' => ManufacturingRejectionRecord::DISPOSITIONS, 'reason_code' => ManufacturingRejectionRecord::REASON_CODES] as $field => $allowed) {
            if ($request->filled($field) && in_array($request->input($field), $allowed, true)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('rejection_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('rejection_date', '<=', $request->date_to);
        }

        $records = $query->orderByDesc('rejection_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('tenant.manufacturing.rejections.index', [
            'records'        => $records,
            'branches'       => Branch::orderBy('name')->get(['id', 'name']),
            'filters'        => $request->only(['q', 'status', 'source_type', 'rejection_type', 'severity', 'disposition', 'reason_code', 'branch_id', 'date_from', 'date_to']),
            'statuses'       => ManufacturingRejectionRecord::STATUSES,
            'sourceTypes'    => ManufacturingRejectionRecord::SOURCE_TYPES,
            'rejectionTypes' => ManufacturingRejectionRecord::REJECTION_TYPES,
            'severities'     => ManufacturingRejectionRecord::SEVERITIES,
            'dispositions'   => ManufacturingRejectionRecord::DISPOSITIONS,
            'reasonCodes'    => ManufacturingRejectionRecord::REASON_CODES,
        ]);
    }

    public function create(Request $request)
    {
        $prefill = $this->buildPrefill($request);

        return view('tenant.manufacturing.rejections.create', $this->formData() + [
            'record'           => null,
            'title'            => 'Record Rejection',
            'nextNo'           => $this->nextRejectionNo(),
            'prefill'          => $prefill['header'],
            'prefillLines'     => $prefill['lines'],
            'selectedWip'      => $prefill['wip_option'],
            'selectedFg'       => $prefill['fg_option'],
            'selectedOrder'    => $prefill['order_option'],
            'selectedCustomer' => $prefill['customer_option'],
            'productOptions'   => $prefill['product_options'],
            'warning'          => $prefill['warning'],
        ]);
    }

    public function store(Request $request)
    {
        if (empty(trim($request->input('rejection_no', '')))) {
            $request->merge(['rejection_no' => $this->nextRejectionNo()]);
        }

        $data  = $this->validateHeader($request);
        $lines = $this->validateLines($request);
        $data  = $this->applyLineTotals($data, $lines);

        $record = ManufacturingRejectionRecord::create(array_merge($data, ['created_by_user_id' => auth('tenant')->id()]));
        $this->syncLines($record, $lines);

        return redirect(url('/manufacturing/rejections/' . $record->id))->with('status', 'Rejection record created.');
    }

    public function show(ManufacturingRejectionRecord $manufacturingRejectionRecord)
    {
        $manufacturingRejectionRecord->load([
            'wipJob', 'finishedGoodReceipt', 'productionOrder', 'manufacturingCustomer', 'branch',
            'createdBy', 'lines.product', 'lines.unit',
        ]);

        return view('tenant.manufacturing.rejections.show', ['record' => $manufacturingRejectionRecord]);
    }

    public function edit(ManufacturingRejectionRecord $manufacturingRejectionRecord)
    {
        $manufacturingRejectionRecord->load(['lines.product', 'wipJob', 'finishedGoodReceipt', 'productionOrder', 'manufacturingCustomer']);

        return view('tenant.manufacturing.rejections.edit', $this->formData() + [
            'record'           => $manufacturingRejectionRecord,
            'title'            => 'Edit Rejection: ' . $manufacturingRejectionRecord->rejection_no,
            'nextNo'           => null,
            'prefill'          => [],
            'prefillLines'     => [],
            'selectedWip'      => $this->wipOption($manufacturingRejectionRecord->wip_job_id),
            'selectedFg'       => $this->fgOption($manufacturingRejectionRecord->finished_good_receipt_id),
            'selectedOrder'    => $this->orderOption($manufacturingRejectionRecord->production_order_id),
            'selectedCustomer' => $this->customerOption($manufacturingRejectionRecord->manufacturing_customer_id),
            'productOptions'   => $this->productOptionsMap($manufacturingRejectionRecord),
            'warning'          => null,
        ]);
    }

    public function update(Request $request, ManufacturingRejectionRecord $manufacturingRejectionRecord)
    {
        $data  = $this->validateHeader($request, $manufacturingRejectionRecord);
        $lines = $this->validateLines($request);
        $data  = $this->applyLineTotals($data, $lines);

        $manufacturingRejectionRecord->update($data);
        $this->syncLines($manufacturingRejectionRecord, $lines);

        return redirect(url('/manufacturing/rejections/' . $manufacturingRejectionRecord->id))->with('status', 'Rejection record updated.');
    }

    public function destroy(ManufacturingRejectionRecord $manufacturingRejectionRecord)
    {
        $manufacturingRejectionRecord->update(['status' => 'cancelled']);

        return redirect(url('/manufacturing/rejections'))->with('status', 'Rejection record cancelled.');
    }

    // ── Validation ──────────────────────────────────────────────────────────

    private function validateHeader(Request $request, ?ManufacturingRejectionRecord $rej = null): array
    {
        $data = $request->validate([
            'rejection_no'                   => ['nullable', 'string', 'max:50',
                                                Rule::unique('manufacturing_rejection_records', 'rejection_no')->ignore($rej?->id)],
            'rejection_date'                 => ['required', 'date'],
            'source_type'                    => ['nullable', Rule::in(ManufacturingRejectionRecord::SOURCE_TYPES)],
            'wip_job_id'                     => ['nullable', 'integer', 'exists:wip_jobs,id'],
            'finished_good_receipt_id'       => ['nullable', 'integer', 'exists:finished_good_receipts,id'],
            'production_order_id'            => ['nullable', 'integer', 'exists:production_orders,id'],
            'manufacturing_customer_id'      => ['nullable', 'integer', 'exists:manufacturing_customers,id'],
            'branch_id'                      => ['required', 'integer', 'exists:branches,id'],
            'status'                         => ['required', Rule::in(ManufacturingRejectionRecord::STATUSES)],
            'rejection_type'                 => ['required', Rule::in(ManufacturingRejectionRecord::REJECTION_TYPES)],
            'severity'                       => ['nullable', Rule::in(ManufacturingRejectionRecord::SEVERITIES)],
            'disposition'                    => ['nullable', Rule::in(ManufacturingRejectionRecord::DISPOSITIONS)],
            'reason_code'                    => ['nullable', Rule::in(ManufacturingRejectionRecord::REASON_CODES)],
            'quality_status'                 => ['nullable', Rule::in(ManufacturingRejectionRecord::QUALITY_STATUSES)],
            'total_quantity'                 => ['required', 'numeric', 'min:0'],
            'rework_quantity'                => ['nullable', 'numeric', 'min:0'],
            'scrap_quantity'                 => ['nullable', 'numeric', 'min:0'],
            'accepted_after_review_quantity' => ['nullable', 'numeric', 'min:0'],
            'disposed_quantity'              => ['nullable', 'numeric', 'min:0'],
            'estimated_loss_value'           => ['nullable', 'numeric', 'min:0'],
            'notes'                          => ['nullable', 'string', 'max:3000'],
        ]);

        // Header guard only matters when there are no lines (line totals override below).
        if (! $request->input('lines')) {
            $total     = (float) $data['total_quantity'];
            $dispTotal = (float) ($data['rework_quantity'] ?? 0)
                       + (float) ($data['scrap_quantity'] ?? 0)
                       + (float) ($data['accepted_after_review_quantity'] ?? 0)
                       + (float) ($data['disposed_quantity'] ?? 0);
            if ($dispTotal > $total + 0.00005) {
                throw ValidationException::withMessages([
                    'rework_quantity' => 'Rework + Scrap + Accepted-after-review + Disposed cannot exceed Total quantity.',
                ]);
            }
        }

        return $data;
    }

    private function validateLines(Request $request): array
    {
        $request->validate([
            'lines'                                  => ['nullable', 'array'],
            'lines.*.product_id'                     => ['required', 'integer', 'exists:products,id'],
            'lines.*.unit_id'                        => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.quantity'                       => ['required', 'numeric', 'min:0.0001'],
            'lines.*.rework_quantity'                => ['nullable', 'numeric', 'min:0'],
            'lines.*.scrap_quantity'                 => ['nullable', 'numeric', 'min:0'],
            'lines.*.accepted_after_review_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.disposed_quantity'              => ['nullable', 'numeric', 'min:0'],
            'lines.*.estimated_loss_value'           => ['nullable', 'numeric', 'min:0'],
            'lines.*.defect_code'                    => ['nullable', 'string', 'max:80'],
            'lines.*.batch_no'                       => ['nullable', 'string', 'max:80'],
            'lines.*.lot_no'                         => ['nullable', 'string', 'max:80'],
            'lines.*.notes'                          => ['nullable', 'string', 'max:500'],
        ]);

        $lines = $request->input('lines', []);

        foreach ($lines as $i => $line) {
            $qty       = (float) ($line['quantity'] ?? 0);
            $dispTotal = (float) ($line['rework_quantity'] ?? 0)
                       + (float) ($line['scrap_quantity'] ?? 0)
                       + (float) ($line['accepted_after_review_quantity'] ?? 0)
                       + (float) ($line['disposed_quantity'] ?? 0);
            if ($dispTotal > $qty + 0.00005) {
                throw ValidationException::withMessages([
                    "lines.{$i}.rework_quantity" => 'Line Rework + Scrap + Accepted-after-review + Disposed cannot exceed line Quantity.',
                ]);
            }
        }

        return $lines;
    }

    /** When lines exist, header totals are auto-calculated from the lines. */
    private function applyLineTotals(array $data, array $lines): array
    {
        if (empty($lines)) {
            return $data;
        }

        $col = fn (string $k) => collect($lines)->sum(fn ($l) => (float) ($l[$k] ?? 0));

        $data['total_quantity']                 = round($col('quantity'), 4);
        $data['rework_quantity']                = round($col('rework_quantity'), 4);
        $data['scrap_quantity']                 = round($col('scrap_quantity'), 4);
        $data['accepted_after_review_quantity'] = round($col('accepted_after_review_quantity'), 4);
        $data['disposed_quantity']              = round($col('disposed_quantity'), 4);
        $est = round($col('estimated_loss_value'), 4);
        $data['estimated_loss_value'] = $est > 0 ? $est : ($data['estimated_loss_value'] ?? null);

        return $data;
    }

    private function syncLines(ManufacturingRejectionRecord $record, array $lines): void
    {
        $record->lines()->delete();

        foreach (array_values($lines) as $i => $line) {
            ManufacturingRejectionLine::create([
                'manufacturing_rejection_record_id' => $record->id,
                'product_id'                        => $line['product_id'],
                'unit_id'                           => $line['unit_id'] ?: null,
                'quantity'                          => $line['quantity'],
                'rework_quantity'                   => $line['rework_quantity'] ?? 0,
                'scrap_quantity'                    => $line['scrap_quantity'] ?? 0,
                'accepted_after_review_quantity'    => $line['accepted_after_review_quantity'] ?? 0,
                'disposed_quantity'                 => $line['disposed_quantity'] ?? 0,
                'estimated_loss_value'              => $line['estimated_loss_value'] ?? null,
                'defect_code'                       => $line['defect_code'] ?? null,
                'batch_no'                          => $line['batch_no'] ?? null,
                'lot_no'                            => $line['lot_no'] ?? null,
                'notes'                             => $line['notes'] ?? null,
                'sort_order'                        => $line['sort_order'] ?? $i,
            ]);
        }
    }

    // ── Generate-from prefill ────────────────────────────────────────────────

    private function buildPrefill(Request $request): array
    {
        $empty = [
            'header' => [], 'lines' => [], 'wip_option' => null, 'fg_option' => null,
            'order_option' => null, 'customer_option' => null, 'product_options' => [], 'warning' => null,
        ];

        $fgId  = $request->input('finished_good_receipt_id');
        $wipId = $request->input('wip_job_id');

        // Prefer finished-goods source (more specific).
        if ($fgId) {
            $fg = FinishedGoodReceipt::with('finishedProduct')->find($fgId);
            if (! $fg) {
                return $empty;
            }
            $rejQty = (float) $fg->rejected_quantity;
            $fpText = $fg->finishedProduct
                ? ($fg->finishedProduct->sku ? $fg->finishedProduct->sku . ' — ' . $fg->finishedProduct->name : $fg->finishedProduct->name)
                : null;

            $lines = [];
            if ($rejQty > 0) {
                $lines[] = [
                    'product_id'                     => $fg->finished_product_id,
                    'unit_id'                        => null,
                    'quantity'                       => $rejQty,
                    'rework_quantity'                => 0,
                    'scrap_quantity'                 => 0,
                    'accepted_after_review_quantity' => 0,
                    'disposed_quantity'              => 0,
                    'estimated_loss_value'           => null,
                    'sort_order'                     => 0,
                    '_text'                          => $fpText,
                ];
            }

            return [
                'header' => array_filter([
                    'source_type'               => 'finished_goods',
                    'finished_good_receipt_id'  => $fg->id,
                    'wip_job_id'                => $fg->wip_job_id,
                    'production_order_id'       => $fg->production_order_id,
                    'manufacturing_customer_id' => $fg->manufacturing_customer_id,
                    'branch_id'                 => $fg->branch_id,
                    'rejection_type'            => 'quality_fail',
                    'quality_status'            => 'failed',
                    'disposition'               => 'pending',
                    'total_quantity'            => $rejQty > 0 ? $rejQty : null,
                ], fn ($v) => $v !== null),
                'lines'            => $lines,
                'wip_option'       => $this->wipOption($fg->wip_job_id),
                'fg_option'        => $this->fgOption($fg->id),
                'order_option'     => $this->orderOption($fg->production_order_id),
                'customer_option'  => $this->customerOption($fg->manufacturing_customer_id),
                'product_options'  => ($fg->finished_product_id && $fpText) ? [$fg->finished_product_id => $fpText] : [],
                'warning'          => $rejQty > 0 ? null : 'This Finished Goods receipt has no rejected quantity recorded. You can still record rejection manually.',
            ];
        }

        if ($wipId) {
            $wip = WipJob::find($wipId);
            if (! $wip) {
                return $empty;
            }
            return array_merge($empty, [
                'header' => array_filter([
                    'source_type'               => 'wip',
                    'wip_job_id'                => $wip->id,
                    'production_order_id'       => $wip->production_order_id,
                    'manufacturing_customer_id' => $wip->manufacturing_customer_id,
                    'branch_id'                 => $wip->branch_id,
                    'rejection_type'            => 'process_defect',
                    'quality_status'            => 'pending',
                    'disposition'               => 'pending',
                ], fn ($v) => $v !== null),
                'wip_option'      => $this->wipOption($wip->id),
                'order_option'    => $this->orderOption($wip->production_order_id),
                'customer_option' => $this->customerOption($wip->manufacturing_customer_id),
            ]);
        }

        return $empty;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formData(): array
    {
        return [
            'units'           => Unit::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'branches'        => Branch::orderBy('name')->get(['id', 'name']),
            'statuses'        => ManufacturingRejectionRecord::STATUSES,
            'sourceTypes'     => ManufacturingRejectionRecord::SOURCE_TYPES,
            'rejectionTypes'  => ManufacturingRejectionRecord::REJECTION_TYPES,
            'severities'      => ManufacturingRejectionRecord::SEVERITIES,
            'dispositions'    => ManufacturingRejectionRecord::DISPOSITIONS,
            'reasonCodes'     => ManufacturingRejectionRecord::REASON_CODES,
            'qualityStatuses' => ManufacturingRejectionRecord::QUALITY_STATUSES,
        ];
    }

    private function nextRejectionNo(): string
    {
        return $this->nextSequentialCode(ManufacturingRejectionRecord::class, 'rejection_no', 'REJ-', 6);
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

    private function fgOption($id): ?array
    {
        if (! $id) {
            return null;
        }
        $f = FinishedGoodReceipt::with('finishedProduct:id,sku,name')->find($id);
        if (! $f) {
            return null;
        }
        $label = $f->fg_no . ($f->finishedProduct ? ' — ' . ($f->finishedProduct->sku ? $f->finishedProduct->sku . ' ' : '') . $f->finishedProduct->name : '');
        return ['id' => $f->id, 'text' => $label];
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

    /** Map product_id => "SKU — Name" for line products referenced by old() or saved lines. */
    private function productOptionsMap(?ManufacturingRejectionRecord $record): array
    {
        $ids = collect(old('lines', []))->pluck('product_id')
            ->merge($record?->lines->pluck('product_id') ?? collect())
            ->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Product::whereIn('id', $ids)->get(['id', 'sku', 'name'])
            ->mapWithKeys(fn (Product $p) => [$p->id => ($p->sku ? $p->sku . ' — ' . $p->name : $p->name)])
            ->all();
    }
}
