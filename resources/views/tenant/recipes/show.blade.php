@extends('layouts.app')

@section('title', $recipe->name . ' — Recipe Cost')

@php
    $b = $breakdown;
    $fp = $b['finished_product'];
    $businessName = (app()->bound('tenant') ? optional(app('tenant'))->name : null) ?: config('saas.brand_name', 'Restaurant');
    // Two GP bases (Technosys shows both; identical when GST = 0).
    $gpEx    = round(($b['sale_price'] ?? 0) - ($b['cost_price'] ?? 0), 2);
    $gpExPct = ($b['cost_price'] ?? 0) > 0 ? round($gpEx / $b['cost_price'] * 100, 2) : 0;
    $numCols = 11;
@endphp

@section('content')
<style>
    .recipe-report .table { font-size:.82rem; }
    .recipe-report .table td, .recipe-report .table th { padding:.35rem .5rem; vertical-align:top; }
    .rr-section-head td { background:#f1f3f5; font-weight:700; border-top:2px solid #adb5bd; }
    .rr-total-row td { border-top:2px solid #adb5bd; font-weight:700; }
    .rr-grand td { border-top:3px double #495057; font-weight:700; background:#f8f9fa; }
    .rr-num { text-align:right; white-space:nowrap; }
    .recipe-report h2 { font-size:1.4rem; }
    @media print {
        body * { visibility:hidden; }
        #recipe-report, #recipe-report * { visibility:visible; }
        #recipe-report { position:absolute; left:0; top:0; width:100%; }
        .no-print { display:none !important; }
    }
</style>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 no-print">
    <h1 class="mb-0">Recipe Cost — {{ $recipe->name }}</h1>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="ti ti-printer me-1"></i>Print</button>
        @can('tenant.recipes.edit')
            <a href="{{ url('/recipes/' . $recipe->id . '/edit') }}" class="btn btn-light">Edit</a>
        @endcan
        <a href="{{ url('/recipes') }}" class="btn btn-light">Back</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show no-print">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card recipe-report" id="recipe-report">
    <div class="card-body">
        {{-- ── Header ──────────────────────────────────────────────────── --}}
        <div class="text-center mb-3">
            <h2 class="mb-0">{{ $businessName }}</h2>
            <div class="fw-semibold">Item Ingredients</div>
            @if($recipe->is_active)
                <div class="text-success fw-bold">ACTIVE RECIPE</div>
            @else
                <div class="text-muted fw-bold">INACTIVE RECIPE</div>
            @endif
        </div>

        <div class="row mb-3 small">
            <div class="col-md-6">
                <div><strong>Doc #:</strong> {{ $recipe->doc_no ?: '—' }}</div>
                <div><strong>Revision #:</strong> {{ $recipe->revision_no ?? 1 }}</div>
                <div><strong>Recipe #:</strong> {{ $recipe->recipe_no ?: $recipe->id }}</div>
                <div><strong>Item Name:</strong> ({{ $fp?->sku }}) {{ $fp?->name }}</div>
                <div><strong>Production Qty:</strong> {{ rtrim(rtrim(number_format((float) $recipe->yield_quantity, 4), '0'), '.') }} {{ $recipe->yieldUnit?->code }}</div>
            </div>
            <div class="col-md-6">
                <div><strong>Review Date:</strong> {{ optional($recipe->review_date)->format('d-M-Y') ?: '—' }}</div>
                <div><strong>Date:</strong> {{ now()->format('d-M-Y g:i a') }}</div>
                <div><strong>Production Weight:</strong> —</div>
            </div>
        </div>

        {{-- ── Ingredient cost table ───────────────────────────────────── --}}
        <div class="table-responsive">
        <table class="table table-bordered align-middle mb-2">
            <thead class="table-light">
                <tr>
                    <th>S.No</th>
                    <th>Barcode</th>
                    <th>Item Description</th>
                    <th>Int.</th>
                    <th class="rr-num">Prod. Qty</th>
                    <th class="rr-num">Prod. Weight</th>
                    <th>Pur. Unit</th>
                    <th class="rr-num">Price / Unit</th>
                    <th class="rr-num">Cost Price</th>
                    <th class="rr-num">Amount</th>
                    <th class="rr-num">Per %</th>
                </tr>
            </thead>
            <tbody>
                @forelse($b['sections'] as $section)
                    <tr class="rr-section-head"><td colspan="{{ $numCols }}">{{ $section['label'] }}</td></tr>
                    @foreach($section['lines'] as $line)
                        <tr>
                            <td>{{ $line['s_no'] }}</td>
                            <td>{{ $line['barcode'] }}</td>
                            <td>{{ $line['item_description'] }}</td>
                            <td class="text-center">{{ $line['is_intermediate'] ? '✓' : '' }}</td>
                            <td class="rr-num">{{ rtrim(rtrim(number_format($line['prod_qty'], 4), '0'), '.') }} {{ $line['prod_unit'] ? '('.$line['prod_unit'].')' : '' }}</td>
                            <td class="rr-num">{{ rtrim(rtrim(number_format($line['prod_weight'], 4), '0'), '.') }}</td>
                            <td>{{ $line['purchase_unit'] }}</td>
                            <td class="rr-num">{{ number_format($line['price_per_unit'], 2) }}</td>
                            <td class="rr-num">{{ number_format($line['cost_price'], 2) }}</td>
                            <td class="rr-num">{{ number_format($line['amount'], 2) }}</td>
                            <td class="rr-num">{{ number_format($line['percent'], 2) }}%</td>
                        </tr>
                    @endforeach
                    <tr class="rr-total-row">
                        <td colspan="3">TOTAL ( {{ $section['label'] }} )</td>
                        <td colspan="2">Items: {{ $section['items_count'] }}</td>
                        <td colspan="2">Qty: {{ number_format($section['quantity_total'], 2) }}</td>
                        <td colspan="2" class="rr-num">Amount:</td>
                        <td class="rr-num">{{ number_format($section['amount_total'], 2) }}</td>
                        <td class="rr-num">{{ number_format($section['percent_of_grand'], 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $numCols }}" class="text-center text-muted py-3">No ingredients in this recipe.</td></tr>
                @endforelse

                <tr class="rr-grand">
                    <td colspan="3">Grand Total</td>
                    <td colspan="2">Items: {{ $b['line_count'] }}</td>
                    <td colspan="2">Qty: {{ number_format($b['quantity_total'], 2) }}</td>
                    <td colspan="2" class="rr-num">Amount:</td>
                    <td class="rr-num">{{ number_format($b['grand_total'], 2) }}</td>
                    <td class="rr-num">100.00%</td>
                </tr>
            </tbody>
        </table>
        </div>

        {{-- ── Recipe Cost Comparison ──────────────────────────────────── --}}
        <h6 class="fw-bold mt-3 mb-2 border rounded px-2 py-1 d-inline-block">Recipe Cost Comparison</h6>
        <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th class="rr-num">Sale Price</th>
                    <th class="rr-num">Sale Price with GST</th>
                    <th class="rr-num">Cost Price</th>
                    <th class="rr-num">GP Amount (%)</th>
                    <th class="rr-num">GP Amount With GST (%)</th>
                    <th class="rr-num">Recipe Cost Price</th>
                    <th class="rr-num">Overall Cost (%)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="rr-num">{{ number_format($b['sale_price'], 2) }}</td>
                    <td class="rr-num">{{ number_format($b['sale_price_with_gst'], 2) }}</td>
                    <td class="rr-num">{{ number_format($b['cost_price'], 2) }}</td>
                    <td class="rr-num">{{ number_format($gpEx, 2) }} ({{ number_format($gpExPct, 2) }})</td>
                    <td class="rr-num">{{ number_format($b['gp_amount'], 2) }} ({{ number_format($b['gp_percent_on_cost'], 2) }})</td>
                    <td class="rr-num">{{ number_format($b['recipe_cost_price'], 2) }}</td>
                    <td class="rr-num">{{ number_format($b['overall_cost_percent'], 2) }}</td>
                </tr>
            </tbody>
        </table>
        </div>

        <div class="small text-muted">
            @if((float) $recipe->overhead_percent <= 0)
                Cost Price equals Recipe Cost Price because overhead is 0%.
            @else
                Cost Price = Recipe Cost Price + {{ number_format((float) $recipe->overhead_percent, 2) }}% overhead ({{ number_format($b['overhead_amount'], 2) }}).
            @endif
            GP % is calculated on Cost Price. Overall Cost % = Recipe Cost ÷ Sale Price (with GST).
        </div>

        <div class="text-end small text-muted mt-3 border-top pt-2">
            Printed: {{ now()->format('d-M-Y g:i a') }} · {{ $businessName }}
        </div>
    </div>
</div>

@if($print)
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
@endif
@endsection
