{{--
    WEBSITE-I18N-GEO-1 P5 — the cookie question, asked only when there is something to ask.

    The site's own cookies (session, form token, language, currency, this answer) are necessary and
    need no permission. Google Analytics does: until the visitor says yes, Google's script is not on
    the page at all (consent mode "basic") — no request leaves for Google. No measurement ID in
    config = no analytics and no banner.

    The answer lives in the bingoo_consent cookie ("v1.a1" yes / "v1.a0" no, 12 months, not encrypted
    because this script writes it). Footer "Cookie settings" opens the question again; saying no after
    a yes clears Google's cookies and reloads without the script.
--}}
@php($ga4 = \App\Support\CookieConsent::ga4Id())
@if ($ga4)
    @php($consent = \App\Support\CookieConsent::choice(request()))
    <style>
        .cookie-consent { position:fixed; inset-inline:16px; bottom:16px; z-index:1080; max-width:560px; margin-inline:auto;
            background:#0f172a; color:#e2e8f0; border:1px solid #1e293b; border-radius:14px; padding:16px 18px;
            box-shadow:0 18px 40px rgba(15,23,42,.35); font-size:.9rem; line-height:1.55; }
        .cookie-consent[hidden] { display:none; }
        .cookie-consent a { color:#93c5fd; }
        .cookie-consent .cc-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
        .cookie-consent .cc-actions .btn { flex:1 1 140px; font-weight:600; }
        .cookie-consent .btn-cc-no { background:transparent; color:#e2e8f0; border:1px solid #475569; }
        .cookie-consent .btn-cc-no:hover, .cookie-consent .btn-cc-no:focus-visible { background:#1e293b; color:#fff; }
        .cookie-consent .btn-cc-yes { background:#e2e8f0; color:#0f172a; border:1px solid #e2e8f0; }
        .cookie-consent .btn-cc-yes:hover, .cookie-consent .btn-cc-yes:focus-visible { background:#fff; }
    </style>
    <section id="cookie-consent" class="cookie-consent" role="dialog" aria-labelledby="cookie-consent-title" @if ($consent !== null) hidden @endif>
        <strong id="cookie-consent-title" class="d-block mb-1">{{ __('Cookies on this site') }}</strong>
        <span>{{ __('We use necessary cookies to run the site and remember your language. With your permission we also use Google Analytics to learn which pages help visitors. Nothing from Google loads until you choose.') }}</span>
        <a href="{{ \App\Support\PublicLocale::url('/privacy') }}#cookies">{{ __('Cookie details') }}</a>
        <div class="cc-actions">
            {{-- Same size, same weight: saying no is as easy as saying yes. --}}
            <button type="button" class="btn btn-sm btn-cc-no" data-consent="0">{{ __('Necessary only') }}</button>
            <button type="button" class="btn btn-sm btn-cc-yes" data-consent="1">{{ __('Allow analytics') }}</button>
        </div>
    </section>
    @if ($consent && $consent['analytics'])
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4 }}" id="ga4-src"></script>
    @endif
    <script>
    (function () {
        var GA = @json($ga4), NAME = @json(\App\Support\CookieConsent::COOKIE), MAX_AGE = @json(\App\Support\CookieConsent::DAYS * 86400);
        var box = document.getElementById('cookie-consent');
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };

        function answer() {
            var m = document.cookie.match(/(?:^|;\s*)bingoo_consent=v1\.a([01])(?:;|$)/);
            return m ? m[1] : null;
        }
        function start() {
            if (window.__bingooGa) { return; }
            window.__bingooGa = true;
            gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'granted' });
            gtag('js', new Date());
            gtag('config', GA);
            if (!document.getElementById('ga4-src')) {
                var s = document.createElement('script');
                s.async = true; s.id = 'ga4-src';
                s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA);
                document.head.appendChild(s);
            }
        }
        function clearGoogle() {
            var host = location.hostname, parts = host.split('.');
            document.cookie.split(';').forEach(function (c) {
                var name = c.split('=')[0].trim();
                if (!/^_ga(_|$)|^_gid$|^_gat/.test(name)) { return; }
                [host, '.' + host, parts.length > 1 ? '.' + parts.slice(-2).join('.') : host].forEach(function (d) {
                    document.cookie = name + '=; Max-Age=0; path=/; domain=' + d;
                });
                document.cookie = name + '=; Max-Age=0; path=/';
            });
        }
        function save(v) {
            var was = answer();
            document.cookie = NAME + '=v1.a' + v + '; path=/; max-age=' + MAX_AGE + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
            box.hidden = true;
            if (v === '1') { start(); }
            else if (was === '1' || window.__bingooGa) { clearGoogle(); location.reload(); }
        }

        box.querySelectorAll('[data-consent]').forEach(function (b) {
            b.addEventListener('click', function () { save(b.getAttribute('data-consent')); });
        });
        document.querySelectorAll('[data-cookie-settings]').forEach(function (a) {
            a.addEventListener('click', function (e) { e.preventDefault(); box.hidden = false; box.querySelector('[data-consent="1"]').focus(); });
        });
        if (answer() === '1') { start(); }
    })();
    </script>
@endif
