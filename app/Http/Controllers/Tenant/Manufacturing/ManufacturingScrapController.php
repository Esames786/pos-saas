<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Concerns\GeneratesSequentialCode;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\FinishedGoodReceipt;
use App\Models\Tenant\ManufacturingCustomer;
use App\Models\Tenant\ManufacturingScrapLine;
use App\Models\Tenant\ManufacturingScrapRecord;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductionOrder;
use App\Models\Tenant\Unit;
use App\Models\Tenant\WipJob;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Scrap / Hard Waste tracking (MANUF-7) — tracking only.
 *
 * Records wasted / damaged / lost / unusable quantity from a WIP job, a finished
 * goods receipt, or manually. It does NOT deduct or adjust inventory, write a
 * stock ledger entry, post scrap expense, post WIP variance, compute COGS, or
 * create GL journals. It does not change WIP / Finished Goods / Production Order
 * status.
 */
class ManufacturingScrapController extends Controller
{
    use GeneratesSequentialCode;

    public function index(Request $request)
    {
        $query = ManufacturingScrapRecord::query()
            ->with(['wipJob', 'finishedGoodReceipt', 'productionOrder', 'manufacturingCustomer', 'branch']);

        if ($request->filled('q')) {
            $s = trim($request->q);
            $query->where(function ($w) use ($s) {
                $w->where('scrap_no', 'like', "%{$s}%")
                  ->orWhereHas('wipJob', fn ($j) => $j->where('wip_no', 'like', "%{$s}%"))
                  ->orWhereHas('finishedGoodReceipt', fn ($f) => $f->where('fg_no', 'like', "%{$s}%"))
                  ->orWhereHas('productionOrder', fn ($p) => $p->where('order_no', 'like', "%{$s}%"))
                  ->orWhereHas('manufacturingCustomer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('lines.product', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
            });
        }

        foreach (['status' => ManufacturingScrapRecord::STATUSES, 'source_type' => ManufacturingScrapRecord::SOURCE_TYPES,
                  'scrap_type' => ManufacturingScrapRecord::SCRAP_TYPES, 'reason_code' => ManufacturingScrapRecord::REASON_CODES] as $field => $allowed) {
            if ($request->filled($field) && in_array($request->input($field), $allowed, true)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('scrap_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('scrap_date', '<=', $request->date_to);
        }

        $records = $query->orderByDesc('scrap_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('tenant.manufacturing.scrap.index', [
            'records'      => $records,
            'branches'     => Branch::orderBy('name')->get(['id', 'name']),
            'filters'      => $request->only(['q', 'status', 'source_type', 'scrap_type', 'reason_code', 'branch_id', 'date_from', 'date_to']),
            'statuses'     => ManufacturingScrapRecord::STATUSES,
            'sourceTypes'  => ManufacturingScrapRecord::SOURCE_TYPES,
            'scrapTypes'   => ManufacturingScrapRecord::SCRAP_TYPES,
            'reasonCodes'  => ManufacturingScrapRecord::REASON_CODES,
        ]);
    }

    public function create(Request $request)
    {
        $prefill = $this->buildPrefill($request);

        return view('tenant.manufacturing.scrap.create', $this->formData() + [
            'record'           => null,
            'title'            => 'Record Scrap / Hard Waste',
            'nextNo'           => $this->nextScrapNo(),
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
        if (empty(trim($request->input('scrap_no', '')))) {
            $request->merge(['scrap_no' => $this->nextScrapNo()]);
        }

        $data  = $this->validateHeader($request);
        $lines = $this->validateLines($request);
        $data  = $this->applyLineTotals($data, $lines);

        $record = ManufacturingScrapRecord::create(array_merge($data, ['created_by_user_id' => auth('tenant')->id()]));
        $this->syncLines($record, $lines);

        return redirect(url('/manufacturing/scrap/' . $record->id))->with('status', 'Scrap record created.');
    }

    public function show(ManufacturingScrapRecord $manufacturingScrapRecord)
    {
        $manufacturingScrapRecord->load([
            'wipJob', 'finishedGoodReceipt', 'productionOrder', 'manufacturingCustomer', 'branch',
            'createdBy', 'lines.product', 'lines.unit',
        ]);

        return view('tenant.manufacturing.scrap.show', ['record' => $manufacturingScrapRecord]);
    }

    public function edit(ManufacturingScrapRecord $manufacturingScrapRecord)
    {
        $manufacturingScrapRecord->load(['lines.product', 'wipJob', 'finishedGoodReceipt', 'productionOrder', 'manufacturingCustomer']);

        return view('tenant.manufacturing.scrap.edit', $this->formData() + [
            'record'           => $manufacturingScrapRecord,
            'title'            => 'Edit Scrap: ' . $manufacturingScrapRecord->scrap_no,
            'nextNo'           => null,
            'prefill'          => [],
            'prefillLines'     => [],
            'selectedWip'      => $this->wipOption($manufacturingScrapRecord->wip_job_id),
            'selectedFg'       => $this->fgOption($manufacturingScrapRecord->finished_good_receipt_id),
            'selectedOrder'    => $this->orderOption($manufacturingScrapRecord->production_order_id),
            'selectedCustomer' => $this->customerOption($manufacturingScrapRecord->manufacturing_customer_id),
            'productOptions'   => $this->productOptionsMap($manufacturingScrapRecord),
            'warning'          => null,
        ]);
    }

    public function update(Request $request, ManufacturingScrapRecord $manufacturingScrapRecord)
    {
        $data  = $this->validateHeader($request, $manufacturingScrapRecord);
        $lines = $this->validateLines($request);
        $data  = $this->applyLineTotals($data, $lines);

        $manufacturingScrapRecord->update($data);
        $this->syncLines($manufacturingScrapRecord, $lines);

        return redirect(url('/manufacturing/scrap/' . $manufacturingScrapRecord->id))->with('status', 'Scrap record updated.');
    }

    public function destroy(ManufacturingScrapRecord $manufacturingScrapRecord)
    {
        $manufacturingScrapRecord->update(['status' => 'cancelled']);

        return redirect(url('/manufacturing/scrap'))->with('status', 'Scrap record cancelled.');
    }

    // ── Validation ──────────────────────────────────────────────────────────

    private function validateHeader(Request $request, ?ManufacturingScrapRecord $scrap = null): array
    {
        $data = $request->validate([
            'scrap_no'                  => ['nullable', 'string', 'max:50',
                                           Rule::unique('manufacturing_scrap_records', 'scrap_no')->ignore($scrap?->id)],
            'scrap_date'                => ['required', 'date'],
            'source_type'               => ['nullable', Rule::in(ManufacturingScrapRecord::SOURCE_TYPES)],
            'wip_job_id'                => ['nullable', 'integer', 'exists:wip_jobs,id'],
            'finished_good_receipt_id'  => ['nullable', 'integer', 'exists:finished_good_receipts,id'],
            'production_order_id'       => ['nullable', 'integer', 'exists:production_orders,id'],
            'manufacturing_customer_id' => ['nullable', 'integer', 'exists:manufacturing_customers,id'],
            'branch_id'                 => ['required', 'integer', 'exists:branches,id'],
            'status'                    => ['required', Rule::in(ManufacturingScrapRecord::STATUSES)],
            'scrap_type'                => ['required', Rule::in(ManufacturingScrapRecord::SCRAP_TYPES)],
            'reason_code'               => ['nullable', Rule::in(ManufacturingScrapRecord::REASON_CODES)],
            'quality_status'            => ['nullable', Rule::in(ManufacturingScrapRecord::QUALITY_STATUSES)],
            'total_quantity'            => ['required', 'numeric', 'min:0'],
            'recoverable_quantity'      => ['nullable', 'numeric', 'min:0'],
            'disposed_quantity'         => ['nullable', 'numeric', 'min:0'],
            'estimated_loss_value'      => ['nullable', 'numeric', 'min:0'],
            'notes'                     => ['nullable', 'string', 'max:3000'],
        ]);

        // Header guard only matters when there are no lines (line totals override below).
        $total    = (float) $data['total_quantity'];
        $recovered = (float) ($data['recoverable_quantity'] ?? 0);
        $disposed  = (float) ($data['disposed_quantity'] ?? 0);
        if (! $request->input('lines') && ($recovered + $disposed) > $total + 0.00005) {
            throw ValidationException::withMessages([
                'recoverable_quantity' => 'Recoverable + Disposed cannot exceed Total quantity.',
            ]);
        }

        return $data;
    }

    private function validateLines(Request $request): array
    {
        $request->validate([
            'lines'                          => ['nullable', 'array'],
            'lines.*.product_id'             => ['required', 'integer', 'exists:products,id'],
            'lines.*.unit_id'                => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.quantity'               => ['required', 'numeric', 'min:0.0001'],
            'lines.*.recoverable_quantity'   => ['nullable', 'numeric', 'min:0'],
            'lines.*.disposed_quantity'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.estimated_loss_value'   => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_no'               => ['nullable', 'string', 'max:80'],
            'lines.*.lot_no'                 => ['nullable', 'string', 'max:80'],
            'lines.*.notes'                  => ['nullable', 'string', 'max:500'],
        ]);

        $lines = $request->input('lines', []);

        foreach ($lines as $i => $line) {
            $qty       = (float) ($line['quantity'] ?? 0);
            $recovered = (float) ($line['recoverable_quantity'] ?? 0);
            $disposed  = (float) ($line['disposed_quantity'] ?? 0);
            if (($recovered + $disposed) > $qty + 0.00005) {
                throw ValidationException::withMessages([
                    "lines.{$i}.recoverable_quantity" => 'Line Recoverable + Disposed cannot exceed line Quantity.',
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

        $data['total_quantity']       = round($col('quantity'), 4);
        $data['recoverable_quantity'] = round($col('recoverable_quantity'), 4);
        $data['disposed_quantity']    = round($col('disposed_quantity'), 4);
        $est = round($col('estimated_loss_value'), 4);
        $data['estimated_loss_value'] = $est > 0 ? $est : ($data['estimated_loss_value'] ?? null);

        return $data;
    }

    private function syncLines(ManufacturingScrapRecord $record, array $lines): void
    {
        $record->lines()->delete();

        foreach (array_values($lines) as $i => $line) {
            ManufacturingScrapLine::create([
                'manufacturing_scrap_record_id' => $record->id,
                'product_id'                    => $line['product_id'],
                'unit_id'                       => $line['unit_id'] ?: null,
                'quantity'                      => $line['quantity'],
                'recoverable_quantity'          => $line['recoverable_quantity'] ?? 0,
                'disposed_quantity'             => $line['disposed_quantity'] ?? 0,
                'estimated_loss_value'          => $line['estimated_loss_value'] ?? null,
                'batch_no'                      => $line['batch_no'] ?? null,
                'lot_no'                        => $line['lot_no'] ?? null,
                'notes'                         => $line['notes'] ?? null,
                'sort_order'                    => $line['sort_order'] ?? $i,
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
            $scrapQty = (float) $fg->scrap_quantity;
            $fpText = $fg->finishedProduct
                ? ($fg->finishedProduct->sku ? $fg->finishedProduct->sku . ' — ' . $fg->finishedProduct->name : $fg->finishedProduct->name)
                : null;

            $lines = [];
            if ($scrapQty > 0) {
                $lines[] = [
                    'product_id'           => $fg->finished_product_id,
                    'unit_id'              => null,
                    'quantity'             => $scrapQty,
                    'recoverable_quantity' => 0,
                    'disposed_quantity'    => 0,
                    'estimated_loss_value' => null,
                    'batch_no'             => null,
                    'lot_no'               => null,
                    'sort_order'           => 0,
                    '_text'                => $fpText,
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
                    'scrap_type'                => 'finished_goods_loss',
                    'total_quantity'            => $scrapQty > 0 ? $scrapQty : null,
                ], fn ($v) => $v !== null),
                'lines'            => $lines,
                'wip_option'       => $this->wipOption($fg->wip_job_id),
                'fg_option'        => $this->fgOption($fg->id),
                'order_option'     => $this->orderOption($fg->production_order_id),
                'customer_option'  => $this->customerOption($fg->manufacturing_customer_id),
                'product_options'  => ($fg->finished_product_id && $fpText) ? [$fg->finished_product_id => $fpText] : [],
                'warning'          => $scrapQty > 0 ? null : 'This Finished Goods receipt has no scrap quantity recorded. You can still record scrap manually.',
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
                    'scrap_type'                => 'wip_loss',
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
            'statuses'        => ManufacturingScrapRecord::STATUSES,
            'sourceTypes'     => ManufacturingScrapRecord::SOURCE_TYPES,
            'scrapTypes'      => ManufacturingScrapRecord::SCRAP_TYPES,
            'reasonCodes'     => ManufacturingScrapRecord::REASON_CODES,
            'qualityStatuses' => ManufacturingScrapRecord::QUALITY_STATUSES,
        ];
    }

    private function nextScrapNo(): string
    {
        return $this->nextSequentialCode(ManufacturingScrapRecord::class, 'scrap_no', 'SCRAP-', 6);
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
    private function productOptionsMap(?ManufacturingScrapRecord $record): array
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
