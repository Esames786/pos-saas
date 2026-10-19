@extends('layouts.app')

@section('title', 'Bingoo Universe')

@section('content')
@php
    $bytes = fn ($b) => \App\Services\Central\SystemUsageService::bytes($b);
    $level = fn ($p) => $p === null ? 'is-unknown' : ($p >= 80 ? 'is-danger' : ($p >= 60 ? 'is-warn' : 'is-ok'));
    $canEditModules = (bool) auth('central')->user()?->can('central.modules.edit');
@endphp

<div class="mu-head">
    <div>
        <p class="mu-eyebrow">Bingoo Master</p>
        <h1>Bingoo Universe</h1>
        <p class="mu-sub">Your server, every customer and every module — on one page.
            <strong>{{ $counts['all'] }}</strong> tenants · <strong>{{ $counts['active'] }}</strong> working ·
            <strong>{{ $counts['pending'] }}</strong> waiting · <strong>{{ $counts['demo'] }}</strong> demos.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @can('central.system-usage.refresh')
            <form method="POST" action="{{ route('central.system-usage.refresh') }}" id="refresh-form">@csrf
                <button class="btn btn-primary" id="refresh-btn" title="Reads the server and every tenant again. Read-only — changes nothing.">
                    <i class="ti ti-refresh me-1" aria-hidden="true"></i><span>Refresh now</span>
                </button>
            </form>
        @endcan
        @can('central.tenants.index')
            <a href="{{ url('/tenants') }}" class="btn btn-light"><i class="ti ti-list me-1" aria-hidden="true"></i>Tenant list</a>
        @endcan
        @can('central.tenants.create')
            <a href="{{ url('/tenants/create') }}" class="btn btn-outline-primary"><i class="ti ti-plus me-1" aria-hidden="true"></i>New tenant</a>
        @endcan
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

