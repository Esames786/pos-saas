@extends('layouts.app')

@section('title', 'Offline Branch Edge')

@section('content')
<div class="content">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-1">Offline Branch Edge{{ !empty($securityOnly) ? ' — Security' : '' }}</h4>
            <p class="text-muted mb-0">
                @if(!empty($securityOnly))
                    Manage or revoke paired devices and cancel pairing codes. Setup/download are unavailable because Offline Edge is not currently enabled for this account.
                @else
                    Pair one branch-local server per branch. Keep selling during internet outages.
                @endif
            </p>
        </div>
        <span class="badge bg-info-subtle text-info align-self-start">@if(!empty($securityOnly))Security mode @else Add-on module @endif</span>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @foreach($errors->all() as $err)
        <div class="alert alert-danger">{{ $err }}</div>
    @endforeach

    {{-- One-time pairing code display (flashed once; never re-rendered on refresh) --}}
    @if(!empty($newCode))
        <div class="alert alert-primary d-flex align-items-center justify-content-between">
            <div>
                <div class="fw-semibold mb-1">Pairing code (shown once)</div>
                <div class="display-6 font-monospace letter-spacing-2">{{ $newCode }}</div>
                <div class="small text-muted">Enter this in the Bingoo Edge installer on the branch PC. Expires {{ \Illuminate\Support\Carbon::parse($newCodeExpires)->diffForHumans() }}. It will not be shown again.</div>
            </div>
            <i class="ti ti-shield-lock fs-1 text-primary"></i>
        </div>
    @endif

    {{-- EDGE 0.9 (K2): the one-time setup code for the Branch Server setup wizard (rendered only in the POST response) --}}
    @if(!empty($setupCode))
        <div class="alert alert-primary edge-setup-code" data-branch="{{ $setupCode['branch_id'] }}">
            <div class="d-flex align-items-start justify-content-between gap-3">
                <div>
                    <div class="fw-semibold mb-1">Branch Server setup code for {{ $setupCode['branch'] }} (shown once)</div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="display-6 font-monospace" id="edge-setup-code">{{ $setupCode['display'] }}</span>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-code="{{ $setupCode['display'] }}"
                                onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.code).then(() => { this.textContent = 'Copied'; });">
                            <i class="ti ti-copy me-1"></i>Copy
                        </button>
                    </div>
                    <ol class="small mb-1 mt-2 ps-3">
                        <li>On the shop PC, download <strong>Branch Server Setup</strong> from this page and run <span class="font-monospace">Setup-BingooEdge</span> as administrator.</li>
                        <li>When the wizard asks, enter this setup code. Cloud address: <span class="font-monospace">{{ $setupCode['cloud_url'] }}</span></li>
                        <li>The wizard pairs the Branch Server, then lists your staff so you can give each person a PIN.</li>
                    </ol>
                    <div class="small text-muted">
                        @if($setupCode['mode'] === 'update')
                            This branch already has a Branch Server: the code lets the wizard update it in place and enroll users. No re-pairing.
                        @else
                            Expires {{ \Illuminate\Support\Carbon::parse($setupCode['expires_at'])->diffForHumans() }}. Works once. It will not be shown again.
                        @endif
                    </div>
                </div>
                <i class="ti ti-server-2 fs-1 text-primary"></i>
            </div>
        </div>
    @endif

    <div class="alert alert-warning py-2 small mb-3">
        <i class="ti ti-info-circle me-1"></i>
        Pairing does <strong>not</strong> activate Local POS. Cloud sales stay available until bootstrap &amp; readiness are completed in a later step.
    </div>

    @unless(!empty($securityOnly))
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-4">
            <div><div class="text-muted small text-uppercase">Licensed devices</div><div class="fw-semibold">{{ $activeDevices }} / {{ $deviceLimit }} active</div></div>
            <div><div class="text-muted small text-uppercase">Branch Server Setup</div>
                <div class="fw-semibold">{{ $installerAvailable ? ('Available' . ($installerVersion ? ' v'.$installerVersion : '')) : 'Not available yet' }}</div>
                @if(!empty($latestRelease))
                    {{-- EDGE 0.9 (stream E contract): the SHA-256 of the exact zip the button serves, so the owner can check the download. --}}
                    <div class="small text-muted edge-release-sha256">SHA-256 <span class="font-monospace text-break">{{ $latestRelease->zip_sha256 }}</span> · {{ number_format($latestRelease->size_bytes / 1048576, 1) }} MB</div>
                @endif
            </div>
            <div class="ms-auto align-self-center">
                @if($installerAvailable)
                    <a href="{{ url('/settings/offline-edge/download') }}" class="btn btn-primary btn-sm"><i class="ti ti-download me-1"></i>Download Branch Server Setup</a>
                @else
                    <button class="btn btn-secondary btn-sm" disabled><i class="ti ti-download me-1"></i>Installer not available yet</button>
                @endif
                @can('tenant.offline-edge.counter-install')
                    <a href="{{ url('/pos/offline-counter') }}" class="btn btn-outline-secondary btn-sm ms-1"><i class="ti ti-device-desktop-down me-1"></i>Install Offline Counter</a>
                @endcan
            </div>
        </div>
    </div>
    @endunless

    <div class="card">
        <div class="card-header"><h5 class="mb-0">Branches</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Branch</th><th>Lifecycle</th><th>Paired device</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    @forelse($branchRows as $row)
                        <tr>
                            <td class="fw-semibold">{{ $row['name'] }}</td>
                            <td><span class="badge bg-secondary-subtle text-secondary">{{ ucfirst($row['lifecycle'] ?? 'inactive') }}</span></td>
                            <td>
                                @if($row['device'])
                                    <div class="small">
                                        <div class="fw-semibold">{{ $row['device']->device_name ?: 'Edge device' }}</div>
                                        <div class="text-muted">{{ $row['device']->status }} · paired {{ optional($row['device']->paired_at)->diffForHumans() }}</div>
                                        {{-- W-F version reporting: the build the appliance reports on its heartbeat (read-only facts). --}}
                                        @php $build = $row['build'] ?? null; @endphp
                                        @if($build)
                                            <div class="text-muted mt-1 edge-device-build" data-device="{{ $row['device']->public_uuid }}">
                                                <div>App version <span class="fw-semibold font-monospace">{{ $build['app_version'] ?? '—' }}</span>
                                                    · Commit <span class="font-monospace">{{ $build['git_commit'] ?? '—' }}</span></div>
                                                <div>Bootstrap schema <span class="font-monospace">{{ $build['bootstrap_schema'] ?? '—' }}</span>
                                                    · Applied edge schema <span class="font-monospace">{{ $build['applied_edge_schema'] ?? '—' }}</span></div>
                                                <div>Capabilities: {{ !empty($build['capabilities']) ? implode(', ', $build['capabilities']) : '—' }}</div>
                                                <div>Reported {{ $build['reported_at'] ? $build['reported_at']->diffForHumans() . ' (' . $build['reported_at']->format('d M Y, H:i') . ')' : '—' }}</div>
                                            </div>
                                        @else
                                            <div class="text-muted mt-1 edge-device-build" data-device="{{ $row['device']->public_uuid }}">Version not reported yet</div>
                                        @endif
                                        {{-- EDGE 0.9 (K4/K5/K6): install/update status, version, LAN profile, update offer, maintenance window. --}}
                                        @php
                                            $st = $row['status'] ?? [];
                                            $win = $row['window'] ?? null;
                                            $phaseLabels = ['installing' => 'Installing…', 'paired' => 'Paired', 'bootstrapped' => 'Bootstrapped', 'enrolled' => 'Users enrolled',
                                                'ready' => 'Ready', 'updating' => 'Updating…', 'updated' => 'Updated', 'update_failed' => 'Update failed', 'rolled_back' => 'Rolled back'];
                                            $phaseClass = ($st['ok'] ?? null) === false ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success';
                                        @endphp
                                        <div class="mt-2 edge-device-status" data-device="{{ $row['device']->public_uuid }}">
                                            <div>Setup status:
                                                @if(!empty($st['phase']))
                                                    <span class="badge {{ $phaseClass }}">{{ $phaseLabels[$st['phase']] ?? $st['phase'] }}</span>
                                                    @if(!empty($st['message'])) <span class="text-muted">· {{ $st['message'] }}</span> @endif
                                                    @if(!empty($st['reported_at'])) <span class="text-muted">· {{ $st['reported_at']->diffForHumans() }}</span> @endif
                                                @else
                                                    <span class="text-muted">not reported yet</span>
                                                @endif
                                            </div>
                                            <div>Running version <span class="font-monospace edge-device-version">{{ $st['version'] ?? '—' }}</span>
                                                · last contact {{ !empty($st['last_seen']) ? $st['last_seen']->diffForHumans() : '—' }}</div>
                                            @if(!empty($st['update_available']))
                                                <div class="text-warning edge-update-available">Update available: <span class="font-monospace">{{ $st['update_available'] }}</span></div>
                                            @endif
                                            @if(!empty($st['update_requested']))
                                                <div class="text-info edge-update-requested">Update requested {{ $st['update_requested']->diffForHumans() }} — the Branch Server installs it on its next check while on standby.</div>
                                            @endif
                                            <div class="edge-device-lan">
                                                @if($row['lan_ready'])
                                                    LAN <span class="font-monospace">{{ $st['lan_hostname'] }}</span> · <span class="font-monospace">{{ $st['lan_ip'] }}:{{ $st['lan_port'] }}</span>
                                                    · CA <span class="font-monospace" title="{{ $st['lan_ca_sha256'] }}">{{ substr((string) $st['lan_ca_sha256'], 0, 16) }}…</span>
                                                @else
                                                    <span class="text-muted">LAN address and certificate not reported yet</span>
                                                @endif
                                            </div>
                                            @php $at = $row['auto_takeover']; @endphp
                                            <div class="edge-auto-takeover" data-enabled="{{ $at['enabled'] ? '1' : '0' }}">Automatic Offline Takeover:
                                                @if($at['enabled'])
                                                    <span class="badge bg-warning-subtle text-warning">ON after {{ $at['after_seconds'] }} s</span>
                                                @else
                                                    <span class="badge bg-secondary-subtle text-secondary">OFF</span>
                                                @endif
                                            </div>
                                            @if($win)
                                                <div class="edge-maintenance-window">Auto-update window {{ $win['start'] }}–{{ $win['end'] }} ({{ $win['tz'] }}) @if($win['default'])<span class="text-muted">default</span>@endif</div>
                                            @endif
                                        </div>
                                    </div>
                                @elseif($row['has_live_code'])
                                    <span class="text-info small">Pairing code active — expires {{ optional($row['code_expires'])->diffForHumans() }}</span>
                                @elseif($row['setup_live'])
                                    <span class="text-info small edge-setup-live">Setup code active — expires {{ optional($row['setup_expires'])->diffForHumans() }}</span>
                                @else
                                    <span class="text-muted small">Not paired</span>
                                @endif
                                @if($row['device'] && $row['setup_live'])
                                    <div class="text-info small edge-setup-live">Setup code active — expires {{ optional($row['setup_expires'])->diffForHumans() }}</div>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="d-flex flex-wrap justify-content-end gap-1">
                                @if($row['device'])
                                    @if(empty($securityOnly))
                                        <form method="POST" action="{{ url('/settings/offline-edge/devices/' . $row['device']->id . '/update-now') }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-outline-primary btn-sm" @if(empty($st['update_available'])) title="No newer release is published yet" @endif><i class="ti ti-refresh me-1"></i>Update now</button>
                                        </form>
                                        @if($row['lan_ready'] && !empty($rendererAvailable))
                                            <a class="btn btn-outline-secondary btn-sm" href="{{ url('/settings/offline-edge/branches/' . $row['id'] . '/counter-setup') }}"><i class="ti ti-device-desktop me-1"></i>Counter setup</a>
                                        @else
                                            <button class="btn btn-outline-secondary btn-sm" disabled title="{{ $row['lan_ready'] ? 'Counter setup is not available on this Cloud yet' : 'Available after the Branch Server reports its LAN address and certificate' }}"><i class="ti ti-device-desktop me-1"></i>Counter setup</button>
                                        @endif
                                        @if($row['lan_ready'])
                                            <a class="btn btn-outline-secondary btn-sm" href="{{ url('/settings/offline-edge/branches/' . $row['id'] . '/phone') }}"><i class="ti ti-device-mobile me-1"></i>Phones</a>
                                            <a class="btn btn-outline-secondary btn-sm" href="{{ url('/settings/offline-edge/branches/' . $row['id'] . '/ca.crt') }}"><i class="ti ti-certificate me-1"></i>CA certificate</a>
                                        @else
                                            <button class="btn btn-outline-secondary btn-sm" disabled title="Available after the Branch Server reports its LAN address and certificate"><i class="ti ti-device-mobile me-1"></i>Phones</button>
                                        @endif
                                        @if(!empty($canSecurity) && !$row['setup_live'])
                                            <form method="POST" action="{{ url('/settings/offline-edge/branches/' . $row['id'] . '/setup-code') }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-outline-primary btn-sm"><i class="ti ti-server-2 me-1"></i>Run setup again</button>
                                            </form>
                                        @endif
                                    @endif
                                    @if($row['setup_live'])
                                        <form method="POST" action="{{ url('/settings/offline-edge/branches/' . $row['id'] . '/setup-code/cancel') }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-outline-secondary btn-sm">Cancel setup code</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ url('/settings/offline-edge/devices/' . $row['device']->id . '/revoke') }}" class="d-inline" onsubmit="return confirm('Revoke this Edge device? It will stop authenticating immediately.');">
                                        @csrf
                                        <button class="btn btn-outline-danger btn-sm"><i class="ti ti-plug-off me-1"></i>Revoke device</button>
                                    </form>
                                @else
                                    @if($row['has_live_code'])
                                        {{-- A live code exists → Cancel first. HARDEN-2: no silent replace, so no "New code" button. --}}
                                        <form method="POST" action="{{ url('/settings/offline-edge/branches/' . $row['id'] . '/pairing-code/cancel') }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-outline-secondary btn-sm">Cancel code</button>
                                        </form>
                                    @elseif(empty($securityOnly))
                                        <form method="POST" action="{{ url('/settings/offline-edge/branches/' . $row['id'] . '/pairing-code') }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-outline-secondary btn-sm"><i class="ti ti-key me-1"></i>Generate pairing code</button>
                                        </form>
                                    @endif
                                    @if($row['setup_live'])
                                        <form method="POST" action="{{ url('/settings/offline-edge/branches/' . $row['id'] . '/setup-code/cancel') }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-outline-secondary btn-sm">Cancel setup code</button>
                                        </form>
                                    @elseif(empty($securityOnly) && !empty($canSecurity) && !$row['has_live_code'])
                                        <form method="POST" action="{{ url('/settings/offline-edge/branches/' . $row['id'] . '/setup-code') }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-primary btn-sm"><i class="ti ti-server-2 me-1"></i>Set up a Branch Server</button>
                                        </form>
                                    @endif
                                @endif
                                </div>
                                @if($row['device'] && !empty($canSecurity))
                                    @php $at = $row['auto_takeover']; @endphp
                                    <form method="POST" action="{{ url('/settings/offline-edge/branches/' . $row['id'] . '/auto-takeover') }}" class="mt-2 small text-start border rounded p-2 edge-auto-takeover-form"
                                          onsubmit="return !this.enabled.checked || confirm('Turn ON Automatic Offline Takeover? When the Cloud cannot reach this Branch Server for the chosen time, the Branch Server starts selling by itself and the Online POS is paused. Returning to Online stays manual.');">
                                        @csrf
                                        <input type="hidden" name="enabled" value="0">
                                        <div class="form-check form-switch d-inline-block me-2">
                                            <input class="form-check-input" type="checkbox" name="enabled" value="1" id="auto-takeover-{{ $row['id'] }}" @checked($at['enabled'])>
                                            <label class="form-check-label" for="auto-takeover-{{ $row['id'] }}">Automatic Offline Takeover after</label>
                                        </div>
                                        <input type="number" name="after_seconds" min="180" max="3600" step="30" value="{{ $at['after_seconds'] }}" class="form-control form-control-sm d-inline-block" style="width: 6rem" aria-label="Seconds without the Cloud"> seconds
                                        <button class="btn btn-outline-warning btn-sm ms-1">Save</button>
                                        <div class="text-muted mt-1">Off by default. When on, the Branch Server takes over selling by itself only after the internet has been gone this long AND its own safety checks pass (clock, lease, sync) AND its local switch allows it. The Online POS is paused while it sells. Return to Online is always manual.</div>
                                    </form>
                                @endif
                                @if($row['device'] && empty($securityOnly) && $win)
                                    <form method="POST" action="{{ url('/settings/offline-edge/devices/' . $row['device']->id . '/maintenance-window') }}" class="d-inline-flex align-items-center gap-1 mt-1 small">
                                        @csrf
                                        <span class="text-muted">Update window</span>
                                        <input type="time" name="start" value="{{ $win['default'] ? '' : $win['start'] }}" class="form-control form-control-sm" style="width: 7rem" aria-label="Window start">
                                        <span>–</span>
                                        <input type="time" name="end" value="{{ $win['default'] ? '' : $win['end'] }}" class="form-control form-control-sm" style="width: 7rem" aria-label="Window end">
                                        <button class="btn btn-outline-secondary btn-sm">Save</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">No active branches.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
