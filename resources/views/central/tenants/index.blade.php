@extends('layouts.app')

@section('title', 'Tenants')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Tenants</h1>
        <p class="fw-medium">Manage customer accounts, domains, databases, and activation status.</p>
    </div>

    @can('central.tenants.create')
        <a href="{{ url('/tenants/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1"></i>Create Tenant
        </a>
    @endcan
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif
@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- MASTER-TENANT-OPS-1: tenant operations help + global actions --}}
<div class="card mb-3 border-primary-subtle">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <h5 class="mb-0"><i class="ti ti-server-cog me-1"></i>Tenant Operations</h5>
            <div class="d-flex flex-wrap gap-2">
                @can('central.tenants.backup-all')
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#opsGlobalModal"
                            data-action="{{ route('central.tenants.backup-all') }}" data-confirm="BACKUP ALL" data-title="Backup All Tenants"
                            data-desc="Creates a SQL backup of every tenant DB. Does not change any data.">
                        <i class="ti ti-database-export me-1"></i>Backup All
                    </button>
                @endcan
                @can('central.tenants.sync-all')
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#opsGlobalModal"
                            data-action="{{ route('central.tenants.sync-all') }}" data-confirm="SYNC ALL" data-title="Sync All Tenants"
                            data-desc="Runs migrations + permission sync for every tenant. Does NOT delete or seed data. Use after a deployment.">
                        <i class="ti ti-refresh me-1"></i>Sync All
                    </button>
                @endcan
                @can('central.tenants.reset-demos')
                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#opsGlobalModal"
                            data-action="{{ route('central.tenants.reset-demos') }}" data-confirm="RESET DEMOS" data-title="Reset Demo Tenants"
                            data-desc="Drops + reseeds ALL public demo tenants. A backup is created for each first. Only is_demo tenants are affected.">
                        <i class="ti ti-rotate-2 me-1"></i>Reset Demos
                    </button>
                @endcan
            </div>
        </div>
        <div class="row small text-muted g-2">
            <div class="col-md-3"><span class="badge bg-success-subtle text-success-emphasis">Safe</span> <strong>Backup</strong> — downloadable SQL of this tenant.</div>
            <div class="col-md-3"><span class="badge bg-success-subtle text-success-emphasis">No data loss</span> <strong>Sync</strong> — applies migrations + permissions.</div>
            <div class="col-md-3"><span class="badge bg-danger-subtle text-danger-emphasis">Danger</span> <strong>Reset</strong> — deletes + reseeds a demo tenant (backup first).</div>
            <div class="col-md-3"><span class="badge bg-warning-subtle text-warning-emphasis">Backup first</span> <strong>Restore</strong> — replaces tenant data from a backup.</div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/tenants') }}" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Business, owner, email, code">
            </div>

            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    @foreach(['pending','active','suspended','cancelled'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-dark">
                    <i class="ti ti-search me-1"></i>Filter
                </button>
                <a href="{{ url('/tenants') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Business</th>
                    <th>Primary Domain</th>
                    <th>Plan</th>
                    <th>DB</th>
                    <th>Status</th>
                    <th>Trial Ends</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>

            <tbody>
            @forelse($tenants as $tenant)
                @php
                    $primaryDomain = $tenant->domains->where('is_primary', true)->first();
                    $dbStatus = $tenant->database?->migration_status ?: 'not created';
                @endphp

                <tr>
                    <td><span class="fw-bold">{{ $tenant->tenant_code }}</span></td>
                    <td>
                        <div class="fw-semibold">{{ $tenant->business_name }}</div>
                        <small class="text-muted">{{ $tenant->owner_email }}</small>
                    </td>
                    <td>
                        @if($primaryDomain)
                            <span>{{ $primaryDomain->domain }}</span>
                            <small class="d-block text-muted">{{ $primaryDomain->status }}</small>
                        @else
                            <span class="text-muted">No domain</span>
                        @endif
                    </td>
                    <td>{{ $tenant->subscription?->plan?->name ?? 'No plan' }}</td>
                    <td><span class="badge bg-light text-dark">{{ $dbStatus }}</span></td>
                    <td>@include('central.tenants.partials.status-badge', ['status' => $tenant->status])</td>
                    <td>{{ $tenant->trial_ends_at?->format('Y-m-d') ?? '-' }}</td>
                    <td class="text-end text-nowrap">
                        @php $resettable = $tenant->isDemo() && in_array($tenant->tenant_code, (array) config('saas.demos.reset_tenant_codes', []), true); @endphp
                        @can('central.tenants.show')
                            <a href="{{ url('/tenants/' . $tenant->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                        @can('central.tenants.backups')
                            <a href="{{ route('central.tenants.backups', $tenant) }}" class="btn btn-sm btn-outline-secondary" title="Backup history"><i class="ti ti-history"></i></a>
                        @endcan
                        @can('central.tenants.backup')
                            <form method="POST" action="{{ route('central.tenants.backup', $tenant) }}" class="d-inline">@csrf
                                <button class="btn btn-sm btn-outline-primary" title="Create backup"><i class="ti ti-database-export"></i></button>
                            </form>
                        @endcan
                        @can('central.tenants.sync')
                            <form method="POST" action="{{ route('central.tenants.sync', $tenant) }}" class="d-inline" onsubmit="return confirm('Sync {{ $tenant->tenant_code }}? This applies migrations + permissions and does NOT delete data.');">@csrf
                                <button class="btn btn-sm btn-outline-success" title="Sync (migrate + permissions)"><i class="ti ti-refresh"></i></button>
                            </form>
                        @endcan
                        @can('central.tenants.reset')
                            @if($resettable)
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Reset demo tenant"
                                        data-bs-toggle="modal" data-bs-target="#tenantResetModal"
                                        data-action="{{ route('central.tenants.reset', $tenant) }}" data-code="{{ $tenant->tenant_code }}">
                                    <i class="ti ti-rotate-2"></i>
                                </button>
                            @endif
                        @endcan
                        @can('central.tenants.edit')
                            <a href="{{ url('/tenants/' . $tenant->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No tenants found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <div class="mt-3">{{ $tenants->links() }}</div>
    </div>
</div>

{{-- Global ops confirmation modal (backup-all / sync-all / reset-demos) --}}
<div class="modal fade" id="opsGlobalModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="opsGlobalForm">@csrf
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="opsGlobalTitle">Confirm</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="small text-muted" id="opsGlobalDesc"></p>
                    <label class="form-label">Type <code id="opsGlobalWord"></code> to confirm</label>
                    <input type="text" name="confirm" class="form-control ops-confirm-input" autocomplete="off" data-target="opsGlobalSubmit">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="opsGlobalSubmit" disabled>Run</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Per-tenant reset modal --}}
