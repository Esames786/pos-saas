@extends('layouts.app')

@section('title', $tenant->business_name)

@section('content')
@php
    $biz = \App\Support\Business::exists($tenant->primary_business) ? $businesses[$tenant->primary_business] : null;
    $primaryDomain = $tenant->domains->where('is_primary', true)->first();
    // Which tab to open after a form came back with an error (the URL hash does it otherwise).
    $openTab = match (true) {
        $errors->has('primary_business') || $errors->has('confirm_code') || $errors->has('features') => 'business',
        $errors->has('branch') || $errors->has('name') || $errors->has('business_type') => 'branches',
        $errors->has('subdomain') || $errors->has('website_domain') || $errors->has('domain') || $errors->has('owner_password') => 'domains',
        $errors->has('understand') || $errors->has('tenant_code') || $errors->has('confirm') => 'danger',
        $errors->has('plan_id') || $errors->has('status') => 'billing',
        default => null,
    };
    // A tenant without a working database opens on its Domains & database tab (provisioning lives there).
    $defaultTab = (! $tenant->database || $tenant->database->migration_status !== 'completed') ? 'domains' : null;
    $tabs = [
        'overview' => ['Overview', 'ti-layout-dashboard'],
        'business' => ['Business & switches', 'ti-building-store'],
        'modules'  => ['Modules', 'ti-puzzle'],
        'branches' => ['Branches', 'ti-git-branch'],
        'billing'  => ['Billing & invoices', 'ti-file-invoice'],
        'backups'  => ['Backups', 'ti-database-export'],
        'domains'  => ['Domains & database', 'ti-world'],
        'danger'   => ['Danger zone', 'ti-alert-octagon'],
    ];
@endphp

{{-- ── Header ─────────────────────────────────────────────────────────────────────────────────── --}}
<div class="mu-hub-head">
    <div class="d-flex align-items-center gap-3" style="min-width:0">
        @if($biz)
            <span class="mu-biz-pic"><img src="{{ asset($biz['image']) }}" alt="{{ $biz['label'] }}"></span>
        @else
            <span class="mu-biz-pic is-unset" title="Business type not set">?</span>
        @endif
        <div style="min-width:0">
            <p class="mu-eyebrow mb-1">Tenant</p>
            <h1>{{ $tenant->business_name }}</h1>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                <span class="mu-tenant-code">Code: <code class="fw-bold">{{ $tenant->tenant_code }}</code></span>
                <span>Status: @include('central.partials.pill', ['status' => $tenant->status])</span>
                @if($tenant->is_demo)
                    <span class="mu-pill is-demo">Demo</span>
                @endif
                @if($biz)
                    <span class="mu-biz-label">{{ $biz['label'] }}</span>
                @else
                    <span class="mu-biz-label is-unset">Business: Not set</span>
                @endif
                <span class="mu-note">Plan: {{ $tenant->subscription?->plan?->name ?? 'No plan' }}</span>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ url('/tenants') }}" class="btn btn-light">Back</a>
        @if($primaryDomain && $primaryDomain->status === 'active')
            <a href="{{ request()->getScheme() }}://{{ $primaryDomain->domain }}" class="btn btn-outline-primary" target="_blank" rel="noopener">
                <i class="ti ti-external-link me-1" aria-hidden="true"></i>Open their app
            </a>
        @endif
        @can('central.tenants.backup')
            <form method="POST" action="{{ route('central.tenants.backup', $tenant) }}">@csrf
                <button class="btn btn-outline-primary"><i class="ti ti-database-export me-1" aria-hidden="true"></i>Backup now</button>
            </form>
        @endcan
        @can('central.tenants.sync')
            <form method="POST" action="{{ route('central.tenants.sync', $tenant) }}" onsubmit="return confirm('Sync {{ $tenant->tenant_code }}? Applies migrations + permissions; no data deleted.');">@csrf
                <button class="btn btn-outline-success"><i class="ti ti-refresh me-1" aria-hidden="true"></i>Sync</button>
            </form>
        @endcan
        @can('central.tenants.edit')
            <a href="{{ url('/tenants/' . $tenant->id . '/edit') }}" class="btn btn-primary">Edit</a>
        @endcan
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert"><i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ $errors->first() }}</div>
@endif

