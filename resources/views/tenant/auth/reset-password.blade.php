@extends('layouts.auth')

@section('title', 'Reset Password')

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

                        <form method="POST" action="{{ url('/reset-password') }}">
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">

                            <div class="card">
                                <div class="card-body p-5">
                                    <div class="login-userheading">
                                        <h3>Set a new password</h3>
                                        <h4>Choose a strong password for your account.</h4>
                                    </div>

                                    @if ($errors->any())
                                        <div class="alert alert-danger">{{ $errors->first() }}</div>
                                    @endif

                                    <div class="mb-3">
                                        <label class="form-label">Email <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="email" name="email" value="{{ old('email', $email) }}" class="form-control border-end-0" required>
                                            <span class="input-group-text border-start-0"><i class="ti ti-mail"></i></span>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">New password <span class="text-danger">*</span></label>
                                        <div class="pass-group">
                                            <input type="password" name="password" class="pass-input form-control" required minlength="8">
                                            <span class="ti toggle-password ti-eye-off text-gray-9"></span>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Confirm new password <span class="text-danger">*</span></label>
                                        <div class="pass-group">
                                            <input type="password" name="password_confirmation" class="pass-input form-control" required minlength="8">
                                            <span class="ti toggle-password ti-eye-off text-gray-9"></span>
                                        </div>
                                    </div>

                                    <div class="form-login mb-3">
                                        <button type="submit" class="btn btn-primary w-100">Reset password</button>
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
