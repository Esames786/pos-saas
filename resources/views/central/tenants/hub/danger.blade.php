{{-- MASTER-UNIVERSE-1 — Danger zone: the old Status Actions (activate / suspend / cancel), Sync, the demo
     Reset (with its RESET + code + checkbox guard) and the way to Restore. --}}
<div class="row g-3">
    <div class="col-xl-6">
        <section class="mu-panel is-danger">
            <h2>Status Actions</h2>
            <p class="mu-panel-sub">Suspend stops staff from logging in until you activate again. Cancel is for customers who have left.</p>
            <div class="d-grid gap-2">
                @if($tenant->status !== 'active')
                    @can('central.tenants.activate')
                        <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/activate') }}">
                            @csrf
                            <button class="btn btn-success w-100">
                                <i class="ti ti-check me-1" aria-hidden="true"></i>Activate Tenant
                            </button>
                        </form>
                    @endcan
                @endif

                @if($tenant->status === 'active')
                    @can('central.tenants.suspend')
                        <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/suspend') }}" onsubmit="return confirm('Suspend {{ $tenant->tenant_code }}? Staff cannot log in until it is activated again.')">
                            @csrf
                            <button class="btn btn-warning w-100">
                                <i class="ti ti-ban me-1" aria-hidden="true"></i>Suspend Tenant
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
                                <i class="ti ti-x me-1" aria-hidden="true"></i>Cancel Tenant
                            </button>
                        </form>
                    @endcan
                @endif
            </div>
        </section>
    </div>

    <div class="col-xl-6">
        <section class="mu-panel">
            <h2>Sync</h2>
            <p class="mu-panel-sub">Applies the latest database updates and permissions to this tenant. <strong>No data is deleted.</strong> Use it after a deployment if something looks missing.</p>
            @can('central.tenants.sync')
                <form method="POST" action="{{ route('central.tenants.sync', $tenant) }}" onsubmit="return confirm('Sync {{ $tenant->tenant_code }}? Applies migrations + permissions; no data deleted.');">@csrf
                    <button class="btn btn-outline-success"><i class="ti ti-refresh me-1" aria-hidden="true"></i>Sync now</button>
                </form>
            @endcan
        </section>

        <section class="mu-panel">
            <h2>Restore from a backup</h2>
            <p class="mu-panel-sub">Replaces this tenant's data with a backup. A fresh backup is taken first. Open the backups list, pick one, and type RESTORE plus the tenant code.</p>
            @can('central.tenants.backups')
                <a href="{{ route('central.tenants.backups', $tenant) }}" class="btn btn-outline-warning"><i class="ti ti-restore me-1" aria-hidden="true"></i>Open backups to restore</a>
            @endcan
        </section>

        @if($resettable)
            @can('central.tenants.reset')
                <section class="mu-panel is-danger">
                    <h2>Reset this demo</h2>
                    <p class="mu-panel-sub">Deletes the demo's data and fills it with fresh sample data. A backup is taken first. Only demo tenants can be reset.</p>
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#tenantResetModal">
                        <i class="ti ti-rotate-2 me-1" aria-hidden="true"></i>Reset demo…
                    </button>
                </section>

                <div class="modal fade" id="tenantResetModal" tabindex="-1" aria-labelledby="tenantResetTitle" aria-hidden="true">
                    <div class="modal-dialog">
                        <form method="POST" action="{{ route('central.tenants.reset', $tenant) }}" id="tenantResetForm">@csrf
                            <div class="modal-content border-danger">
                                <div class="modal-header"><h5 class="modal-title text-danger" id="tenantResetTitle"><i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>Reset Tenant</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                <div class="modal-body">
                                    <div class="alert alert-danger small">This deletes and reseeds demo data for <strong>{{ $tenant->tenant_code }}</strong>. A backup is created first.</div>
                                    <label class="form-label" for="reset-confirm">Type <code>RESET</code> to confirm</label>
                                    <input type="text" name="confirm" id="reset-confirm" class="form-control mb-2" autocomplete="off">
                                    <label class="form-label" for="reset-code">Type the tenant code (<code>{{ $tenant->tenant_code }}</code>)</label>
                                    <input type="text" name="tenant_code" id="reset-code" class="form-control mb-2" autocomplete="off">
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
                    var form = document.getElementById('tenantResetForm');
                    if (!form) return;
                    var code = @json($tenant->tenant_code);
                    function check() {
                        document.getElementById('tenantResetSubmit').disabled = !(
                            document.getElementById('reset-confirm').value.trim() === 'RESET'
                            && document.getElementById('reset-code').value.trim() === code
                            && document.getElementById('resetUnderstand').checked);
                    }
                    form.addEventListener('input', check);
                    form.addEventListener('change', check);
                })();
                </script>
                @endpush
            @endcan
        @endif
    </div>
</div>