{{-- ── Server ─────────────────────────────────────────────────────────────────────────────────── --}}
<h2 class="mu-section-title"><i class="ti ti-server-2" aria-hidden="true"></i>Server</h2>
@if($server)
    <div class="mu-hero" aria-label="Server health">
        <div class="mu-meter">
            <div class="mu-gauge {{ $level($server['cpu_pct']) }}" style="--p: {{ min(100, (float) ($server['cpu_pct'] ?? 0)) }}" role="img"
                 aria-label="CPU {{ $server['cpu_pct'] !== null ? $server['cpu_pct'] . ' percent busy' : 'unknown' }}"><span>{{ $server['cpu_pct'] !== null ? round($server['cpu_pct']) . '%' : '—' }}</span></div>
            <div class="mu-meter-body">
                <div class="mu-meter-label" title="Processor">CPU</div>
                <div class="mu-meter-value">{{ $server['cpu_pct'] !== null ? round($server['cpu_pct']) . '% busy' : '—' }}</div>
                <div class="mu-meter-note">
                    @if($server['cpu_load'] !== null && $server['cpu_cores'])
                        Load {{ $server['cpu_load'] }} on {{ $server['cpu_cores'] }} cores (1-minute average)
                    @else
                        {{ $server['meta']['cpu_note'] ?? 'Not reported by this server.' }}
                    @endif
                </div>
            </div>
        </div>
        <div class="mu-meter">
            <div class="mu-gauge {{ $level($server['ram_pct']) }}" style="--p: {{ (float) ($server['ram_pct'] ?? 0) }}" role="img"
                 aria-label="Memory {{ $server['ram_pct'] !== null ? $server['ram_pct'] . ' percent used' : 'unknown' }}"><span>{{ $server['ram_pct'] !== null ? round($server['ram_pct']) . '%' : '—' }}</span></div>
            <div class="mu-meter-body">
                <div class="mu-meter-label" title="RAM">Memory</div>
                <div class="mu-meter-value">{{ $server['ram_pct'] !== null ? round($server['ram_pct']) . '% used' : '—' }}</div>
                <div class="mu-meter-note">
                    @if($server['ram_total'] !== null)
                        {{ $bytes($server['ram_used']) }} of {{ $bytes($server['ram_total']) }}
                    @else
                        {{ $server['meta']['ram_note'] ?? 'Not reported by this server.' }}
                    @endif
                </div>
            </div>
        </div>
        <div class="mu-meter">
            <div class="mu-gauge {{ $level($server['disk_pct']) }}" style="--p: {{ (float) ($server['disk_pct'] ?? 0) }}" role="img"
                 aria-label="Disk {{ $server['disk_pct'] !== null ? $server['disk_pct'] . ' percent full' : 'unknown' }}"><span>{{ $server['disk_pct'] !== null ? round($server['disk_pct']) . '%' : '—' }}</span></div>
            <div class="mu-meter-body">
                <div class="mu-meter-label">Disk</div>
                <div class="mu-meter-value">{{ $server['disk_free'] !== null ? $bytes($server['disk_free']) . ' free' : '—' }}</div>
                <div class="mu-meter-note">{{ $server['disk_total'] !== null ? 'of ' . $bytes($server['disk_total']) . ' (' . round((float) $server['disk_pct']) . '% full)' : 'Not reported.' }}</div>
            </div>
        </div>
        <div class="mu-meter">
            <div class="mu-meter-icon" aria-hidden="true"><i class="ti ti-database"></i></div>
            <div class="mu-meter-body">
                <div class="mu-meter-label" title="MySQL">Databases</div>
                <div class="mu-meter-value">{{ $bytes($server['mysql']) }}</div>
                <div class="mu-meter-note">Master {{ $bytes($server['meta']['master_db_bytes'] ?? null) }} + {{ (int) ($server['meta']['tenants_read'] ?? 0) }} tenant databases</div>
            </div>
        </div>
        <div class="mu-meter">
            <div class="mu-meter-icon" aria-hidden="true"><i class="ti ti-chart-line"></i></div>
            <div class="mu-meter-body">
                <div class="mu-meter-label">Disk forecast</div>
                <div class="mu-meter-value">
                    @if($forecast['state'] === 'ok')
                        ~{{ number_format($forecast['days']) }} days
                    @elseif($forecast['state'] === 'steady')
                        Not growing
                    @else
                        Collecting
                    @endif
                </div>
                <div class="mu-meter-note">{{ $forecast['text'] }}</div>
            </div>
        </div>
    </div>
    <div class="mu-snapline">
        <span><i class="ti ti-clock me-1" aria-hidden="true"></i>Last reading {{ $server['taken_at']->diffForHumans() }} ({{ \App\Services\Central\MasterOverviewService::pkt($server['taken_at']) }}). Read automatically every morning at 05:30.</span>
    </div>
@else
    <div class="mu-empty">
        <i class="ti ti-gauge" aria-hidden="true"></i>
        No reading yet. Press <strong>Refresh now</strong> to read the server and every tenant (read-only — nothing is changed).
    </div>
@endif

{{-- ── Alerts ─────────────────────────────────────────────────────────────────────────────────── --}}
<div class="mu-alerts" aria-label="Things that need attention">
    @forelse($alerts as $alert)
        <div class="mu-alert is-{{ $alert['level'] }}" role="status">
            <i class="ti {{ $alert['icon'] }}" aria-hidden="true"></i>
            <div>
                <strong>{{ $alert['title'] }}</strong>
                <span class="mu-alert-text">{{ $alert['text'] }}</span>
                @if($alert['tenants']->isNotEmpty())
                    <div class="mu-alert-tenants">
                        @foreach($alert['tenants']->take(12) as $t)
                            <a href="{{ url('/tenants/' . $t->id) }}">{{ $t->tenant_code }}</a>
                        @endforeach
                        @if($alert['tenants']->count() > 12)
                            <span class="mu-note">+{{ $alert['tenants']->count() - 12 }} more</span>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @empty
        <div class="mu-alert is-ok" role="status">
            <i class="ti ti-circle-check" aria-hidden="true"></i>
            <div><strong>All clear</strong><span class="mu-alert-text">Every tenant has a business type and a recent backup, the disk has room, and no invoice is overdue.</span></div>
        </div>
    @endforelse
