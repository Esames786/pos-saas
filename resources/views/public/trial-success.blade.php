@extends('layouts.public')

@section('title', 'Trial Created')

@section('content')
<section class="section-pad">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body p-5">
                        <i class="ti ti-circle-check mb-3" style="font-size:3.5rem;color:#16a34a;"></i>
                        <h1 class="fw-bold mb-2">Trial created successfully</h1>
                        <p class="text-muted mb-4">
                            Welcome aboard{{ session('trial_business_name') ? ', ' . session('trial_business_name') : '' }}!
                            Your workspace is ready.
                        </p>

                        <div class="bg-light rounded p-4 text-start mb-4">
                            @if(session('trial_business_name'))
                                <div class="mb-3">
                                    <small class="text-muted d-block">Business</small>
                                    <span class="fw-semibold">{{ session('trial_business_name') }}</span>
                                </div>
                            @endif
                            <div class="mb-3">
                                <small class="text-muted d-block">Your login URL</small>
                                <code>{{ session('trial_login_url') }}</code>
                            </div>
                            <div>
                                <small class="text-muted d-block">Owner email</small>
                                <span class="fw-semibold">{{ session('trial_owner_email') }}</span>
                            </div>
                        </div>

                        <a href="{{ session('trial_login_url') }}" class="btn btn-primary btn-lg px-4">
                            Go to your login
                        </a>

                        <p class="text-muted small mt-4 mb-0">
                            Reminder: sign in with the password you created during signup.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
