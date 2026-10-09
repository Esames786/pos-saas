@extends('layouts.public')

@section('title', __('Setting Up Your Trial'))
@section('meta_description', __('Your Bingoo POS cloud trial workspace is being set up.'))

@section('content')
{{-- TRIAL-SIGNUP-QUEUE-1: the workspace is built on the queue. This page shows where it is and
     polls /trial/status; the same news arrives by email, so closing the page loses nothing. --}}
<section class="section-pad">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm text-center" id="trial-status" data-state="preparing"
                     data-status-url="{{ $lurl('/trial/status') }}">
                    <div class="card-body p-5">

                        <div data-show="preparing securing">
                            <div class="spinner-border text-warning mb-3" role="status" style="width:3rem;height:3rem;">
                                <span class="visually-hidden">{{ __('Working') }}</span>
                            </div>
                            <h1 class="fw-bold mb-2" data-show="preparing">{{ __('Setting up your workspace…') }}</h1>
                            <h1 class="fw-bold mb-2" data-show="securing">{{ __('Securing your address…') }}</h1>
                            <p class="text-muted mb-1" data-show="preparing">
                                {{ __('We are creating the workspace for :business. This usually takes a minute or two.', ['business' => $signup['business_name']]) }}
                            </p>
                            <p class="text-muted mb-1" data-show="securing">
                                {{ __('Your workspace is built. We are switching on HTTPS for its address, so your browser trusts it.') }}
                            </p>
                            <p class="text-muted small mb-4">
                                {{ __('We have emailed :email, and we will email your login link the moment it is ready. You can close this page.', ['email' => $signup['owner_email']]) }}
                            </p>
                        </div>

                        <div data-show="ready">
                            <i class="ti ti-circle-check mb-3" style="font-size:3.5rem;color:#16a34a;"></i>
                            <h1 class="fw-bold mb-2">{{ __('Your cloud POS trial is ready.') }}</h1>
                            <p class="text-muted mb-4">{{ __('Welcome aboard, :business! Sign in below to start selling.', ['business' => $signup['business_name']]) }}</p>
                        </div>

                        <div data-show="failed">
                            <i class="ti ti-alert-triangle mb-3" style="font-size:3.5rem;color:#dc2626;"></i>
                            <h1 class="fw-bold mb-2">{{ __('We could not create your workspace.') }}</h1>
                            <p class="text-muted mb-4">
                                {{ __('Nothing was charged and the unfinished workspace was removed, so you can try again with the same details. If it happens again, write to') }}
                                <a href="mailto:{{ config('saas.contact.support_email', 'support@bingoopos.com') }}">{{ config('saas.contact.support_email', 'support@bingoopos.com') }}</a>.
                            </p>
                            <a href="{{ $lurl('/start-trial') }}" class="btn btn-primary btn-lg px-4">{{ __('Try again') }}</a>
                        </div>

                        <div class="bg-light rounded p-4 text-start my-4" data-show="preparing securing ready">
                            <div class="mb-3">
                                <small class="text-muted d-block">{{ __('Business') }}</small>
                                <span class="fw-semibold">{{ $signup['business_name'] }}</span>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">{{ __('Your login URL') }}</small>
                                <code dir="ltr">{{ $signup['login_url'] }}</code>
                            </div>
                            <div>
                                <small class="text-muted d-block">{{ __('Owner email') }}</small>
                                <span class="fw-semibold">{{ $signup['owner_email'] }}</span>
                            </div>
                        </div>

                        <div data-show="ready">
                            <a href="{{ $signup['login_url'] }}" class="btn btn-primary btn-lg px-4" id="trial-login-link">{{ __('Go to your login') }}</a>
                            <p class="text-muted small mt-4 mb-0">{{ __('Reminder: sign in with the password you created during signup.') }}</p>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

@push('styles')
<style>
    /* One state on screen at a time, decided by data-state — right from the first paint. */
    #trial-status [data-show] { display: none; }
    #trial-status[data-state="preparing"] [data-show~="preparing"],
    #trial-status[data-state="securing"] [data-show~="securing"],
    #trial-status[data-state="ready"] [data-show~="ready"],
    #trial-status[data-state="failed"] [data-show~="failed"] { display: block; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    var box = document.getElementById('trial-status');
    if (!box) return;

    function show(state) { box.setAttribute('data-state', state); }

    function poll() {
        fetch(box.getAttribute('data-status-url'), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.state === 'ready' || data.state === 'failed') { show(data.state); return; }
                if (data.state === 'preparing' || data.state === 'securing') show(data.state);
                setTimeout(poll, 3000);
            })
            .catch(function () { setTimeout(poll, 5000); });   // a dropped request is not a failed signup
    }

    show('preparing');
    poll();
})();
</script>
@endpush
