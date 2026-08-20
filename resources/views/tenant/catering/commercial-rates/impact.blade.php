@extends('layouts.app')

@section('title', 'Rate Impact — ' . $material->name)

@section('content')
@php
    use App\Services\Catering\CateringCommercialRateImpactService as Impact;

    $stateBadge = [
        Impact::STATE_APPLICABLE => 'bg-primary-subtle text-primary-emphasis',
        Impact::STATE_REVISION_REQUIRED => 'bg-warning-subtle text-warning-emphasis',
        Impact::STATE_LOCKED => 'bg-dark-subtle text-dark-emphasis',
        Impact::STATE_CUSTOMER_SUPPLIED => 'bg-success-subtle text-success-emphasis',
        Impact::STATE_UNIT_MISMATCH => 'bg-danger-subtle text-danger-emphasis',
    ];
    $money = fn ($n) => $n === null ? '—' : number_format($n, 2);
    $signed = fn ($n) => $n === null ? '—' : ($n > 0 ? '+' : '').number_format($n, 2);
    $tone = fn ($n) => $n === null ? 'text-muted' : ($n > 0 ? 'text-danger' : ($n < 0 ? 'text-success' : 'text-muted'));
@endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">What would change?</h1>
        <p class="fw-medium mb-0">
            {{ $material->name }} —
            @if($impact['recommended'] !== null)
                house rate now <strong>{{ number_format($impact['recommended'], 2) }}</strong>
                per {{ $impact['recommended_unit'] ?? 'unit' }}
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
    'note' => 'Nothing changes until you select it and apply. Applying to a dish changes what FUTURE quotations are priced at; applying to a draft reprices that one quotation. A sent quotation is never changed in place — it can only take the rate by becoming a new version.',
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
                        <td class="text-end text-muted">
                            {{ rtrim(rtrim(number_format($row['ratio'], 4), '0'), '.') }} {{ $row['unit_code'] }}
                        </td>
                        <td class="text-end">{{ $money($row['applied_rate']) }}</td>
                        <td class="text-end">{{ $money($row['recommended_rate']) }}</td>
                        <td class="text-end">{{ $money($row['old_calculated_rate']) }}</td>
                        <td class="text-end fw-semibold">{{ $money($row['projected_calculated_rate']) }}</td>
                        <td class="text-end fw-semibold {{ $tone($row['difference']) }}">{{ $signed($row['difference']) }}</td>
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

{{-- Why something is not on the list is as useful as why something is, and it is
     deliberately shown WITHOUT a number: an excluded row carrying "+200" reads as
     a change the system is refusing to make, when 200 is not its impact at all. --}}
