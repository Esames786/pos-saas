@extends('layouts.app')

@section('title', 'Making Adjustment')

@section('content')
@php
    $p = $preview;
    $proposed = $p['proposed'];
    $mode = $p['mode'] ?? 'set';
    $categories = collect($p['products'])->pluck('category_name', 'category_id')->sort();
@endphp
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Making Adjustment</h1>
        <div class="text-muted">
            Change the Making charge across the menu — preview first, then apply only what you tick.
            Only charges classified as <strong>Making</strong> on a dish's Cost Blocks can move here;
            Packing, Waiter, Decoration and every other charge stay untouched.
        </div>
    </div>
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.screen-impact', ['manages' => 'The Making charge on dishes and draft quotations you explicitly select.', 'managesUr' => 'صرف منتخب ڈشز اور ڈرافٹ تخمینوں کی میکنگ۔', 'reversible' => 'safe', 'note' => 'Previewing changes nothing. Applying moves NO stock and posts NOTHING to finance — it changes a charge figure and reprices the selected documents. Sent quotations are never touched; use Create Revision.', 'noteUr' => 'پیش منظر سے کچھ تبدیل نہیں ہوتا۔ لاگو کرنے سے نہ سٹاک حرکت کرتا ہے نہ کھاتے۔'])

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label">Change type</label>
                <select name="mode" class="form-select">
                    <option value="set" @selected($mode === 'set')>Set exact rate</option>
                    <option value="increase" @selected($mode === 'increase')>Increase (+)</option>
                    <option value="decrease" @selected($mode === 'decrease')>Decrease (−)</option>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label">{{ $mode === 'set' ? 'New Making rate' : 'Change amount' }}</label>
                <input type="number" step="0.01" min="0" name="proposed_rate" class="form-control"
                       style="width:160px" value="{{ $proposed !== null ? number_format($proposed, 2, '.', '') : '' }}"
                       placeholder="e.g. 350" required>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary">Preview Impact</button>
            </div>
            <div class="col text-muted fs-13 align-self-center">
                {{ $p['classified_count'] }} {{ \Illuminate\Support\Str::plural('dish', $p['classified_count']) }}
                {{ $p['classified_count'] === 1 ? 'has' : 'have' }} a Making charge classified.
                @if($p['classified_count'] === 0)
                    Open a dish's <a href="{{ url('/catering/profiles') }}">Cost Blocks</a> and set its
                    labour charge's role to <strong>Making</strong> first — nothing is guessed from labels.
                @endif
            </div>
        </form>
    </div>
</div>

{{-- ── Dishes (product masters) ──────────────────────────────────────────── --}}
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h5 class="mb-0 me-2">Dishes</h5>
            <select id="making-category-filter" class="form-select form-select-sm" style="width:220px">
                <option value="">All categories</option>
                @foreach($categories as $categoryId => $categoryName)
                    <option value="{{ $categoryId }}">{{ $categoryName }}</option>
                @endforeach
            </select>
            @if($proposed !== null)
                <button type="button" class="btn btn-sm btn-outline-secondary js-select-visible" data-target="product-making-row">Select visible</button>
                <button type="button" class="btn btn-sm btn-link js-clear-selection" data-target="product-making-row">Clear</button>
            @endif
        </div>
        @if($proposed !== null && count($p['products']))
            @can('tenant.catering.making-adjustment.apply-products')
                <button class="btn btn-sm btn-primary" form="apply-products-form"
                        onclick="return confirm('Apply Making {{ number_format($proposed, 2) }} to the selected dishes? Existing quotations keep their own snapshots.')">
                    Apply to Selected Dishes
                </button>
            @endcan
        @endif
    </div>
    <div class="card-body p-0">
        <form id="apply-products-form" method="POST" action="{{ url('/catering/making-adjustment/apply-products') }}">
            @csrf
            <input type="hidden" name="proposed_rate" value="{{ $proposed }}">
            <input type="hidden" name="mode" value="{{ $mode }}">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr class="text-muted fs-12">
                            @if($proposed !== null)<th style="width:34px" class="ps-3"></th>@endif
                            <th @if($proposed === null) class="ps-3" @endif>Product</th>
                            <th class="text-end">Current Making</th>
                            <th class="text-end">New Making</th>
                            <th class="text-end">Old Calculated Rate</th>
                            <th class="text-end">New Calculated Rate</th>
                            <th class="text-end pe-3">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($p['products'] as $row)
                        <tr class="product-making-row" data-category="{{ $row['category_id'] }}">
                            @if($proposed !== null)
                                <td class="ps-3">
                                    <input type="checkbox" class="form-check-input" name="block_ids[]" value="{{ $row['block_id'] }}">
                                </td>
                            @endif
                            <td @if($proposed === null) class="ps-3" @endif>
                                {{ $row['product_name'] }}
                                <span class="text-muted fs-12">· {{ $row['label'] }}</span>
                                @if($row['charge_basis'] === 'lump_sum')
                                    <span class="badge bg-light text-muted fs-12"
                                          data-bs-toggle="tooltip"
                                          title="Charged once per booking — changing it moves the one-off amount, never the per-unit rate.">lump sum</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($row['current_making'], 2) }}</td>
                            <td class="text-end fw-semibold">{{ $row['new_making'] !== null ? number_format($row['new_making'], 2) : '—' }}</td>
                            <td class="text-end">{{ number_format($row['old_calculated_rate'], 2) }}</td>
                            <td class="text-end">{{ $row['new_calculated_rate'] !== null ? number_format($row['new_calculated_rate'], 2) : '—' }}</td>
                            <td class="text-end pe-3 {{ ($row['difference'] ?? 0) > 0 ? 'text-success' : (($row['difference'] ?? 0) < 0 ? 'text-danger' : 'text-muted') }}">
                                {{ $row['difference'] !== null ? ($row['difference'] > 0 ? '+' : '').number_format($row['difference'], 2) : '—' }}
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">
                            No dish has a Making charge classified yet.
                        </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>

