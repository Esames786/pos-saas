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
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}">
@endpush
@push('scripts')
<script src="{{ asset('assets/plugins/flatpickr/flatpickr.js') }}"></script>
@endpush
@endonce
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
                // A name nobody has yet is typed right here and becomes the new
                // customer — no modal, no second screen, no separate save.
                tags: true,
                minimumInputLength: 1,
                placeholder: 'Phone ya naam…',
                cache: false,
                templateResult: function (item) {
                    if (! item.id || ! item.customer) return item.text;
                    const name = $('<div>').text(item.customer.name || '').html();
                    const phone = item.customer.phone
                        ? '<div class="fs-12 text-muted">' + $('<div>').text(item.customer.phone).html() + '</div>'
                        : '';
                    return $('<div><strong>' + name + '</strong>' + phone + '</div>');
                },
                templateSelection: function (item) {
                    if (! item.customer) return item.text || '';
                    return item.customer.phone ? (item.customer.name + ' — ' + item.customer.phone) : item.customer.name;
                },
                language: { inputTooShort: () => 'Phone ya naam likhein…', noResults: () => 'Koi match nahi — likha hua naam Enter karein' },
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
                const data = e.params.data;
                const c = data.customer;
                const set = (n, v) => { if (v !== undefined && v !== null) $root.find('[name=' + n + ']').val(v); };

                if (! c) {
                    // A typed name: it IS the customer. Only the name is known,
                    // and the fields below are already open for the rest.
                    const typed = (data.text || '').trim();
                    set('customer_name', typed);
                    ['customer_phone', 'customer_email', 'customer_address'].forEach(n => set(n, ''));
                    $root.find('[name=customer_phone]').trigger('focus');

                    return;
                }

                // A match: fill everything the customer already told us. The
                // fields stay editable — a different address for THIS booking
                // is typed right there and belongs to the booking, not the
                // customer's record.
                set('customer_name', c.name || '');
                set('customer_phone', c.phone || '');
                set('customer_email', c.email || '');
                const addr = (c.addresses && c.addresses.length) ? c.addresses[0].address : c.legacy_address;
                set('customer_address', addr || '');
            });

            // Clearing means CLEARING: the search box AND everything it filled.
            // Leaving the fields behind is how a booking ends up carrying the
            // wrong customer's phone under the right customer's name.
            function clearCustomer() {
                $customer.val(null).trigger('change');
                ['customer_name', 'customer_name_ur', 'customer_phone', 'customer_email', 'customer_address']
                    .forEach(n => $root.find('[name=' + n + ']').val(''));
                $root.find('[name=customer_name]').trigger('focus');
            }
            $customer.on('select2:clear select2:unselect', clearCustomer);
            $root.find('.customer-reset').on('click', clearCustomer);

            // KASHIF-EVENT-FORM-3 — a typed name is not a customer id.
            // Leaving typed text in the box used to post it AS the id and the
            // booking was refused with "The selected customer id is invalid",
            // even though the name, phone and address below were all filled.
            // A non-numeric value simply means "no existing customer": it is
            // dropped, and the typed text becomes the name if none was given.
            $root.closest('form').on('submit', function () {
                const raw = String($customer.val() ?? '');
                if (raw !== '' && ! /^\d+$/.test(raw)) {
                    if (! $root.find('[name=customer_name]').val()) {
                        $root.find('[name=customer_name]').val(raw);
                    }
                    $customer.val(null);
                }
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
        const syncMin = () => {
            if (! bookingDate) return;
            const min = bookingDate.value || '';
            if (eventDate._flatpickr) { eventDate._flatpickr.set('minDate', min || null); }
            else { eventDate.min = min; }
        };

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
                // With a picker attached the visible box is flatpickr's own;
                // writing .value alone changed a hidden field and nothing moved.
                if (eventDate._flatpickr) { eventDate._flatpickr.setDate(iso(d), true); }
                else { eventDate.value = iso(d); }
                render();
            });
        });
        root.querySelectorAll('[data-time]').forEach(btn => {
            btn.addEventListener('click', () => {
                const t = root.querySelector('[name=service_time]');
                if (!t) return;
                if (t._flatpickr) { t._flatpickr.setDate(btn.dataset.time, true); } else { t.value = btn.dataset.time; }
                root.querySelectorAll('[data-time]').forEach(b => b.classList.remove('btn-secondary', 'text-white'));
                btn.classList.add('btn-secondary', 'text-white');
            });
        });
        root.querySelector('[data-open-service-time]')?.addEventListener('click', () => {
            const input = root.querySelector('[name=service_time]');
            if (input?._flatpickr) input._flatpickr.open();
            else input?.showPicker?.();
        });

        // KASHIF-EVENT-FORM-1 — dates remain keyboard-friendly; service time
        // is deliberately selection-only so arbitrary text cannot be entered.
        // Native date/time fields remain the fallback when Flatpickr is absent.
        if (window.flatpickr) {
            root.querySelectorAll('input[type=date]').forEach(function (el) {
                if (el._flatpickr) return;
                window.flatpickr(el, {
                    dateFormat: 'Y-m-d', altInput: true, altFormat: 'D, d M Y',
                    allowInput: true, disableMobile: true,
                    // The hint and the clash warning listen for 'change' — say it
                    // out loud rather than trusting the library to.
                    onChange: function () { el.dispatchEvent(new Event('change', { bubbles: true })); },
                });
            });
            root.querySelectorAll('input[type=time]').forEach(function (el) {
                if (el._flatpickr) return;
                window.flatpickr(el, {
                    enableTime: true, noCalendar: true, dateFormat: 'H:i',
                    altInput: true, altFormat: 'h:i K', time_24hr: false,
                    // Selection-only: use the professional clock, AM/PM toggle,
                    // or a house preset. Arbitrary letters cannot remain in the
                    // visible field or reach the canonical H:i value.
                    allowInput: false, disableMobile: true, minuteIncrement: 15,
                    clickOpens: true,
                    onReady: function (dates, value, instance) {
                        if (! instance.altInput) return;
                        instance.altInput.readOnly = true;
                        instance.altInput.inputMode = 'none';
                        instance.altInput.autocomplete = 'off';
                        instance.altInput.setAttribute('aria-label', 'Select service time');
                    },
                    onChange: function (dates, value) {
                        root.querySelectorAll('[data-time]').forEach(function (button) {
                            const selected = button.dataset.time === value;
                            button.classList.toggle('btn-secondary', selected);
                            button.classList.toggle('text-white', selected);
                        });
                    },
                });
            });
        }

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
