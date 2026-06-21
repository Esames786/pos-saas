<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Concerns\NormalizesBranchIds;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\FinishedGoodReceipt;
use App\Models\Tenant\ManufacturingConsumptionRecord;
use App\Models\Tenant\ManufacturingRejectionRecord;
use App\Models\Tenant\ManufacturingScrapRecord;
use App\Models\Tenant\MaterialRequisition;
use App\Models\Tenant\ProductionOrder;
use App\Models\Tenant\WipJob;
use App\Support\CsvStreamer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Manufacturing Production Reports (MANUF-10) — READ-ONLY analytics.
 *
 * Aggregates existing manufacturing operational data (production orders, MRC,
 * WIP, finished goods, scrap, rejections, consumption) into summary cards,
 * grouped tables and CSV exports. It performs NO writes: no inventory/stock
 * ledger, no WIP/FG/COGS/variance accounting, no GL posting, and no mutation of
 * any manufacturing record. Pure SELECT queries.
 */
class ManufacturingReportController extends Controller
{
    use NormalizesBranchIds;

    /** @var array<string, mixed> */
    private array $f = [];

    public function index(Request $request)
    {
        $this->f = $this->filters($request);

        return view('tenant.manufacturing.reports.index', array_merge([
            'filters'           => $this->f,
            'selectedBranchIds' => $this->f['branch_ids'] ?? [],
            'branches'          => Branch::orderBy('name')->get(['id', 'name']),
            'statuses'          => ProductionOrder::STATUSES,
            'selectedOrder'     => $this->orderOption($request->input('production_order_id')),
            'selectedCustomer'  => $this->customerOption($request->input('manufacturing_customer_id')),
            'selectedProduct'   => $this->productOption($request->input('product_id')),
        ], [
            'overview'     => $this->overview(),
            'poSummary'    => $this->productionOrdersSummary(),
            'mrcSummary'   => $this->mrcSummary(),
            'wipSummary'   => $this->wipSummary(),
            'fgSummary'    => $this->finishedGoodsSummary(),
            'scrapSummary' => $this->scrapSummary(),
            'rejSummary'   => $this->rejectionsSummary(),
            'consSummary'  => $this->consumptionSummary(),
            'yield'        => $this->yieldVarianceSummary(),
        ]));
    }

    public function export(Request $request)
    {
        $this->f = $this->filters($request);
        $report  = (string) $request->input('report', 'overview');

        $period = ($this->f['date_from'] ?? '') . '_to_' . ($this->f['date_to'] ?? '');
        $meta   = [
            'Date from' => $this->f['date_from'],
            'Date to'   => $this->f['date_to'],
            'Branch'    => $this->branchLabel(),
        ];

        [$title, $headers, $rows] = $this->csvRowsForReport($report);

        $headerBlock = CsvStreamer::financeHeader('Manufacturing — ' . $title, $meta);

        return CsvStreamer::download(
            'manufacturing-' . $report . '-' . $period . '.csv',
            $headerBlock,
            function ($fp) use ($headers, $rows) {
                fputcsv($fp, $headers);
                foreach ($rows as $row) {
                    fputcsv($fp, $row);
                }
            }
        );
    }

    // ── Filters / scope ───────────────────────────────────────────────────────

    private function filters(Request $request): array
    {
        return [
            'date_from'                 => $request->input('date_from', now()->startOfMonth()->toDateString()),
            'date_to'                   => $request->input('date_to', now()->toDateString()),
            'branch_ids'                => $this->normalizeBranchIds($request), // branch_ids[] or legacy branch_id
            'production_order_id'        => $request->input('production_order_id'),
            'manufacturing_customer_id' => $request->input('manufacturing_customer_id'),
            'product_id'                => $request->input('product_id'),
            'status'                    => $request->input('status'),
        ];
    }

