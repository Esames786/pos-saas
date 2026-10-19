{{-- MASTER-UNIVERSE-1 — Branches: read from the tenant's own database (read-only), plus "Add branch",
     which does exactly what the tenant's own Branches screen does (one row, plan limit checked). --}}
@php
    $limit = $branchList['limit'] ?? null;
    $canAdd = (bool) auth('central')->user()?->can('central.tenants.branches.store');
    $limitReached = $limit && ! ($limit['allowed'] ?? true);
    $tzDefault = old('timezone', $tenant->timezone ?: 'Asia/Karachi');
@endphp
<section class="mu-panel">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h2>Branches</h2>
            <p class="mu-panel-sub mb-0">
                @if($limit)
                    @if($limit['limit'] === null)
                        {{ $limit['used'] }} active branch(es). This plan has no branch limit.
                    @else
                        {{ $limit['used'] }} of {{ $limit['limit'] }} active branches used (plan limit).
                    @endif
                @else
                    Read live from the tenant's database.
                @endif
            </p>
        </div>
        @if($canAdd && $branchList['ok'])
            @if($limitReached)
                <button type="button" class="btn btn-primary" disabled title="{{ $limit['message'] ?? 'Plan limit reached' }}"><i class="ti ti-plus me-1" aria-hidden="true"></i>Add branch</button>
            @else
                <button type="button" class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#add-branch" aria-expanded="{{ $errors->has('branch') || $errors->has('name') ? 'true' : 'false' }}" aria-controls="add-branch">
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>Add branch
                </button>
            @endif
        @endif
    </div>
    @if($limitReached)
        <div class="alert alert-warning mt-3 mb-0 py-2">{{ $limit['message'] ?? 'The plan does not allow another branch.' }} Raise the branch limit on the plan first.</div>
    @endif

    @if($canAdd && $branchList['ok'] && ! $limitReached)
        <div class="collapse {{ $errors->has('branch') || $errors->has('name') ? 'show' : '' }}" id="add-branch">
            <form method="POST" action="{{ route('central.tenants.branches.store', $tenant) }}" class="mu-confirm" onsubmit="return confirm('Add this branch to {{ $tenant->tenant_code }}?');">
                @csrf
                <p class="mu-panel-sub">Adds the branch exactly like the tenant's own <em>Branches</em> screen. Terminals, printers and staff are then added there. Recorded in the history.</p>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label" for="branch-name">Branch name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="branch-name" class="form-control" maxlength="190" required value="{{ old('name') }}" placeholder="e.g. North Branch">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="branch-code">Code</label>
                        <input type="text" name="code" id="branch-code" class="form-control" maxlength="50" value="{{ old('code') }}" placeholder="e.g. NB">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="branch-type">Kind of branch</label>
                        <select name="business_type" id="branch-type" class="form-select">
                            @foreach(['restaurant' => 'Restaurant', 'store' => 'Store / shop', 'hybrid' => 'Both (hybrid)'] as $v => $l)
                                <option value="{{ $v }}" @selected(old('business_type', $branchDefaultType) === $v)>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="branch-tz">Timezone</label>
                        <input type="text" name="timezone" id="branch-tz" class="form-control" value="{{ $tzDefault }}" list="branch-tz-list">
                        <datalist id="branch-tz-list">
                            @foreach(['Asia/Karachi', 'Asia/Dubai', 'Asia/Riyadh', 'Asia/Qatar', 'Europe/London', 'America/New_York'] as $tz)
                                <option value="{{ $tz }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label" for="branch-address">Address</label>
                        <input type="text" name="address" id="branch-address" class="form-control" maxlength="500" value="{{ old('address') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="branch-phone">Phone</label>
                        <input type="text" name="phone" id="branch-phone" class="form-control" maxlength="50" value="{{ old('phone') }}">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary w-100">Add branch</button>
                    </div>
                </div>
            </form>
        </div>
    @endif
</section>

@if(! $branchList['ok'])
    <div class="mu-empty"><i class="ti ti-plug-x" aria-hidden="true"></i>{{ $branchList['error'] ?? 'Could not read the branches.' }}</div>
@elseif($branchList['branches'] === [])
    <div class="mu-empty"><i class="ti ti-git-branch" aria-hidden="true"></i>No branches yet.</div>
@else
    <div class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr><th>Branch</th><th>Code</th><th>Address</th><th>Kind</th><th>Status</th><th class="text-end">Terminals</th><th class="text-end">Staff</th></tr>
                </thead>
                <tbody>
                @foreach($branchList['branches'] as $b)
                    <tr>
                        <td class="fw-semibold">{{ $b['name'] }}</td>
                        <td><code>{{ $b['code'] ?: '—' }}</code></td>
                        <td style="white-space:normal;max-width:320px">{{ $b['address'] ?: '—' }}</td>
                        <td>{{ ['restaurant' => 'Restaurant', 'store' => 'Store', 'hybrid' => 'Hybrid'][$b['business_type']] ?? $b['business_type'] }}</td>
                        <td><span class="mu-pill {{ $b['status'] === 'active' ? 'is-active' : 'is-cancelled' }}">{{ $b['status'] === 'active' ? 'Open' : 'Closed' }}</span></td>
                        <td class="text-end">{{ $b['terminals'] }}</td>
                        <td class="text-end">{{ $b['users'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <p class="mu-note mt-2">Staff = users assigned to the branch. Terminals and staff are managed on the tenant's own screens.</p>
@endif
