@php
    $editing = isset($combo);
    // DEAL-SPLIT-REPORT-1: false = the form exactly as before (switch off, or the columns not there yet).
    $dealSplit = (bool) ($dealSplit ?? false);
    $reportByItems = $dealSplit && (bool) old('report_by_items', $editing ? (bool) $combo->report_by_items : false);
    $componentRows = old('components', $editing ? $combo->components->map(fn ($component) => [
        'product_id' => $component->product_id,
        'product_variant_id' => $component->product_variant_id,
        'quantity' => $component->quantity,
        'sort_order' => $component->sort_order,
    ] + ($dealSplit ? ['report_amount' => $component->report_amount] : []))->all() : [['product_id' => '', 'product_variant_id' => '', 'quantity' => 1, 'sort_order' => 10]]);
    if ($dealSplit) {
        // The live line under the rows, as the page opens (the script keeps it current while typing).
        $rsText = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $partsPaisa = (int) round(collect($componentRows)->sum(fn ($r) => (float) ($r['report_amount'] ?? 0)) * 100);
        $pricePaisa = (int) round((float) old('price', $combo->price ?? 0) * 100);
        $partsGap = $pricePaisa - $partsPaisa;
        $partsLine = 'Parts add up to ' . $rsText($partsPaisa / 100) . ' — deal price ' . $rsText($pricePaisa / 100)
            . ($partsGap > 1 ? ' (' . $rsText($partsGap / 100) . ' short)' : '')
            . ($partsGap < -1 ? ' (' . $rsText(-$partsGap / 100) . ' too much)' : '');
        $partsEqual = abs($partsGap) <= 1;
    }
@endphp
@extends('layouts.app')

@section('title', $editing ? 'Edit Combo' : 'Create Combo')

