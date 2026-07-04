@extends('layouts.app')

@section('title', 'Tenant Detail')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $tenant->business_name }}</h1>
        <p class="fw-medium">
            Code: <span class="fw-bold">{{ $tenant->tenant_code }}</span>
            &nbsp;|&nbsp;
            Status: @include('central.tenants.partials.status-badge', ['status' => $tenant->status])
        </p>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ url('/tenants') }}" class="btn btn-light">Back</a>

        @can('central.tenants.edit')
            <a href="{{ url('/tenants/' . $tenant->id . '/edit') }}" class="btn btn-primary">Edit</a>
        @endcan
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif
@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- MASTER-TENANT-OPS-1: tenant operations --}}
<div class="card mb-3">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="small text-muted"><i class="ti ti-server-cog me-1"></i><strong>Operations</strong> — Backup is safe; Sync applies migrations + permissions (no data loss); Reset deletes + reseeds a demo (backup first).</div>
        <div class="d-flex flex-wrap gap-2">
            @can('central.tenants.backup')
                <form method="POST" action="{{ route('central.tenants.backup', $tenant) }}">@csrf
                    <button class="btn btn-sm btn-outline-primary"><i class="ti ti-database-export me-1"></i>Backup</button>
                </form>
            @endcan
            @can('central.tenants.backups')
                <a href="{{ route('central.tenants.backups', $tenant) }}" class="btn btn-sm btn-outline-secondary"><i class="ti ti-history me-1"></i>Backups</a>
            @endcan
            @can('central.tenants.sync')
                <form method="POST" action="{{ route('central.tenants.sync', $tenant) }}" onsubmit="return confirm('Sync {{ $tenant->tenant_code }}? Applies migrations + permissions; no data deleted.');">@csrf
                    <button class="btn btn-sm btn-outline-success"><i class="ti ti-refresh me-1"></i>Sync</button>
                </form>
            @endcan
        </div>
    </div>
</div>

