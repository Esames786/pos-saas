@extends('layouts.app')

@section('title', 'WhatsApp Usage')

@section('content')
@php
    $fmt = fn ($v) => number_format((float) $v, 2);
    $badge = ['draft' => 'bg-secondary', 'issued' => 'bg-info text-dark', 'paid' => 'bg-success', 'void' => 'bg-dark'];
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">WhatsApp Usage</h1>
        <p class="text-muted mb-0">
            Report messages billed to tenants at PKR {{ $fmt($rate) }} each.
        </p>
    </div>
    <form method="GET" class="d-flex gap-2">
        <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
            @foreach($months as $m)
                <option value="{{ $m }}" @selected($m === $month->format('Y-m'))>
                    {{ \Carbon\Carbon::parse($m.'-01')->format('F Y') }}
                </option>
            @endforeach
        </select>
    </form>
</div>

{{-- ── is mahine ka khulasa ── --}}
<div class="row g-3 mb-4">
    @php
        $cards = [
            ['Messages', number_format($totals['messages']), 'ti-message-2', null],
            ['Billed', 'PKR '.$fmt($totals['amount']), 'ti-cash', null],
            ['Our cost', 'PKR '.$fmt($totals['cost']), 'ti-receipt', null],
            ['Margin', 'PKR '.$fmt($totals['amount'] - $totals['cost']), 'ti-trending-up', null],
        ];
    @endphp
    @foreach($cards as [$label, $value, $icon, $note])
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small"><i class="ti {{ $icon }} me-1"></i>{{ $label }}</div>
                <div class="fs-4 fw-semibold">{{ $value }}</div>
            </div></div>
        </div>
    @endforeach
</div>

@if($totals['failed'] > 0)
    {{-- Ye ginti chhupane ki nahi hai. Isi se pata chala ke kashiffood ka aik number HAR raat fail
         hota hai — hafton se, aur kisi ko khabar nahi thi. --}}
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="ti ti-alert-triangle fs-5"></i>
        <div>
            <strong>{{ $totals['failed'] }} message is mahine pohanche nahi.</strong>
            Bill phir bhi banta hai (charge bhejne par hai), magar agar ye ginti roz aik jaisi rehti
            hai to kisi tenant ki list me koi number aisa hai jo kabhi wasool nahi karta.
        </div>
    </div>
@endif

@if($totals['unbilled'] > 0)
    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="ti ti-file-invoice fs-5"></i>
        <div>
            <strong>{{ $totals['unbilled'] }} message abhi kisi invoice se nahi juRe.</strong>
            <code>php artisan billing:whatsapp-invoice</code> chalane par us mahine ki draft invoice
            me juR jayenge.
        </div>
    </div>
@endif

