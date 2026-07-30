@extends('layouts.app')

@section('title', 'Offline Branch Edge')

@section('content')
<div class="content">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-1">Offline Branch Edge@if(!empty($securityOnly)) — Security@endif</h4>
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

    <div class="alert alert-warning py-2 small mb-3">
        <i class="ti ti-info-circle me-1"></i>
        Pairing does <strong>not</strong> activate Local POS. Cloud sales stay available until bootstrap &amp; readiness are completed in a later step.
    </div>

    @unless(!empty($securityOnly))
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-4">
            <div><div class="text-muted small text-uppercase">Licensed devices</div><div class="fw-semibold">{{ $activeDevices }} / {{ $deviceLimit }} active</div></div>
            <div><div class="text-muted small text-uppercase">Installer</div>
                <div class="fw-semibold">{{ $installerAvailable ? ('Available' . ($installerVersion ? ' v'.$installerVersion : '')) : 'Not available yet' }}</div>
            </div>
            <div class="ms-auto align-self-center">
                @if($installerAvailable)
                    <a href="{{ url('/settings/offline-edge/download') }}" class="btn btn-primary btn-sm"><i class="ti ti-download me-1"></i>Download BingooEdgeSetup.exe</a>
                @else
                    <button class="btn btn-secondary btn-sm" disabled><i class="ti ti-download me-1"></i>Installer not available yet</button>
                @endif
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
                                    </div>
                                @elseif($row['has_live_code'])
                                    <span class="text-info small">Pairing code active — expires {{ optional($row['code_expires'])->diffForHumans() }}</span>
                                @else
                                    <span class="text-muted small">Not paired</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($row['device'])
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
                                            <button class="btn btn-primary btn-sm"><i class="ti ti-key me-1"></i>Generate pairing code</button>
                                        </form>
                                    @endif
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