    /**
     * Apply the common filters to a manufacturing query.
     *
     * @param  string       $dateCol     the model's date column
     * @param  string|null  $poColumn    column linking to a production order ('id' for production_orders, else 'production_order_id'); null = skip
     * @param  string|null  $productCol  direct product column on the table (null = no direct product filter)
     * @param  bool         $applyStatus apply the status filter (status values are PO-style, so only PO uses it)
     */
    private function scope(Builder $q, string $dateCol, ?string $poColumn = 'production_order_id', ?string $productCol = null, bool $applyStatus = false): Builder
    {
        if (! empty($this->f['branch_ids'])) {
            $q->whereIn('branch_id', $this->f['branch_ids']);
        }
        if (! empty($this->f['date_from'])) {
            $q->whereDate($dateCol, '>=', $this->f['date_from']);
        }
        if (! empty($this->f['date_to'])) {
            $q->whereDate($dateCol, '<=', $this->f['date_to']);
        }
        if (! empty($this->f['manufacturing_customer_id'])) {
            $q->where('manufacturing_customer_id', $this->f['manufacturing_customer_id']);
        }
        if (! empty($this->f['production_order_id']) && $poColumn) {
            $q->where($poColumn, $this->f['production_order_id']);
        }
        if (! empty($this->f['product_id']) && $productCol) {
            $q->where($productCol, $this->f['product_id']);
        }
        if ($applyStatus && ! empty($this->f['status'])) {
            $q->where('status', $this->f['status']);
        }

        return $q;
    }

    private function po(): Builder   { return $this->scope(ProductionOrder::query(), 'order_date', 'id', 'product_id', true); }
    private function mrc(): Builder   { return $this->scope(MaterialRequisition::query(), 'request_date'); }
    private function wip(): Builder   { return $this->scope(WipJob::query(), 'start_date', 'production_order_id', 'finished_product_id'); }
    private function fg(): Builder    { return $this->scope(FinishedGoodReceipt::query(), 'receipt_date', 'production_order_id', 'finished_product_id'); }
    private function scrap(): Builder { return $this->scope(ManufacturingScrapRecord::query(), 'scrap_date'); }
    private function rej(): Builder   { return $this->scope(ManufacturingRejectionRecord::query(), 'rejection_date'); }
    private function cons(): Builder  { return $this->scope(ManufacturingConsumptionRecord::query(), 'consumption_date'); }

    // ── Report sections (read-only) ─────────────────────────────────────────

    private function overview(): array
    {
        $fg   = (clone $this->fg())->selectRaw('COUNT(*) c, COALESCE(SUM(received_quantity),0) recv, COALESCE(SUM(accepted_quantity),0) acc, COALESCE(SUM(rejected_quantity),0) rej, COALESCE(SUM(scrap_quantity),0) scr')->first();
        $wip  = (clone $this->wip())->selectRaw('COUNT(*) c, COALESCE(SUM(planned_quantity),0) plan, COALESCE(SUM(completed_quantity),0) comp')->first();
        $cons = (clone $this->cons())->selectRaw('COUNT(*) c, COALESCE(SUM(total_planned_quantity),0) plan, COALESCE(SUM(total_consumed_quantity),0) cons')->first();
        $scr  = (clone $this->scrap())->selectRaw('COUNT(*) c, COALESCE(SUM(total_quantity),0) qty')->first();
        $rj   = (clone $this->rej())->selectRaw('COUNT(*) c, COALESCE(SUM(total_quantity),0) qty')->first();

        return [
            'counts' => [
                'production_orders'   => (clone $this->po())->count(),
                'open_orders'         => (clone $this->po())->whereNotIn('status', ['completed', 'cancelled'])->count(),
                'closed_orders'       => (clone $this->po())->whereIn('status', ['completed', 'cancelled'])->count(),
                'mrcs'                => (clone $this->mrc())->count(),
                'wip_jobs'            => (int) $wip->c,
                'finished_goods'      => (int) $fg->c,
                'scrap_records'       => (int) $scr->c,
                'rejection_records'   => (int) $rj->c,
                'consumption_records' => (int) $cons->c,
            ],
            'quantities' => [
                'planned_production'  => (float) (clone $this->po())->sum('planned_quantity'),
                'wip_planned'         => (float) $wip->plan,
                'wip_completed'       => (float) $wip->comp,
                'fg_received'         => (float) $fg->recv,
                'fg_accepted'         => (float) $fg->acc,
                'fg_rejected'         => (float) $fg->rej,
                'fg_scrap'            => (float) $fg->scr,
                'cons_planned'        => (float) $cons->plan,
                'cons_consumed'       => (float) $cons->cons,
                'scrap_total'         => (float) $scr->qty,
                'rejection_total'     => (float) $rj->qty,
            ],
        ];
    }