</div>

{{-- ── Tenants ────────────────────────────────────────────────────────────────────────────────── --}}
<h2 class="mu-section-title"><i class="ti ti-building-store" aria-hidden="true"></i>Tenants <span class="mu-count">{{ $cards->count() }} — click a card to open it</span></h2>
@if($cards->isEmpty())
    <div class="mu-empty"><i class="ti ti-building-store" aria-hidden="true"></i>No tenants yet.</div>
@else
    <div class="mu-grid">
        @foreach($cards as $c)
            @php $t = $c['tenant']; @endphp
            <a href="{{ url('/tenants/' . $t->id) }}" class="mu-tenant {{ $t->is_demo ? 'is-demo' : '' }} {{ $t->status === 'cancelled' ? 'is-muted' : '' }}"
               aria-label="Open {{ $t->business_name }} ({{ $t->tenant_code }})">
                <div class="mu-tenant-top">
                    @if($c['business'])
                        <span class="mu-biz-pic"><img src="{{ asset($c['business']['image']) }}" alt="" loading="lazy"></span>
                    @else
                        <span class="mu-biz-pic is-unset" aria-hidden="true">?</span>
                    @endif
                    <div style="min-width:0">
                        <div class="mu-tenant-name">{{ $t->business_name }}</div>
                        <div class="mu-tenant-code"><code>{{ $t->tenant_code }}</code> · {{ $c['plan'] ?? 'No plan' }}</div>
                    </div>
                </div>
                <div class="mu-tenant-tags">
                    @include('central.partials.pill', ['status' => $t->status])
                    @if($t->is_demo)
                        <span class="mu-pill is-demo">Demo</span>
                    @endif
                    @if($c['business'])
                        <span class="mu-biz-label">{{ $c['business']['label'] }}</span>
                    @else
                        <span class="mu-biz-label is-unset">Business: Not set</span>
                    @endif
                </div>
                @include('central.partials.tenant-stats', ['c' => $c])
            </a>
        @endforeach
    </div>
    <p class="mu-note mt-2 mb-0"><i class="ti ti-info-circle me-1" aria-hidden="true"></i><strong>Activity share is an estimate:</strong> this tenant's share of all paid POS bills in the last 30 days. All tenants share one server, so CPU and memory per tenant cannot be measured.</p>
@endif

{{-- ── Modules ────────────────────────────────────────────────────────────────────────────────── --}}
<h2 class="mu-section-title"><i class="ti ti-puzzle" aria-hidden="true"></i>Modules <span class="mu-count">how many tenants use each</span></h2>
<div class="mu-chipbar" aria-label="Modules">
    @foreach($modules as $m)
        @if($canEditModules)
            <a href="{{ url('/modules/' . $m['module']->id . '/edit') }}" class="mu-chip" title="{{ $m['description'] }}">
                <b>{{ $m['tenants'] }}</b>
                <span>{{ $m['module']->name }}</span>
                <small>{{ $m['screens'] }} screens · {{ $m['plans'] }} {{ $m['plans'] === 1 ? 'plan' : 'plans' }}</small>
            </a>
        @else
            <div class="mu-chip" title="{{ $m['description'] }}">
                <b>{{ $m['tenants'] }}</b>
                <span>{{ $m['module']->name }}</span>
                <small>{{ $m['screens'] }} screens · {{ $m['plans'] }} {{ $m['plans'] === 1 ? 'plan' : 'plans' }}</small>
            </div>
        @endif
    @endforeach
</div>

@push('scripts')
<script>
(function () {
    var form = document.getElementById('refresh-form');
    if (!form) return;
    form.addEventListener('submit', function () {
        var btn = document.getElementById('refresh-btn');
        btn.disabled = true;
        btn.querySelector('span').textContent = 'Reading every tenant…';
    });
})();
</script>
@endpush
@endsection
