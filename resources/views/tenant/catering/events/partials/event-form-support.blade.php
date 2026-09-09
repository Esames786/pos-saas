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
        // EVENT-FORM-KEYBOARD-1 — what an operator actually types.
        //
        // The boxes already allowed typing, but only in the shape flatpickr
        // prints — "Wed, 09 Sep 2026" — which nobody types. This reads the
        // shapes people use instead. DAY FIRST, because that is how the date is
        // written and said here: 9/10 is the ninth of October.
        //
        // Anything not clearly recognised falls through to the browser's own
        // parse, and anything it cannot read either is REFUSED rather than
        // guessed at. A silently wrong event date is worse than a retype.
        const typedDate = function (str) {
            const s = String(str || '').trim().toLowerCase();
            if (! s) return undefined;

            const midnight = d => { d.setHours(0, 0, 0, 0); return d; };
            // A real day, or nothing. JavaScript's Date carries overflow
            // forward — new Date(2026, 98, 99) is a valid object in June 2034 —
            // so 99/99 would have become a date instead of a refusal.
            const made = (y, m, d) => {
                if (! (y >= 2000 && y <= 2100) || ! (m >= 1 && m <= 12) || ! (d >= 1 && d <= 31)) return undefined;
                const made = midnight(new Date(y, m - 1, d));

                return (made.getFullYear() === y && made.getMonth() === m - 1 && made.getDate() === d)
                    ? made : undefined;
            };
            if (s === 't' || s === 'today' || s === 'aaj') return midnight(new Date());
            if (s === 'kal' || s === 'tomorrow') { const d = new Date(); d.setDate(d.getDate() + 1); return midnight(d); }

            // +7 / -3 — days from today, the way a booking is usually described.
            const rel = s.match(/^([+-])\s*(\d{1,3})$/);
            if (rel) {
                const d = new Date();
                d.setDate(d.getDate() + (rel[1] === '-' ? -1 : 1) * parseInt(rel[2], 10));
                return midnight(d);
            }

            // 2026-10-09 — the canonical value flatpickr itself stores.
            const iso = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
            if (iso) return made(+iso[1], +iso[2], +iso[3]);

            // 9/10, 9-10-26, 9.10.2026
            const dmy = s.match(/^(\d{1,2})[\/\-. ](\d{1,2})(?:[\/\-. ](\d{2}|\d{4}))?$/);
            if (dmy) {
                const now = new Date();
                let y = dmy[3] === undefined ? now.getFullYear() : parseInt(dmy[3], 10);
                if (y < 100) y += 2000;

                return made(y, +dmy[2], +dmy[1]);
            }

            // 091026 / 09102026 — typed straight off the number pad.
            const run = s.match(/^(\d{2})(\d{2})(\d{2}|\d{4})$/);
            if (run) {
                let y = parseInt(run[3], 10);
                if (y < 100) y += 2000;

                return made(y, +run[2], +run[1]);
            }

            const native = new Date(str);

            return isNaN(native.getTime()) ? undefined : midnight(native);
        };

        /**
         * EVENT-FORM-KEYBOARD-3 — a typed service time, or nothing.
         *
         * Bare numbers are read as a 24-HOUR clock: 10 is ten in the morning,
         * 22 is ten at night. Guessing that a caterer "probably meant evening"
         * is how a booking ends up twelve hours out, and the box prints back
         * "10:00 PM" either way, so the operator sees what they got.
         *
         * Anything that is not a real time returns undefined, and flatpickr then
         * keeps the value it already had — which is the whole answer to why this
         * field was locked in the first place.
         */
        const typedTime = function (str) {
            const s = String(str || '').trim().toLowerCase().replace(/\s+/g, '');
            if (! s) return undefined;

            const m = s.match(/^(\d{1,2})(?::?(\d{2}))?(am|pm|a|p)?$/);
            if (! m) return undefined;

            let hours = parseInt(m[1], 10);
            let minutes = m[2] === undefined ? 0 : parseInt(m[2], 10);

            // 1030 / 2215 — typed straight off the number pad.
            if (m[2] === undefined && m[1].length > 2) {
                if (m[1].length !== 4) return undefined;
                hours = parseInt(m[1].slice(0, 2), 10);
                minutes = parseInt(m[1].slice(2), 10);
            }

            const suffix = m[3] ? m[3][0] : null;
            if (suffix) {
                if (hours < 1 || hours > 12) return undefined;
                hours = hours % 12;
                if (suffix === 'p') hours += 12;
            }

            if (! (hours >= 0 && hours <= 23) || ! (minutes >= 0 && minutes <= 59)) return undefined;

            const d = new Date();
            d.setHours(hours, minutes, 0, 0);

            return d;
        };

        /**
         * EVENT-FORM-KEYBOARD-4 — refuse the keystroke, not the value.
         *
         * `allow` is asked whether the value WOULD still be on its way to
         * something valid; if not, the character never lands. `shape` then
         * formats what is there — but only while the caret is at the end, so
         * editing in the middle is never fought over.
         */
        const restrictTyping = function (input, allow, shape) {
            input.addEventListener('beforeinput', function (e) {
                // Deleting is always allowed; so is anything the browser is
                // doing on its own behalf.
                if (e.data === null || e.data === undefined) return;

                const start = input.selectionStart ?? input.value.length;
                const end = input.selectionEnd ?? input.value.length;
                const next = input.value.slice(0, start) + e.data + input.value.slice(end);

                if (! allow(next)) e.preventDefault();
            });

            input.addEventListener('input', function () {
                if (input.selectionStart !== input.value.length) return;
                const shaped = shape(input.value);
                if (shaped === input.value) return;
                input.value = shaped;
                input.setSelectionRange(shaped.length, shaped.length);
            });
        };

        // Every word the date box understands. A letter is only ever accepted
        // while the value is still walking towards one of these.
        const DATE_WORDS = ['today', 'aaj', 'kal', 'tomorrow', 't'];

        const dateAllows = function (value) {
            const s = String(value).trim().toLowerCase();
            if (s === '') return true;
            if (/^[+-]\d{0,3}$/.test(s)) return true;                                  // +7, -3
            if (/^\d{0,2}([\/\-. ]\d{0,2}([\/\-. ]\d{0,4})?)?$/.test(s)) return true;   // 9/10/26
            if (/^\d{0,8}$/.test(s)) return true;                                      // 09102026
            // The mask's own half-finished output — 10-11-2026 on its way in.
            if (/^[\d-]*$/.test(s) && s.replace(/\D/g, '').length <= 8) return true;
            return DATE_WORDS.some(function (w) { return w.startsWith(s); });
        };

        const dateMasked = function (digits) {
            const d = digits.slice(0, 8);
            if (d.length <= 2) return d;
            if (d.length <= 4) return d.slice(0, 2) + '-' + d.slice(2);

            return d.slice(0, 2) + '-' + d.slice(2, 4) + '-' + d.slice(4);
        };

        const dateShape = function (value) {
            // Anything carrying a slash, a dot, a space or a letter is the
            // operator's own way of writing it — 9/10, 9.10.26, today — and is
            // never rewritten.
            if (! /^[\d-]*$/.test(value)) return value;

            // The mask puts its dashes after the day and after the month, and
            // nowhere else. A date punctuated by hand — 9-10-26 — has them
            // somewhere else, and reshaping it on digit count would turn it into
            // 91-02-6. So only the mask's own dash positions are reshaped.
            for (let i = 0; i < value.length; i++) {
                if (value[i] === '-' && i !== 2 && i !== 5) return value;
            }

            return dateMasked(value.replace(/-/g, ''));
        };

        const timeAllows = function (value) {
            const s = String(value).trim().toLowerCase().replace(/\s+/g, '');
            if (s === '') return true;

            return /^\d{0,2}(:\d{0,2})?(a|p|am|pm)?$/.test(s) || /^\d{0,4}$/.test(s);
        };

        const timeShape = function (value) {
            if (! /^\d+$/.test(value)) return value;                                   // 10pm keeps its own shape
            const d = value.slice(0, 4);
            if (d.length <= 2) return d;

            return d.slice(0, 2) + ':' + d.slice(2);
        };

        if (window.flatpickr) {
            root.querySelectorAll('input[type=date]').forEach(function (el) {
                if (el._flatpickr) return;
                window.flatpickr(el, {
                    dateFormat: 'Y-m-d', altInput: true, altFormat: 'D, d M Y',
                    allowInput: true, disableMobile: true,
                    parseDate: typedDate,
                    // The hint and the clash warning listen for 'change' — say it
                    // out loud rather than trusting the library to.
                    onChange: function (dates, value, fp) {
                        if (fp.altInput) fp.altInput.classList.remove('is-invalid');
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    },
                    onReady: function (dates, value, fp) {
                        if (! fp.altInput) return;
                        fp.altInput.setAttribute('placeholder', '10-11-2026 · 9/10 · +7 · today');
                        restrictTyping(fp.altInput, dateAllows, dateShape);
                        // Enter accepts what was typed and MOVES ON; the calendar
                        // must not sit open swallowing the next Tab. Escape closes
                        // it and leaves the date alone.
                        fp.altInput.addEventListener('keydown', function (e) {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                const parsed = typedDate(fp.altInput.value);
                                if (parsed) fp.setDate(parsed, true);
                                fp.close();
                                const fields = [...root.querySelectorAll('input, select, textarea, button')]
                                    .filter(f => ! f.disabled && f.type !== 'hidden' && f.offsetParent !== null);
                                const at = fields.indexOf(fp.altInput);
                                if (at > -1 && at < fields.length - 1) fields[at + 1].focus();
                                return;
                            }
                            if (e.key === 'Escape') { fp.close(); }
                        });
                        // Typing over the box should REPLACE the date, not append
                        // to "Wed, 09 Sep 2026".
                        fp.altInput.addEventListener('focus', function () { fp.altInput.select(); });

                        // EVENT-FORM-KEYBOARD-2 — the calendar follows the typing.
                        //
                        // 09-10-2026 was understood the moment it was typed, but
                        // nothing on screen said so: the calendar stayed on the old
                        // month until Enter, which reads as "it did not work".
                        //
                        // jumpToDate only — NOT setDate. setDate rewrites the box
                        // into "Fri, 09 Oct 2026" mid-word and throws the caret to
                        // the end, so the next keystroke lands in the wrong place.
                        // The month heading changing is the whole confirmation
                        // needed; Enter or Tab still commits.
                        fp.altInput.addEventListener('input', function () {
                            const raw = fp.altInput.value.trim();
                            if (! raw) { fp.altInput.classList.remove('is-invalid'); return; }

                            const parsed = typedDate(raw);
                            if (parsed) {
                                fp.altInput.classList.remove('is-invalid');
                                fp.jumpToDate(parsed);
                            } else {
                                // Say it while the caret is still in the box, not
                                // after the operator has moved on and the value has
                                // quietly reverted to the old date.
                                fp.altInput.classList.add('is-invalid');
                            }
                        });
                        fp.altInput.addEventListener('blur', function () {
                            if (typedDate(fp.altInput.value.trim())) fp.altInput.classList.remove('is-invalid');
                        });
                    },
                });
            });
            root.querySelectorAll('input[type=time]').forEach(function (el) {
                if (el._flatpickr) return;
                window.flatpickr(el, {
                    enableTime: true, noCalendar: true, dateFormat: 'H:i',
                    altInput: true, altFormat: 'h:i K', time_24hr: false,
                    // EVENT-FORM-KEYBOARD-3: typeable again. The lock existed to
                    // keep arbitrary letters out of the canonical H:i value; the
                    // parser above refuses them instead, which keeps the value
                    // safe AND lets the operator type. The clock, the AM/PM
                    // toggle and the house presets all still work.
                    allowInput: true, disableMobile: true, minuteIncrement: 15,
                    clickOpens: true,
                    parseDate: typedTime,
                    onReady: function (dates, value, instance) {
                        if (! instance.altInput) return;
                        instance.altInput.autocomplete = 'off';
                        instance.altInput.setAttribute('placeholder', '22:00 · 10pm · 7');
                        restrictTyping(instance.altInput, timeAllows, timeShape);
                        instance.altInput.setAttribute('aria-label', 'Service time — type or pick');
                        instance.altInput.addEventListener('focus', function () { instance.altInput.select(); });
                        instance.altInput.addEventListener('input', function () {
                            const raw = instance.altInput.value.trim();
                            instance.altInput.classList.toggle('is-invalid', !! raw && ! typedTime(raw));
                        });
                        instance.altInput.addEventListener('keydown', function (e) {
                            if (e.key !== 'Enter') return;
                            e.preventDefault();
                            const parsed = typedTime(instance.altInput.value);
                            if (parsed) instance.setDate(parsed, true);
                            instance.close();
                        });
                        instance.altInput.addEventListener('blur', function () {
                            if (typedTime(instance.altInput.value.trim())) instance.altInput.classList.remove('is-invalid');
                        });
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
