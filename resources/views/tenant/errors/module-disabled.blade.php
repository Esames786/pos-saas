@extends('layouts.app')

@section('title', 'Module Not Available')

@section('content')
<div class="card">
    <div class="card-body text-center py-5">
        <h3 class="mb-3">Module Not Available</h3>

        <p class="text-muted mb-2">
            {{ $message ?? 'Your current subscription plan does not include this module.' }}
        </p>

        @if(!empty($moduleKey))
            <p class="small text-muted mb-4">
                Module route key: {{ $moduleKey }}
            </p>
        @endif

        <a href="{{ url('/dashboard') }}" class="btn btn-primary">
            Back to Dashboard
        </a>
    </div>
</div>
@endsection