@if(count($impact['ineligible']))
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0">Not following the house rate</h5></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Dish</th><th class="text-end">Charging</th><th>Why it is left alone</th></tr></thead>
            <tbody>
                @foreach($impact['ineligible'] as $row)
                <tr>
                    <td>
                        {{ $row['product_name'] }} <span class="text-muted fs-12">· {{ $row['label'] }}</span>
                        @if($row['state'] === Impact::STATE_UNIT_MISMATCH)
                            <span class="badge {{ $stateBadge[$row['state']] }} fs-12 ms-1">Unit mismatch</span>
                        @endif
                    </td>
                    <td class="text-end">{{ $money($row['applied_rate']) }}</td>
                    <td class="text-muted fs-13">{{ $row['reason'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ── Quotations ──────────────────────────────────────────────────────────
     Three rates, because they answer three different questions and only the
     third one is what the customer was told. --}}
<form method="POST" action="{{ url('/catering/commercial-rates/' . $material->id . '/apply-drafts') }}">
    @csrf
    <div class="card mb-4">
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
                        <th>State</th>
                        <th class="text-end">Material</th>
                        <th class="text-end">Material change</th>
                        <th class="text-end">Calculated now</th>
                        <th class="text-end">Would become</th>
                        <th class="text-end">Quoted</th>
                        <th class="text-end">Quotation change</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($drafts as $row)
                    <tr class="{{ $row['shows_impact'] ? '' : 'text-muted' }}">
                        <td>
                            @if($row['eligible'])
                                <input type="checkbox" class="form-check-input" name="snapshot_ids[]" value="{{ $row['snapshot_id'] }}">
                            @endif
                        </td>
                        <td>
                            {{ $row['event_no'] }} <span class="text-muted fs-12">v{{ $row['version_no'] }}</span>
                            <div class="fs-12 text-muted">{{ $row['customer'] }}</div>
                        </td>
                        <td>
                            {{ $row['item_name'] }}
                            <div class="fs-12 text-muted">{{ $row['label'] }}</div>
                        </td>
                        <td>
                            <span class="badge {{ $stateBadge[$row['state']] ?? 'bg-secondary-subtle text-secondary-emphasis' }} fs-12">
                                {{ $row['state_label'] }}
                            </span>
                        </td>
                        <td class="text-end">
                            {{ rtrim(rtrim(number_format($row['material_qty'], 4), '0'), '.') }} {{ $row['unit_code'] }}
                        </td>
                        <td class="text-end {{ $tone($row['difference']) }}">{{ $signed($row['difference']) }}</td>
                        <td class="text-end">{{ $money($row['old_calculated_rate']) }}</td>
                        <td class="text-end fw-semibold">{{ $money($row['projected_calculated_rate']) }}</td>
                        <td class="text-end">
                            {{ $money($row['quoted_rate']) }}
                            @if($row['quoted_is_override'])
                                <div class="fs-12 text-warning-emphasis">agreed rate</div>
                            @endif
                        </td>
                        <td class="text-end fw-semibold {{ $tone($row['quotation_difference']) }}">
                            {{ $signed($row['quotation_difference']) }}
                        </td>
                        <td class="text-end">
                            @if($row['revisable'])
                                @can('tenant.catering.commercial-rates.revise-and-apply')
                                    <button type="submit" form="revise-{{ $row['snapshot_id'] }}"
                                            class="btn btn-sm btn-outline-primary text-nowrap"
                                            onclick="return confirm('Create a new revision of {{ $row['event_no'] }} and apply the house rate to it? The sent version is kept as it was.')">
                                        Create Revision &amp; Apply
                                    </button>
                                @endcan
                            @endif
                        </td>
                    </tr>
                    @if($row['reason'])
                    <tr class="text-muted">
                        <td></td>
                        <td colspan="10" class="fs-12 pt-0">
                            {{ $row['reason'] }}
                            @if($row['shows_impact'] && $row['quoted_is_override'])
                                The agreed rate of {{ $money($row['quoted_rate']) }} stays where it is —
                                only the calculation underneath it moves.
                            @endif
                        </td>
                    </tr>
                    @elseif($row['quoted_is_override'])
                    <tr class="text-muted">
                        <td></td>
                        <td colspan="10" class="fs-12 pt-0">
                            Quoted at {{ $money($row['quoted_rate']) }} by agreement
                            @if($row['quoted_override_reason']) — {{ $row['quoted_override_reason'] }} @endif.
                            Applying moves the calculation to {{ $money($row['projected_calculated_rate']) }};
                            the customer still pays {{ $money($row['quoted_rate']) }} unless somebody chooses
                            <em>Use calculated rate</em> on the quotation.
                        </td>
                    </tr>
                    @endif
                @empty
                    <tr><td colspan="11" class="text-center text-muted py-4">
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

{{-- One form per revisable quotation, outside the selection form above — a sent
     quotation is a different act from a bulk reprice and must not be able to ride
     along with one. --}}
@can('tenant.catering.commercial-rates.revise-and-apply')
    @foreach($drafts as $row)
        @if($row['revisable'])
            <form id="revise-{{ $row['snapshot_id'] }}" method="POST"
                  action="{{ url('/catering/commercial-rates/' . $material->id . '/revise-and-apply') }}" class="d-none">
                @csrf
                <input type="hidden" name="estimate_id" value="{{ $row['estimate_id'] }}">
            </form>
        @endif
    @endforeach
@endcan

{{-- ── What has actually been done ─────────────────────────────────────────
     A selective apply is only defensible if it can be read back afterwards. --}}
@if($log->isNotEmpty())
<div class="card">
    <div class="card-header"><h5 class="mb-0">Recent rate activity</h5></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>When</th><th>What</th><th>Where</th>
                    <th class="text-end">Rate</th><th class="text-end">Calculated</th><th>By</th>
                </tr>
            </thead>
            <tbody>
            @foreach($log as $entry)
                <tr>
                    <td class="text-muted fs-13">{{ $entry->created_at?->format('d M Y H:i') }}</td>
                    <td class="fs-13">{{ ucfirst(str_replace('_', ' ', $entry->action)) }}</td>
                    <td class="fs-13">{{ $entry->target_label }}</td>
                    <td class="text-end fs-13">
                        {{ $entry->old_commercial_rate === null ? '—' : number_format($entry->old_commercial_rate, 2) }}
                        &rarr; {{ $entry->new_commercial_rate === null ? '—' : number_format($entry->new_commercial_rate, 2) }}
                    </td>
                    <td class="text-end fs-13">
                        @if($entry->old_calculated_rate === null && $entry->new_calculated_rate === null)
                            —
                        @else
                            {{ number_format((float) $entry->old_calculated_rate, 2) }}
                            &rarr; {{ number_format((float) $entry->new_calculated_rate, 2) }}
                        @endif
                    </td>
                    <td class="fs-13 text-muted">{{ $entry->performedBy?->name ?? 'system' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
