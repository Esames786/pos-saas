{{-- W-A (Phase 2) — the SHARED cashier POS layout. Renders `tenant.pos.index` on the Cloud (Online POS) and, later, on a
     Branch Server (Edge), driven only by `$posRuntime` (App\Support\Pos\PosRuntime).

     It is `layouts.app` with the POS rules made explicit: the same <head>/<body> skeleton, the SAME theme asset files in the
     SAME order (each URL through $posRuntime->asset()), and the POS chrome rules that layouts.app applied only on /pos
     (header/sidebar/banner hidden, .page-wrapper offsets 0, content padding 12px) applied unconditionally. The Cloud header
     and sidebar are NOT part of this file: the runtime names a chrome view (Cloud: partials.pos-chrome-cloud → the existing
     partials.header + partials.sidebar, hidden exactly as before), so this layout never touches Cloud-only services.
     `layouts.app` is untouched for every other screen. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar', 'ur']) ? 'rtl' : 'ltr' }}" data-layout-mode="light_mode">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} - @yield('title')</title>

    <script src="{{ $posRuntime->asset('assets/js/theme-script.js') }}"></script>

    <link rel="shortcut icon" type="image/x-icon" href="{{ $posRuntime->asset('assets/img/favicon.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $posRuntime->asset('assets/img/apple-touch-icon.png') }}">
    <link rel="stylesheet" href="{{ $posRuntime->asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ $posRuntime->asset('assets/css/bootstrap-datetimepicker.min.css') }}">
    <link rel="stylesheet" href="{{ $posRuntime->asset('assets/css/animate.css') }}">
    <link rel="stylesheet" href="{{ $posRuntime->asset('assets/plugins/select2/css/select2.min.css') }}">
    <link rel="stylesheet" href="{{ $posRuntime->asset('assets/plugins/daterangepicker/daterangepicker.css') }}">
    <link rel="stylesheet" href="{{ $posRuntime->asset('assets/plugins/tabler-icons/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ $posRuntime->asset('assets/plugins/fontawesome/css/fontawesome.min.css') }}">
    <link rel="stylesheet" href="{{ $posRuntime->asset('assets/plugins/fontawesome/css/all.min.css') }}">
    {{-- A3 (W-C): self-hosted Nunito, linked right before the theme (style.css no longer @imports Google Fonts). --}}
    <link rel="stylesheet" href="{{ $posRuntime->asset('assets/css/fonts-local.css') }}">
    <link rel="stylesheet" href="{{ $posRuntime->asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ $posRuntime->asset('assets/css/a11y-custom.css') }}">
    {{-- POS chrome rules — identical to what layouts.app emitted on /pos, now unconditional (both runtimes). --}}
    <style>
        body.pos-workspace .header { display: none; }
        body.pos-workspace.nosidebar .sidebar { display: none; }
        body.pos-workspace:not(.nosidebar) .sidebar { display: block; z-index: 1050; }
        body.pos-workspace .page-wrapper { margin: 0; padding-top: 0; }
        body.pos-workspace .page-wrapper .content { padding: 12px; min-height: 100vh; }
        body.pos-workspace .tenant-subscription-banner { display: none; }
    </style>
    {{-- Embedded mode (?embed=1 or iframe detection) — kept verbatim from layouts.app. --}}
    <style>
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
    {{-- The runtime map must exist before ANY page script: the page's inline scripts run during parse. --}}
    <script>window.POS_RUNTIME = @json($posRuntime);</script>
    @include('tenant.pos.js.pos-runtime')
</head>

<body @class([
    'pos-workspace nosidebar',
    'embedded-workspace' => request()->boolean('embed'),
])>
<script>try{if(window.self!==window.top){document.body.classList.add('embedded-workspace');}}catch(e){}</script>
<a href="#main-content" class="skip-link">Skip to main content</a>
<div id="global-loader">
    <div class="whirly-loader"></div>
</div>

<div class="main-wrapper">
    {{-- Chrome slot: the runtime's own chrome view (Cloud: the existing header + sidebar, hidden by the rules above). --}}
    @if($posRuntime->chromeView)
        @include($posRuntime->chromeView)
    @endif
    @include('tenant.pos.partials.pos-clock')

    <div class="page-wrapper">
        <div class="content" id="main-content" tabindex="-1">
            @if(session('status'))
                <div class="alert alert-success" role="status" aria-live="polite">
                    {{ session('status') }}
                </div>
            @endif
            @yield('content')
        </div>
    </div>
</div>

{{-- A5: the ONE shared overlay for urgent authority warnings (both runtimes). Fixed-position and hidden by default, so it
     never reflows the page; shown only through POS.overlay.show(). --}}
<div id="pos-runtime-overlay" class="position-fixed top-0 start-50 translate-middle-x mt-2 d-none" style="z-index:1090;max-width:min(560px,94vw)" role="alert" aria-live="assertive">
    <div class="alert alert-warning shadow d-flex align-items-start gap-2 mb-0" id="pos-runtime-overlay-box">
        <i class="ti ti-alert-triangle mt-1" aria-hidden="true"></i>
        <div class="flex-grow-1">
            <strong id="pos-runtime-overlay-title"></strong>
            <div class="small" id="pos-runtime-overlay-text"></div>
        </div>
        <button type="button" class="btn-close" id="pos-runtime-overlay-close" aria-label="Dismiss"></button>
    </div>
</div>

<script src="{{ $posRuntime->asset('assets/js/jquery-3.7.1.min.js') }}"></script>
<script src="{{ $posRuntime->asset('assets/js/feather.min.js') }}"></script>
<script src="{{ $posRuntime->asset('assets/js/jquery.slimscroll.min.js') }}"></script>
<script src="{{ $posRuntime->asset('assets/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ $posRuntime->asset('assets/js/moment.min.js') }}"></script>
<script src="{{ $posRuntime->asset('assets/plugins/daterangepicker/daterangepicker.js') }}"></script>
<script src="{{ $posRuntime->asset('assets/plugins/select2/js/select2.min.js') }}"></script>
<script src="{{ $posRuntime->asset('assets/plugins/sweetalert/sweetalert2.all.min.js') }}"></script>
<script src="{{ $posRuntime->asset('assets/js/script.js') }}"></script>
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

    var saved = localStorage.getItem(KEY);
    if (saved) {
        setTimeout(function () { inner.scrollTop = parseInt(saved, 10); }, 50);
    }

    inner.addEventListener('scroll', function () {
        localStorage.setItem(KEY, inner.scrollTop);
    });
})();

// Portal-wide wheel guard: never let the mouse wheel silently change a FOCUSED <input type="number">.
document.addEventListener('wheel', function (e) {
    var el = e.target;
    if (el && el.tagName === 'INPUT' && el.type === 'number' && el === document.activeElement) {
        e.preventDefault();
    }
}, { passive: false });
</script>
</body>
</html>
