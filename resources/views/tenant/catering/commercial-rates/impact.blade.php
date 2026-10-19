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

{{-- ── Quotations, ORDER ke hisaab se ───────────────────────────────────────

     RATE-IMPACT-BY-ORDER-1 (28 Sep) — malik: "order aare hon, main order par
     click karun to collapse khul ke order ki detail aaye, total wagera, aur jis
     item ka rate update hoga wo highlight ho."

     Pehle har qatar ek DISH thi: ek booking ke teen dish teen qatarein bante,
     aur koi qatar ye nahi bata sakti thi ke POORE bill par kya asar parega — jo
     ke asal sawal hai, kyunke graahak bill dekhta hai, dish ki qatar nahi. Sirf
     chicken par 119 qatarein thin, aur un me kaam ki 17.

     Ab ek qatar ek BOOKING hai, aur click par poora order khulta hai. --}}
<form method="POST" action="{{ url('/catering/commercial-rates/' . $material->id . '/apply-drafts') }}">
    @csrf
    <div class="card mb-4" id="order-impact">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h5 class="mb-0">Bookings priced at the old rate</h5>
            <span class="text-muted fs-12">Qatar par click karein — poora order khulta hai</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle" id="order-impact-table">
                <thead>
                    <tr>
                        <th style="width:32px"></th>
                        <th style="min-width:190px;">Booking</th>
                        <th style="min-width:150px;">Haalat</th>
                        <th class="text-end" style="min-width:120px;">Total abhi</th>
                        <th class="text-end" style="min-width:120px;">Total baad me</th>
                        <th class="text-end" style="min-width:110px;">Farq</th>
                        <th style="min-width:150px;"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($orders as $order)
                    @php $oid = 'ord-'.$order['estimate_id']; @endphp
                    <tr class="order-row" data-bs-toggle="collapse" data-bs-target="#{{ $oid }}" role="button">
                        <td onclick="event.stopPropagation()">
                            @if($order['eligible'])
                                <input type="checkbox" class="form-check-input order-pick"
                                       data-target="{{ $oid }}-ids">
                            @endif
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $order['event_no'] }}
                                <span class="text-muted fs-12">v{{ $order['version_no'] }}</span>
                            </div>
                            <div class="fs-12 text-muted">{{ $order['customer'] }}</div>
                        </td>
                        <td>
                            @if($order['eligible'])
                                <span class="badge bg-success-subtle text-success-emphasis">Apply ho sakta hai</span>
                            @elseif($order['revisable'])
                                <span class="badge bg-primary-subtle text-primary-emphasis">Nayi version chahiye</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Band</span>
                            @endif
                            <div class="fs-12 text-muted">{{ ucfirst(str_replace('_', ' ', (string) $order['event_status'])) }}</div>
                        </td>
                        <td class="text-end">{{ $money($order['old_total']) }}</td>
                        <td class="text-end fw-semibold">{{ $money($order['new_total']) }}</td>
                        <td class="text-end fw-semibold {{ $tone($order['total_difference']) }}">
                            {{ $signed($order['total_difference']) }}
                        </td>
                        <td class="text-end" onclick="event.stopPropagation()">
                            @if($order['revisable'])
                                @can('tenant.catering.commercial-rates.revise-and-apply')
                                    <button type="submit" form="revise-{{ $order['estimate_id'] }}"
                                            class="btn btn-sm btn-outline-primary text-nowrap"
                                            onclick="return confirm('{{ $order['event_no'] }} ki nayi version bana kar us par house rate lagayen? Bheji hui version jyon ki tyon rahegi.')">
                                        Nayi version + apply
                                    </button>
                                @endcan
                            @endif
                            <i class="ti ti-chevron-down ms-1 text-muted"></i>
                        </td>
                    </tr>

                    {{-- Poora order — sirf mutasir lines nahi. Operator ko ye
                         dekhna hota hai ke jo badal raha hai wo kis ke beech
                         me hai. --}}
                    <tr class="order-detail-row">
                        <td colspan="7" class="p-0 border-0">
                            <div class="collapse" id="{{ $oid }}">
                                <div class="p-3 bg-body-tertiary">
                                    @if($order['eligible'])
                                        <div id="{{ $oid }}-ids" class="d-none">
                                            @foreach($order['eligible_snapshot_ids'] as $sid)
                                                <input type="checkbox" name="snapshot_ids[]" value="{{ $sid }}" checked disabled>
                                            @endforeach
                                        </div>
                                    @endif
                                    <table class="table table-sm mb-0 align-middle order-lines">
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th class="text-end">Qty</th>
                                                <th class="text-end">Rate</th>
                                                <th class="text-end">Amount abhi</th>
                                                <th class="text-end">Amount baad me</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($order['lines'] as $line)
                                            <tr class="{{ $line['moves'] ? 'line-moves' : ($line['affected'] ? 'line-touched' : '') }}">
                                                <td>
                                                    {{ $line['item_name'] }}
                                                    @if($line['is_override'])
                                                        <span class="badge bg-warning-subtle text-warning-emphasis fs-12">agreed rate</span>
                                                    @endif
                                                </td>
                                                <td class="text-end">{{ rtrim(rtrim(number_format($line['quantity'], 3), '0'), '.') }} {{ $line['unit_code'] }}</td>
                                                <td class="text-end">{{ $money($line['rate']) }}</td>
                                                <td class="text-end">{{ $money($line['amount']) }}</td>
                                                <td class="text-end fw-semibold">
                                                    {{ $money($line['new_amount']) }}
                                                </td>
                                            </tr>
                                            @if($line['affected'] && ! $line['moves'])
                                            <tr class="text-muted">
                                                <td colspan="5" class="fs-12 pt-0 border-0">
                                                    @if($line['is_override'])
                                                        {{-- Ye wo jagah hai jahan operator warna dhoka
                                                             kha jata: apply dabta hai, total wohi rehta
                                                             hai, aur wo samajhta hai kuch chala hi nahi. --}}
                                                        Is par qeemat alag se tay hui thi
                                                        @if($line['override_reason']) — {{ $line['override_reason'] }} @endif.
                                                        Apply andar ka hisaab badal dega, magar graahak phir bhi
                                                        {{ $money($line['rate']) }} hi dega — jab tak koi quotation par
                                                        <em>Use calculated rate</em> na chunay.
                                                    @else
                                                        @foreach($line['hits'] as $hit)
                                                            @if($hit['reason']) {{ $hit['reason'] }} @endif
                                                        @endforeach
                                                    @endif
                                                </td>
                                            </tr>
                                            @endif
                                        @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr class="fw-semibold">
                                                <td colspan="3" class="text-end">Booking total</td>
                                                <td class="text-end">{{ $money($order['old_total']) }}</td>
                                                <td class="text-end {{ $tone($order['total_difference']) }}">
                                                    {{ $money($order['new_total']) }}
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">
                        Is material par koi booking nahi.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if(collect($orders)->where('eligible', true)->isNotEmpty())
            @can('tenant.catering.commercial-rates.apply-drafts')
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted fs-12">
                    Jis qeemat par graahak se baat ho chuki hai wo apni jagah rehti hai — sirf andar ka hisaab hilta hai.
                </span>
                <button class="btn btn-primary btn-sm"
                        onclick="return confirm('Chuni hui bookings par house rate laga dein?')">
                    Chuni hui bookings par apply
                </button>
            </div>
            @endcan
        @endif
    </div>
