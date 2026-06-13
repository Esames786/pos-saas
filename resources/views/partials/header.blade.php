@php
    $isTenant = app()->bound('tenant');
    $guard = $isTenant ? 'tenant' : 'central';
    $user = auth($guard)->user();
@endphp

<div class="header">
    <div class="main-header">
        <div class="header-left active">
            <a href="{{ url('/dashboard') }}" class="logo logo-normal">
                <img src="{{ asset('assets/img/logo.svg') }}" alt="Logo">
            </a>
            <a href="{{ url('/dashboard') }}" class="logo logo-white">
                <img src="{{ asset('assets/img/logo-white.svg') }}" alt="Logo">
            </a>
            <a href="{{ url('/dashboard') }}" class="logo-small">
                <img src="{{ asset('assets/img/logo-small.svg') }}" alt="Habibi POS">
            </a>
        </div>

        <a id="mobile_btn" class="mobile_btn" href="#sidebar">
            <span class="bar-icon">
                <span></span>
                <span></span>
                <span></span>
            </span>
        </a>

        <ul class="nav user-menu">
            @if($isTenant)
                <li class="nav-item pos-nav">
                    @can('tenant.dashboard')
                        <a href="{{ url('/dashboard') }}" class="btn btn-dark btn-md d-inline-flex align-items-center">
                            <i class="ti ti-device-laptop me-1"></i>POS
                        </a>
                    @endcan
                </li>
            @endif

            <li class="nav-item dropdown has-arrow flag-nav nav-item-box">
                <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="javascript:void(0);" role="button">
                    <img src="{{ asset('assets/img/flags/us-flag.svg') }}" alt="Language" class="img-fluid">
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="{{ url('/locale/en') }}" class="dropdown-item">
                        <img src="{{ asset('assets/img/flags/english.svg') }}" alt="English" height="16">
                        {{ __('common.english') }}
                    </a>
                </div>
            </li>

            <li class="nav-item nav-item-box">
                <a href="javascript:void(0);" id="btnFullscreen">
                    <i class="ti ti-maximize"></i>
                </a>
            </li>

            <li class="nav-item dropdown has-arrow main-drop profile-nav">
                <a href="javascript:void(0);" class="nav-link userset" data-bs-toggle="dropdown">
                    <span class="user-info p-0">
                        <span class="user-letter">
                            <img src="{{ asset('assets/img/profiles/avator1.jpg') }}" alt="User" class="img-fluid">
                        </span>
                    </span>
                </a>

                <div class="dropdown-menu menu-drop-user">
                    <div class="profileset d-flex align-items-center">
                        <span class="user-img me-2">
                            <img src="{{ asset('assets/img/profiles/avator1.jpg') }}" alt="User">
                        </span>
                        <div>
                            <h6 class="fw-medium">{{ $user?->name }}</h6>
                            <p>{{ $isTenant ? 'Tenant User' : 'Central Admin' }}</p>
                        </div>
                    </div>

                    <a class="dropdown-item" href="{{ url('/password/change') }}">
                        <i class="ti ti-lock me-2"></i>{{ __('common.change_password') }}
                    </a>

                    <hr class="my-2">

                    <form method="POST" action="{{ url('/logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item logout border-0 bg-transparent">
                            <i class="ti ti-logout me-2"></i>{{ __('common.logout') }}
                        </button>
                    </form>
                </div>
            </li>
        </ul>
    </div>
</div>
