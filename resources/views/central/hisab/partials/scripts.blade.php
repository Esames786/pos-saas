{{-- MASTER-HISAB-1 — safhe ka JS: entry popup (Add = Edit), hatana, owners/khate, table aur graphs. --}}
@verbatim
<script>
(function () {
    'use strict';
    var H = window.HISAB || {};
    var nf = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
    function money(v) { return 'Rs ' + nf.format(Math.round((Number(v) || 0) * 100) / 100); }
    function num(v) { var n = parseFloat(v); return isFinite(n) ? n : 0; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function $1(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function accountName(id) { var a = (H.accounts || []).find(function (x) { return String(x.id) === String(id); }); return a ? a.name : '—'; }
    function catLabel(type, key) { var c = (H.categories[type] || {})[key]; return c ? c.icon + ' ' + c.label : key; }

    function send(method, url, body) {
        return fetch(url, {
            method: method,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': H.csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : null,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) { return { status: r.status, data: data }; });
        });
    }
    function errorList(data) {
        var errs = (data && data.errors) ? Object.keys(data.errors).map(function (k) { return [].concat(data.errors[k]).join(' '); }) : [];
        if (!errs.length) { errs = [(data && data.message) || 'Kuch ghalat ho gaya — dobara koshish karein.']; }
        return '<ul class="mb-0 ps-3">' + errs.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>';
    }
    function done(message, month) {
        var go = function () {
            var u = new URL(H.urls.page, window.location.origin);
            u.searchParams.set('month', month || H.month);
            var acc = new URL(window.location.href).searchParams.get('account');
            if (acc) { u.searchParams.set('account', acc); }
            window.location.href = u.toString();
        };
        if (window.Swal) {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: message, timer: 1600, showConfirmButton: false }).then(go);
        } else { go(); }
    }

    /* ── Entry popup ─────────────────────────────────────────────────────────────── */
    var modalEl = $1('#hsEntryModal');
    var form = $1('#hsEntryForm');
    var modal = modalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
    var linesBox = form ? $1('[data-hs-lines]', form) : null;
    var editing = null;

    function type() { var r = form ? $1('input[name="type"]:checked', form) : null; return r ? r.value : 'in'; }
    function accountOptions(selected) {
        return (H.accounts || []).map(function (a) {
            return '<option value="' + a.id + '"' + (String(a.id) === String(selected) ? ' selected' : '') + '>' + esc(a.name) + '</option>';
        }).join('');
    }
    function categoryOptions(t, selected) {
        var list = H.categories[t] || {};
        return '<option value="">— chunein —</option>' + Object.keys(list).map(function (k) {
            return '<option value="' + k + '"' + (k === selected ? ' selected' : '') + '>' + list[k].icon + ' ' + esc(list[k].label) + '</option>';
        }).join('');
    }
    function addLine(line) {
        var t = type();
        line = line || {};
        var row = document.createElement('div');
        row.className = 'hs-line mb-2';
        row.setAttribute('data-hs-line', '');
        var defAccount = line.account_id || (H.accounts[0] && H.accounts[0].id);
        row.innerHTML =
            '<div><label class="form-label small mb-1">' + (t === 'in' ? 'Kis cheez ka' : 'Kis cheez par') + '</label>' +
            '<select class="form-select" data-f="category">' + categoryOptions(t, line.category || '') + '</select></div>' +
            '<div><label class="form-label small mb-1">Raqam (Rs)</label>' +
            '<input type="number" step="0.01" min="0" class="form-control" data-f="amount" value="' + (line.amount != null ? line.amount : '') + '"></div>' +
            '<div><label class="form-label small mb-1">' + (t === 'in' ? 'Kis ke khate mein aaya' : 'Kis ke khate se gaya') + '</label>' +
            '<select class="form-select" data-f="account_id">' + accountOptions(defAccount) + '</select></div>' +
            '<div><button type="button" class="btn btn-outline-danger w-100" data-hs-remove-line aria-label="Ye hissa hatayein"><i class="ti ti-x"></i></button></div>';
        linesBox.appendChild(row);
        refresh();
    }
    function lines() {
        return $all('[data-hs-line]', linesBox).map(function (row) {
            return {
                category: $1('[data-f="category"]', row).value,
                amount: $1('[data-f="amount"]', row).value,
                account_id: $1('[data-f="account_id"]', row).value
            };
        });
    }
    function isCard() { var l = lines(); return type() === 'out' && l.length > 0 && l[0].category === 'whatsapp_meta'; }

    function setType(t, keepLines) {
        var radio = $1('input[name="type"][value="' + t + '"]', form);
        if (radio) { radio.checked = true; }
        $all('[data-hs-only]', form).forEach(function (el) {
            el.classList.toggle('d-none', el.getAttribute('data-hs-only').split(' ').indexOf(t) === -1);
        });
        $1('[data-hs-lines-title]', form).textContent = t === 'in' ? 'Kitna aaya, kis cheez ka, kis ke khate mein' : 'Kitna gaya, kis cheez par, kis ke khate se';
        $1('[data-hs-lines-help]', form).textContent = t === 'in'
            ? 'Ek raqam do hisson mein ho to "Ek aur hissa" — jaise mahana fee + WhatsApp, ya do owners ke khate.'
            : 'WhatsApp (Meta) chunein to card wale bill ke box khul jayenge.';
        if (!keepLines && t !== 'transfer') { linesBox.innerHTML = ''; addLine(); }
        refresh();
    }

    function refresh() {
        if (!form) { return; }
        var t = type();
        var card = isCard();
        var cardBox = $1('[data-hs-card]', form);
        cardBox.classList.toggle('d-none', !card);
        $1('[data-hs-add-line]', form).classList.toggle('d-none', card);
        var rows = $all('[data-hs-line]', linesBox);
        rows.forEach(function (row, i) {
            var amount = $1('[data-f="amount"]', row);
            amount.readOnly = card && i === 0;
            $1('[data-hs-remove-line]', row).classList.toggle('invisible', rows.length === 1);
            if (card && i > 0) { row.remove(); }
        });

        if (card) {
            var usd = num($1('#hsUsd').value), bill = num($1('#hsBill').value), fee = num($1('#hsFee').value), msgs = num($1('#hsMsgs').value);
            var total = Math.round((bill + fee) * 100) / 100;
            var first = $1('[data-hs-line] [data-f="amount"]', linesBox);
            if (first) { first.value = total ? total.toFixed(2) : ''; }
            var bits = ['Kul: <strong>' + money(total) + '</strong>'];
            if (usd > 0 && bill > 0) { bits.push('Dollar rate: <strong>' + (bill / usd).toFixed(2) + '</strong>'); }
            if (bill > 0) { bits.push('Tax: <strong>' + (fee / bill * 100).toFixed(2) + '%</strong>'); }
            if (msgs > 0 && total > 0) { bits.push('Fi message: <strong>' + money(total / msgs) + '</strong>'); }
            $1('[data-hs-card-calc]', form).innerHTML = bits.join(' · ');
        }

        // "Kya hoga" — save se pehle saaf jumla.
        var text = '—';
        if (t === 'transfer') {
            var from = $1('#hsFrom').value, to = $1('#hsTo').value, amt = num($1('#hsTransferAmount').value);
            if (amt > 0 && from && to) {
                text = from === to ? '⚠️ Dono khate ek hi hain.' :
                    '🔁 <strong>' + esc(accountName(from)) + '</strong> se <strong>' + esc(accountName(to)) + '</strong> mein <strong>' + money(amt) + '</strong> jayenge. Nafa nahi badlega, sirf barabari.';
            }
        } else {
            var byAccount = {}, parts = [];
            lines().forEach(function (l) {
                var a = num(l.amount);
                if (a <= 0 || !l.account_id) { return; }
                byAccount[l.account_id] = (byAccount[l.account_id] || 0) + a;
                if (l.category) { parts.push(catLabel(t, l.category) + ' ' + nf.format(a)); }
            });
            var keys = Object.keys(byAccount);
            if (keys.length) {
                text = keys.map(function (id) {
                    return (t === 'in' ? '💰 <strong>' : '🧾 <strong>') + esc(accountName(id)) + '</strong> ke khate ' + (t === 'in' ? 'mein ' : 'se ') +
                        '<strong>' + money(byAccount[id]) + '</strong> ' + (t === 'in' ? 'aayenge' : 'jayenge');
                }).join('<br>') + (parts.length ? '<div class="text-muted small mt-1">' + esc(parts.join(' + ')) + '</div>' : '');
            }
        }
        $1('[data-hs-summary]', form).innerHTML = text;
    }

    function defaultDate() {
        return (H.today && H.today.slice(0, 7) === H.month) ? H.today : H.month + '-01';
    }
    function openEntry(t, data) {
        editing = data && data.id ? data : null;
        form.reset();
        $1('[data-hs-errors]', form).classList.add('d-none');
        $1('input[name="_id"]', form).value = editing ? editing.id : '';
        $1('input[name="_was"]', form).value = editing ? editing._was : '';
        $1('#hsEntryTitle').textContent = editing ? editing.entry_no + ' badlein' : 'Nayi entry';
        $1('#hsDate').value = (data && data.entry_date) || defaultDate();
        $1('#hsTenant').value = (data && data.tenant_id) || '';
        $1('#hsNote').value = (data && data.note) || '';
        $1('#hsFrom').innerHTML = accountOptions(null);
        $1('#hsTo').innerHTML = accountOptions(null);
        linesBox.innerHTML = '';
        setType(t, true);
        if (t === 'transfer') {
            var l0 = (data && data.lines && data.lines[0]) || {};
            $1('#hsFrom').value = l0.account_id || data && data.from || (H.accounts[0] && H.accounts[0].id) || '';
            $1('#hsTo').value = l0.to_account_id || data && data.to || (H.accounts[1] && H.accounts[1].id) || '';
            $1('#hsTransferAmount').value = l0.amount || (data && data.amount) || '';
        } else if (data && data.lines && data.lines.length) {
            data.lines.forEach(function (l) { addLine(l); });
        } else {
            addLine();
        }
        $1('#hsUsd').value = (data && data.usd_amount) || '';
        $1('#hsBill').value = (data && data.bill_amount) || '';
        $1('#hsFee').value = (data && data.bank_fee != null) ? data.bank_fee : '';
        $1('#hsMsgs').value = (data && data.message_count) || '';
        refresh();
        modal.show();
    }

    if (form) {
        $all('input[name="type"]', form).forEach(function (r) {
            r.addEventListener('change', function () { setType(r.value, false); });
        });
        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);
        $1('[data-hs-add-line]', form).addEventListener('click', function () { addLine(); });
        linesBox.addEventListener('click', function (e) {
            var b = e.target.closest('[data-hs-remove-line]');
            if (b) { b.closest('[data-hs-line]').remove(); refresh(); }
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var t = type();
            var payload = {
                type: t,
                entry_date: $1('#hsDate').value,
                tenant_id: t === 'in' ? ($1('#hsTenant').value || null) : null,
                note: $1('#hsNote').value,
                _was: $1('input[name="_was"]', form).value || null
            };
            if (t === 'transfer') {
                payload.lines = [{ account_id: $1('#hsFrom').value, to_account_id: $1('#hsTo').value, category: 'transfer', amount: $1('#hsTransferAmount').value }];
            } else {
                payload.lines = lines();
                if (isCard()) {
                    payload.usd_amount = $1('#hsUsd').value || null;
                    payload.bill_amount = $1('#hsBill').value || null;
                    payload.bank_fee = $1('#hsFee').value || null;
                    payload.message_count = $1('#hsMsgs').value || null;
                }
            }
            var id = $1('input[name="_id"]', form).value;
            var btn = $1('[data-hs-save]', form);
            btn.disabled = true;
            send(id ? 'PUT' : 'POST', id ? H.urls.entry + '/' + id : H.urls.store, payload).then(function (res) {
                btn.disabled = false;
                if (res.status >= 200 && res.status < 300 && res.data.ok) {
                    modal.hide();
                    done(res.data.message, res.data.month);
                    return;
                }
                var box = $1('[data-hs-errors]', form);
                box.innerHTML = errorList(res.data);
                box.classList.remove('d-none');
                box.scrollIntoView({ block: 'nearest' });
            }).catch(function () {
                btn.disabled = false;
                var box = $1('[data-hs-errors]', form);
                box.innerHTML = errorList(null);
                box.classList.remove('d-none');
            });
        });
    }

    $all('[data-hs-new]').forEach(function (b) {
        b.addEventListener('click', function () { openEntry(b.getAttribute('data-hs-new'), null); });
    });
    $all('[data-hs-settle]').forEach(function (b) {
        b.addEventListener('click', function () {
            var s = JSON.parse(b.getAttribute('data-hs-settle'));
            openEntry('transfer', { from: s.from, to: s.to, amount: s.amount, note: s.note });
        });
    });
    document.addEventListener('click', function (e) {
        var edit = e.target.closest('[data-hs-edit]');
        if (edit) {
            var data = (H.entries || {})[edit.getAttribute('data-hs-edit')];
            if (data) { openEntry(data.type, data); }
            return;
        }
        var del = e.target.closest('[data-hs-delete]');
        if (del && window.Swal) {
            var id = del.getAttribute('data-hs-delete');
            Swal.fire({
                title: del.getAttribute('data-hs-no') + ' hatayein?',
                text: 'Entry hisab se nikal jayegi, magar record (wajah ke saath) rahega.',
                input: 'text',
                inputLabel: 'Hatane ki wajah',
                inputPlaceholder: 'Jaise: ghalti se do dafa daali',
                showCancelButton: true,
                confirmButtonText: 'Haan, hatayein',
                cancelButtonText: 'Wapas',
                confirmButtonColor: '#c42b2b',
                inputValidator: function (v) { return v && v.trim() ? null : 'Wajah likhein.'; },
                preConfirm: function (reason) {
                    return send('DELETE', H.urls.entry + '/' + id, { reason: reason }).then(function (res) {
                        if (!(res.status >= 200 && res.status < 300 && res.data.ok)) {
                            Swal.showValidationMessage((res.data && res.data.message) || 'Hata nahi saka.');
                            return false;
                        }
                        return res.data;
                    });
                }
            }).then(function (r) { if (r.isConfirmed && r.value) { done(r.value.message, H.month); } });
        }
    });

    /* ── Owners aur khate ────────────────────────────────────────────────────────── */
    var setup = $1('#hsSetupForm');
    if (setup) {
        var pBox = $1('[data-hs-partners]', setup), aBox = $1('[data-hs-accounts]', setup);
        var newKey = 0;
        var partnerRow = function (p) {
            var row = document.createElement('div');
            row.className = 'row g-2 align-items-end mb-2';
            row.setAttribute('data-hs-p', p.key);
            row.innerHTML =
                '<div class="col-6 col-md-6"><label class="form-label small mb-1">Naam</label><input class="form-control" data-f="name" value="' + esc(p.name || '') + '" maxlength="100"></div>' +
                '<div class="col-3 col-md-3"><label class="form-label small mb-1">Hissa %</label><input type="number" step="0.01" min="0" max="100" class="form-control" data-f="share_percent" value="' + (p.share_percent != null ? p.share_percent : '') + '"></div>' +
                '<div class="col-3 col-md-3"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" data-f="is_active"' + (p.is_active !== false ? ' checked' : '') + ' id="hsP' + p.key + '"><label class="form-check-label small" for="hsP' + p.key + '">Active</label></div></div>';
            row.dataset.id = p.id || '';
            pBox.appendChild(row);
        };
        var partnerChoices = function (selected) {
            var opts = '<option value="">🏢 Company ka apna khata</option>';
            $all('[data-hs-p]', pBox).forEach(function (r) {
                var name = $1('[data-f="name"]', r).value || '(naam?)';
                var key = r.getAttribute('data-hs-p');
                opts += '<option value="' + key + '"' + (key === selected ? ' selected' : '') + '>👤 ' + esc(name) + '</option>';
            });
            return opts;
        };
        var accountRow = function (a) {
            var row = document.createElement('div');
            row.className = 'row g-2 align-items-end mb-2';
            row.setAttribute('data-hs-a', '');
            row.dataset.id = a.id || '';
            row.dataset.partner = a.partner_key || '';
            row.innerHTML =
                '<div class="col-12 col-md-5"><label class="form-label small mb-1">Khate ka naam</label><input class="form-control" data-f="name" value="' + esc(a.name || '') + '" maxlength="100" placeholder="Jaise: Mohsin ka account"></div>' +
                '<div class="col-8 col-md-4"><label class="form-label small mb-1">Kis ka</label><select class="form-select" data-f="partner_key"></select></div>' +
                '<div class="col-4 col-md-3"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" data-f="is_active"' + (a.is_active !== false ? ' checked' : '') + ' id="hsA' + (a.id || ('n' + (++newKey))) + '"><label class="form-check-label small" for="hsA' + (a.id || ('n' + newKey)) + '">Active</label></div>' +
                (a.balance ? '<div class="small text-muted">' + money(a.balance) + '</div>' : '') + '</div>';
            aBox.appendChild(row);
        };
        var syncChoices = function () {
            $all('[data-hs-a]', aBox).forEach(function (r) {
                var sel = $1('[data-f="partner_key"]', r);
                var cur = sel.options.length ? sel.value : r.dataset.partner;
                sel.innerHTML = partnerChoices(cur);
            });
            var total = 0;
            $all('[data-hs-p]', pBox).forEach(function (r) { if ($1('[data-f="is_active"]', r).checked) { total += num($1('[data-f="share_percent"]', r).value); } });
            var t = $1('[data-hs-share-total]', setup);
            t.innerHTML = 'Abhi: <strong class="' + (Math.abs(total - 100) < 0.001 ? 'hs-ok' : 'hs-bad') + '">' + nf.format(total) + '%</strong>';
        };
        JSON.parse(setup.getAttribute('data-partners') || '[]').forEach(partnerRow);
        JSON.parse(setup.getAttribute('data-accounts') || '[]').forEach(accountRow);
        syncChoices();
        setup.addEventListener('input', syncChoices);
        $1('[data-hs-add-partner]', setup).addEventListener('click', function () {
            partnerRow({ key: 'n' + (++newKey), name: '', share_percent: '', is_active: true });
            syncChoices();
        });
        $1('[data-hs-add-account]', setup).addEventListener('click', function () {
            accountRow({ name: '', partner_key: '', is_active: true });
            syncChoices();
        });
        setup.addEventListener('submit', function (e) {
            e.preventDefault();
            var body = {
                partners: $all('[data-hs-p]', pBox).map(function (r) {
                    return { key: r.getAttribute('data-hs-p'), id: r.dataset.id || null, name: $1('[data-f="name"]', r).value,
                        share_percent: $1('[data-f="share_percent"]', r).value, is_active: $1('[data-f="is_active"]', r).checked };
                }),
                accounts: $all('[data-hs-a]', aBox).map(function (r) {
                    return { id: r.dataset.id || null, name: $1('[data-f="name"]', r).value,
                        partner_key: $1('[data-f="partner_key"]', r).value || null, is_active: $1('[data-f="is_active"]', r).checked };
                })
            };
            send('PUT', H.urls.setup, body).then(function (res) {
                if (res.status >= 200 && res.status < 300 && res.data.ok) {
                    bootstrap.Modal.getOrCreateInstance($1('#hsSetupModal')).hide();
                    done(res.data.message, H.month);
                    return;
                }
                var box = $1('[data-hs-setup-errors]', setup);
                box.innerHTML = errorList(res.data);
                box.classList.remove('d-none');
            });
        });
    }

    /* ── Table ───────────────────────────────────────────────────────────────────── */
    if (window.jQuery && jQuery.fn.DataTable && document.getElementById('hsEntries')) {
        jQuery('#hsEntries').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100],
            columnDefs: [{ targets: 'no-sort', orderable: false }],
            language: {
                search: 'Dhoondein:', lengthMenu: '_MENU_ entries', info: '_START_–_END_ / _TOTAL_',
                infoEmpty: 'Koi entry nahi', infoFiltered: '(kul _MAX_ mein se)', zeroRecords: 'Kuch nahi mila',
                emptyTable: 'Is mahine koi entry nahi — upar "Paisa aaya" ya "Kharcha" dabayein.',
                paginate: { previous: '‹', next: '›' }
            }
        });
    }

    /* ── Graphs (rang Master ke dark card #102038 par jaanche gaye) ───────────────── */
    if (!window.ApexCharts) { return; }
    var C = { income: '#3987e5', expense: '#d95926', profit: '#199e70', surface: '#102038', grid: '#1b3254', ink: '#a8b8d0' };
    var short = function (v) {
        var a = Math.abs(v);
        if (a >= 100000) { return (v / 100000).toFixed(a >= 1000000 ? 0 : 1).replace(/\.0$/, '') + 'L'; }
        if (a >= 1000) { return (v / 1000).toFixed(0) + 'k'; }
        return String(Math.round(v));
    };
    var base = function (extra) {
        return Object.assign({
            chart: { background: 'transparent', foreColor: C.ink, toolbar: { show: false }, fontFamily: 'inherit', animations: { enabled: false } },
            theme: { mode: 'dark' },
            grid: { borderColor: C.grid, strokeDashArray: 0 },
            dataLabels: { enabled: false },
            tooltip: { theme: 'dark', y: { formatter: function (v) { return money(v); } } }
        }, extra);
    };
    var trendEl = document.getElementById('hsTrend');
    if (trendEl && (H.trend || []).length) {
        new ApexCharts(trendEl, base({
            chart: Object.assign(base({}).chart, { type: 'line', height: 290 }),
            series: [
                { name: 'Aamdani', type: 'column', data: H.trend.map(function (t) { return t.income; }) },
                { name: 'Kharcha', type: 'column', data: H.trend.map(function (t) { return t.expense; }) },
                { name: 'Nafa', type: 'line', data: H.trend.map(function (t) { return t.profit; }) }
            ],
            colors: [C.income, C.expense, C.profit],
            stroke: { width: [2, 2, 2], colors: [C.surface, C.surface, C.profit] },
            markers: { size: [0, 0, 4], strokeColors: C.surface, strokeWidth: 2 },
            plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end' } },
            xaxis: { categories: H.trend.map(function (t) { return t.label; }), axisBorder: { color: C.grid }, axisTicks: { color: C.grid } },
            yaxis: { labels: { formatter: short } },
            legend: { position: 'top', horizontalAlign: 'left', labels: { colors: C.ink } }
        })).render();
    }
    // Axis ki aakhri raqam gol (40k, 2L…), taake chaar barabar qadam saaf parhe jayein.
    var roundUp = function (v) {
        if (!(v > 0)) { return 4; }
        var raw = v / 4, mag = Math.pow(10, Math.floor(Math.log10(raw)));
        var nice = [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find(function (n) { return n * mag >= raw; });
        return nice * mag * 4;
    };
    var hbar = function (el, rows, color, name) {
        if (!el || !rows.length) { return; }
        new ApexCharts(el, base({
            chart: Object.assign(base({}).chart, { type: 'bar', height: Math.max(220, 70 + rows.length * 46) }),
            series: [{ name: name, data: rows.map(function (r) { return r.amount; }) }],
            colors: [color],
            stroke: { width: 2, colors: [C.surface] },
            // Raqam bar ke aage (andar nahi) — chhoti bar par bhi parhi jaye; is liye aage thori jagah.
            plotOptions: { bar: { horizontal: true, barHeight: '60%', borderRadius: 4, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
            dataLabels: { enabled: true, formatter: function (v) { return short(v); }, offsetX: 30, style: { colors: ['#e7eef8'], fontWeight: 600 } },
            xaxis: { categories: rows.map(function (r) { return r.name || r.label; }), labels: { formatter: short }, axisBorder: { color: C.grid },
                min: 0, max: roundUp(Math.max.apply(null, rows.map(function (r) { return r.amount; })) * 1.3), tickAmount: 4 },
            legend: { show: false }
        })).render();
    };
    hbar(document.getElementById('hsClients'), H.clients || [], C.income, 'Aamdani');
    hbar(document.getElementById('hsExpenses'), H.expenses || [], C.expense, 'Kharcha');
})();
</script>
@endverbatim