    private function productionOrdersSummary(): array
    {
        return [
            'by_status' => (clone $this->po())
                ->selectRaw('status, COUNT(*) AS orders, COALESCE(SUM(planned_quantity),0) AS planned_qty')
                ->groupBy('status')->orderBy('status')->get(),
            'latest' => (clone $this->po())
                ->with(['manufacturingCustomer', 'product', 'branch'])
                ->orderByDesc('order_date')->orderByDesc('id')->limit(10)->get(),
        ];
    }

    private function mrcSummary(): array
    {
        $base = $this->mrc();
        $lineTotals = (clone $base)
            ->leftJoin('material_requisition_lines as l', 'l.material_requisition_id', '=', 'material_requisitions.id')
            ->selectRaw('COALESCE(SUM(l.required_quantity),0) req, COALESCE(SUM(l.issued_quantity),0) iss')->first();

        return [
            'cards' => [
                'total'    => (clone $base)->count(),
                'open'     => (clone $base)->whereNotIn('status', ['cancelled', 'closed'])->count(),
                'required' => (float) $lineTotals->req,
                'issued'   => (float) $lineTotals->iss,
            ],
            'by_status' => (clone $this->mrc())
                ->leftJoin('material_requisition_lines as l', 'l.material_requisition_id', '=', 'material_requisitions.id')
                ->selectRaw('material_requisitions.status, COUNT(DISTINCT material_requisitions.id) AS mrc_count, COALESCE(SUM(l.required_quantity),0) AS required_qty, COALESCE(SUM(l.issued_quantity),0) AS issued_qty')
                ->groupBy('material_requisitions.status')->orderBy('material_requisitions.status')->get(),
            'latest' => (clone $base)
                ->withCount('lines')->with(['productionOrder', 'manufacturingCustomer', 'branch'])
                ->orderByDesc('request_date')->orderByDesc('id')->limit(10)->get(),
        ];
    }

    private function wipSummary(): array
    {
        $agg = (clone $this->wip())->selectRaw('COUNT(*) c, COALESCE(SUM(planned_quantity),0) plan, COALESCE(SUM(completed_quantity),0) comp, COALESCE(AVG(progress_percent),0) prog')->first();

        return [
            'cards' => [
                'total'        => (int) $agg->c,
                'open'         => (clone $this->wip())->whereNotIn('status', ['completed', 'cancelled'])->count(),
                'planned'      => (float) $agg->plan,
                'completed'    => (float) $agg->comp,
                'avg_progress' => round((float) $agg->prog, 2),
            ],
            'by_status' => (clone $this->wip())
                ->selectRaw('status, COUNT(*) AS wip_count, COALESCE(SUM(planned_quantity),0) AS planned_qty, COALESCE(SUM(completed_quantity),0) AS completed_qty, COALESCE(AVG(progress_percent),0) AS avg_progress')
                ->groupBy('status')->orderBy('status')->get(),
            'latest' => (clone $this->wip())
                ->with(['productionOrder', 'finishedProduct', 'manufacturingCustomer', 'branch'])
                ->orderByDesc('start_date')->orderByDesc('id')->limit(10)->get(),
        ];
    }

