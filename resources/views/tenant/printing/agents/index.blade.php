@extends('layouts.app')

@section('title', 'Print Agents')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Print Agents</h1>
        <p class="text-muted mb-0">Local bridge apps installed inside the restaurant network for silent LAN printing.</p>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

@if(session('status'))
    <div class="alert alert-warning">{{ session('status') }}</div>
@endif

@can('tenant.print-agents.store')
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Create Print Agent</h2>
        <form method="POST" action="{{ url('/print/agents') }}" class="row g-3 align-items-end">
            @csrf
            <div class="col-md-3">
                <label class="form-label required">Agent Name</label>
                <input name="name" class="form-control" required placeholder="Counter PC Agent" value="{{ old('name') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label">Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Terminal</label>
                <select name="terminal_id" class="form-select">
                    <option value="">All Terminals</option>
                    @foreach($terminals as $t)
                        <option value="{{ $t->id }}" @selected(old('terminal_id') == $t->id)>{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Device Label</label>
                <input name="device_name" class="form-control" placeholder="Kitchen PC" value="{{ old('device_name') }}">
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Create Agent &amp; Get Token</button>
            </div>
        </form>
        <div class="alert alert-info mt-3 mb-0">
            <i class="ti ti-info-circle me-1"></i>
            The token is shown <strong>only once</strong> after creation or regeneration. Copy it into the local print agent config immediately.
        </div>
    </div>
</div>
@endcan

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Agent</th>
                    <th>Branch / Terminal</th>
                    <th>Device</th>
                    <th>Last Seen</th>
                    <th>Status</th>
                    <th>Last Error</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($agents as $agent)
                <tr>
                    <td>
                        <strong>{{ $agent->name }}</strong>
                        <small class="d-block text-muted">{{ $agent->agent_code }}</small>
                    </td>
                    <td>
                        {{ $agent->branch?->name ?? 'All Branches' }}
                        <small class="d-block text-muted">{{ $agent->terminal?->name ?? 'All Terminals' }}</small>
                    </td>
                    <td>
                        {{ $agent->device_name ?: '—' }}
                        @if($agent->device_os || $agent->local_ip)
                            <small class="d-block text-muted">{{ $agent->device_os }} {{ $agent->local_ip }}</small>
                        @endif
                    </td>
                    <td>{{ $agent->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                    <td>
                        @if(!$agent->is_active)
                            <span class="badge bg-danger">Inactive</span>
                        @elseif($agent->last_seen_at && $agent->last_seen_at->gt(now()->subMinutes(2)))
                            <span class="badge bg-success">Online</span>
                        @elseif($agent->last_seen_at)
                            <span class="badge bg-warning text-dark">Offline</span>
                        @else
                            <span class="badge bg-secondary">Never Connected</span>
                        @endif
                    </td>
                    <td>
                        @if($agent->last_error)
                            <small class="text-danger">{{ Str::limit($agent->last_error, 60) }}</small>
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-end">
                        @can('tenant.print-agents.regenerate-token')
                            <form method="POST"
                                  action="{{ url('/print/agents/' . $agent->id . '/regenerate-token') }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Regenerate token? Old token will stop working immediately.')">
                                @csrf
                                <button class="btn btn-sm btn-warning" type="submit">New Token</button>
                            </form>
                        @endcan
                        @can('tenant.print-agents.deactivate')
                            @if($agent->is_active)
                                <form method="POST"
                                      action="{{ url('/print/agents/' . $agent->id . '/deactivate') }}"
                                      class="d-inline"
                                      onsubmit="return confirm('Deactivate this agent?')">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Deactivate</button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No print agents configured yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $agents->links() }}

<div class="card mt-4">
    <div class="card-header"><strong>Quick Setup Guide</strong></div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Create a print agent above and copy the token.</li>
            <li>Go to <strong>Printing → Printers</strong> and add printers with Type = <em>Network</em>, IP address, and port 9100.</li>
            <li>Go to <strong>Printing → KOT Routing</strong> to map categories to specific printers.</li>
            <li>Download <code>tools/print-agent/print-agent.js</code> and run on the restaurant PC:</li>
        </ol>
        <pre class="bg-light p-3 mt-2 rounded"><code>POS_BASE_URL="{{ request()->getSchemeAndHttpHost() }}" \
POS_PRINT_AGENT_CODE="AG-xxxx" \
POS_PRINT_AGENT_TOKEN="token-from-system" \
node print-agent.js</code></pre>
    </div>
</div>
@endsection
