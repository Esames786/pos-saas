{{--
    KASHIF-CATERING-DOUBLE-SUBMIT-1 — the browser half of the guard.

    Two layers, because neither alone is enough:

      this file   stops the accident — the button greys out on first click, so a
                  double-tap never sends a second request
      middleware  stops everything else — a refresh, a back button, a replayed
                  request, or two clicks that both left before the first
                  disabled anything

    The token is generated once per page render. Every form on the page carries
    the same one, which is correct: a page shows one booking, and submitting any
    action on it twice is the thing being prevented.
--}}
@once
@php $submitToken = (string) \Illuminate\Support\Str::uuid(); @endphp

@push('scripts')
<script>
(function () {
    var TOKEN = @json($submitToken);

    // Stamp every POST form so the server can claim the submission.
    document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function (form) {
        if (form.querySelector('input[name="_submit_token"]')) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_submit_token';
        input.value = TOKEN;
        form.appendChild(input);
    });

    // Grey the button the moment it is used. Keep the label so the operator can
    // still read what they pressed, and re-enable if the browser restores the
    // page from history — otherwise a back button leaves a dead form.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (btn) {
            if (btn.disabled) return;
            btn.disabled = true;
            btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + btn.textContent.trim();
        });
    }, true);

    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('button[disabled][data-original-html]').forEach(function (btn) {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.originalHtml;
        });
    });
})();
</script>
@endpush
@endonce
