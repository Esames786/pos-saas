@extends('layouts.app')

@section('title', 'Set Manager PIN — ' . $user->name)

@section('content')
<div class="page-wrapper">
    <div class="content">
        <div class="page-header">
            <div class="page-title">
                <h4>Manager PIN — {{ $user->name }}</h4>
                <h6>{{ $hasPin ? 'Update existing PIN' : 'Set a new manager PIN' }}</h6>
            </div>
        </div>

        <div class="card" style="max-width:480px">
            <div class="card-body">
                @if($hasPin)
                    <div class="alert alert-info mb-3">
                        <i class="ti ti-lock-check me-2"></i>This user already has an active manager PIN. Enter a new PIN below to replace it.
                    </div>
                @endif

                @if($errors->any())
                    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                @endif

                <form method="POST" action="{{ url('/users/' . $user->id . '/manager-pin') }}">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label required" for="pin">New PIN <small class="text-muted">(4–8 digits)</small></label>
                        <input type="password" id="pin" name="pin" class="form-control @error('pin') is-invalid @enderror"
                            inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" required>
                        @error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label required" for="pin_confirmation">Confirm PIN</label>
                        <input type="password" id="pin_confirmation" name="pin_confirmation" class="form-control"
                            inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password" required>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-lock me-1"></i>{{ $hasPin ? 'Update PIN' : 'Set PIN' }}
                        </button>
                        <a href="{{ url('/users/' . $user->id) }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
