@extends('layouts.app')

@section('title', 'Hisab')

{{-- MASTER-HISAB-1 — Bingoo ka apna hisab. Plan: docs/plans/master-hisab-2026-10-29.md.
     Aik safha: upar mahine ka khulasa (cards), barabari (50/50), khate, graphs, WhatsApp, aur neeche har entry
     (table). Entry daalna / badalna aik hi popup se (Add = Edit). --}}

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/css/dataTables.bootstrap5.min.css') }}">
<style>
    .hs-kpi .hs-kpi-label { color: var(--mu-text-2); font-size: 12.5px; display: flex; align-items: center; gap: 6px; }
    .hs-kpi .hs-kpi-value { font-size: clamp(1.25rem, 2.2vw, 1.6rem); font-weight: 700; color: var(--mu-text); line-height: 1.2; margin-top: 4px; overflow-wrap: anywhere; }
    .hs-kpi .hs-kpi-note { color: var(--mu-text-3); font-size: 12px; margin-top: 2px; }
    .hs-kpi .hs-kpi-icon { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
    .hs-ink-in { color: #6aa8f0; }
    .hs-ink-out { color: #f08a5d; }
    .hs-ok { color: var(--mu-ok); }
    .hs-bad { color: var(--mu-danger); }
    .hs-soft-in { background: rgba(57, 135, 229, .16); color: #6aa8f0; }
    .hs-soft-out { background: rgba(217, 89, 38, .18); color: #f08a5d; }
    .hs-soft-ok { background: var(--mu-ok-soft); color: var(--mu-ok); }
    .hs-soft-acc { background: var(--mu-accent-soft); color: var(--mu-accent); }
    .hs-move { border: 1px dashed var(--mu-border); border-radius: 10px; padding: 10px 12px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; justify-content: space-between; }
    .hs-move strong { color: var(--mu-text); }
    .hs-khata { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--mu-border-soft); }
    .hs-khata:last-child { border-bottom: 0; }
    .hs-khata a { color: var(--mu-text); text-decoration: none; }
    .hs-khata a:hover { color: var(--mu-accent); }
    .hs-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
    .hs-chart { min-height: 280px; }
    .hs-empty { color: var(--mu-text-3); font-size: 13px; padding: 40px 0; text-align: center; }
    .hs-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; border: 1px solid var(--mu-border); color: var(--mu-text-2); font-size: 12px; text-decoration: none; }
    .hs-chip.active, .hs-chip:hover { border-color: var(--mu-accent); color: var(--mu-text); background: var(--mu-accent-soft); }
    .hs-deleted td { text-decoration: line-through; opacity: .55; }
    .hs-type-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
    @media (max-width: 575px) { .hs-type-grid { grid-template-columns: 1fr; } }
    .hs-type input { position: absolute; opacity: 0; pointer-events: none; }
    .hs-type label { display: block; cursor: pointer; border: 1px solid var(--mu-border); border-radius: 12px; padding: 12px; height: 100%; }
    .hs-type label .hs-type-emoji { font-size: 26px; line-height: 1; }
    .hs-type label .hs-type-title { font-weight: 700; color: var(--mu-text); margin-top: 6px; }
    .hs-type label .hs-type-help { font-size: 12px; color: var(--mu-text-3); }
    .hs-type input:checked + label { border-color: var(--mu-accent); background: var(--mu-accent-soft); box-shadow: 0 0 0 1px var(--mu-accent) inset; }
    .hs-type input:focus-visible + label { outline: 2px solid var(--mu-focus); outline-offset: 2px; }
    .hs-line { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr) minmax(0, 1.3fr) 38px; gap: 8px; align-items: end; }
    @media (max-width: 575px) { .hs-line { grid-template-columns: 1fr; } }
    .hs-summary { border-radius: 10px; padding: 10px 12px; background: var(--mu-surface-2); color: var(--mu-text); font-size: 13px; }
    .hs-urdu { color: var(--mu-text-3); font-size: 12px; }
    .hs-wa-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
    @media (max-width: 767px) { .hs-wa-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    table.dataTable thead th { white-space: nowrap; }
    .dataTables_wrapper .dataTables_filter input, .dataTables_wrapper .dataTables_length select { background: var(--mu-input); color: var(--mu-text); border: 1px solid var(--mu-border); }
    .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_filter { color: var(--mu-text-2); font-size: 12.5px; }
    .apexcharts-tooltip { color: #0b0b0b; }
</style>
@endpush

@section('content')
@php
    // Poore rupay ho to bina paisa, warna 2 decimal — "35,000" aur "535.66" dono saaf parhe jayein.
    $fmt = function ($v) {
        $v = round((float) $v, 2);

        return number_format($v, abs($v - round($v)) > 0.004 ? 2 : 0);
    };
    $activeAccounts = $accounts->where('is_active', true)->values();
    // JS ke liye — @json() commas par toRta hai, is liye yahan pehle se.
    $jsAccounts = $activeAccounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'partner' => $a->partner?->name])->values();
    $ready = $activeAccounts->isNotEmpty();
    $companyTotal = round(array_sum($balances), 2);
    $monthEnd = $month->endOfMonth();
    $monthLabel = $month->format('F Y');
    $typeBadge = [
        'in'       => ['Paisa aaya', 'hs-soft-in', '💰'],
        'out'      => ['Kharcha', 'hs-soft-out', '🧾'],
        'transfer' => ['Khate se khate', 'hs-soft-acc', '🔁'],
    ];
    $accountName = function ($id) use ($accounts) {
        $a = $accounts->firstWhere('id', $id);

        return $a ? $a->name : '—';
    };
    // Popup me "badlo" ke liye har entry ka saaf data.
    $entryJson = $entries->filter(fn ($e) => ! $e->deleted_at)->mapWithKeys(function ($e) use ($fingerprints) {
        $card = $e->cardFigures();

        return [$e->id => [
            'id'            => $e->id,
            'entry_no'      => $e->entry_no,
            'type'          => $e->type,
            'entry_date'    => $e->entry_date->toDateString(),
            'tenant_id'     => $e->tenant_id,
            'note'          => $e->note,
            'usd_amount'    => $card['usd'] ?? null,
            'bill_amount'   => $card['bill'] ?? null,
            'bank_fee'      => $card['fee'] ?? null,
            'message_count' => $e->message_count,
            '_was'          => $fingerprints[$e->id] ?? null,
            'lines'         => $e->lines->map(fn ($l) => [
                'account_id' => $l->account_id, 'to_account_id' => $l->to_account_id,
                'category' => $l->category, 'amount' => (float) $l->amount,
            ])->values()->all(),
        ]];
    });
@endphp

{{-- ── Upar: naam, mahina, buttons ── --}}
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <h1 class="mb-1">Hisab</h1>
        <p class="text-muted mb-0">Bingoo ka apna paisa — kis ke paas kitna hai, kya kharcha hua, aur owners ki barabari.</p>
        <div class="hs-urdu" dir="rtl" lang="ur">بنگو کا اپنا حساب — پیسہ کس کے پاس ہے، کیا خرچ ہوا، اور مالکان کا برابر حصہ</div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="btn-group" role="group" aria-label="Mahina">
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('central.hisab.index', ['month' => $prevMonth]) }}" aria-label="Pichhla mahina"><i class="ti ti-chevron-left"></i></a>
            <form method="GET" action="{{ route('central.hisab.index') }}" class="d-inline">
                <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="form-control form-control-sm" style="width: 150px"
                       onchange="this.form.submit()" aria-label="Mahina chunein">
            </form>
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('central.hisab.index', ['month' => $nextMonth]) }}" aria-label="Agla mahina"><i class="ti ti-chevron-right"></i></a>
        </div>
        @if($ready)
            <button type="button" class="btn btn-primary btn-sm" data-hs-new="in"><span aria-hidden="true">💰</span> Paisa aaya</button>
            <button type="button" class="btn btn-light btn-sm" data-hs-new="out"><span aria-hidden="true">🧾</span> Kharcha</button>
            <button type="button" class="btn btn-light btn-sm" data-hs-new="transfer"><span aria-hidden="true">🔁</span> Khate se khate</button>
        @endif
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#hsSetupModal">
            <i class="ti ti-settings"></i> Owners aur khate
        </button>
    </div>
