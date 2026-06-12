@extends('layouts.app')

@section('title', 'Upgrade Plan')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Upgrade Plan</h1>
        <p class="text-muted mb-0">Request a move to a higher plan. Our team will review and issue an invoice.</p>
    </div>
    <div>
        <a href="{{ url('/billing') }}" class="btn btn-outline-secondary">Back to Billing</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <small class="text-muted d-block">Current Plan</small>
        <span class="fw-semibold fs-5">{{ $currentPlan?->name ?? 'No plan' }}</span>
        @if($currentPlan)
            <span class="text-muted">— {{ $currentPlan->currency_code }} {{ number_format((float) $currentPlan->price, 2) }}</span>
        @endif
    </div>
</div>

@if($openRequest)
    <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span>You have an upgrade request in progress
            (status: <strong>{{ ucfirst(str_replace('_',' ', $openRequest->status)) }}</strong>).</span>
        <a href="{{ url('/billing/upgrade/' . $openRequest->id) }}" class="btn btn-sm btn-primary">View Request</a>
    </div>
@elseif($plans->isEmpty())
    <div class="alert alert-secondary">No higher plans are available to upgrade to right now.</div>
@else
    <form method="POST" action="{{ url('/billing/upgrade') }}">
        @csrf
        <div class="card mb-3">
            <div class="card-body">
                <label class="form-label fw-semibold">Choose a plan to upgrade to</label>
                <div class="row g-3">
                    @foreach($plans as $plan)
                        <div class="col-md-4">
                            <label class="card h-100 p-3 d-block" style="cursor:pointer;">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="requested_plan_id"
                                           value="{{ $plan->id }}" {{ old('requested_plan_id') == $plan->id ? 'checked' : '' }} required>
                                    <span class="form-check-label fw-semibold">{{ $plan->name }}</span>
                                </div>
                                <div class="mt-2 fs-5">{{ $plan->currency_code }} {{ number_format((float) $plan->price, 2) }}</div>
                                <small class="text-muted">per {{ $plan->billing_period }}</small>
                            </label>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3">
                    <label class="form-label" for="customer_notes">Notes (optional)</label>
                    <textarea class="form-control" id="customer_notes" name="customer_notes" rows="3"
                              placeholder="Anything we should know about this upgrade?">{{ old('customer_notes') }}</textarea>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-success">Submit Upgrade Request</button>
            </div>
        </div>
    </form>
@endif
@endsection