{{-- ── Draft quotations ──────────────────────────────────────────────────── --}}
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h5 class="mb-0 me-2">Draft Quotations</h5>
            @if($proposed !== null)
                <button type="button" class="btn btn-sm btn-outline-secondary js-select-visible" data-target="draft-making-row">Select all</button>
                <button type="button" class="btn btn-sm btn-link js-clear-selection" data-target="draft-making-row">Clear</button>
            @endif
        </div>
        @if($proposed !== null && count($p['drafts']))
            @can('tenant.catering.making-adjustment.apply-drafts')
                <button class="btn btn-sm btn-primary" form="apply-drafts-form"
                        onclick="return confirm('Apply Making {{ number_format($proposed, 2) }} to the selected draft lines? Agreed quoted rates keep their reasons.')">
                    Apply to Selected Drafts
                </button>
            @endcan
        @endif
    </div>
    <div class="card-body p-0">
        <form id="apply-drafts-form" method="POST" action="{{ url('/catering/making-adjustment/apply-drafts') }}">
            @csrf
            <input type="hidden" name="proposed_rate" value="{{ $proposed }}">
            <input type="hidden" name="mode" value="{{ $mode }}">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr class="text-muted fs-12">
                            @if($proposed !== null)<th style="width:34px" class="ps-3"></th>@endif
                            <th @if($proposed === null) class="ps-3" @endif>Event / Quotation</th>
                            <th>Item</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Current Making</th>
                            <th class="text-end">Proposed</th>
                            <th class="text-end">Old Calculated</th>
                            <th class="text-end">New Calculated</th>
                            <th class="text-end">Difference</th>
                            <th class="text-end pe-3">Quoted Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($p['drafts'] as $row)
                        <tr class="draft-making-row" data-category="{{ $row['category_id'] }}">
                            @if($proposed !== null)
                                <td class="ps-3">
                                    <input type="checkbox" class="form-check-input" name="snapshot_ids[]" value="{{ $row['snapshot_id'] }}">
                                </td>
                            @endif
                            <td @if($proposed === null) class="ps-3" @endif>
                                {{ $row['event_no'] }} <span class="text-muted">Q{{ $row['version_no'] }}</span>
                            </td>
                            <td>{{ $row['item_name'] }}</td>
                            <td class="text-end">{{ rtrim(rtrim(number_format($row['quantity'], 3), '0'), '.') }}</td>
                            <td class="text-end">{{ number_format($row['current_making'], 2) }}</td>
                            <td class="text-end fw-semibold">{{ $row['new_making'] !== null ? number_format($row['new_making'], 2) : '—' }}</td>
                            <td class="text-end">{{ number_format($row['old_calculated_rate'], 2) }}</td>
                            <td class="text-end">{{ $row['new_calculated_rate'] !== null ? number_format($row['new_calculated_rate'], 2) : '—' }}</td>
                            <td class="text-end">{{ $row['difference'] !== null ? ($row['difference'] > 0 ? '+' : '').number_format($row['difference'], 2) : '—' }}</td>
                            <td class="text-end pe-3">
                                {{ number_format($row['quoted_rate'], 2) }}
                                @if($row['quoted_is_override'])
                                    <div class="fs-12 text-warning-emphasis"
                                         data-bs-toggle="tooltip" title="{{ $row['quoted_override_reason'] }}">
                                        agreed rate — kept
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">
                            No draft quotation carries a Making charge snapshot.
                        </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>

{{-- ── Documents that cannot move ────────────────────────────────────────── --}}
@if(count($p['ineligible_documents']))
<div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">Not Adjustable Here</h5></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead>
                <tr class="text-muted fs-12">
                    <th class="ps-3">Event / Quotation</th>
                    <th>Item</th>
                    <th class="text-end">Current Making</th>
                    <th class="pe-3">Why</th>
                </tr>
            </thead>
            <tbody>
                @foreach($p['ineligible_documents'] as $row)
                <tr class="text-muted">
                    <td class="ps-3">{{ $row['event_no'] }} <span class="fs-12">Q{{ $row['version_no'] }}</span></td>
                    <td>{{ $row['item_name'] }}</td>
                    <td class="text-end">{{ number_format($row['current_making'], 2) }}</td>
                    <td class="pe-3 fs-12">{{ $row['reason'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
$(document).on('change', '#making-category-filter', function () {
    const category = String(this.value);
    $('.product-making-row, .draft-making-row').each(function () {
        $(this).toggle(!category || String(this.dataset.category) === category);
    });
});
$(document).on('click', '.js-select-visible', function () {
    $('.' + this.dataset.target + ':visible input[type=checkbox]').prop('checked', true);
});
$(document).on('click', '.js-clear-selection', function () {
    $('.' + this.dataset.target + ' input[type=checkbox]').prop('checked', false);
});
</script>
@endpush