    private function finishedGoodsSummary(): array
    {
        $agg = (clone $this->fg())->selectRaw('COUNT(*) c, COALESCE(SUM(received_quantity),0) recv, COALESCE(SUM(accepted_quantity),0) acc, COALESCE(SUM(rejected_quantity),0) rej, COALESCE(SUM(scrap_quantity),0) scr')->first();
        $recv = (float) $agg->recv;

        return [
            'cards' => [
                'count'         => (int) $agg->c,
                'received'      => $recv,
                'accepted'      => (float) $agg->acc,
                'rejected'      => (float) $agg->rej,
                'scrap'         => (float) $agg->scr,
                'acceptance_pc' => $recv > 0 ? round(((float) $agg->acc / $recv) * 100, 2) : 0,
            ],
            'by_status' => (clone $this->fg())
                ->selectRaw('status, quality_status, COUNT(*) AS receipt_count, COALESCE(SUM(received_quantity),0) AS received_qty, COALESCE(SUM(accepted_quantity),0) AS accepted_qty, COALESCE(SUM(rejected_quantity),0) AS rejected_qty, COALESCE(SUM(scrap_quantity),0) AS scrap_qty')
                ->groupBy('status', 'quality_status')->orderBy('status')->get(),
            'latest' => (clone $this->fg())
                ->with(['wipJob', 'productionOrder', 'finishedProduct', 'branch'])
                ->orderByDesc('receipt_date')->orderByDesc('id')->limit(10)->get(),
        ];
    }

    private function scrapSummary(): array
    {
        $agg = (clone $this->scrap())->selectRaw('COUNT(*) c, COALESCE(SUM(total_quantity),0) qty, COALESCE(SUM(recoverable_quantity),0) rec, COALESCE(SUM(disposed_quantity),0) disp, COALESCE(SUM(estimated_loss_value),0) loss')->first();

        return [
            'cards' => [
                'count'       => (int) $agg->c,
                'total'       => (float) $agg->qty,
                'recoverable' => (float) $agg->rec,
                'disposed'    => (float) $agg->disp,
                'loss'        => (float) $agg->loss,
            ],
            'by_type' => (clone $this->scrap())
                ->selectRaw('scrap_type, reason_code, COUNT(*) AS records, COALESCE(SUM(total_quantity),0) AS total_qty, COALESCE(SUM(recoverable_quantity),0) AS recoverable_qty, COALESCE(SUM(disposed_quantity),0) AS disposed_qty, COALESCE(SUM(estimated_loss_value),0) AS estimated_loss')
                ->groupBy('scrap_type', 'reason_code')->orderBy('scrap_type')->get(),
        ];
    }

    private function rejectionsSummary(): array
    {
        $agg = (clone $this->rej())->selectRaw('COUNT(*) c, COALESCE(SUM(total_quantity),0) qty, COALESCE(SUM(rework_quantity),0) rew, COALESCE(SUM(scrap_quantity),0) scr, COALESCE(SUM(accepted_after_review_quantity),0) acc, COALESCE(SUM(disposed_quantity),0) disp, COALESCE(SUM(estimated_loss_value),0) loss')->first();

        return [
            'cards' => [
                'count'    => (int) $agg->c,
                'total'    => (float) $agg->qty,
                'rework'   => (float) $agg->rew,
                'scrap'    => (float) $agg->scr,
                'accepted' => (float) $agg->acc,
                'disposed' => (float) $agg->disp,
                'loss'     => (float) $agg->loss,
            ],
            'by_type' => (clone $this->rej())
                ->selectRaw('rejection_type, severity, disposition, COUNT(*) AS records, COALESCE(SUM(total_quantity),0) AS total_qty, COALESCE(SUM(rework_quantity),0) AS rework_qty, COALESCE(SUM(scrap_quantity),0) AS scrap_qty, COALESCE(SUM(accepted_after_review_quantity),0) AS accepted_qty, COALESCE(SUM(disposed_quantity),0) AS disposed_qty, COALESCE(SUM(estimated_loss_value),0) AS estimated_loss')
                ->groupBy('rejection_type', 'severity', 'disposition')->orderBy('rejection_type')->get(),
        ];
    }

