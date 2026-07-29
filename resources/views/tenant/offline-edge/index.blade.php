@extends('layouts.app')

@section('title', 'Offline Branch Edge')

@section('content')
<div class="content">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-1">Offline Branch Edge</h4>
            <p class="text-muted mb-0">Sellable licensed module &mdash; keep selling from a branch-local server during internet outages.</p>
        </div>
        <span class="badge bg-info-subtle text-info align-self-start">Add-on module</span>
    </div>

    {{-- Status strip: the three independent gates --}}
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Entitlement</div>
                    <div class="fw-semibold text-success"><i class="ti ti-circle-check me-1"></i>Active for this account</div>
                    <div class="small text-muted mt-1">Your plan includes the <code>offline_edge</code> module.</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Rollout</div>
                    <div class="fw-semibold text-success"><i class="ti ti-rocket me-1"></i>Enabled (controlled release)</div>
                    <div class="small text-muted mt-1">Feature flag is on for your environment.</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Installer</div>
                    @if($installerAvailable)
                        <div class="fw-semibold text-success"><i class="ti ti-download me-1"></i>Available{{ $installerVersion ? ' — v'.$installerVersion : '' }}</div>
                    @else
                        <div class="fw-semibold text-secondary"><i class="ti ti-clock me-1"></i>Not available yet</div>
                        <div class="small text-muted mt-1">Bingoo Edge installer is not available in this release yet.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">How Offline Branch Edge works</h5></div>
                <div class="card-body">
                    <ul class="mb-3">
                        <li><strong>One Branch Server per branch.</strong> A single Windows PC on the branch LAN runs Bingoo Edge; every till is just a browser on its LAN URL.</li>
                        <li><strong>The cloud stays the official accounting authority.</strong> Stock, COGS and journals are always posted in the cloud — Edge captures sales locally and syncs them up idempotently.</li>
                        <li><strong>Your existing Print Agent is reused</strong> for local receipt/KOT printing — just pointed at the local Edge server.</li>
                        <li><strong>Each licensed Edge install binds to one branch</strong> during pairing (coming in a later release).</li>
                    </ul>

                    <div class="border rounded p-3 bg-light-subtle">
                        <div class="fw-semibold mb-2">Download the Branch Server installer</div>
                        @if($installerAvailable)
                            <a href="{{ url('/settings/offline-edge/download') }}" class="btn btn-primary">
                                <i class="ti ti-download me-1"></i> Download BingooEdgeSetup.exe{{ $installerVersion ? ' (v'.$installerVersion.')' : '' }}
                            </a>
                        @else
                            <button type="button" class="btn btn-secondary" disabled>
                                <i class="ti ti-download me-1"></i> Download BingooEdgeSetup.exe
                            </button>
                            <div class="small text-muted mt-2">
                                Bingoo Edge installer is not available in this release yet. This page is ready; the one-click
                                Setup.exe is delivered in an upcoming update.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Eligible branches</h5></div>
                <div class="card-body">
                    <p class="small text-muted">Read-only. Pairing a licensed Edge install to a specific branch is not enabled in this release.</p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Branch</th><th>Sales mode</th></tr></thead>
                            <tbody>
                            @forelse($branches as $branch)
                                <tr>
                                    <td>{{ $branch->name }}</td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-secondary">
                                            {{ ucfirst(str_replace('_', ' ', $branch->sales_operating_mode ?? 'cloud')) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-muted">No active branches.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
