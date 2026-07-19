@extends('layouts.app')

@section('title', 'Print Agents')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Print Agents</h1>
        <p class="text-muted mb-0">Local bridge apps installed inside the restaurant network for silent LAN printing.</p>
    </div>
    @can('tenant.print-agents.download-windows')
    <a href="{{ url('/print/agents/download/windows') }}" class="btn btn-outline-primary">
        <i class="ti ti-brand-windows me-1"></i>Download Windows Agent
    </a>
    @endcan
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

@if(session('status'))
    <div class="alert alert-warning">{{ session('status') }}</div>
@endif

{{-- PRINT-AGENT-INSTALLER-1: pairing code panel (shown once after create / new code) --}}
@if(session('pairing'))
    @php $pairing = session('pairing'); @endphp
    <div class="card border-primary mb-4">
        <div class="card-body text-center">
            <h2 class="h5 mb-1">Pairing code for <strong>{{ $pairing['agent_name'] }}</strong> ({{ $pairing['agent_code'] }})</h2>
            <p class="text-muted mb-3">Enter this code in the agent app on the shop PC. It expires in 15 minutes and works only once.</p>
            <div class="display-4 fw-bold font-monospace mb-2" id="pairing-code-value">{{ $pairing['code'] }}</div>
            <div class="mb-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText('{{ str_replace('-', '', $pairing['code']) }}'); this.textContent='Copied!';">Copy code</button>
                <span class="text-muted small ms-2">Server URL: <code>{{ $pairing['server_url'] }}</code></span>
            </div>
            <ol class="text-start small text-muted mx-auto" style="max-width: 480px;">
                <li>Download the Windows Agent (button top-right) on the Counter/Kitchen PC.</li>
                <li>Install / run it — it asks for the Server URL and this pairing code.</li>
                <li>The agent connects automatically. Status below turns <span class="badge bg-success">Online</span>.</li>
                <li>Click <em>Test Print</em> to confirm the printer works.</li>
            </ol>
        </div>
    </div>
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
                <button class="btn btn-primary" type="submit">Create Agent &amp; Get Pairing Code</button>
            </div>
        </form>
        <div class="alert alert-info mt-3 mb-0">
            <i class="ti ti-info-circle me-1"></i>
            Creating an agent shows a <strong>6-digit pairing code</strong>. No token copy-paste needed —
            the agent app exchanges the code automatically and the permanent key is never shown in the browser.
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
                        {{ $agent->paired_device_name ?: $agent->device_name ?: '—' }}
                        @if($agent->paired_device_platform || $agent->device_os || $agent->local_ip)
                            <small class="d-block text-muted">{{ $agent->paired_device_platform ?: $agent->device_os }} {{ $agent->local_ip }}</small>
                        @endif
                    </td>
                    <td>{{ $agent->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                    <td>
                        @if(!$agent->is_active)
                            <span class="badge bg-danger">Inactive</span>
                        @elseif($agent->isWaitingToPair())
                            <span class="badge bg-info text-dark">Waiting to Pair</span>
                        @elseif($agent->last_seen_at && $agent->last_seen_at->gt(now()->subMinutes(2)))
                            <span class="badge bg-success">Online</span>
                        @elseif($agent->last_seen_at)
                            <span class="badge bg-warning text-dark">Offline</span>
                        @else
                            <span class="badge bg-secondary">Never Connected</span>
                        @endif
                        @if($agent->paired_at)
                            <small class="d-block text-muted">Paired {{ $agent->paired_at->diffForHumans() }}</small>
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
                        @can('tenant.print-agents.pairing-code')
                            <form method="POST"
                                  action="{{ url('/print/agents/' . $agent->id . '/pairing-code') }}"
                                  class="d-inline"
                                  @if($agent->paired_at) onsubmit="return confirm('Generate a new pairing code? After the new device pairs, the currently paired agent stops working.')" @endif>
                                @csrf
                                <button class="btn btn-sm btn-primary" type="submit">{{ $agent->paired_at ? 'Re-pair' : 'Pairing Code' }}</button>
                            </form>
                        @endcan
                        @can('tenant.print-agents.test-print')
                            <form method="POST"
                                  action="{{ url('/print/agents/' . $agent->id . '/test-print') }}"
                                  class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-success" type="submit">Test Print</button>
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
        <ol class="mb-3">
            <li><strong>Download Windows Agent</strong> (top-right) on the Counter/Kitchen PC.</li>
            <li>Install it — the agent asks for the Server URL and your <strong>pairing code</strong>.</li>
            <li>Add printers under <strong>Printing → Printers</strong> (Type = Network, IP, port 9100) and map categories under <strong>Printing → KOT Routing</strong>.</li>
            <li>Click <strong>Test Print</strong> on the agent row. Done.</li>
        </ol>

        <details>
            <summary class="text-muted">Advanced: manual / developer setup (legacy token mode)</summary>
            <div class="mt-2">
                <p class="small text-muted mb-2">
                    Existing agents installed with the old token flow keep working — nothing to change.
                    For manual runs, use <em>New Token</em> below (shown once) with:
                </p>
                <pre class="bg-light p-3 rounded"><code>POS_BASE_URL="{{ request()->getSchemeAndHttpHost() }}" \
POS_PRINT_AGENT_CODE="AG-xxxx" \
POS_PRINT_AGENT_TOKEN="token-from-system" \
node print-agent.js</code></pre>
                @can('tenant.print-agents.regenerate-token')
                <p class="small text-muted mb-1">Generate a legacy raw token for an agent:</p>
                @foreach($agents as $agent)
                    <form method="POST" action="{{ url('/print/agents/' . $agent->id . '/regenerate-token') }}" class="d-inline"
                          onsubmit="return confirm('Regenerate raw token for {{ addslashes($agent->name) }}? Old token stops working immediately.')">
                        @csrf
                        <button class="btn btn-sm btn-outline-warning mb-1" type="submit">New Token — {{ $agent->name }}</button>
                    </form>
                @endforeach
                @endcan
            </div>
        </details>
    </div>
</div>
@endsection