</form>

<style>
    /* Jo line waqai hilegi wo nazar aani chahiye — baqi sirf saath hain. */
    #order-impact-table tr.order-row { cursor: pointer; }
    #order-impact-table tr.order-row:hover > td { background: var(--bs-primary-bg-subtle, #e8eefa); }
    #order-impact .order-lines tr.line-moves > td { background: #fff8e1; font-weight: 600; }
    #order-impact .order-lines tr.line-touched > td { background: #f6f7f9; }
</style>

<script>
    (function () {
        const card = document.getElementById('order-impact');
        if (! card) return;

        // Hidden ids sirf tab bhejе jayen jab us booking ka khana tick ho.
        // Disabled input submit nahi hota — yehi sab se seedha tareeqa hai, aur
        // is me "tick hai magar bheja kuch nahi" wali soorat ban hi nahi sakti.
        card.querySelectorAll('.order-pick').forEach(function (pick) {
            pick.addEventListener('change', function () {
                const box = document.getElementById(pick.dataset.target);
                if (! box) return;
                box.querySelectorAll('input[name="snapshot_ids[]"]').forEach(function (i) {
                    i.disabled = ! pick.checked;
                });
            });
        });
    })();
</script>

{{-- Ek form fi booking, upar wale selection form se BAHAR — bheji hui quotation
     ki nayi version banana ek alag amal hai aur usay bulk reprice ke saath
     sawari nahi milni chahiye. --}}
@can('tenant.catering.commercial-rates.revise-and-apply')
    @foreach($orders as $order)
        @if($order['revisable'])
            <form id="revise-{{ $order['estimate_id'] }}" method="POST"
                  action="{{ url('/catering/commercial-rates/' . $material->id . '/revise-and-apply') }}" class="d-none">
                @csrf
                <input type="hidden" name="estimate_id" value="{{ $order['estimate_id'] }}">
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
