@php
    $isTenant = app()->bound('tenant');
    $guard = $isTenant ? 'tenant' : 'central';
    $user = auth($guard)->user();
@endphp

<div class="header">
    <div class="main-header">
        <div class="header-left active">
            <a href="{{ url('/dashboard') }}" class="logo logo-normal">
                <img src="{{ asset('images/bingoo_new/bingoo-navbar-logo.webp') }}" alt="Bingoo">
            </a>
            <a href="{{ url('/dashboard') }}" class="logo logo-white">
                <img src="{{ asset('images/bingoo_new/bingoo-navbar-logo.webp') }}" alt="Bingoo">
            </a>
            <a href="{{ url('/dashboard') }}" class="logo-small">
                <img src="{{ asset('images/bingoo_new/bingoo-footer-icon.webp') }}" alt="Bingoo">
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
                @php
                    $clock = app(\App\Support\TenantClock::class);
                    $displayTz = $clock->displayTimezone();
                    $businessTz = $clock->businessTimezone();
                    $businessToday = \Illuminate\Support\Carbon::parse(
                        $clock->businessDateForOpening($businessTz)
                    )->format('D, d M Y');
                @endphp
                {{-- SHIFT-TIMEZONE-BUSINESS-DATE-1 (D/R/S): live 24h clock, server-anchored so it
                     ticks correctly even if the client wall clock is wrong; shows the display
                     timezone and today's business date. --}}
                <li class="nav-item d-none d-lg-flex align-items-center shift-clock-widget me-2"
                    data-epoch="{{ (int) round(microtime(true) * 1000) }}"
                    data-tz="{{ $displayTz }}"
                    title="Live time ({{ $displayTz }}) · Business date {{ $businessToday }} ({{ $businessTz }})">
                    <i class="ti ti-clock-hour-3 me-1 text-muted"></i>
                    <span class="shift-clock-time fw-semibold" aria-hidden="true">--:--:--</span>
                    <span class="shift-clock-tz small text-muted ms-1">{{ $displayTz }}</span>
                    <span class="shift-clock-bizdate badge bg-light text-dark border ms-2">
                        <i class="ti ti-calendar-event me-1"></i>{{ $businessToday }}
                    </span>
                </li>

                <li class="nav-item pos-nav">
                    @can('tenant.pos.index')
                        <a href="{{ url('/pos') }}" class="btn btn-dark btn-md d-inline-flex align-items-center">
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

@once
@if($isTenant)
{{-- Server-anchored ticking clock. We seed an absolute instant (ms since epoch) from the
     server at render time, then advance it locally using performance.now() deltas and render
     the wall-clock time for the display timezone with Intl — so a wrong client clock cannot
     skew it. --}}
<script>
(function () {
    var el = document.querySelector('.shift-clock-widget');
    if (!el) return;
    var out = el.querySelector('.shift-clock-time');
    var serverMs = parseInt(el.getAttribute('data-epoch'), 10);
    var tz = el.getAttribute('data-tz') || undefined;
    if (!out || isNaN(serverMs)) return;

    var perfBase = (window.performance && performance.now) ? performance.now() : null;
    var fmt;
    try {
        fmt = new Intl.DateTimeFormat('en-GB', {
            timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
        });
    } catch (e) {
        fmt = new Intl.DateTimeFormat('en-GB', {
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
        });
    }

    function tick() {
        var elapsed = perfBase !== null ? (performance.now() - perfBase) : 0;
        out.textContent = fmt.format(new Date(serverMs + elapsed));
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
@endif
@endonce
