@extends('layouts.public')

@section('title', 'Contact Sales')
@section('meta_description', 'Contact Habibi POS for enterprise rollout, multi-branch deployment, FBR-ready workflows, training, and onboarding.')

@section('content')
<section class="section-pad">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-4">
                    <h1 class="fw-bold mb-1">Contact Sales</h1>
                    <p class="text-muted">
                        For Enterprise, multi-branch, franchise, and custom rollouts, contact our team for setup guidance.
                    </p>
                </div>

                @if(request('plan'))
                    <div class="alert alert-info">
                        Interested plan: <strong>{{ request('plan') }}</strong>
                    </div>
                @endif

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="fw-semibold mb-3">Talk to us</h5>
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2"><i class="ti ti-mail me-2 text-primary"></i>Email: <strong>sales@example.com</strong></li>
                            <li class="mb-2"><i class="ti ti-brand-whatsapp me-2 text-primary"></i>WhatsApp / Phone: <strong>+92 300 0000000</strong></li>
                        </ul>
                        <p class="text-muted small mt-3 mb-0">
                            <i class="ti ti-alert-triangle me-1"></i>
                            Replace these contact details before production.
                        </p>
                    </div>
                </div>

                @if($customPlans->count())
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <h5 class="fw-semibold mb-3">Custom &amp; Enterprise plans</h5>
                            @foreach($customPlans as $plan)
                                <div class="mb-3">
                                    <div class="fw-semibold">{{ $plan->name }}</div>
                                    <div class="text-muted small">{{ $plan->public_description }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="text-center mt-4">
                    <a href="{{ url('/pricing') }}" class="btn btn-outline-secondary">Back to Pricing</a>
                    <a href="{{ url('/start-trial') }}" class="btn btn-primary">Start a Self-Service Trial</a>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
