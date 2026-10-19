@extends('layouts.app')
@section('title', 'Partner Share Rates')
@section('content')
@php
    // PARTNER-SHARE-1 — the rate list behind the Report Center's "Partner Share" section.
    $money = fn ($v) => number_format((float) $v, 0);
    $pname = $partner?->name ?? 'Partner';
    $payerLabel = fn (string $payer) => $payer === 'partner' ? $pname . ' pays us' : 'We pay ' . $pname;
@endphp
<div class="page-header">
    <div class="page-title">
        <h4>Partner Share Rates</h4>
        <h6>What one side owes the other each time a shared dish sells. The Report Center's "Partner Share" section adds it up.</h6>
    </div>
    <div class="page-btn d-flex gap-2">
        @can('tenant.reports.center.index')
            <a href="{{ url('/reports/center?tab=partner_share') }}" class="btn btn-outline-primary btn-sm" id="partner-share-report-link"><i class="ti ti-report-analytics me-1"></i>Open report</a>
        @endcan
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" id="partner-share-errors">
        @foreach($errors->all() as $err)<div>{{ $err }}</div>@endforeach
    </div>
@endif

<div class="row g-3">
    {{-- Partners: any number of them; each has its own rate list. --}}
    <div class="col-lg-3">
        <div class="card h-100" id="partner-list">
            <div class="card-header"><h6 class="mb-0">Partners</h6></div>
            <div class="list-group list-group-flush">
                @forelse($partners as $p)
                    <a href="{{ url('/reports/partner-share?partner_id=' . $p->id) }}"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center @if($partner && $partner->id === $p->id) active @endif">
                        <span>{{ $p->name }}</span>
                        @unless($p->is_active)<span class="badge bg-secondary">Off</span>@endunless
                    </a>
                @empty
                    <div class="list-group-item text-muted small">No partner yet. Add the first one below.</div>
                @endforelse
            </div>
            <div class="card-body border-top">
                <form method="POST" action="{{ url('/reports/partner-share/partners') }}" id="partner-add-form">
                    @csrf
                    <label class="form-label mb-1" for="new-partner-name">Add a partner</label>
                    <input type="text" name="name" id="new-partner-name" class="form-control form-control-sm mb-2" maxlength="120" placeholder="Partner kitchen name" required>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Add partner</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-9">
        @if(! $partner)
            <div class="card"><div class="card-body text-muted">Add a partner on the left to start its rate list.</div></div>
        @else
        {{-- Partner settings --}}
        <div class="card mb-3" id="partner-settings">
            <div class="card-body">
                <form method="POST" action="{{ url('/reports/partner-share/partners/' . $partner->id) }}" class="row g-2 align-items-end">
                    @csrf @method('PUT')
                    <div class="col-md-4">
                        <label class="form-label mb-1" for="partner-name">Partner name</label>
                        <input type="text" name="name" id="partner-name" class="form-control form-control-sm" value="{{ $partner->name }}" maxlength="120" required>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" id="partner-active" class="form-check-input" @checked($partner->is_active)>
                            <label class="form-check-label" for="partner-active">Active (shown in the report)</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="hidden" name="count_free_lines" value="0">
                            <input type="checkbox" name="count_free_lines" value="1" id="partner-free" class="form-check-input" @checked($partner->count_free_lines)>
                            <label class="form-check-label" for="partner-free">Count dishes given free</label>
                        </div>
                    </div>
                    <div class="col-md-2"><button type="submit" class="btn btn-outline-primary btn-sm w-100">Save</button></div>
                </form>
                <p class="form-text mb-0 mt-2">A dish sold for Rs 0 (given away) owes nothing unless "Count dishes given free" is ticked.</p>
            </div>
        </div>

        {{-- Current rate list --}}
        <div class="card mb-3" id="partner-current-rates">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0">{{ $pname }}: rates in force on {{ \Illuminate\Support\Carbon::parse($today)->format('d M Y') }}</h6>
                <span class="text-muted small">{{ $current->count() }} items</span>
            </div>
            <div class="card-body table-responsive p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr>
                        <th>Dish / Deal</th><th class="text-end">Price</th><th>Who pays</th><th class="text-end">Amount</th>
                        <th class="text-end">{{ $pname }} share</th><th class="text-end">Our share</th><th>Effective from</th>
                    </tr></thead>
                    <tbody>
                    @forelse($current as $v)
                        @php
                            $price = $itemPrice($v);
                            $partnerShare = $v->payer === 'partner' ? $price - (float) $v->amount : (float) $v->amount;
                            $ourShare = $price - $partnerShare;
                            $upcoming = $v->effective_from->toDateString() > $today;
                        @endphp
                        <tr>
                            <td>{{ $itemName($v) }} @if($v->combo_id)<span class="text-muted small">Deal</span>@endif</td>
                            <td class="text-end">{{ $money($price) }}</td>
                            <td><span class="badge {{ $v->payer === 'partner' ? 'bg-warning text-dark' : 'bg-primary' }}">{{ $payerLabel($v->payer) }}</span></td>
                            <td class="text-end fw-semibold">{{ $money($v->amount) }}</td>
                            <td class="text-end">{{ $money($partnerShare) }}</td>
                            <td class="text-end">{{ $money($ourShare) }}</td>
                            <td>{{ $v->effective_from->format('d M Y') }} @if($upcoming)<span class="badge bg-info text-dark">upcoming</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No rates yet. Add the first one below.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Add a rate (or a new version of one) --}}
        <div class="card mb-3" id="partner-rate-form">
            <div class="card-header"><h6 class="mb-0">Add a rate</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ url('/reports/partner-share/rates') }}" class="row g-2 align-items-end">
                    @csrf
                    <input type="hidden" name="share_partner_id" value="{{ $partner->id }}">
                    <div class="col-md-5">
                        <label class="form-label mb-1" for="rate-item-filter">Dish or deal</label>
                        <input type="search" id="rate-item-filter" class="form-control form-control-sm mb-1" placeholder="Type to filter the list">
                        <select name="item" id="rate-item" class="form-select form-select-sm" size="6" required>
                            <optgroup label="Deals">
                                @foreach($combos as $c)
                                    <option value="c:{{ $c->id }}" @selected(old('item') === 'c:' . $c->id)>{{ $c->name }} — {{ $money($c->price) }}</option>
                                @endforeach
                            </optgroup>
                            <optgroup label="Products">
                                @foreach($products as $pr)
                                    <option value="p:{{ $pr->id }}" @selected(old('item') === 'p:' . $pr->id)>{{ $pr->name }} — {{ $money($pr->default_selling_price) }}</option>
                                @endforeach
                            </optgroup>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1" for="rate-payer">Who pays</label>
                        <select name="payer" id="rate-payer" class="form-select form-select-sm" required>
                            <option value="partner" @selected(old('payer') === 'partner')>{{ $pname }} pays us</option>
                            <option value="us" @selected(old('payer') === 'us')>We pay {{ $pname }}</option>
                        </select>
                        <label class="form-label mb-1 mt-2" for="rate-amount">Amount per dish (Rs)</label>
                        <input type="number" name="amount" id="rate-amount" class="form-control form-control-sm" min="0" step="0.01" value="{{ old('amount') }}" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1" for="rate-from">Effective from</label>
                        <input type="date" name="effective_from" id="rate-from" class="form-control form-control-sm" value="{{ old('effective_from', $today) }}" required>
                        <label class="form-label mb-1 mt-2" for="rate-notes">Note</label>
                        <input type="text" name="notes" id="rate-notes" class="form-control form-control-sm" maxlength="255" value="{{ old('notes') }}">
                    </div>
                    <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Save rate</button></div>
                </form>
                <p class="form-text mb-0 mt-2">To change a rate, add it again with a new "Effective from" date. Sales before that date keep the old rate. To stop a rate, add it with amount 0.</p>
            </div>
        </div>

        {{-- Every version ever entered --}}
        <div class="card" id="partner-rate-history">
            <div class="card-header"><h6 class="mb-0">All rate versions</h6></div>
            <div class="card-body table-responsive p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Dish / Deal</th><th>Who pays</th><th class="text-end">Amount</th><th>Effective from</th><th>Note</th><th></th></tr></thead>
                    <tbody>
                    @forelse($history as $v)
                        <tr>
                            <td>{{ $itemName($v) }}</td>
                            <td>{{ $payerLabel($v->payer) }}</td>
                            <td class="text-end">{{ $money($v->amount) }}</td>
                            <td>{{ $v->effective_from->format('d M Y') }}</td>
                            <td class="small text-muted">{{ $v->notes }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ url('/reports/partner-share/rates/' . $v->id) }}"
                                      onsubmit="return confirm('Delete this rate? Reports for past dates will change too.');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">Nothing entered yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</div>

<script>
(function () {
    var filter = document.getElementById('rate-item-filter');
    var select = document.getElementById('rate-item');
    if (!filter || !select) return;
    filter.addEventListener('input', function () {
        var q = filter.value.trim().toLowerCase();
        Array.prototype.forEach.call(select.options, function (o) {
            o.hidden = q !== '' && o.text.toLowerCase().indexOf(q) === -1;
        });
    });
})();
</script>
@endsection