</div>

@if(! $ready)
    <div class="card mb-4"><div class="card-body text-center py-5">
        <div style="font-size: 40px" aria-hidden="true">👥</div>
        <h5 class="mt-2">Pehle owners aur khate banayein</h5>
        <p class="text-muted mb-3">Owner = Bingoo ka maalik aur nafe mein us ka hissa (jaise 50%).<br>
            Khata = jahan paisa aata hai (jaise "Mohsin ka account").</p>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#hsSetupModal">
            <i class="ti ti-plus"></i> Owners aur khate banayein
        </button>
    </div></div>
@endif

{{-- ── Is mahine ka khulasa ── --}}
<div class="row g-3 mb-3" data-hs-kpis>
    @php
        $profitGood = $period['profit'] >= 0;
        $kpis = [
            ['Aamdani', $period['income'], 'ti-trending-up', 'hs-soft-in', $monthLabel.' mein aaya', 'income'],
            ['Kharcha', $period['expense'], 'ti-trending-down', 'hs-soft-out', $monthLabel.' mein gaya', 'expense'],
            ['Nafa', $period['profit'], $profitGood ? 'ti-pig-money' : 'ti-alert-triangle', $profitGood ? 'hs-soft-ok' : 'hs-soft-out',
                $profitGood ? 'Aamdani − kharcha' : 'Is mahine nuqsan', 'profit'],
            ['Company ka kul paisa', $companyTotal, 'ti-wallet', 'hs-soft-acc', 'Aaj tak, sab khaton mein', 'total'],
        ];
    @endphp
    @foreach($kpis as [$label, $value, $icon, $soft, $note, $key])
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 hs-kpi"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="hs-kpi-label">{{ $label }}</div>
                    <span class="hs-kpi-icon {{ $soft }}" aria-hidden="true"><i class="ti {{ $icon }}"></i></span>
                </div>
                <div class="hs-kpi-value hs-num" data-hs-kpi="{{ $key }}">Rs {{ $fmt($value) }}</div>
                <div class="hs-kpi-note">{{ $note }}</div>
            </div></div>
        </div>
    @endforeach