{{-- ── per tenant ── --}}
<div class="card mb-4"><div class="card-body table-responsive">
    <h6>Per tenant — {{ $month->format('F Y') }}</h6>
    <table class="table table-sm align-middle">
        <thead><tr>
            <th>Tenant</th><th class="text-end">Days</th><th class="text-end">Messages</th>
            <th class="text-end">Not delivered</th><th class="text-end">Billed</th>
            <th class="text-end">Our cost</th><th class="text-end">Margin</th><th class="text-end">Unbilled</th>
        </tr></thead>
        <tbody>
        @forelse($rows as $r)
            <tr>
                <td>
                    <div class="fw-semibold">{{ $r->tenant_code }}</div>
                    <div class="text-muted small">{{ $r->business_name }}</div>
                </td>
                <td class="text-end">{{ $r->days }}</td>
                <td class="text-end">{{ number_format($r->messages) }}</td>
                <td class="text-end {{ $r->failed > 0 ? 'text-warning fw-semibold' : 'text-muted' }}">
                    {{ $r->failed ?: '—' }}
                </td>
                <td class="text-end fw-semibold">{{ $fmt($r->amount) }}</td>
                <td class="text-end text-muted">{{ $fmt($r->cost) }}</td>
                <td class="text-end">{{ $fmt($r->amount - $r->cost) }}</td>
                <td class="text-end">{{ $r->unbilled ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-muted">Is mahine koi message nahi gaya.</td></tr>
        @endforelse
        </tbody>
        @if($rows->isNotEmpty())
            <tfoot><tr class="table-light fw-semibold">
                <td>Total</td>
                <td></td>
                <td class="text-end">{{ number_format($totals['messages']) }}</td>
                <td class="text-end">{{ $totals['failed'] ?: '—' }}</td>
                <td class="text-end">{{ $fmt($totals['amount']) }}</td>
                <td class="text-end">{{ $fmt($totals['cost']) }}</td>
                <td class="text-end">{{ $fmt($totals['amount'] - $totals['cost']) }}</td>
                <td class="text-end">{{ $totals['unbilled'] ?: '—' }}</td>
            </tr></tfoot>
        @endif
    </table>
</div></div>

{{-- ── rozana — malik ne "per day ki cost" maangi thi ── --}}
<div class="card mb-4"><div class="card-body table-responsive">
    <h6>Day by day</h6>
    <table class="table table-sm align-middle">
        <thead><tr><th>Date</th><th>Tenant</th><th class="text-end">Messages</th>
            <th class="text-end">Not delivered</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        @forelse($daily as $date => $group)
            @foreach($group as $i => $r)
                <tr>
                    <td class="{{ $i === 0 ? '' : 'text-muted' }}">
                        {{ $i === 0 ? \Carbon\Carbon::parse($date)->format('D, d M') : '' }}
                    </td>
                    <td>{{ $r->tenant_code }}</td>
                    <td class="text-end">{{ $r->messages }}</td>
                    <td class="text-end {{ $r->failed > 0 ? 'text-warning' : 'text-muted' }}">{{ $r->failed ?: '—' }}</td>
                    <td class="text-end">{{ $fmt($r->amount) }}</td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="5" class="text-muted">Kuch nahi.</td></tr>
        @endforelse
        </tbody>
    </table>
</div></div>

{{-- ── WhatsApp ke invoice ── --}}
<div class="card mb-4"><div class="card-body table-responsive">
    <h6>WhatsApp invoices</h6>
    <p class="text-muted small mb-3">
        Har mahine ki aik invoice, jo mahine ke dauran <span class="badge bg-secondary">draft</span>
        rehti hai aur roz barhti hai. Mahina khatam hone par band ho kar
        <span class="badge bg-info text-dark">issued</span> banti hai — tab deni hoti hai.
        Subscription ke invoice is se alag hain.
    </p>
    <table class="table table-sm align-middle">
        <thead><tr><th>Invoice #</th><th>Tenant</th><th>Period</th><th>Status</th>
            <th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th></tr></thead>
        <tbody>
        @forelse($invoices as $i)
            <tr>
                <td><code>{{ $i->invoice_no }}</code></td>
                <td>{{ $i->tenant?->tenant_code ?? '—' }}</td>
                <td class="small">{{ $i->period_start }} → {{ $i->period_end }}</td>
                <td><span class="badge {{ $badge[$i->status] ?? 'bg-secondary' }}">{{ $i->status }}</span></td>
                <td class="text-end">{{ $fmt($i->total_amount) }}</td>
                <td class="text-end">{{ $fmt($i->paid_amount) }}</td>
                <td class="text-end fw-semibold">{{ $fmt($i->balance_amount) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-muted">Abhi koi WhatsApp invoice nahi bani.</td></tr>
        @endforelse
        </tbody>
    </table>
</div></div>

{{-- ── kis kis ka on hai ── --}}
<div class="card"><div class="card-body">
    <h6>WhatsApp on</h6>
    @if($tenantsOn->isEmpty())
        <p class="text-muted mb-0">Kisi tenant par WhatsApp on nahi hai.</p>
    @else
        <div class="d-flex flex-wrap gap-2">
            @foreach($tenantsOn as $t)
                <span class="badge bg-light text-dark border">
                    {{ $t['code'] }} · {{ $t['numbers'] }} number{{ $t['numbers'] === 1 ? '' : 's' }}
                    · PKR {{ $fmt($t['numbers'] * $rate) }}/report
                </span>
            @endforeach
        </div>
        <div class="form-text mt-2">
            Har number apna alag message hai. Tenant apni list Report Center se khud badal sakta hai,
            aur har naya number mahine ka kharcha barhata hai.
        </div>
    @endif
</div></div>
@endsection
