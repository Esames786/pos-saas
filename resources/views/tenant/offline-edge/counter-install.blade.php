@extends('layouts.app')

@section('title', 'Install Offline Counter')

@section('content')
<div class="content">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-1">Install Offline Counter</h4>
            <p class="text-muted mb-0">Set up this Windows PC to keep selling through your branch's Branch Server when the internet is down (BINGOO Offline).</p>
        </div>
        <a href="{{ url('/pos') }}" class="btn btn-outline-secondary btn-sm align-self-start"><i class="ti ti-arrow-left me-1"></i>Back to POS</a>
    </div>

    @foreach($errors->all() as $err)
        <div class="alert alert-danger">{{ $err }}</div>
    @endforeach

    @if($problem)
        <div class="alert alert-warning edge-counter-problem">{{ $problem }}</div>
    @elseif($context)
        @php $d = $context['device']; @endphp
        <div class="card mb-3 edge-counter-context">
            <div class="card-body">
                <div class="row g-3 small">
                    <div class="col-md-4"><div class="text-muted text-uppercase">Branch</div><div class="fw-semibold">{{ $context['branch']->name }}</div></div>
                    <div class="col-md-4"><div class="text-muted text-uppercase">Branch Server</div><div class="font-monospace">{{ $d->lan_hostname }} · {{ $d->lan_ip }}:{{ $d->lan_https_port }}</div></div>
                    <div class="col-md-4"><div class="text-muted text-uppercase">Certificate (SHA-256)</div><div class="font-monospace text-break">{{ $d->lan_ca_sha256 }}</div></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <ol class="small ps-3">
                    <li>Download the counter setup on THIS counter PC and run it (right-click → Run with PowerShell). It asks before it trusts the branch certificate.</li>
                    <li>It adds the <span class="font-monospace">{{ $d->lan_hostname }}</span> line, the Online / Offline / Status shortcuts, and checks the secure connection.</li>
                    <li>Optional: choose the second button to also set <strong>your own</strong> Edge PIN on this counter (the download works for 15 minutes).</li>
                </ol>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ url('/pos/offline-counter/setup') }}" class="btn btn-primary btn-sm"><i class="ti ti-download me-1"></i>Download counter setup</a>
                    @if($canEnroll)
                        <form method="POST" action="{{ url('/pos/offline-counter/my-enrollment') }}" class="d-inline">
                            @csrf
                            <button class="btn btn-outline-primary btn-sm"><i class="ti ti-key me-1"></i>Download with my Edge PIN setup</button>
                        </form>
                    @else
                        <button class="btn btn-outline-secondary btn-sm" disabled title="Your user has no employee code, so it cannot log in on the Branch Server">Download with my Edge PIN setup</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