</div>

<div class="row g-3 mb-3">
    {{-- ── Barabari: kis ka kitna banta tha, kitna mila ── --}}
    <div class="col-lg-7">
        <div class="card h-100" data-hs-settlement><div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <h6 class="mb-0"><i class="ti ti-scale me-1"></i>Barabari — owners ka hissa</h6>
                    <div class="text-muted small">Shuru se {{ $monthEnd->format('d M Y') }} tak · kul nafa <strong class="hs-num">Rs {{ $fmt($settlement['profit']) }}</strong></div>
                </div>
            </div>
            @if(empty($settlement['partners']))
                <div class="hs-empty">Owners abhi nahi bane.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-3">
                        <thead><tr>
                            <th>Owner</th>
                            <th class="text-end">Hissa</th>
                            <th class="text-end">Banta tha</th>
                            <th class="text-end">Mil gaya</th>
                            <th class="text-end">Farq</th>
                        </tr></thead>
                        <tbody>
                        @foreach($settlement['partners'] as $p)
                            <tr data-hs-partner="{{ $p['id'] }}">
                                <td class="fw-semibold">{{ $p['name'] }}</td>
                                <td class="text-end hs-num">{{ rtrim(rtrim(number_format($p['percent'], 2), '0'), '.') }}%</td>
                                <td class="text-end hs-num" data-hs-share>Rs {{ $fmt($p['share']) }}</td>
                                <td class="text-end hs-num" data-hs-got>Rs {{ $fmt($p['got']) }}</td>
                                <td class="text-end hs-num" data-hs-diff="{{ number_format($p['diff'], 2, '.', '') }}">
                                    @if(abs($p['diff']) <= 0.004)
                                        <span class="badge bg-success"><i class="ti ti-check"></i> Barabar</span>
                                    @elseif($p['diff'] > 0)
                                        <span class="hs-ink-out"><i class="ti ti-arrow-up-right"></i> Rs {{ $fmt($p['diff']) }} zyada</span>
                                    @else
                                        <span class="hs-ink-in"><i class="ti ti-arrow-down-left"></i> Rs {{ $fmt(-$p['diff']) }} kam</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @forelse($settlement['moves'] as $m)
                    <div class="hs-move mb-2" data-hs-move>
                        <div>
                            <span aria-hidden="true">🤝</span>
                            <strong>{{ $m['from'] }}</strong> → <strong>{{ $m['to'] }}</strong>:
                            <strong class="hs-num">Rs {{ $fmt($m['amount']) }}</strong> bhej dein, to hisab barabar.
                        </div>
                        @if($ready)
                            @php
                                // @json() argument ko commas par toRta hai — is liye array pehle yahan banta hai.
                                $settle = ['from' => $m['from_account_id'], 'to' => $m['to_account_id'], 'amount' => $m['amount'], 'note' => 'Barabari — '.$monthLabel];
                            @endphp
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-hs-settle='@json($settle)'>
                                Bhej diya — entry daalein
                            </button>
                        @endif
                    </div>
                @empty
                    <div class="text-success small"><i class="ti ti-circle-check"></i> Hisab barabar hai — kisi ko kuch nahi bhejna.</div>
                @endforelse
                @foreach($settlement['company'] as $c)
                    @if(abs($c['balance']) > 0.004)
                        <div class="text-muted small mt-1"><i class="ti ti-building-bank"></i> {{ $c['name'] }} mein Rs {{ $fmt($c['balance']) }} — company ka paisa, abhi kisi owner ka nahi.</div>
                    @endif
                @endforeach
                <div class="text-muted small mt-2">
                    "Mil gaya" = owner ke khaton ka balance: jo paisa us ke paas aaya, minus jo company ke kharche us ne diye, plus/minus jo owners ne aapas mein bheja.
                </div>
            @endif
        </div></div>
    </div>

    {{-- ── Khate ── --}}
    <div class="col-lg-5">
        <div class="card h-100"><div class="card-body">
            <h6 class="mb-1"><i class="ti ti-building-bank me-1"></i>Khate — paisa kahan hai</h6>
            <div class="text-muted small mb-2">{{ $monthEnd->format('d M Y') }} tak ka balance · naam dabayein to us khate ka hisaab</div>
            @forelse($accounts as $a)
                @if($a->is_active || abs($monthBalances[$a->id] ?? 0) > 0.004)
                    <div class="hs-khata" data-hs-account="{{ $a->id }}">
                        <div>
                            <a href="{{ route('central.hisab.index', ['month' => $month->format('Y-m'), 'account' => $a->id]) }}" class="fw-semibold">{{ $a->name }}</a>
                            <div class="small text-muted">
                                {{ $a->partner ? 'Owner: '.$a->partner->name : 'Company ka apna khata' }}
                                @unless($a->is_active) · <span class="badge bg-secondary">Band</span> @endunless
                            </div>
                        </div>
                        @php $bal = $monthBalances[$a->id] ?? 0; @endphp
                        <div class="hs-num fw-semibold {{ $bal < 0 ? 'hs-bad' : '' }}" data-hs-balance>Rs {{ $fmt($bal) }}</div>
                    </div>
                @endif
            @empty
                <div class="hs-empty">Abhi koi khata nahi.</div>
            @endforelse
            @if($accounts->isNotEmpty())
                <div class="hs-khata border-top mt-1 pt-2">
                    <div class="fw-semibold">Sab khate mila kar</div>
                    <div class="hs-num fw-semibold">Rs {{ $fmt(array_sum($monthBalances)) }}</div>
                </div>
            @endif
        </div></div>
    </div>
