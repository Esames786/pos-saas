<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-layout-mode="light_mode">
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
    @stack('styles')
</head>

<body>
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
                <div class="alert alert-{{ $tenantSubscriptionStatus['severity'] === 'danger' ? 'danger' : 'warning' }} mb-3" role="status">
                    {{ $tenantSubscriptionStatus['message'] }}
                </div>
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
</script>
</body>
</html>
