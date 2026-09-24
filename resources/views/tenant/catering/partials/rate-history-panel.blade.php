{{-- CATERING-RATE-HISTORY-1 — yesterday's rate, inside the box.

     Asked for on 24 September: "is popup mai history b show honi chahye k mai
     kal k rate dekh sakon or re apply karsakaon".

     A caterer prices chicken against what it was yesterday. Before this, the
     previous rates were in a table at the BOTTOM of the page — so the operator
     had to close the modal, scroll, remember a number, and open it again. That
     is how the wrong number gets typed.

     ONE partial, included by both rate screens. The two books ask the same
     question of the same operator and must not answer it in two styles.

     Expects:
       $historyByMaterial  array keyed by product id
       $historyLabel       what this book's rates ARE, in the operator's words

     Scoped to its own <form>, so the same markup works in either modal without
     either one knowing about the other. --}}
<div class="col-12 rate-history-wrap d-none">
    <div class="border rounded p-2 bg-light-subtle">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-semibold fs-13">
                <i class="ti ti-history me-1"></i>{{ $historyLabel ?? 'Pichli rates' }}
            </span>
            <span class="fs-12 text-body-secondary rate-history-count"></span>
        </div>
        <div class="rate-history-rows fs-13"></div>
        <div class="fs-12 text-body-secondary mt-1">
            {{-- Said out loud because the button LOOKS like it applies something. --}}
            "Use this" sirf ooper ke khane bharta hai — rate aur unit. <strong>Tareekh nahi
            badalti</strong>, aur record karne tak kuch nahi hota.
        </div>
    </div>
</div>

@once
    @push('scripts')
    <script>
    (function () {
        // Every rate screen on the page, each with its own data and its own form.
        document.querySelectorAll('[data-rate-history]').forEach(function (form) {
            let book = {};
            try {
                book = JSON.parse(form.dataset.rateHistory || '{}');
            } catch (e) {
                return; // Malformed data must not take the modal down with it.
            }

            const product = form.querySelector('[name=product_id]');
            const rate = form.querySelector('[name=rate]');
            const unit = form.querySelector('[name=unit_id]');
            const wrap = form.querySelector('.rate-history-wrap');
            const rows = form.querySelector('.rate-history-rows');
            const count = form.querySelector('.rate-history-count');
            if (! product || ! wrap || ! rows) return;

            const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g,
                c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

            function render() {
                const list = book[product.value] || [];
                rows.innerHTML = '';

                if (! list.length) {
                    wrap.classList.add('d-none');

                    return;
                }

                count.textContent = list.length + (list.length === 1 ? ' rate' : ' rates');
                rows.innerHTML = list.map((r, i) => {
                    // The top row is what is in force today — worth saying, because
                    // "re-apply yesterday's" only means something next to it.
                    const badge = i === 0
                        ? '<span class="badge bg-success-subtle text-success-emphasis fs-12 ms-1">abhi</span>'
                        : '';
                    const note = r.note ? '<span class="text-body-secondary ms-1">· ' + esc(r.note) + '</span>' : '';

                    return '<div class="d-flex align-items-center justify-content-between py-1 border-bottom">'
                        + '<div><strong>' + esc(Number(r.rate).toLocaleString(undefined, {minimumFractionDigits: 2})) + '</strong>'
                        + (r.unit ? ' <span class="text-body-secondary">/ ' + esc(r.unit) + '</span>' : '')
                        + badge
                        + '<div class="fs-12 text-body-secondary">' + esc(r.effective_from) + note + '</div></div>'
                        + '<button type="button" class="btn btn-sm btn-outline-secondary rate-history-use"'
                        + ' data-rate="' + esc(r.rate) + '" data-unit="' + esc(r.unit_id || '') + '">Use this</button>'
                        + '</div>';
                }).join('');

                wrap.classList.remove('d-none');
            }

            rows.addEventListener('click', function (e) {
                const btn = e.target.closest('.rate-history-use');
                if (! btn) return;

                if (rate) { rate.value = btn.dataset.rate; }
                if (unit && btn.dataset.unit) { unit.value = btn.dataset.unit; }

                // effective_from is DELIBERATELY not touched. The whole point of
                // putting an old rate back is that it takes effect from TODAY;
                // quietly restoring the old date would back-date the decision and
                // change what past quotations costed at.
                if (rate) { rate.focus(); rate.select(); }
            });

            product.addEventListener('change', render);
            // select2 fires its own event, and the plain select fires neither on
            // programmatic change — so ask jQuery too when it is present.
            if (window.jQuery) { window.jQuery(product).on('change select2:select', render); }
            render();
        });
    })();
    </script>
    @endpush
@endonce
