@extends('layouts.auth')

@section('title', __('auth.login_title'))

@section('content')
<div class="main-wrapper">
    <div class="account-content">
        <div class="login-wrapper login-new">
            <div class="row w-100">
                <div class="col-lg-5 mx-auto">
                    <div class="login-content user-login">
                        <div class="login-logo">
                            <img src="{{ asset('images/bingoo_new/bingoo-login-main-logo.webp') }}" alt="Bingoo" style="max-width:220px;height:auto;">
                            <a href="javascript:void(0);" class="login-logo logo-white">
                                <img src="{{ asset('images/bingoo_new/bingoo-login-main-logo.webp') }}" alt="Bingoo" style="max-width:220px;height:auto;">
                            </a>
                        </div>

                        <form method="POST" action="{{ url('/login') }}">
                            @csrf

                            <div class="card">
                                <div class="card-body p-5">
                                    <div class="login-userheading">
                                        <h3>{{ __('auth.login_title') }}</h3>
                                        <h4>{{ __('auth.login_subtitle') }}</h4>
                                    </div>

                                    @if ($errors->any())
                                        <div class="alert alert-danger">
                                            {{ $errors->first() }}
                                        </div>
                                    @endif

                                    @if (session('status'))
                                        <div class="alert alert-success">
                                            {{ session('status') }}
                                        </div>
                                    @endif

                                    <div class="mb-3">
                                        <label class="form-label">
                                            {{ __('auth.email') }} <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <input type="email" name="email" value="{{ old('email') }}" class="form-control border-end-0" required autofocus>
                                            <span class="input-group-text border-start-0">
                                                <i class="ti ti-mail"></i>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">
                                            {{ __('auth.password_label') }} <span class="text-danger">*</span>
                                        </label>
                                        <div class="pass-group">
                                            <input type="password" name="password" class="pass-input form-control" required>
                                            <span class="ti toggle-password ti-eye-off text-gray-9"></span>
                                        </div>
                                    </div>

                                    <div class="form-login authentication-check">
                                        <div class="row">
                                            <div class="col-12 d-flex align-items-center justify-content-between">
                                                <div class="custom-control custom-checkbox">
                                                    <label class="checkboxs ps-4 mb-0 pb-0 line-height-1 fs-16 text-gray-6">
                                                        <input type="checkbox" name="remember" value="1">
                                                        <span class="checkmarks"></span>{{ __('auth.remember_me') }}
                                                    </label>
                                                </div>

                                                <div class="text-end">
                                                    <a class="text-orange fs-16 fw-medium" href="javascript:void(0);">
                                                        {{ __('auth.forgot_password') }}
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-login">
                                        <button type="submit" class="btn btn-primary w-100">
                                            {{ __('auth.sign_in') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="my-4 d-flex justify-content-center align-items-center copyright-text">
                        <p>Copyright &copy; {{ date('Y') }} {{ config('app.name') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