<div class="row">
    {{-- Left column: info + domains --}}
    <div class="col-lg-8">

        {{-- Tenant info card --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Tenant Information</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <small class="text-muted d-block">Owner Name</small>
                        <span class="fw-semibold">{{ $tenant->owner_name ?? '-' }}</span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Owner Email</small>
                        <span class="fw-semibold">{{ $tenant->owner_email ?? '-' }}</span>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Currency</small>
                        <span class="fw-semibold">{{ $tenant->currency_code }}</span>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Trial Ends</small>
                        <span class="fw-semibold">{{ $tenant->trial_ends_at?->format('Y-m-d') ?? '-' }}</span>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Activated At</small>
                        <span class="fw-semibold">{{ $tenant->activated_at?->format('Y-m-d H:i') ?? '-' }}</span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Plan</small>
                        <span class="fw-semibold">{{ $tenant->subscription?->plan?->name ?? 'No plan' }}</span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Subscription Status</small>
                        <span class="fw-semibold">{{ $tenant->subscription?->status ?? '-' }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Subscription card --}}
        @can('central.tenants.subscription.update')
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Subscription</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/subscription') }}">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Plan</label>
                            <select name="plan_id" class="form-select">
                                <option value="">No Plan</option>
                                @foreach($plans as $plan)
                                    <option value="{{ $plan->id }}" @selected(old('plan_id', $tenant->subscription?->plan_id) == $plan->id)>
                                        {{ $plan->name }} ({{ $plan->code }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Subscription Status</label>
                            <select name="status" class="form-select">
                                @foreach(['trial', 'active', 'past_due', 'cancelled'] as $status)
                                    <option value="{{ $status }}" @selected(old('status', $tenant->subscription?->status ?? 'trial') === $status)>
                                        {{ ucfirst(str_replace('_', ' ', $status)) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Trial Ends At</label>
                            <input type="date" name="trial_ends_at" class="form-control"
                                   value="{{ old('trial_ends_at', optional($tenant->subscription?->trial_ends_at)->format('Y-m-d')) }}">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Current Period Ends At</label>
                            <input type="date" name="current_period_ends_at" class="form-control"
                                   value="{{ old('current_period_ends_at', optional($tenant->subscription?->current_period_ends_at)->format('Y-m-d')) }}">
                        </div>
                    </div>

                    <div class="text-end mt-3">
                        <button class="btn btn-primary">Update Subscription</button>
                    </div>
                </form>
            </div>
        </div>
        @endcan

        {{-- Billing / Invoices card --}}
        @can('central.invoices.index')
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Billing</h5>
                @can('central.tenants.invoices.create')
                    <a href="{{ url('/tenants/' . $tenant->id . '/invoices/create') }}" class="btn btn-sm btn-primary">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Invoice
                    </a>
                @endcan
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr><th>Invoice #</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Balance</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                    @forelse(($recentInvoices ?? []) as $invoice)
                        <tr>
                            <td><code>{{ $invoice->invoice_no }}</code></td>
                            <td>{{ ucfirst(str_replace('_',' ',$invoice->status)) }}</td>
                            <td class="text-end">{{ $invoice->currency_code }} {{ number_format((float) $invoice->total_amount, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $invoice->balance_amount, 2) }}</td>
                            <td class="text-end"><a href="{{ url('/invoices/' . $invoice->id) }}" class="btn btn-sm btn-light">View</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No invoices yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @endcan

        {{-- Domains card --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Domains</h5></div>
            <div class="card-body">

                @can('central.tenant-domains.store')
                    <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/domains') }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-md-8">
                            <div class="input-group">
                                <input type="text" name="subdomain" class="form-control" placeholder="branch2">
                                <span class="input-group-text">.{{ config('tenancy.tenant_base_domain') }}</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-primary w-100">Add Domain</button>
                        </div>
                    </form>
                @endcan

                <div class="table-responsive">
                    <table class="table table-nowrap align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Primary</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($tenant->domains as $domain)
                            <tr>
                                <td>{{ $domain->domain }}</td>
                                <td>
                                    @if($domain->is_primary)
                                        <span class="badge bg-success">Primary</span>
                                    @else
                                        <span class="badge bg-light text-dark">Secondary</span>
                                    @endif
                                </td>
                                <td>{{ ucfirst($domain->status) }}</td>
                                <td class="text-end">
                                    @if(!$domain->is_primary)
                                        @can('central.tenant-domains.primary')
                                            <form method="POST" action="{{ url('/tenant-domains/' . $domain->id . '/primary') }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-light">Make Primary</button>
                                            </form>
                                        @endcan
                                    @endif

                                    @if($domain->status !== 'active')
                                        @can('central.tenant-domains.activate')
                                            <form method="POST" action="{{ url('/tenant-domains/' . $domain->id . '/activate') }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-success">Activate</button>
                                            </form>
                                        @endcan
                                    @else
                                        @if(!$domain->is_primary)
                                            @can('central.tenant-domains.deactivate')
                                                <form method="POST" action="{{ url('/tenant-domains/' . $domain->id . '/deactivate') }}" class="d-inline">
                                                    @csrf
                                                    <button class="btn btn-sm btn-warning">Deactivate</button>
                                                </form>
                                            @endcan
                                        @endif
                                    @endif

                                    @if(!$domain->is_primary)
                                        @can('central.tenant-domains.destroy')
                                            <form method="POST" action="{{ url('/tenant-domains/' . $domain->id) }}" class="d-inline"
                                                  onsubmit="return confirm('Delete this domain?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-3">No domains configured.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    {{-- Right column: database status + provision + actions --}}
    <div class="col-lg-4">

        {{-- Database status card --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Database</h5></div>
            <div class="card-body">
                @if($tenant->database)
                    <div class="mb-2">
                        <small class="text-muted d-block">Database Name</small>
                        <span class="fw-semibold">{{ $tenant->database->db_database }}</span>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted d-block">Migration Status</small>
                        <span class="badge @if($tenant->database->migration_status === 'completed') bg-success @elseif($tenant->database->migration_status === 'failed') bg-danger @else bg-warning text-dark @endif">
                            {{ ucfirst($tenant->database->migration_status) }}
                        </span>
                    </div>
                    @if($tenant->database->migration_status === 'running')
                        <div class="alert alert-warning py-2 px-3 mb-0 small" id="provision-progress">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
                                <strong>Provisioning in progress…</strong>
                            </div>
                            <div class="progress mb-2" style="height:6px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning w-100"></div>
                            </div>
                            Creating the database, running migrations, and seeding starter data.
                            This page refreshes automatically every few seconds.
                            <div class="text-muted mt-1">Stuck on "Running" for over 2 minutes? Click <strong>Provision &amp; Activate</strong> again — it safely resumes and completes a half-done setup.</div>
                        </div>
                        <script>setTimeout(function () { window.location.reload(); }, 6000);</script>
                    @elseif($tenant->database->migration_status === 'failed')
                        <div class="alert alert-danger py-2 px-3 mb-0 small">
                            <i class="ti ti-alert-triangle me-1"></i>
                            Last provisioning attempt <strong>failed</strong>. Click <strong>Provision &amp; Activate</strong> again — it safely retries from where it stopped.
                        </div>
                    @endif
                @else
                    <p class="text-muted mb-0">No database provisioned yet.</p>
                @endif
            </div>
        </div>

        {{-- Provision card --}}
        @can('central.tenants.provision')
            @if(!$tenant->database || $tenant->database->migration_status !== 'completed')
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">Provision Database</h5></div>
                    <div class="card-body">
                        <p class="text-muted small">
                            Creates the tenant database, runs all migrations, and seeds the starter data
                            (owner login, main branch, currency, payment methods, chart of accounts).
                            <strong>Takes 1–2 minutes.</strong>
                        </p>

                        <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/provision') }}" class="row g-3" id="provision-form">
                            @csrf

                            <div class="col-12">
                                <label class="form-label">Owner Password <span class="text-danger">*</span></label>
                                <input type="password" name="owner_password" class="form-control" minlength="8" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                <input type="password" name="owner_password_confirmation" class="form-control" required>
                            </div>

                            <div class="col-12">
                                <button class="btn btn-success w-100" id="provision-btn">
                                    <span class="spinner-border spinner-border-sm me-1 d-none" id="provision-spinner" role="status" aria-hidden="true"></span>
                                    <i class="ti ti-database me-1" id="provision-icon"></i>
                                    <span id="provision-label">Provision &amp; Activate</span>
                                </button>
                                <div class="alert alert-info py-2 px-3 mt-2 mb-0 small d-none" id="provision-wait-note">
                                    <strong>Setting up the account…</strong> creating database, running migrations, seeding starter data.
                                    Please keep this page open — it can take up to 2 minutes.
                                </div>
                            </div>
                        </form>
                        <script>
                            (function () {
                                var form = document.getElementById('provision-form');
                                if (!form) return;
                                form.addEventListener('submit', function () {
                                    var btn = document.getElementById('provision-btn');
                                    btn.disabled = true;
                                    document.getElementById('provision-spinner').classList.remove('d-none');
                                    document.getElementById('provision-icon').classList.add('d-none');
                                    document.getElementById('provision-label').textContent = 'Provisioning… please wait';
                                    document.getElementById('provision-wait-note').classList.remove('d-none');
                                });
                            })();
                        </script>
                    </div>
                </div>
            @endif
        @endcan

        {{-- Status actions card --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Status Actions</h5></div>
            <div class="card-body d-grid gap-2">

                @if($tenant->status !== 'active')
                    @can('central.tenants.activate')
                        <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/activate') }}">
                            @csrf
                            <button class="btn btn-success w-100">
                                <i class="ti ti-check me-1"></i>Activate Tenant
                            </button>
                        </form>
                    @endcan
                @endif

                @if($tenant->status === 'active')
                    @can('central.tenants.suspend')
                        <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/suspend') }}">
                            @csrf
                            <button class="btn btn-warning w-100">
                                <i class="ti ti-ban me-1"></i>Suspend Tenant
                            </button>
                        </form>
                    @endcan
                @endif

                @if($tenant->status !== 'cancelled')
                    @can('central.tenants.cancel')
                        <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/cancel') }}"
                              onsubmit="return confirm('Cancel this tenant? This cannot be undone easily.')">
                            @csrf
                            <button class="btn btn-danger w-100">
                                <i class="ti ti-x me-1"></i>Cancel Tenant
                            </button>
                        </form>
                    @endcan
                @endif

            </div>
        </div>

    </div>
</div>
@endsection