{{-- ── Tabs ───────────────────────────────────────────────────────────────────────────────────── --}}
<ul class="nav mu-tabs" role="tablist" id="hub-tabs" @if($openTab) data-open-tab="{{ $openTab }}" @endif @if($defaultTab) data-default-tab="{{ $defaultTab }}" @endif>
    @foreach($tabs as $id => [$label, $icon])
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $loop->first ? 'active' : '' }} {{ $id === 'danger' ? 'is-danger' : '' }}" id="tab-btn-{{ $id }}"
                    data-bs-toggle="tab" data-bs-target="#tab-{{ $id }}" data-tab="{{ $id }}" type="button" role="tab"
                    aria-controls="tab-{{ $id }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                <i class="ti {{ $icon }}" aria-hidden="true"></i>{{ $label }}
            </button>
        </li>
    @endforeach
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-overview" role="tabpanel" aria-labelledby="tab-btn-overview" tabindex="0">
        @include('central.tenants.hub.overview')
    </div>
    <div class="tab-pane fade" id="tab-business" role="tabpanel" aria-labelledby="tab-btn-business" tabindex="0">
        @include('central.tenants.hub.business')
    </div>
    <div class="tab-pane fade" id="tab-modules" role="tabpanel" aria-labelledby="tab-btn-modules" tabindex="0">
        @include('central.tenants.hub.modules')
    </div>
    <div class="tab-pane fade" id="tab-branches" role="tabpanel" aria-labelledby="tab-btn-branches" tabindex="0">
        @include('central.tenants.hub.branches')
    </div>
    <div class="tab-pane fade" id="tab-billing" role="tabpanel" aria-labelledby="tab-btn-billing" tabindex="0">
        @include('central.tenants.hub.billing')
    </div>
    <div class="tab-pane fade" id="tab-backups" role="tabpanel" aria-labelledby="tab-btn-backups" tabindex="0">
        @include('central.tenants.hub.backups')
    </div>
    <div class="tab-pane fade" id="tab-domains" role="tabpanel" aria-labelledby="tab-btn-domains" tabindex="0">
        @include('central.tenants.hub.domains')
    </div>
    <div class="tab-pane fade" id="tab-danger" role="tabpanel" aria-labelledby="tab-btn-danger" tabindex="0">
        @include('central.tenants.hub.danger')
    </div>
</div>

@push('scripts')
<script>
/* MASTER-UNIVERSE-1 — tabs follow the URL hash (#business, #features, #backups …) so a redirect lands
   on the right tab, and clicking a tab updates the hash (shareable, survives reload). */
(function () {
    var alias = { features: 'business', history: 'business' };
    var tabs = document.getElementById('hub-tabs');
    if (!tabs || !window.bootstrap) return;
    function show(id) {
        var btn = document.querySelector('#hub-tabs [data-tab="' + id + '"]');
        if (btn) { bootstrap.Tab.getOrCreateInstance(btn).show(); return true; }
        return false;
    }
    var hash = (location.hash || '').replace('#', '');
    var wanted = tabs.getAttribute('data-open-tab') || alias[hash] || hash || tabs.getAttribute('data-default-tab');
    if (wanted && show(wanted) && alias[hash]) {
        var target = document.getElementById(hash);
        if (target) setTimeout(function () { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 200);
    }
    // Buttons inside a tab that jump to another tab ("Change business or switches").
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-goto-tab]') : null;
        if (b) { e.preventDefault(); show(b.getAttribute('data-goto-tab')); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    });
    tabs.addEventListener('shown.bs.tab', function (e) {
        var id = e.target.getAttribute('data-tab');
        if (id && history.replaceState) history.replaceState(null, '', '#' + id);
    });
})();
</script>
@endpush
@endsection
