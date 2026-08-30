<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar', 'ur']) ? 'rtl' : 'ltr' }}" data-layout-mode="light_mode">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }} - @yield('title')</title>

    <script src="{{ asset('assets/js/theme-script.js') }}"></script>

    <link rel="shortcut icon" type="image/x-icon" href="{{ asset('assets/img/favicon.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('assets/img/apple-touch-icon.png') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap-datetimepicker.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/animate.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/select2/css/select2.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/daterangepicker/daterangepicker.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/tabler-icons/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/fontawesome/css/fontawesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/fontawesome/css/all.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/a11y-custom.css') }}">
    @if(request()->is('pos'))
        <style>
            body.pos-workspace .header { display: none; }
            body.pos-workspace.nosidebar .sidebar { display: none; }
            body.pos-workspace:not(.nosidebar) .sidebar { display: block; z-index: 1050; }
            body.pos-workspace .page-wrapper { margin: 0; padding-top: 0; }
            body.pos-workspace .page-wrapper .content { padding: 12px; min-height: 100vh; }
            body.pos-workspace .tenant-subscription-banner { display: none; }
        </style>
    @endif
    {{-- Embedded mode: a page opened inside a POS window (iframe) hides the app chrome so only its
         own content shows. Triggered by ?embed=1 (flash-free first load) OR by the iframe-detection
         script below (covers in-window navigations that drop the query param). CSS is always emitted;
         it only bites when the body actually carries .embedded-workspace. --}}
    <style>
        /* Hide the template's floating "Theme Customizer" toggle (a spinning cog in a gold circle on
           the right edge, injected by theme-script.js) — a demo widget with a "Buy Product" button,
           not for production. The script's dark/light logic is untouched; only its FAB is hidden. */
        .toggle-theme { display: none !important; }
        body.embedded-workspace .header,
        body.embedded-workspace .sidebar,
        body.embedded-workspace .skip-link,
        body.embedded-workspace .tenant-subscription-banner { display: none !important; }
        body.embedded-workspace .page-wrapper { margin: 0 !important; padding: 0 !important; }
        body.embedded-workspace .page-wrapper > .content { min-height: 100vh; padding: 12px !important; }
        body.embedded-workspace .content-wrapper,
        body.embedded-workspace .content-wrapper > .content { margin: 0 !important; padding: 0 !important; }
    </style>
    @stack('styles')
</head>

<body @class([
    'pos-workspace nosidebar' => request()->is('pos'),
    'embedded-workspace' => request()->boolean('embed'),
])>
{{-- Any page opened inside a POS window (iframe) hides the app chrome — even after an in-window
     navigation (e.g. picking a sale) that drops the ?embed=1 param. Runs before paint, so no flash. --}}
<script>try{if(window.self!==window.top){document.body.classList.add('embedded-workspace');}}catch(e){}</script>
<a href="#main-content" class="skip-link">Skip to main content</a>
<div id="global-loader">
    <div class="whirly-loader"></div>
</div>

<div class="main-wrapper">
    @include('partials.header')
    @include('partials.sidebar')

    <div class="page-wrapper">
        <div class="content" id="main-content" tabindex="-1">
            @if(session('status'))
                <div class="alert alert-success" role="status" aria-live="polite">
                    {{ session('status') }}
                </div>
            @endif
            @if(!empty($tenantSubscriptionStatus) && !empty($tenantSubscriptionStatus['message']))
                {{-- POS-UX-2: dismissible per browser-session (danger severity stays visible) --}}
                <div class="tenant-subscription-banner alert alert-{{ $tenantSubscriptionStatus['severity'] === 'danger' ? 'danger' : 'warning' }} mb-3 {{ $tenantSubscriptionStatus['severity'] === 'danger' ? '' : 'alert-dismissible fade show' }}" role="status"
                     @if($tenantSubscriptionStatus['severity'] !== 'danger') id="tenant-subscription-banner-dismissible" @endif>
                    {{ $tenantSubscriptionStatus['message'] }}
                    @if($tenantSubscriptionStatus['severity'] !== 'danger')
                        <button type="button" class="btn-close" aria-label="Dismiss"
                                onclick="try{sessionStorage.setItem('subBannerDismissed','1')}catch(e){}"
                                data-bs-dismiss="alert"></button>
                    @endif
                </div>
                @if($tenantSubscriptionStatus['severity'] !== 'danger')
                <script>
                    try {
                        if (sessionStorage.getItem('subBannerDismissed') === '1') {
                            var b = document.getElementById('tenant-subscription-banner-dismissible');
                            if (b) b.remove();
                        }
                    } catch (e) {}
                </script>
                @endif
            @endif
            @yield('content')
        </div>
    </div>
</div>

<script src="{{ asset('assets/js/jquery-3.7.1.min.js') }}"></script>
<script src="{{ asset('assets/js/feather.min.js') }}"></script>
<script src="{{ asset('assets/js/jquery.slimscroll.min.js') }}"></script>
<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/js/moment.min.js') }}"></script>
<script src="{{ asset('assets/plugins/daterangepicker/daterangepicker.js') }}"></script>
<script src="{{ asset('assets/plugins/select2/js/select2.min.js') }}"></script>
<script src="{{ asset('assets/plugins/sweetalert/sweetalert2.all.min.js') }}"></script>
<script src="{{ asset('assets/js/script.js') }}"></script>
{{-- UX-HARDEN-1: global flash toast — every session "status" flash surfaces as
     a SweetAlert toast so success feedback is consistent portal-wide. --}}
@if(session('status'))
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.Swal) {
        Swal.fire({ toast: true, position: 'top-end', timer: 3500, timerProgressBar: true,
            showConfirmButton: false, icon: 'success', title: @json(session('status')) });
    }
});
</script>
@endif
@stack('scripts')
<script>
(function () {
    var KEY = 'sidebar_scroll_top';
    var inner = document.querySelector('.sidebar-inner');
    if (!inner) return;

    // Restore on load
    var saved = localStorage.getItem(KEY);
    if (saved) {
        setTimeout(function () { inner.scrollTop = parseInt(saved, 10); }, 50);
    }

    // Save on scroll
    inner.addEventListener('scroll', function () {
        localStorage.setItem(KEY, inner.scrollTop);
    });
})();

// Portal-wide: prevent the mouse wheel from silently changing <input type="number">
// values. Scrolling over a FOCUSED number input used to increment/decrement it by its
// step (e.g. 1 -> 1.001 on a 0.001-step field), silently corrupting quantities on the
// split-bill / POS / edit screens. Ignore the wheel while a number input is focused.
document.addEventListener('wheel', function (e) {
    var el = e.target;
    if (el && el.tagName === 'INPUT' && el.type === 'number' && el === document.activeElement) {
        e.preventDefault();
    }
}, { passive: false });
</script>
</body>
</html>
