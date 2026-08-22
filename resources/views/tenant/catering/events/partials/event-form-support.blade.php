{{-- KASHIF-CATERING-NO-RELOAD-2 — behavior for the shared event form.

     Two halves. initCateringEventForm(root) gives one form instance its
     customer search, date hints and quick-pick chips — container-scoped, so
     the standalone page and the workspace offcanvas each initialise their own
     copy (and a workspace swap re-initialises the fresh one).

     The delegated submit handler makes form[data-event-ajax] post by fetch:
     a 422 renders the validation messages IN PLACE with every typed value
     kept — fixing a form never costs a reload — and success follows the
     form's declared mode: "navigate" (create: one clean GET into the new
     event's own workspace, so the address bar, refresh and Back all behave;
     rendering that workspace without navigating would mean executing fetched
     scripts, which is a SPA framework by the back door) or "refresh" (the
     offcanvas: close, re-render the workspace in place, say what happened).
     Server validation, services, CSRF and the duplicate-submit guard are all
     untouched — this is transport. --}}
@once
@push('scripts')
<script>
(function () {
    window.initCateringEventForm = function (root) {
        var $root = $(root);

        var $customer = $root.find('.customer-select');
        if ($customer.length && ! $customer.hasClass('select2-hidden-accessible')) {
            $customer.select2({
                width: '100%',
                allowClear: true,
                placeholder: 'Search customers…',
                dropdownParent: $root.closest('.offcanvas').length ? $root.closest('.offcanvas') : $(document.body),
                ajax: {
                    url: '{{ url('/ajax/customers') }}',
                    dataType: 'json',
                    delay: 200,
                    data: params => ({ q: params.term }),
                    processResults: data => ({
                        results: (data.customers || []).map(c => ({
                            id: c.id,
                            text: c.phone ? (c.name + ' — ' + c.phone) : c.name,
                            customer: c,
                        })),
                    }),
                },
            });

            $customer.on('select2:select', function (e) {
                const c = e.params.data.customer || {};
                if (c.name && ! $root.find('[name=customer_name]').val()) $root.find('[name=customer_name]').val(c.name);
                if (c.phone) $root.find('[name=customer_phone]').val(c.phone);
                if (c.email) $root.find('[name=customer_email]').val(c.email);
                const addr = (c.addresses && c.addresses.length) ? c.addresses[0].address : c.legacy_address;
                if (addr) $root.find('[name=customer_address]').val(addr);
            });
        }

        // Date usability — progressive enhancement over the native inputs (no
        // CDN picker: branch terminals run offline). Weekday, distance, and
        // whether the kitchen is already booked that night.
        const eventDate = root.querySelector('[name=event_date]');
        const bookingDate = root.querySelector('[name=booking_date]');
        const hint = root.querySelector('.event-date-hint');
        if (! eventDate || ! hint) return;

        let booked = {};
        try { booked = JSON.parse(root.getAttribute('data-booked') || '{}') || {}; } catch (e) {}

        const iso = d => d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const syncMin = () => { if (bookingDate) eventDate.min = bookingDate.value || ''; };

        function render() {
            const val = eventDate.value;
            if (! val) { hint.innerHTML = ''; return; }
            const d = new Date(val + 'T00:00:00');
            if (isNaN(d)) { hint.innerHTML = ''; return; }

            const weekday = d.toLocaleDateString(undefined, { weekday: 'long' });
            const days = Math.round((d - today) / 86400000);
            const away = days === 0 ? 'today'
                : days === 1 ? 'tomorrow'
                : days > 0 ? 'in ' + days + ' days'
                : Math.abs(days) + ' days ago';

            let html = '<span class="fw-semibold">' + weekday + '</span> · ' + away;
            if (days < 0) html += ' <span class="text-danger">— this date has already passed</span>';

            const clashes = booked[val] || [];
            if (clashes.length) {
                const list = clashes.map(c => c.event_no + ' (' + c.customer + ', ' + c.pax + ' pax)').join(', ');
                html += '<div class="text-warning mt-1"><i class="ti ti-alert-triangle"></i> Already booked: ' + list + '</div>';
            }
            hint.innerHTML = html;
        }

        root.querySelectorAll('.date-chips [data-days], .date-chips [data-weekend]').forEach(btn => {
            btn.addEventListener('click', () => {
                const d = new Date(today);
                if (btn.dataset.weekend) {
                    d.setDate(d.getDate() + ((6 - d.getDay()) + 7) % 7);
                } else {
                    d.setDate(d.getDate() + parseInt(btn.dataset.days, 10));
                }
                eventDate.value = iso(d);
                render();
            });
        });
        root.querySelectorAll('[data-time]').forEach(btn => {
            btn.addEventListener('click', () => {
                const t = root.querySelector('[name=service_time]');
                if (t) t.value = btn.dataset.time;
            });
        });

        eventDate.addEventListener('change', render);
        if (bookingDate) bookingDate.addEventListener('change', syncMin);
        syncMin();
        render();
    };

    document.querySelectorAll('[data-event-form-root]').forEach(function (el) {
        window.initCateringEventForm(el);
    });

    function reenable(form) {
        form.querySelectorAll('button[disabled][data-original-html]').forEach(function (btn) {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.originalHtml;
        });
    }

    function showErrors(form, payload) {
        var box = form.querySelector('.event-form-errors');
        if (! box) return;
        var messages = [];
        if (payload && payload.errors) {
            Object.keys(payload.errors).forEach(function (k) {
                (payload.errors[k] || []).forEach(function (m) { messages.push(m); });
            });
        }
        if (! messages.length && payload && payload.message) messages.push(payload.message);
        box.innerHTML = messages.map(function (m) {
            return '<div>' + String(m).replace(/[&<>"]/g, function (c) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c];
            }) + '</div>';
        }).join('');
        box.classList.remove('d-none');
        box.scrollIntoView({block: 'nearest', behavior: 'smooth'});
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (! (form instanceof HTMLFormElement) || ! form.hasAttribute('data-event-ajax')) return;

        e.preventDefault();
        var fd = new FormData(form);
        if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value);

        fetch(form.action, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
        }).then(function (r) {
            if (r.status === 422) {
                return r.json().then(function (payload) {
                    showErrors(form, payload);
                    reenable(form);
                });
            }
            if (! r.ok) throw new Error('unexpected ' + r.status);

            return r.json().then(function (payload) {
                if (form.getAttribute('data-event-ajax') === 'refresh'
                        && window.cateringWorkspaceRefresh
                        && payload.redirect
                        && new URL(payload.redirect, window.location.origin).pathname === window.location.pathname) {
                    var oc = form.closest('.offcanvas');
                    if (oc && typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
                        var inst = bootstrap.Offcanvas.getInstance(oc);
                        if (inst) inst.hide();
                    }
                    window.cateringWorkspaceRefresh(payload.message || null);
                    return;
                }
                // One clean GET into the saved event — address bar, refresh and
                // Back all behave; no POST page ever re-renders.
                window.location.assign(payload.redirect || window.location.href);
            });
        }).catch(function () {
            // The enhancement must never cost the operator their booking.
            reenable(form);
            form.removeAttribute('data-event-ajax');
            form.submit();
        });
    });
})();
</script>
@endpush
@endonce