    private function consumptionSummary(): array
    {
        $agg = (clone $this->cons())->selectRaw('COUNT(*) c, COALESCE(SUM(total_planned_quantity),0) plan, COALESCE(SUM(total_consumed_quantity),0) cons, COALESCE(SUM(total_wastage_quantity),0) wst, COALESCE(SUM(total_variance_quantity),0) var, COALESCE(SUM(estimated_consumption_value),0) val')->first();

        return [
            'cards' => [
                'count'    => (int) $agg->c,
                'planned'  => (float) $agg->plan,
                'consumed' => (float) $agg->cons,
                'wastage'  => (float) $agg->wst,
                'variance' => (float) $agg->var,
                'value'    => (float) $agg->val,
            ],
            // Group by type + a derived variance bucket (over/under/on plan).
            'by_type' => (clone $this->cons())
                ->selectRaw("consumption_type,
                    CASE WHEN total_variance_quantity > 0 THEN 'over_consumed' WHEN total_variance_quantity < 0 THEN 'under_consumed' ELSE 'on_plan' END AS variance_status,
                    COUNT(*) AS records, COALESCE(SUM(total_planned_quantity),0) AS planned_qty, COALESCE(SUM(total_consumed_quantity),0) AS consumed_qty, COALESCE(SUM(total_wastage_quantity),0) AS wastage_qty, COALESCE(SUM(total_variance_quantity),0) AS variance_qty, COALESCE(SUM(estimated_consumption_value),0) AS estimated_value")
                ->groupBy('consumption_type', 'variance_status')->orderBy('consumption_type')->get(),
        ];
    }

    private function yieldVarianceSummary(): array
    {
        $fg   = $this->overview()['quantities'];
        $recv = $fg['fg_received'];
        $consP = $fg['cons_planned'];
        $consC = $fg['cons_consumed'];

        $pct = fn ($num, $den) => $den > 0 ? round(($num / $den) * 100, 2) : 0;

        return [
            'fg_acceptance_rate'   => $pct($fg['fg_accepted'], $recv),
            'fg_rejection_rate'    => $pct($fg['fg_rejected'], $recv),
            'fg_scrap_rate'        => $pct($fg['fg_scrap'], $recv),
            'consumption_variance' => round($consC - $consP, 4),
            'consumption_variance_pct' => $pct($consC - $consP, $consP),
            'scrap_vs_fg_received'  => $pct($fg['scrap_total'], $recv),
            'rejection_vs_fg_received' => $pct($fg['rejection_total'], $recv),
        ];
    }

    // ── CSV ─────────────────────────────────────────────────────────────────

    /** @return array{0:string,1:array<int,string>,2:iterable} [title, headers, rows] */
    private function csvRowsForReport(string $report): array
    {
        $n = fn ($v) => number_format((float) $v, 4, '.', '');

        switch ($report) {
            case 'production_orders':
                $rows = $this->po()->with(['manufacturingCustomer', 'product', 'branch'])->orderByDesc('order_date')->get()
                    ->map(fn ($o) => [$o->order_no, optional($o->order_date)->format('Y-m-d'), $o->manufacturingCustomer?->name, $o->product?->sku, $o->product?->name, $o->branch?->name, $o->status, $o->priority, $n($o->planned_quantity), $n($o->produced_quantity)]);
                return ['Production Orders', ['Order No', 'Date', 'Customer', 'SKU', 'Product', 'Branch', 'Status', 'Priority', 'Planned Qty', 'Produced Qty'], $rows];

            case 'mrc':
                $rows = $this->mrc()->withCount('lines')->with(['productionOrder', 'manufacturingCustomer', 'branch'])->orderByDesc('request_date')->get()
                    ->map(fn ($m) => [$m->mrc_no, optional($m->request_date)->format('Y-m-d'), optional($m->required_date)->format('Y-m-d'), $m->productionOrder?->order_no, $m->manufacturingCustomer?->name, $m->branch?->name, $m->status, $m->priority, $m->lines_count]);
                return ['Material Requisitions', ['MRC No', 'Request Date', 'Required Date', 'Production Order', 'Customer', 'Branch', 'Status', 'Priority', 'Lines'], $rows];

            case 'wip':
                $rows = $this->wip()->with(['productionOrder', 'finishedProduct', 'manufacturingCustomer', 'branch'])->orderByDesc('start_date')->get()
                    ->map(fn ($w) => [$w->wip_no, optional($w->start_date)->format('Y-m-d'), $w->productionOrder?->order_no, $w->finishedProduct?->sku, $w->finishedProduct?->name, $w->manufacturingCustomer?->name, $w->branch?->name, $w->status, $n($w->planned_quantity), $n($w->completed_quantity), $n($w->progress_percent)]);
                return ['WIP Jobs', ['WIP No', 'Start Date', 'Production Order', 'SKU', 'Finished Product', 'Customer', 'Branch', 'Status', 'Planned', 'Completed', 'Progress %'], $rows];

            case 'finished_goods':
                $rows = $this->fg()->with(['wipJob', 'productionOrder', 'finishedProduct', 'branch'])->orderByDesc('receipt_date')->get()
                    ->map(fn ($r) => [$r->fg_no, optional($r->receipt_date)->format('Y-m-d'), $r->wipJob?->wip_no, $r->productionOrder?->order_no, $r->finishedProduct?->sku, $r->finishedProduct?->name, $r->branch?->name, $r->status, $r->quality_status, $n($r->planned_quantity), $n($r->received_quantity), $n($r->accepted_quantity), $n($r->rejected_quantity), $n($r->scrap_quantity)]);
                return ['Finished Goods', ['FG No', 'Receipt Date', 'WIP Job', 'Production Order', 'SKU', 'Finished Product', 'Branch', 'Status', 'Quality', 'Planned', 'Received', 'Accepted', 'Rejected', 'Scrap'], $rows];

            case 'scrap':
                $rows = $this->scrap()->with(['productionOrder', 'manufacturingCustomer', 'branch'])->orderByDesc('scrap_date')->get()
                    ->map(fn ($s) => [$s->scrap_no, optional($s->scrap_date)->format('Y-m-d'), $s->source_type, $s->scrap_type, $s->reason_code, $s->quality_status, $s->productionOrder?->order_no, $s->branch?->name, $s->status, $n($s->total_quantity), $n($s->recoverable_quantity), $n($s->disposed_quantity), $s->estimated_loss_value !== null ? $n($s->estimated_loss_value) : '']);
                return ['Scrap / Hard Waste', ['Scrap No', 'Date', 'Source', 'Type', 'Reason', 'Quality', 'Production Order', 'Branch', 'Status', 'Total', 'Recoverable', 'Disposed', 'Est. Loss'], $rows];

            case 'rejections':
                $rows = $this->rej()->with(['productionOrder', 'manufacturingCustomer', 'branch'])->orderByDesc('rejection_date')->get()
                    ->map(fn ($r) => [$r->rejection_no, optional($r->rejection_date)->format('Y-m-d'), $r->source_type, $r->rejection_type, $r->severity, $r->disposition, $r->reason_code, $r->productionOrder?->order_no, $r->branch?->name, $r->status, $n($r->total_quantity), $n($r->rework_quantity), $n($r->scrap_quantity), $n($r->accepted_after_review_quantity), $n($r->disposed_quantity), $r->estimated_loss_value !== null ? $n($r->estimated_loss_value) : '']);
                return ['Rejections', ['Rejection No', 'Date', 'Source', 'Type', 'Severity', 'Disposition', 'Reason', 'Production Order', 'Branch', 'Status', 'Total', 'Rework', 'Scrap', 'Accepted', 'Disposed', 'Est. Loss'], $rows];

            case 'consumption':
                $rows = $this->cons()->with(['productionOrder', 'materialRequisition', 'wipJob', 'branch'])->orderByDesc('consumption_date')->get()
                    ->map(fn ($c) => [$c->consumption_no, optional($c->consumption_date)->format('Y-m-d'), $c->source_type, $c->consumption_type, $c->wipJob?->wip_no, $c->materialRequisition?->mrc_no, $c->productionOrder?->order_no, $c->branch?->name, $c->status, $n($c->total_planned_quantity), $n($c->total_consumed_quantity), $n($c->total_wastage_quantity), $n($c->total_variance_quantity), $c->estimated_consumption_value !== null ? $n($c->estimated_consumption_value) : '']);
                return ['Consumption', ['Consumption No', 'Date', 'Source', 'Type', 'WIP Job', 'MRC', 'Production Order', 'Branch', 'Status', 'Planned', 'Consumed', 'Wastage', 'Variance', 'Est. Value'], $rows];

            case 'yield':
                $y = $this->yieldVarianceSummary();
                $rows = [
                    ['FG Acceptance Rate %', $y['fg_acceptance_rate']],
                    ['FG Rejection Rate %', $y['fg_rejection_rate']],
                    ['FG Scrap Rate %', $y['fg_scrap_rate']],
                    ['Consumption Variance', $n($y['consumption_variance'])],
                    ['Consumption Variance %', $y['consumption_variance_pct']],
                    ['Scrap vs FG Received %', $y['scrap_vs_fg_received']],
                    ['Rejection vs FG Received %', $y['rejection_vs_fg_received']],
                ];
                return ['Yield / Variance', ['Metric', 'Value'], $rows];

            case 'overview':
            default:
                $o = $this->overview();
                $rows = [];
                foreach ($o['counts'] as $k => $v) {
                    $rows[] = [ucwords(str_replace('_', ' ', $k)), $v];
                }
                foreach ($o['quantities'] as $k => $v) {
                    $rows[] = [ucwords(str_replace('_', ' ', $k)) . ' (qty)', $n($v)];
                }
                return ['Production Overview', ['Metric', 'Value'], $rows];
        }
    }

    private function branchLabel(): string
    {
        if (empty($this->f['branch_ids'])) {
            return 'All Branches';
        }
        return Branch::whereIn('id', $this->f['branch_ids'])->orderBy('name')->pluck('name')->implode(', ');
    }

    // ── Select2 preselect options ─────────────────────────────────────────────

    private function orderOption($id): ?array
    {
        if (! $id) {
            return null;
        }
        $o = ProductionOrder::with('product:id,sku,name')->find($id);
        if (! $o) {
            return null;
        }
        return ['id' => $o->id, 'text' => $o->order_no . ($o->product ? ' — ' . $o->product->name : '')];
    }

    private function customerOption($id): ?array
    {
        if (! $id) {
            return null;
        }
        $c = \App\Models\Tenant\ManufacturingCustomer::find($id, ['id', 'code', 'name']);
        return $c ? ['id' => $c->id, 'text' => ($c->code ? $c->code . ' — ' . $c->name : $c->name)] : null;
    }

    private function productOption($id): ?array
    {
        if (! $id) {
            return null;
        }
        $p = \App\Models\Tenant\Product::find($id, ['id', 'sku', 'name']);
        return $p ? ['id' => $p->id, 'text' => ($p->sku ? $p->sku . ' — ' . $p->name : $p->name)] : null;
    }
}