@section('content')
    <div class="page-header">
        <div class="page-title">
            <h4>{{ $editing ? 'Edit Combo' : 'New Combo' }}</h4>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ $editing ? url('/combos/' . $combo->id) : url('/combos') }}" enctype="multipart/form-data">
                @csrf
                @if($editing) @method('PUT') @endif

                @if($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required" for="name">Name</label>
                        <input id="name" name="name" class="form-control" value="{{ old('name', $combo->name ?? '') }}" required>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="code">Code</label>
                        <input id="code" name="code" class="form-control" value="{{ old('code', $combo->code ?? '') }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label required" for="price">Combo Price</label>
                        <input id="price" type="number" step="0.01" min="0" name="price" class="form-control" value="{{ old('price', $combo->price ?? '') }}" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="branch_id">Branch</label>
                        <select id="branch_id" name="branch_id" class="form-select">
                            <option value="">All branches</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('branch_id', $combo->branch_id ?? '') === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        {{-- POS-COMBO-CATEGORY-1: optional POS tab grouping for this deal (Al-Faham /
                             Midnight / Platters …). Leave blank to keep it under the shared "Deals" tab. --}}
                        <label class="form-label" for="category_id">POS Category <span class="text-muted small">(deal grouping)</span></label>
                        <select id="category_id" name="category_id" class="form-select">
                            <option value="">Deals (default)</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected((string) old('category_id', $combo->category_id ?? '') === (string) $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label required" for="status">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="active" @selected(old('status', $combo->status ?? 'active') === 'active')>Active</option>
                            <option value="inactive" @selected(old('status', $combo->status ?? '') === 'inactive')>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="sort_order">Sort Order</label>
                        <input id="sort_order" type="number" min="0" name="sort_order" class="form-control" value="{{ old('sort_order', $combo->sort_order ?? 0) }}">
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label" for="description">Description</label>
                        <textarea id="description" name="description" rows="2" class="form-control">{{ old('description', $combo->description ?? '') }}</textarea>
                    </div>
                </div>

                @if($dealSplit)
                    {{-- DEAL-SPLIT-REPORT-1: report-only. The bill, the customer's price, KOT and payments never change. --}}
                    <div class="border rounded p-3 mb-3" id="deal-split">
                        <div class="fw-semibold mb-2">How should this deal show in reports?</div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="report_by_items" id="report_by_items_0" value="0" @checked(! $reportByItems)>
                            <label class="form-check-label" for="report_by_items_0">
                                <strong>One rate</strong> — the whole deal as one line
                                <span class="d-block text-muted small">Reports show the deal under its own name, the same as today.</span>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="report_by_items" id="report_by_items_1" value="1" @checked($reportByItems)>
                            <label class="form-check-label" for="report_by_items_1">
                                <strong>Each item's rate</strong> — split the deal's money between its items
                                <span class="d-block text-muted small">Type each item's part of ONE deal in "Report rate (Rs)" below. The parts must add up to the deal price. Type 0 for an item that should not show. The bill and the customer's price do not change.</span>
                            </label>
                        </div>
                    </div>
                @endif

                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="mb-0">Components</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="add-component-row">
                        <i class="ti ti-plus me-1"></i> Add Component
                    </button>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th style="min-width:260px">Product</th>
                            <th style="min-width:220px">Variant</th>
                            <th style="width:140px">Quantity</th>
                            @if($dealSplit)
                                <th style="width:170px" data-report-col @class(['d-none' => ! $reportByItems])>Report rate (Rs) <span class="text-muted small fw-normal">for 1 deal</span></th>
                            @endif
                            <th style="width:130px">Sort</th>
                            <th style="width:80px"></th>
                        </tr>
                        </thead>
                        <tbody id="component-rows">
                        @foreach($componentRows as $i => $row)
                            @include('tenant.combos.partials.component-row', ['index' => $i, 'row' => $row])
                        @endforeach
                        </tbody>
                    </table>
                </div>

                @if($dealSplit)
                    <div id="deal-split-sum" data-report-col aria-live="polite"
                         @class(['fw-semibold mb-3', 'd-none' => ! $reportByItems, 'text-success' => $partsEqual, 'text-danger' => ! $partsEqual])>{{ $partsLine }}</div>
                @endif

                {{-- ONLINE-ORDERING-1: website placement in its own card at the bottom — only on a plan with Online Ordering; the POS deal does not change. --}}
                @if(\App\Support\OnlineOrdering::enabledForTenant())
                    <div class="mb-4">
                        @include('tenant.partials.website-fields', array_merge(['model' => $combo ?? null, 'kind' => 'combo'], \App\Services\OnlineOrdering\WebsiteFields::formData()))
                    </div>
                @endif

                <div class="d-flex gap-2">
                    <button class="btn btn-primary">{{ $editing ? 'Update Combo' : 'Create Combo' }}</button>
                    <a href="{{ url('/combos') }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <template id="component-row-template">
        @include('tenant.combos.partials.component-row', ['index' => '__INDEX__', 'row' => ['product_id' => '', 'product_variant_id' => '', 'quantity' => 1, 'sort_order' => '', 'report_amount' => '']])
    </template>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const rows = document.getElementById('component-rows');
    const template = document.getElementById('component-row-template');

    document.getElementById('add-component-row')?.addEventListener('click', function () {
        const index = rows.querySelectorAll('[data-component-row]').length;
        rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
    });

    rows.addEventListener('click', function (event) {
        const button = event.target.closest('[data-remove-component]');
        if (!button) return;
        if (rows.querySelectorAll('[data-component-row]').length <= 1) return;
        button.closest('[data-component-row]').remove();
    });
});
</script>
@if($dealSplit)
<script>
// DEAL-SPLIT-REPORT-1: show the Report rate boxes only for "Each item's rate", and keep the live total line current.
document.addEventListener('DOMContentLoaded', function () {
    const box = document.getElementById('deal-split');
    const rows = document.getElementById('component-rows');
    const price = document.getElementById('price');
    const sumLine = document.getElementById('deal-split-sum');
    if (!box || !rows || !price || !sumLine) return;
    const form = box.closest('form');

    function rs(paisa) {
        return (paisa / 100).toLocaleString('en-US', { maximumFractionDigits: 2 });
    }

    function splitOn() {
        const picked = form.querySelector('input[name="report_by_items"]:checked');
        return !!picked && picked.value === '1';
    }

    function refresh() {
        const on = splitOn();
        form.querySelectorAll('[data-report-col]').forEach(function (el) { el.classList.toggle('d-none', !on); });
        // A switched-off box is not sent: "One rate" keeps no parts.
        rows.querySelectorAll('[data-report-amount]').forEach(function (input) { input.disabled = !on; });
        if (!on) return;

        let parts = 0;
        rows.querySelectorAll('[data-report-amount]').forEach(function (input) {
            parts += Math.round(Number(input.value || 0) * 100);
        });
        const deal = Math.round(Number(price.value || 0) * 100);
        const gap = deal - parts;
        let text = 'Parts add up to ' + rs(parts) + ' — deal price ' + rs(deal);
        if (gap > 1) text += ' (' + rs(gap) + ' short)';
        if (gap < -1) text += ' (' + rs(-gap) + ' too much)';
        const equal = Math.abs(gap) <= 1;
        sumLine.textContent = text;
        sumLine.classList.toggle('text-success', equal);
        sumLine.classList.toggle('text-danger', !equal);
    }

    box.addEventListener('change', refresh);
    rows.addEventListener('input', refresh);
    price.addEventListener('input', refresh);
    // Rows added or removed (the Add Component button clones the row template): the new box follows the choice.
    new MutationObserver(refresh).observe(rows, { childList: true });
    refresh();
});
</script>
@endif
@endpush
