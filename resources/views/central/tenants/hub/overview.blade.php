{{-- MASTER-UNIVERSE-1 — Overview: the tenant at a glance (numbers from the newest usage snapshot). --}}
@php
    $snapAt = $card['read_at'];
@endphp
<div class="row g-3">
    <div class="col-xl-8">
        <section class="mu-panel">
            <h2>At a glance</h2>
            <p class="mu-panel-sub">
                @if($snapAt)
                    Numbers from the last reading, {{ $snapAt->diffForHumans() }} ({{ \App\Services\Central\MasterOverviewService::pkt($snapAt) }}).
                @else
                    No reading yet — the dashboard's <strong>Refresh now</strong> reads every tenant (read-only).
                @endif
            </p>
            @include('central.partials.tenant-stats', ['c' => $card])
            <div class="mu-stats mt-2">
                <div class="mu-stat">
                    <div class="mu-stat-label">Products</div>
                    <div class="mu-stat-value">@if($card['products'] !== null) {{ number_format($card['products']) }} @else <span class="mu-unknown" title="{{ $card['read_error'] }}">—</span> @endif</div>
                </div>
                <div class="mu-stat">
                    <div class="mu-stat-label">Users</div>
                    <div class="mu-stat-value">@if($card['users'] !== null) {{ number_format($card['users']) }} @else <span class="mu-unknown" title="{{ $card['read_error'] }}">—</span> @endif</div>
                </div>
                <div class="mu-stat">
                    <div class="mu-stat-label">Branches</div>
                    <div class="mu-stat-value">@if($branchList['ok']) {{ count($branchList['branches']) }} @else <span class="mu-unknown" title="{{ $branchList['error'] ?? '' }}">—</span> @endif</div>
                </div>
                <div class="mu-stat">
                    <div class="mu-stat-label">Sales amount · 30 days</div>
                    <div class="mu-stat-value">@if($card['sales_amount'] !== null) {{ $tenant->currency_code }} {{ number_format($card['sales_amount']) }} @else <span class="mu-unknown" title="{{ $card['read_error'] }}">—</span> @endif</div>
                </div>
            </div>
            @if($snapshot && isset($snapshot->meta['backups_bytes']))
                <p class="mu-note mt-2 mb-0">Backup files kept for this tenant: {{ \App\Services\Central\SystemUsageService::bytes((int) $snapshot->meta['backups_bytes']) }} · Files = website pictures + product pictures.</p>
            @endif
        </section>

        <section class="mu-panel">
            <h2>Tenant information</h2>
            <div class="mu-kv mt-2">
                <div><div class="mu-k">Owner name</div><div class="mu-v">{{ $tenant->owner_name ?? '-' }}</div></div>
                <div><div class="mu-k">Owner email</div><div class="mu-v">{{ $tenant->owner_email ?? '-' }}</div></div>
                <div><div class="mu-k">Currency</div><div class="mu-v">{{ $tenant->currency_code }}</div></div>
                <div><div class="mu-k">Trial ends</div><div class="mu-v">{{ $tenant->trial_ends_at?->format('Y-m-d') ?? '-' }}</div></div>
                <div><div class="mu-k">Activated at</div><div class="mu-v">{{ $tenant->activated_at?->format('Y-m-d H:i') ?? '-' }}</div></div>
                <div><div class="mu-k">Plan</div><div class="mu-v">{{ $tenant->subscription?->plan?->name ?? 'No plan' }}</div></div>
                <div><div class="mu-k">Subscription status</div><div class="mu-v">{{ $tenant->subscription?->status ?? '-' }}</div></div>
                <div><div class="mu-k">Created</div><div class="mu-v">{{ $tenant->created_at?->format('Y-m-d') ?? '-' }}</div></div>
                @if($tenant->timezone)
                    <div><div class="mu-k">Timezone</div><div class="mu-v">{{ $tenant->timezone }}</div></div>
                @endif
                <div><div class="mu-k">Main address</div><div class="mu-v">{{ $primaryDomain?->domain ?? 'No domain' }}</div></div>
            </div>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="mu-panel">
            <h2>Business type</h2>
            @if($biz)
                <div class="d-flex align-items-center gap-3 mt-2">
                    <span class="mu-biz-pic"><img src="{{ asset($biz['image']) }}" alt=""></span>
                    <div>
                        <div class="fw-bold" style="font-size:15px">{{ $biz['label'] }}</div>
                        <div class="mu-note">{{ $biz['hint'] }}</div>
                    </div>
                </div>
            @else
                <p class="text-danger fw-bold mt-2 mb-1">Not set</p>
                <p class="mu-note mb-0">Until it is set, the tenant's screens behave exactly as before.</p>
            @endif
            <button type="button" class="btn btn-light btn-sm mt-3" data-goto-tab="business">Change business or switches</button>
        </section>

        <section class="mu-panel">
            <h2>Quick actions</h2>
            <p class="mu-panel-sub">Backup is always safe. Sync applies updates and permissions — it never deletes data.</p>
            <div class="d-grid gap-2">
                @can('central.tenants.backup')
                    <form method="POST" action="{{ route('central.tenants.backup', $tenant) }}">@csrf
                        <button class="btn btn-outline-primary w-100"><i class="ti ti-database-export me-1" aria-hidden="true"></i>Backup now</button>
                    </form>
                @endcan
                @can('central.tenants.sync')
                    <form method="POST" action="{{ route('central.tenants.sync', $tenant) }}" onsubmit="return confirm('Sync {{ $tenant->tenant_code }}? Applies migrations + permissions; no data deleted.');">@csrf
                        <button class="btn btn-outline-success w-100"><i class="ti ti-refresh me-1" aria-hidden="true"></i>Sync (updates + permissions)</button>
                    </form>
                @endcan
                @can('central.tenants.invoices.create')
                    <a href="{{ url('/tenants/' . $tenant->id . '/invoices/create') }}" class="btn btn-light"><i class="ti ti-file-plus me-1" aria-hidden="true"></i>Create invoice</a>
                @endcan
                @can('central.tenants.edit')
                    <a href="{{ url('/tenants/' . $tenant->id . '/edit') }}" class="btn btn-light"><i class="ti ti-pencil me-1" aria-hidden="true"></i>Edit name, owner, plan</a>
                @endcan
            </div>
        </section>
    </div>
</div>