<div class="modal fade" id="tenantResetModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="tenantResetForm">@csrf
            <div class="modal-content border-danger">
                <div class="modal-header"><h5 class="modal-title text-danger"><i class="ti ti-alert-triangle me-1"></i>Reset Tenant</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="alert alert-danger small">This deletes and reseeds demo data for <strong id="resetCodeLabel"></strong>. A backup is created first.</div>
                    <label class="form-label">Type <code>RESET</code> to confirm</label>
                    <input type="text" name="confirm" class="form-control mb-2 ops-confirm-input" autocomplete="off" data-target="tenantResetSubmit" data-expect="RESET">
                    <label class="form-label">Type the tenant code (<code id="resetCodeHint"></code>)</label>
                    <input type="text" name="tenant_code" class="form-control mb-2" autocomplete="off">
                    <div class="form-check">
                        <input type="checkbox" name="understand" value="1" class="form-check-input" id="resetUnderstand">
                        <label for="resetUnderstand" class="form-check-label small">I understand this will delete tenant data and reseed demo data.</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="tenantResetSubmit" disabled>Reset Tenant</button>
                </div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var globalWord = '';
    // Global ops modal: populate action + required word from the trigger button.
    document.getElementById('opsGlobalModal').addEventListener('show.bs.modal', function (e) {
        var b = e.relatedTarget;
        document.getElementById('opsGlobalForm').setAttribute('action', b.getAttribute('data-action'));
        document.getElementById('opsGlobalTitle').textContent = b.getAttribute('data-title');
        document.getElementById('opsGlobalDesc').textContent = b.getAttribute('data-desc');
        globalWord = b.getAttribute('data-confirm');
        document.getElementById('opsGlobalWord').textContent = globalWord;
        var inp = document.querySelector('#opsGlobalForm .ops-confirm-input');
        inp.value = ''; inp.setAttribute('data-expect', globalWord);
        document.getElementById('opsGlobalSubmit').disabled = true;
    });
    // Reset modal: populate action + expected code.
    document.getElementById('tenantResetModal').addEventListener('show.bs.modal', function (e) {
        var b = e.relatedTarget;
        document.getElementById('tenantResetForm').setAttribute('action', b.getAttribute('data-action'));
        var code = b.getAttribute('data-code');
        document.getElementById('resetCodeLabel').textContent = code;
        document.getElementById('resetCodeHint').textContent = code;
        document.getElementById('tenantResetForm').reset();
        document.getElementById('tenantResetSubmit').disabled = true;
    });
    // Enable submit only when the confirm word matches exactly.
    document.addEventListener('input', function (e) {
        if (!e.target.classList.contains('ops-confirm-input')) return;
        var btn = document.getElementById(e.target.getAttribute('data-target'));
        if (btn) btn.disabled = (e.target.value.trim() !== (e.target.getAttribute('data-expect') || ''));
    });
})();
</script>
@endpush
@endsection
