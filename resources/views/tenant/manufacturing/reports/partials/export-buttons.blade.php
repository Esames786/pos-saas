{{-- CSV export dropdown — each link carries the current filters --}}
@php
    $base = array_filter([
        'date_from'                 => $filters['date_from'] ?? null,
        'date_to'                   => $filters['date_to'] ?? null,
        'production_order_id'        => $filters['production_order_id'] ?? null,
        'manufacturing_customer_id' => $filters['manufacturing_customer_id'] ?? null,
        'product_id'                => $filters['product_id'] ?? null,
        'status'                    => $filters['status'] ?? null,
    ], fn ($v) => $v !== null && $v !== '');
    $qs = http_build_query($base);
    foreach (($selectedBranchIds ?? []) as $bid) {
        $qs .= '&branch_ids[]=' . $bid;
    }
    $exportUrl = fn ($report) => url('/manufacturing/reports/export') . '?report=' . $report . ($qs !== '' ? '&' . ltrim($qs, '&') : '');
    $reports = [
        'overview'          => 'Overview',
        'production_orders' => 'Production Orders',
        'mrc'               => 'Material Requisitions',
        'wip'               => 'WIP Jobs',
        'finished_goods'    => 'Finished Goods',
        'scrap'             => 'Scrap / Hard Waste',
        'rejections'        => 'Rejections',
        'consumption'       => 'Consumption',
        'yield'             => 'Yield / Variance',
    ];
@endphp

@can('tenant.manufacturing.reports.export')
<div class="dropdown">
    <button class="btn btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="ti ti-download me-1"></i>Export CSV
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        @foreach($reports as $key => $label)
            <li><a class="dropdown-item" href="{{ $exportUrl($key) }}">{{ $label }}</a></li>
        @endforeach
    </ul>
</div>
@endcan
