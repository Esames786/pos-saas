@extends('layouts.app')

@section('title', 'Branch Server on phones')

@section('content')
<div class="content">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-1">Branch Server on phones — {{ $branch->name }}</h4>
            <p class="text-muted mb-0">Phones reach the Branch Server by its IP address. Install the branch certificate once, then open the login page.</p>
        </div>
        <a href="{{ url('/settings/offline-edge') }}" class="btn btn-outline-secondary btn-sm align-self-start"><i class="ti ti-arrow-left me-1"></i>Back</a>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card h-100 edge-phone-ca">
                <div class="card-body text-center">
                    <h5 class="mb-2">1 · Install the branch certificate</h5>
                    @if($caQr)
                        <div class="d-inline-block p-2 bg-white rounded">{!! $caQr !!}</div>
                        <p class="small text-muted mt-2 mb-1">This QR link works for {{ $caTtl }} minutes. Reload the page for a new one.</p>
                    @endif
                    <div class="small">Fingerprint (SHA-256): <span class="font-monospace text-break">{{ $onboarding['ca_sha256'] }}</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 edge-phone-login">
                <div class="card-body text-center">
                    <h5 class="mb-2">2 · Open the Branch Server</h5>
                    <div class="d-inline-block p-2 bg-white rounded">{!! $loginQr !!}</div>
                    <p class="mt-2 mb-0"><span class="font-monospace">{{ $onboarding['login_url'] }}</span></p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-body">
            @if($hasPartial)
                @include('tenant.offline-edge.partials.phone-onboarding', ['onboarding' => $onboarding, 'profile' => $profile, 'branch' => $branch])
            @else
                <div class="row">
                    @foreach(($onboarding['steps'] ?? []) as $os => $steps)
                        <div class="col-md-6 edge-phone-steps" data-os="{{ $os }}">
                            <h6 class="text-uppercase">{{ $os === 'ios' ? 'iPhone / iPad' : ucfirst($os) }}</h6>
                            <ol class="small ps-3">
                                @foreach($steps as $step)
                                    <li>{{ $step }}</li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach
                </div>
            @endif
            <p class="small text-muted mb-0">Never accept a certificate warning on the login page — if one appears, the certificate is not installed (or the phone is on another network).</p>
        </div>
    </div>
</div>
@endsection
