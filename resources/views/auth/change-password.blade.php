@extends('layouts.auth')

@section('title', __('auth.change_password'))

@section('content')
<div class="main-wrapper">
    <div class="account-content">
        <div class="login-wrapper login-new">
            <div class="row w-100">
                <div class="col-lg-5 mx-auto">
                    <div class="login-content user-login">
                        <div class="login-logo">
                            <img src="{{ asset('assets/img/logo.svg') }}" alt="Logo">
                        </div>

                        <form method="POST" action="{{ url('/password/change') }}">
                            @csrf

                            <div class="card">
                                <div class="card-body p-5">
                                    <div class="login-userheading">
                                        <h3>{{ __('auth.change_password') }}</h3>
                                        <h4>Update your account password securely.</h4>
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
                                            {{ __('auth.old_password') }} <span class="text-danger">*</span>
                                        </label>
                                        <div class="pass-group">
                                            <input type="password" name="old_password" class="pass-input form-control" required>
                                            <span class="ti toggle-password ti-eye-off text-gray-9"></span>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">
                                            {{ __('auth.new_password') }} <span class="text-danger">*</span>
                                        </label>
                                        <div class="pass-group">
                                            <input type="password" name="password" class="pass-input form-control" required>
                                            <span class="ti toggle-password ti-eye-off text-gray-9"></span>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">
                                            {{ __('auth.confirm_password') }} <span class="text-danger">*</span>
                                        </label>
                                        <div class="pass-group">
                                            <input type="password" name="password_confirmation" class="pass-input form-control" required>
                                            <span class="ti toggle-password ti-eye-off text-gray-9"></span>
                                        </div>
                                    </div>

                                    <div class="form-login">
                                        <button type="submit" class="btn btn-primary w-100">
                                            {{ __('auth.change_password') }}
                                        </button>
                                    </div>

                                    <div class="mt-3 text-center">
                                        <a href="{{ url('/dashboard') }}">
                                            {{ __('common.back_to_dashboard') }}
                                        </a>
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
