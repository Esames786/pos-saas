{{-- MASTER-UNIVERSE-1 — Domains & database: the old page's Domains, Database and Provision cards, unchanged
     in what they do. --}}
<div class="row g-3">
    <div class="col-xl-8">
        {{-- Domains card --}}
        <section class="mu-panel">
            <h2>Domains</h2>
            <p class="mu-panel-sub">The addresses this tenant opens on. The primary one is where staff log in.</p>

            @can('central.tenant-domains.store')
                <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/domains') }}" class="row g-2 mb-3">
                    @csrf
                    <div class="col-md-8">
                        <div class="input-group">
                            <input type="text" name="subdomain" class="form-control" placeholder="branch2" aria-label="Subdomain">
                            <span class="input-group-text">.{{ config('tenancy.tenant_base_domain') }}</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary w-100">Add Domain</button>
                    </div>
                </form>

                {{-- ONLINE-ORDERING-1 (Phase 5): client ka apna domain — sirf ordering website. --}}
                <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/domains') }}" class="row g-2 mb-3">
                    @csrf
                    <input type="hidden" name="kind" value="website">
                    <div class="col-md-8">
                        <input type="text" name="website_domain" class="form-control" placeholder="their-shop.com" value="{{ old('website_domain') }}" aria-label="Website domain">
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-outline-primary w-100">Add Website Domain</button>
                    </div>
                    <div class="col-12 form-text">
                        Client's own domain — opens only the ordering website (no login or POS). Client's DNS: A record for <code>@</code> and <code>www</code> → this server's IP. Nameservers and MX stay as they are (email keeps working).
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
                            <td>
                                {{ $domain->domain }}
                                @if($domain->isWebsite())
                                    <span class="badge bg-info-subtle text-info ms-1">Website</span>
                                @endif
                            </td>
                            <td>
                                @if($domain->is_primary)
                                    <span class="badge bg-success">Primary</span>
                                @else
                                    <span class="badge bg-light text-dark">Secondary</span>
                                @endif
                            </td>
                            <td>{{ ucfirst($domain->status) }}</td>
                            <td class="text-end">
                                @if(!$domain->is_primary && !$domain->isWebsite())
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
        </section>
    </div>

    <div class="col-xl-4">
        {{-- Database status card --}}
        <section class="mu-panel">
            <h2>Database</h2>
            @if($tenant->database)
                <div class="mu-kv mt-2 mb-2">
                    <div><div class="mu-k">Database Name</div><div class="mu-v">{{ $tenant->database->db_database }}</div></div>
                    <div><div class="mu-k">Size</div><div class="mu-v">
                        @if($card['db_bytes'] !== null)
                            {{ \App\Services\Central\SystemUsageService::bytes($card['db_bytes']) }}
                        @else
                            <span class="mu-unknown" title="{{ $card['read_error'] }}">—</span>
                        @endif
                    </div></div>
                </div>
                <div class="mb-2">
                    <div class="mu-k">Migration Status</div>
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
                        <div class="mt-1">Stuck on "Running" for over 2 minutes? Click <strong>Provision &amp; Activate</strong> again — it safely resumes and completes a half-done setup.</div>
                    </div>
                    <script>setTimeout(function () { window.location.reload(); }, 6000);</script>
                @elseif($tenant->database->migration_status === 'failed')
                    <div class="alert alert-danger py-2 px-3 mb-0 small">
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                        Last provisioning attempt <strong>failed</strong>. Click <strong>Provision &amp; Activate</strong> again — it safely retries from where it stopped.
                    </div>
                @endif
            @else
                <p class="text-muted mb-0">No database provisioned yet.</p>
            @endif
        </section>

        {{-- Provision card --}}
        @can('central.tenants.provision')
            @if(!$tenant->database || $tenant->database->migration_status !== 'completed')
                <section class="mu-panel">
                    <h2>Provision Database</h2>
                    <p class="mu-panel-sub">
                        Creates the tenant database, runs all migrations, and seeds the starter data
                        (owner login, main branch, currency, payment methods, chart of accounts).
                        <strong>Takes 1–2 minutes.</strong>
                    </p>

                    <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/provision') }}" class="row g-3" id="provision-form">
                        @csrf

                        <div class="col-12">
                            <label class="form-label" for="owner_password">Owner Password <span class="text-danger">*</span></label>
                            <input type="password" name="owner_password" id="owner_password" class="form-control" minlength="8" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="owner_password_confirmation">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="owner_password_confirmation" id="owner_password_confirmation" class="form-control" required>
                        </div>

                        <div class="col-12">
                            <button class="btn btn-success w-100" id="provision-btn">
                                <span class="spinner-border spinner-border-sm me-1 d-none" id="provision-spinner" role="status" aria-hidden="true"></span>
                                <i class="ti ti-database me-1" id="provision-icon" aria-hidden="true"></i>
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
                </section>
            @endif
        @endcan
    </div>
</div>