</div>

{{-- ── Graphs ── --}}
<div class="row g-3 mb-3">
    <div class="col-xl-6">
        <div class="card h-100"><div class="card-body">
            <h6 class="mb-0">Mahine-war: aamdani, kharcha aur nafa</h6>
            <div class="text-muted small">Pichhle mahine (zyada se zyada 12) · Rs</div>
            @if(count($trend))
                <div id="hsTrend" class="hs-chart" role="img" aria-label="Mahine-war aamdani, kharcha aur nafa ka graph"></div>
                <details class="small mt-1">
                    <summary class="text-muted">Table mein dekhein</summary>
                    <table class="table table-sm mt-2 mb-0">
                        <thead><tr><th>Mahina</th><th class="text-end">Aamdani</th><th class="text-end">Kharcha</th><th class="text-end">Nafa</th></tr></thead>
                        <tbody>
                        @foreach($trend as $t)
                            <tr><td>{{ $t['label'] }}</td><td class="text-end hs-num">{{ $fmt($t['income']) }}</td>
                                <td class="text-end hs-num">{{ $fmt($t['expense']) }}</td><td class="text-end hs-num">{{ $fmt($t['profit']) }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </details>
            @else
                <div class="hs-empty">Abhi koi entry nahi.</div>
            @endif
        </div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card h-100"><div class="card-body">
            <h6 class="mb-0">Kis client se kitna aaya</h6>
            <div class="text-muted small">{{ $monthLabel }} · Rs</div>
            @if(count($period['by_client']))
                <div id="hsClients" class="hs-chart" role="img" aria-label="Client-wise aamdani"></div>
            @else
                <div class="hs-empty">Is mahine kuch nahi aaya.</div>
            @endif
        </div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card h-100"><div class="card-body">
            <h6 class="mb-0">Kharcha kis cheez par</h6>
            <div class="text-muted small">{{ $monthLabel }} · Rs</div>
            @if(count($period['out_category']))
                <div id="hsExpenses" class="hs-chart" role="img" aria-label="Kharcha cheez-wise"></div>
            @else
                <div class="hs-empty">Is mahine koi kharcha nahi.</div>
            @endif
        </div></div>
    </div>
</div>

{{-- ── WhatsApp: clients se liya vs card se gaya ── --}}
<div class="card mb-3" data-hs-whatsapp><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
            <h6 class="mb-0"><i class="ti ti-brand-whatsapp me-1"></i>WhatsApp — {{ $monthLabel }}</h6>
            <div class="text-muted small">Clients se jo liya, aur card se jo asal mein kata (Meta ka bill + bank ka tax/fee)</div>
        </div>
        @can('central.whatsapp-usage.index')
            <a href="{{ route('central.whatsapp-usage.index', ['month' => $month->format('Y-m')]) }}" class="small">Messages ki ginti <i class="ti ti-arrow-right"></i></a>
        @endcan
    </div>
    <div class="hs-wa-grid mb-2">
        <div><div class="text-muted small">Clients se liya</div><div class="fs-5 fw-semibold hs-num" data-hs-wa="billed">Rs {{ $fmt($whatsapp['billed']) }}</div></div>
        <div><div class="text-muted small">Card se gaya</div><div class="fs-5 fw-semibold hs-num" data-hs-wa="cost">Rs {{ $fmt($whatsapp['cost']) }}</div></div>
        <div><div class="text-muted small">WhatsApp ka nafa</div>
            <div class="fs-5 fw-semibold hs-num {{ $whatsapp['profit'] < 0 ? 'hs-bad' : 'hs-ok' }}" data-hs-wa="profit">Rs {{ $fmt($whatsapp['profit']) }}</div></div>
        <div><div class="text-muted small">Card ke bill</div><div class="fs-5 fw-semibold hs-num">{{ count($whatsapp['cards']) }}</div></div>
    </div>
    @if(count($whatsapp['cards']))
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" data-hs-cards>
                <thead><tr>
                    <th>Tareekh</th><th>No.</th><th class="text-end">Meta bill</th><th class="text-end">Bank ne kata</th>
                    <th class="text-end">Tax / fee</th><th class="text-end">Tax %</th><th class="text-end">Dollar rate</th>
                    <th class="text-end">Messages</th><th class="text-end">Fi message</th><th class="text-end">Kul</th>
                </tr></thead>
                <tbody>
                @foreach($whatsapp['cards'] as $c)
                    <tr>
                        <td>{{ $c['date'] }}</td><td>{{ $c['entry_no'] }}</td>
                        <td class="text-end hs-num">${{ rtrim(rtrim(number_format($c['usd'], 4), '0'), '.') }}</td>
                        <td class="text-end hs-num">Rs {{ $fmt($c['bill']) }}</td>
                        <td class="text-end hs-num">Rs {{ $fmt($c['fee']) }}</td>
                        <td class="text-end hs-num" data-hs-tax>{{ $c['tax_percent'] !== null ? number_format($c['tax_percent'], 2).'%' : '—' }}</td>
                        <td class="text-end hs-num" data-hs-rate>{{ $c['rate'] !== null ? number_format($c['rate'], 2) : '—' }}</td>
                        <td class="text-end hs-num">{{ $c['messages'] ? number_format($c['messages']) : '—' }}</td>
                        <td class="text-end hs-num" data-hs-per>{{ $c['per_message'] !== null ? 'Rs '.number_format($c['per_message'], 2) : '—' }}</td>
                        <td class="text-end hs-num fw-semibold">Rs {{ $fmt($c['total']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-muted small">Is mahine card se WhatsApp ka koi bill nahi likha. "Kharcha" → "WhatsApp (Meta)" se daalein.</div>
    @endif
</div></div>

{{-- ── Har entry ── --}}
<div class="card mb-4"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div>
            <h6 class="mb-0">
                @if($account)
                    {{ $account->name }} — {{ $monthLabel }} ka hisaab
                @else
                    Entries — {{ $monthLabel }}
                @endif
            </h6>
            <div class="text-muted small">
                @if($account)
                    Bank statement jesa: aaya (+), gaya (−) aur har entry ke baad balance.
                @else
                    Aaya (+) = khate mein paisa aaya · Gaya (−) = khate se paisa gaya.
                @endif
            </div>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <a class="hs-chip {{ $account ? '' : 'active' }}" href="{{ route('central.hisab.index', ['month' => $month->format('Y-m')]) }}">Sab entries</a>
            @foreach($accounts as $a)
                <a class="hs-chip {{ $account && $account->id === $a->id ? 'active' : '' }}"
                   href="{{ route('central.hisab.index', ['month' => $month->format('Y-m'), 'account' => $a->id]) }}">{{ $a->name }}</a>
            @endforeach
            @if($deletedCount)
                <a class="hs-chip {{ $showDeleted ? 'active' : '' }}"
                   href="{{ route('central.hisab.index', array_filter(['month' => $month->format('Y-m'), 'account' => $account?->id, 'deleted' => $showDeleted ? null : 1])) }}">
                    <i class="ti ti-trash"></i> Hataye gaye ({{ $deletedCount }})
                </a>
            @endif
        </div>
    </div>

    @if($account && $statement)
        @php
            $byEntry = $entries->keyBy('id');
        @endphp
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle w-100" id="hsStatement" data-hs-statement>
                <thead><tr>
                    <th>Tareekh</th><th>No.</th><th>Kya</th><th>Client / note</th>
                    <th class="text-end">Aaya (+)</th><th class="text-end">Gaya (−)</th><th class="text-end">Balance</th>
                </tr></thead>
                <tbody>
                    <tr data-hs-opening>
                        <td>{{ $month->format('d M Y') }}</td><td></td><td class="fw-semibold">Shuru ka balance</td><td></td><td></td><td></td>
                        <td class="text-end hs-num fw-semibold">Rs {{ $fmt($statement['opening']) }}</td>
                    </tr>
                    @foreach($statement['rows'] as $r)
                        @php $e = $byEntry[$r['entry_id']] ?? null; @endphp
                        @continue(! $e)
                        <tr>
                            <td>{{ $e->entry_date->format('d M Y') }}</td>
                            <td>{{ $e->entry_no }}</td>
                            <td>{{ $e->lines->pluck('category')->unique()->map(fn ($c) => \App\Models\Master\CompanyEntry::categoryLabel($c))->implode(', ') }}</td>
                            <td>{{ $e->tenant?->business_name }}{{ $e->tenant && $e->note ? ' · ' : '' }}{{ $e->note }}</td>
                            <td class="text-end hs-num hs-ink-in">{{ $r['in'] > 0 ? 'Rs '.$fmt($r['in']) : '' }}</td>
                            <td class="text-end hs-num hs-ink-out">{{ $r['out'] > 0 ? 'Rs '.$fmt($r['out']) : '' }}</td>
                            <td class="text-end hs-num fw-semibold">Rs {{ $fmt($r['balance']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr>
                    <th colspan="4">{{ $monthEnd->format('d M Y') }} ka balance</th>
                    <th class="text-end hs-num">Rs {{ $fmt(collect($statement['rows'])->sum('in')) }}</th>
                    <th class="text-end hs-num">Rs {{ $fmt(collect($statement['rows'])->sum('out')) }}</th>
                    <th class="text-end hs-num" data-hs-closing>Rs {{ $fmt($statement['closing']) }}</th>
                </tr></tfoot>
            </table>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle w-100" id="hsEntries" data-hs-entries>
                <thead><tr>
                    <th>Tareekh</th><th>No.</th><th>Kya</th><th>Client</th><th>Khata</th>
                    <th class="text-end">Aaya (+)</th><th class="text-end">Gaya (−)</th><th>Note</th><th class="text-end no-sort">Kaam</th>
                </tr></thead>
                <tbody>
                @foreach($entries as $e)
                    @php
                        [$tLabel, $tSoft, $tEmoji] = $typeBadge[$e->type] ?? [$e->type, '', ''];
                        $total = $e->total();
                        $edits = $e->logs->where('action', 'updated')->count();
                    @endphp
                    <tr class="{{ $e->deleted_at ? 'hs-deleted' : '' }}" data-hs-row="{{ $e->entry_no }}">
                        <td data-order="{{ $e->entry_date->format('Ymd') }}{{ str_pad((string) $e->id, 8, '0', STR_PAD_LEFT) }}">{{ $e->entry_date->format('d M Y') }}</td>
                        <td>{{ $e->entry_no }}</td>
                        <td>
                            <span class="badge {{ $tSoft }}">{{ $tEmoji }} {{ $tLabel }}</span>
                            <div class="small">
                                @if($e->type === 'transfer')
                                    {{ $accountName($e->lines->first()?->account_id) }} → {{ $accountName($e->lines->first()?->to_account_id) }}: Rs {{ $fmt($total) }}
                                @else
                                    {{ $e->lines->pluck('category')->unique()->map(fn ($c) => \App\Models\Master\CompanyEntry::categoryLabel($c))->implode(', ') }}
                                @endif
                            </div>
                        </td>
                        <td>{{ $e->tenant?->business_name ?? '—' }}</td>
                        <td class="small">
                            @if($e->type === 'transfer')
                                {{ $accountName($e->lines->first()?->account_id) }} → {{ $accountName($e->lines->first()?->to_account_id) }}
                            @else
                                @foreach($e->lines as $l)
                                    <div>{{ $accountName($l->account_id) }}@if($e->lines->count() > 1): <span class="hs-num">{{ $fmt($l->amount) }}</span>@endif</div>
                                @endforeach
                            @endif
                        </td>
                        <td class="text-end hs-num hs-ink-in" data-order="{{ $e->type === 'in' ? $total : 0 }}">{{ $e->type === 'in' ? 'Rs '.$fmt($total) : '' }}</td>
                        <td class="text-end hs-num hs-ink-out" data-order="{{ $e->type === 'out' ? $total : 0 }}">{{ $e->type === 'out' ? 'Rs '.$fmt($total) : '' }}</td>
                        <td class="small" style="max-width: 240px">
                            {{ $e->note }}
                            @if($e->deleted_at)
                                <div class="hs-bad">Hatayi: {{ $e->delete_reason }}</div>
                            @endif
                            {{-- Kis ne daali — note ke neeche, taake table chhoti rahe. --}}
                            <div class="text-muted">
                                — {{ $userNames[$e->created_by] ?? '—' }}
                                @if($edits)
                                    <span class="badge bg-secondary" title="{{ $edits }} dafa badli gayi">badli {{ $edits }}×</span>
                                @endif
                            </div>
                        </td>
                        <td class="text-end text-nowrap">
                            @unless($e->deleted_at)
                                <button type="button" class="btn btn-sm btn-outline-primary" data-hs-edit="{{ $e->id }}" aria-label="{{ $e->entry_no }} badlein"><i class="ti ti-pencil"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-hs-delete="{{ $e->id }}" data-hs-no="{{ $e->entry_no }}" aria-label="{{ $e->entry_no }} hatayein"><i class="ti ti-trash"></i></button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr>
                    <th colspan="5">{{ $monthLabel }} ka total</th>
                    <th class="text-end hs-num">Rs {{ $fmt($period['income']) }}</th>
                    <th class="text-end hs-num">Rs {{ $fmt($period['expense']) }}</th>
                    <th colspan="2"></th>
                </tr></tfoot>
            </table>
        </div>
    @endif
</div></div>

@include('central.hisab.partials.entry-modal')
@include('central.hisab.partials.setup-modal')
@endsection

@push('scripts')
<script src="{{ asset('assets/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/js/dataTables.bootstrap5.min.js') }}"></script>
{{-- ApexCharts sirf is safhe par (jese analytics report par) — layout mein nahi. --}}
<script src="{{ asset('assets/plugins/apexchart/apexcharts.min.js') }}"></script>
<script>
window.HISAB = {
    csrf: @json(csrf_token()),
    urls: {
        store: @json(route('central.hisab.entries.store')),
        entry: @json(url('/hisab/entries')),
        setup: @json(route('central.hisab.setup.update')),
        page: @json(route('central.hisab.index')),
    },
    month: @json($month->format('Y-m')),
    today: @json(now()->toDateString()),
    accounts: @json($jsAccounts),
    categories: @json($categories),
    entries: @json($entryJson),
    trend: @json($trend),
    clients: @json($period['by_client']),
    expenses: @json($period['out_category']),
};
</script>
@include('central.hisab.partials.scripts')
@endpush
