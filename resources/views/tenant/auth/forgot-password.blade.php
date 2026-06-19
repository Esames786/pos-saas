@extends('layouts.auth')

@section('title', 'Forgot Password')

@section('content')
<div class="main-wrapper">
    <div class="account-content">
        <div class="login-wrapper login-new">
            <div class="row w-100">
                <div class="col-lg-5 mx-auto">
                    <div class="login-content user-login">
                        <div class="login-logo">
                            <img src="{{ asset('images/bingoo_new/bingoo-login-main-logo.webp') }}" alt="Bingoo" style="max-width:220px;height:auto;">
                        </div>

                        <form method="POST" action="{{ url('/forgot-password') }}">
                            @csrf

                            <div class="card">
                                <div class="card-body p-5">
                                    <div class="login-userheading">
                                        <h3>Forgot password?</h3>
                                        <h4>Enter your account email and we'll send you a reset link.</h4>
                                    </div>

                                    @if (session('status'))
                                        <div class="alert alert-success">{{ session('status') }}</div>
                                    @endif
                                    @if ($errors->any())
                                        <div class="alert alert-danger">{{ $errors->first() }}</div>
                                    @endif

                                    <div class="mb-3">
                                        <label class="form-label">Email <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="email" name="email" value="{{ old('email') }}" class="form-control border-end-0" required autofocus>
                                            <span class="input-group-text border-start-0"><i class="ti ti-mail"></i></span>
                                        </div>
                                    </div>

                                    <div class="form-login mb-3">
                                        <button type="submit" class="btn btn-primary w-100">Send reset link</button>
                                    </div>

                                    <div class="text-center">
                                        <a href="{{ url('/login') }}" class="fs-14 fw-medium">Back to login</a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
