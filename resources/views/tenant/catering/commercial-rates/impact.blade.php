@extends('layouts.app')

@section('title', 'Rate Impact — ' . $material->name)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">What would change?</h1>
        <p class="fw-medium mb-0">
            {{ $material->name }} —
            @if($impact['recommended'] !== null)
                house rate now <strong>{{ number_format($impact['recommended'], 2) }}</strong> per unit
            @else
                no house rate set
            @endif
        </p>
    </div>
    <a href="{{ url('/catering/commercial-rates') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1"></i>Back to rates
    </a>
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.screen-impact', [
    'manages' => 'Which dishes and quotations would move if they followed the house rate — and which of them actually do.',
    'managesUr' => 'کون سی ڈشیں اور تخمینے نئے ریٹ سے متاثر ہوں گے۔',
    'reversible' => 'partly',
    'note' => 'Nothing changes until you select it and apply. Applying to a dish changes what FUTURE quotations are priced at; applying to a draft reprices that one quotation. Sent quotations are never changed in place.',
    'noteUr' => 'جب تک آپ منتخب کر کے اپلائی نہ کریں، کچھ تبدیل نہیں ہوتا۔',
])

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

{{-- ── Dishes ──────────────────────────────────────────────────────────── --}}
<form method="POST" action="{{ url('/catering/commercial-rates/' . $material->id . '/apply-products') }}">
    @csrf
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h5 class="mb-0">Dishes that follow the house rate</h5>
            <span class="text-muted fs-12">Changes what future quotations are priced at</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width:32px"></th>
                        <th>Dish</th>
                        <th class="text-end">Uses</th>
                        <th class="text-end">Charging now</th>
                        <th class="text-end">House rate</th>
                        <th class="text-end">Rate now</th>
                        <th class="text-end">Would become</th>
                        <th class="text-end">Change</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($impact['products'] as $row)
                    <tr>
                        <td><input type="checkbox" class="form-check-input" name="block_ids[]" value="{{ $row['block_id'] }}"></td>
                        <td>{{ $row['product_name'] }} <span class="text-muted fs-12">· {{ $row['label'] }}</span></td>
                        <td class="text-end text-muted">{{ rtrim(rtrim(number_format($row['ratio'], 4), '0'), '.') }}</td>
                        <td class="text-end">{{ number_format($row['applied_rate'], 2) }}</td>
                        <td class="text-end">{{ number_format($row['recommended_rate'] ?? 0, 2) }}</td>
                        <td class="text-end">{{ number_format($row['old_calculated_rate'], 2) }}</td>
                        <td class="text-end fw-semibold">{{ number_format($row['projected_calculated_rate'], 2) }}</td>
                        <td class="text-end fw-semibold {{ $row['difference'] > 0 ? 'text-danger' : ($row['difference'] < 0 ? 'text-success' : 'text-muted') }}">
                            {{ $row['difference'] > 0 ? '+' : '' }}{{ number_format($row['difference'], 2) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">
                        No dish follows the house rate for this material.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if(count($impact['products']))
            @can('tenant.catering.commercial-rates.apply-products')
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted fs-12">
                    Quotations already drafted or sent are not touched by this.
                </span>
                <button class="btn btn-primary btn-sm"
                        onclick="return confirm('Apply the house rate to the selected dishes? Existing quotations are not changed.')">
                    Apply to selected dishes
                </button>
            </div>
            @endcan
        @endif
    </div>
</form>

{{-- Why something is not on the list is as useful as why something is. --}}
@if(count($impact['ineligible']))
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0">Not following the house rate</h5></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Dish</th><th class="text-end">Charging</th><th>Why it is left alone</th></tr></thead>
            <tbody>
                @foreach($impact['ineligible'] as $row)
                <tr>
                    <td>{{ $row['product_name'] }} <span class="text-muted fs-12">· {{ $row['label'] }}</span></td>
                    <td class="text-end">{{ number_format($row['applied_rate'], 2) }}</td>
                    <td class="text-muted fs-13">{{ $row['reason'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ── Quotations ──────────────────────────────────────────────────────── --}}
<form method="POST" action="{{ url('/catering/commercial-rates/' . $material->id . '/apply-drafts') }}">
    @csrf
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h5 class="mb-0">Quotations priced at the old rate</h5>
            <span class="text-muted fs-12">Reprices that one quotation</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width:32px"></th>
                        <th>Booking</th>
                        <th>Dish</th>
                        <th>Status</th>
                        <th class="text-end">Material</th>
                        <th class="text-end">Charged now</th>
                        <th class="text-end">Would become</th>
                        <th class="text-end">Change</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($drafts as $row)
                    <tr class="{{ $row['eligible'] ? '' : 'text-muted' }}">
                        <td>
                            @if($row['eligible'])
                                <input type="checkbox" class="form-check-input" name="snapshot_ids[]" value="{{ $row['snapshot_id'] }}">
                            @endif
                        </td>
                        <td>
                            {{ $row['event_no'] }}
                            <div class="fs-12 text-muted">{{ $row['customer'] }}</div>
                        </td>
                        <td>{{ $row['item_name'] }}</td>
                        <td>
                            @if($row['customer_supplied'])
                                <span class="badge bg-success-subtle text-success-emphasis fs-12">Customer supplied</span>
                            @elseif($row['status'] === 'draft')
                                <span class="badge bg-primary-subtle text-primary-emphasis fs-12">Draft</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary-emphasis fs-12">{{ ucfirst($row['status']) }}</span>
                            @endif
                        </td>
                        <td class="text-end">{{ rtrim(rtrim(number_format($row['material_qty'], 4), '0'), '.') }}</td>
                        <td class="text-end">{{ number_format($row['old_amount'], 2) }}</td>
                        <td class="text-end">{{ $row['eligible'] ? number_format($row['new_amount'], 2) : '—' }}</td>
                        <td class="text-end fw-semibold {{ $row['difference'] > 0 ? 'text-danger' : ($row['difference'] < 0 ? 'text-success' : 'text-muted') }}">
                            {{ $row['difference'] > 0 ? '+' : '' }}{{ number_format($row['difference'], 2) }}
                        </td>
                    </tr>
                    @if(! $row['eligible'] && $row['reason'])
                    <tr class="text-muted">
                        <td></td>
                        <td colspan="7" class="fs-12 pt-0">{{ $row['reason'] }}</td>
                    </tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">
                        No quotation uses this material yet.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if(collect($drafts)->where('eligible', true)->isNotEmpty())
            @can('tenant.catering.commercial-rates.apply-drafts')
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted fs-12">
                    A rate someone agreed with a customer stays where it is — only the calculation moves.
                </span>
                <button class="btn btn-primary btn-sm"
                        onclick="return confirm('Reprice the selected quotations at the house rate?')">
                    Apply to selected quotations
                </button>
            </div>
            @endcan
        @endif
    </div>
</form>
@endsection
