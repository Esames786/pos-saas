// W3/W4 S3c — Recipe, Bill of materials and Event costing inside the form. Rows added or removed here are posted with
// the product and saved with it in one go; the server checks everything again and decides what is written. Nothing
// here leaves the page, so whatever was typed on the other tabs stays.
(function () {
    var form = document.getElementById('pv2-form');
    if (!form) return;
    var seq = 0;

    function answer(name) {
        var r = form.querySelector('input[name="' + name + '"]:checked') || form.querySelector('input[type="hidden"][name="' + name + '"]');
        return !!r && r.value === '1';
    }
    // Which section is open follows the chosen card (and, for a recipe, the "made from other items?" answer).
    function showSections() {
        var c = window.pv2Card ? window.pv2Card() : null;
        if (!c) return;
        form.querySelectorAll('[data-ws]').forEach(function (box) {
            var k = box.dataset.ws;
            var on = k === 'recipe' ? (!!c.ask.recipe && answer('_recipe')) : (k === 'bom' ? c.mfg === 'finished' : c.kind === 'sale_item');
            var a = box.querySelector('[data-ws-on]'), b = box.querySelector('[data-ws-off]');
            if (a) a.hidden = !on;
            if (b) b.hidden = on;
        });
    }

    function money(n) { return 'Rs ' + (Math.round(n * 100) / 100).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    function sync(ed) {
        var rows = Array.prototype.slice.call(ed.querySelectorAll('[data-ws-rows] > [data-ws-row]'));
        var empty = ed.querySelector('[data-ws-empty]');
        if (empty) empty.hidden = rows.length > 0;
        rows.forEach(function (row) {
            // "its own unit" names the chosen item's unit.
            var item = row.querySelector('[data-f="item"]'), own = row.querySelector('option[data-own]');
            if (item && own) {
                var o = item.options[item.selectedIndex];
                own.textContent = o && o.dataset.unit ? 'its own unit (' + o.dataset.unit + ')' : 'its own unit';
            }
            // A cost block shows material boxes only when it is a material (what it is = two radios, COST-BLOCK-CLARITY-1).
            if (row.querySelector('[data-f="type"]')) {
                var mat = isMaterial(row);
                row.querySelectorAll('[data-only-material]').forEach(function (el) { el.hidden = !mat; });
                row.querySelectorAll('[data-only-charge]').forEach(function (el) { el.hidden = mat; });
                var source = row.querySelector('[data-f="rate-source"]'), rate = row.querySelector('[data-f="rate"]');
                var followsBook = mat && source && source.value === 'commercial_book';
                if (rate) rate.readOnly = !!followsBook;
                var note = row.querySelector('[data-book-note]');
                if (note) note.hidden = !followsBook;
            }
        });
        // The recipe's cost, worked out as the Recipes screen does: purchase price ÷ pack size, × quantity.
        var total = ed.querySelector('[data-ws-cost]');
        if (ed.dataset.wsEditor === 'recipe' && total) {
            var sum = 0, any = false;
            rows.forEach(function (row) {
                var item = row.querySelector('[data-f="item"]'), qty = parseFloat(row.querySelector('[data-f="qty"]').value);
                var cell = row.querySelector('[data-f="cost"]'), o = item.options[item.selectedIndex];
                if (!o || !o.value || isNaN(qty)) { cell.textContent = '—'; return; }
                var override = row.querySelector('[data-f="override"]');
                var price = override && override.value !== '' ? parseFloat(override.value) / (parseFloat(o.dataset.pack) || 1) : parseFloat(o.dataset.price || '0');
                var amount = qty * price;
                cell.textContent = money(amount);
                sum += amount; any = true;
            });
            var overhead = parseFloat(ed.querySelector('[name="recipe[overhead_percent]"]').value) || 0;
            total.textContent = any ? money(sum * (1 + overhead / 100)) : '—';
        }
        if (ed.dataset.wsEditor === 'cost_blocks') blockSample(ed, rows);
    }

    // ── COST-BLOCK-CLARITY-1 (5 Nov): live words, what each block adds to one unit of the dish, and a booking worked out
    // the way the event screen does (CateringCostBlockService::priceLine → CateringProductCostBlock::amountFor):
    // once per order = the rate, once; per unit of the material = rate × (how much per unit × quantity, to 4 places);
    // anything else = rate × quantity; each amount to 2 places. The dish rate leaves the once-per-order charges out.
    function isMaterial(row) { var t = row.querySelector('[data-f="type"]:checked'); return !!t && t.value === 'material'; }
    function num(el) { var v = el ? parseFloat(el.value) : NaN; return isNaN(v) ? 0 : v; }
    function round(n, places) { var f = Math.pow(10, places); return Math.round((n + Number.EPSILON) * f) / f; }
    function plain(n) { return round(n, 4).toLocaleString('en-PK', { maximumFractionDigits: 4 }); }
    function picked(sel) { var o = sel ? sel.options[sel.selectedIndex] : null; return o && o.value ? o : null; }
    function unitOf(row) {
        var u = picked(row.querySelector('[data-f="unit"]')), m = picked(row.querySelector('[data-f="mat"]'));
        return u ? u.textContent.trim() : (m && m.dataset.unit ? m.dataset.unit : 'unit');
    }
    function blockSample(ed, rows) {
        var plate = ed.dataset.plate || 'unit', qtyBox = ed.querySelector('[data-cb-sample-qty]'), Q = Math.max(0, num(qtyBox));
        var lines = [], warn = [], perUnit = 0, total = 0, once = 0, makings = 0;
        rows.forEach(function (row, i) {
            var mat = isMaterial(row), m = picked(row.querySelector('[data-f="mat"]')), unit = unitOf(row);
            var label = (row.querySelector('[data-f="label"]').value || '').trim() || (m ? m.textContent.trim() : 'Block ' + (i + 1));
            var rateBox = row.querySelector('[data-f="rate"]'), rate = num(rateBox), ratio = num(row.querySelector('[data-f="qty"]'));
            var lump = row.querySelector('[data-f="basis"]').value === 'lump_sum';
            var basis = row.querySelector('[data-f="rate-basis"]'), perMat = mat && basis.value === 'per_material_unit';
            // The words follow what was chosen: the box stays short ("per KG of material"), the line under the rate says it
            // in full ("Rs 200.00 for 1 KG of Chicken", "Rs 500.00 for every 1 KG of the dish", "Rs 50.00 once for the whole order").
            var matOpt = basis.querySelector('option[data-per="material"]');
            if (matOpt) matOpt.textContent = 'per ' + unit + ' of material';
            var words = row.querySelector('[data-f="rate-words"]');
            if (words) words.textContent = money(rate) + (lump ? ' once for the whole order'
                : perMat ? ' for 1 ' + unit + ' of ' + (m ? m.textContent.trim() : 'the material')
                : ' for every 1 ' + plate + ' of the dish');

            var amount, adds, how;
            if (lump) {
                amount = round(rate, 2); adds = 'once ' + money(amount); how = 'once per order'; once += amount;
            } else if (perMat) {
                var need = round(ratio * Q, 4), part = round(ratio * rate, 4);
                amount = round(need * rate, 2); adds = money(part); perUnit += part;
                how = plain(ratio) + ' × ' + plain(Q) + ' = ' + plain(need) + ' ' + unit + ' × ' + money(rate);
            } else {
                amount = round(rate * Q, 2); adds = money(rate); perUnit += rate;
                how = money(rate) + ' × ' + plain(Q) + ' ' + plate;
            }
            total += amount;
            row.querySelector('[data-f="adds"]').textContent = adds;
            lines.push([label, how, amount]);

            if (rateBox.value === '' || rate === 0) warn.push(label + ': the rate is 0 — the booking will show Rs 0 for it.');
            if (mat && !m) warn.push(label + ': choose the material.');
            if (perMat && !lump && ratio === 0) warn.push(label + ': how much ' + (m ? m.textContent.trim() : 'material') + ' goes into 1 ' + plate + '? Empty means Rs 0.');
            if (!mat && row.querySelector('[data-f="role"][value="making"]:checked')) makings++;
        });
        if (makings > 1) warn.push('Only one Making charge is allowed per dish — ' + makings + ' are marked Yes.');

        var body = ed.querySelector('[data-cb-sample-lines]'), tot = ed.querySelector('[data-cb-sample-total]'), list = ed.querySelector('[data-cb-warn]');
        if (!body) return;
        body.textContent = '';
        lines.forEach(function (l) {
            var tr = document.createElement('tr');
            [l[0], l[1], '= ' + money(l[2])].forEach(function (t, j) {
                var td = document.createElement('td');
                td.textContent = t;
                if (j === 2) td.className = 'cb-amt';
                tr.appendChild(td);
            });
            body.appendChild(tr);
        });
        tot.textContent = '';
        function part(label, value) {
            var s = document.createElement('span'), b = document.createElement('strong');
            s.textContent = label + ' ';
            b.textContent = value;
            s.appendChild(b);
            tot.appendChild(s);
        }
        if (!rows.length) {
            tot.textContent = 'Add a cost block to see the sample.';
        } else {
            part('Rate for 1 ' + plate + ':', money(round(perUnit, 2)));
            part('Total for ' + plain(Q) + ' ' + plate + ':', money(round(total, 2)));
            if (once) part('of which once per order:', money(round(once, 2)));
        }
        list.textContent = '';
        warn.forEach(function (w) { var li = document.createElement('li'); li.textContent = w; list.appendChild(li); });
    }

    form.querySelectorAll('[data-ws-editor]').forEach(function (ed) {
        var body = ed.querySelector('[data-ws-rows]'), tpl = ed.querySelector('template[data-ws-template]'), add = ed.querySelector('[data-ws-add]');
        add.addEventListener('click', function () {
            // A new row gets a key no saved row has (saved rows are keyed by their position).
            body.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__K__/g, 'n' + Date.now().toString(36) + (seq++)));
            var row = body.lastElementChild, first = row.querySelector('select, input:not([type="hidden"])');
            sync(ed);
            form.dispatchEvent(new Event('input'));
            if (first) first.focus();
        });
        ed.addEventListener('click', function (e) {
            var b = e.target.closest('[data-ws-remove]');
            if (!b) return;
            b.closest('[data-ws-row]').remove();
            sync(ed);
            form.dispatchEvent(new Event('input'));
            add.focus();
        });
        ed.addEventListener('input', function () { sync(ed); });
        ed.addEventListener('change', function (e) {
            // An explicit kind change clears only the settings that no longer apply, not hidden saved settings on load.
            if (e.target.matches('[data-f="type"]')) {
                var row = e.target.closest('[data-ws-row]');
                if (e.target.value === 'charge') {
                    row.querySelector('[data-f="rate-source"]').value = 'manual';
                    row.querySelector('[data-f="rate-basis"]').value = 'per_dish_unit';
                } else {
                    var no = row.querySelector('[data-f="role"][value=""]');
                    if (no) no.checked = true;
                    // Back to a material: the basis it was saved with, else a new material's (per unit of the material) —
                    // never the per-dish basis a moment as a charge gave it (COST-BLOCK-CLARITY-1).
                    row.querySelector('[data-f="rate-basis"]').value = row.dataset.savedType === 'material' ? row.dataset.savedBasis : 'per_material_unit';
                }
            }
            // A material chosen with no unit yet gets its own unit.
            if (e.target.matches('[data-f="mat"]')) {
                var u = e.target.closest('[data-ws-row]').querySelector('[data-f="unit"]'), o = picked(e.target);
                if (u && !u.value && o && o.dataset.unitId && u.querySelector('option[value="' + o.dataset.unitId + '"]')) u.value = o.dataset.unitId;
            }
            sync(ed);
        });
        // The sample's quantity is not part of the dish: it is never posted and does not count as an unsaved change.
        var sampleQty = ed.querySelector('[data-cb-sample-qty]');
        if (sampleQty) {
            ['input', 'change'].forEach(function (t) { sampleQty.addEventListener(t, function (e) { e.stopPropagation(); sync(ed); }); });
            sampleQty.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
        }
        sync(ed);
    });

    // COST-BLOCK-CLARITY-1: a "?" opens one small box under it saying what that box means; the same "?", ×, Esc or a
    // click elsewhere closes it (Esc and × send focus back to the "?").
    (function () {
        var pop = form.querySelector('#cb-pop');
        if (!pop) return;
        var host = pop.parentElement, text = pop.querySelector('[data-cb-pop-text]'), open = null;
        function close(back) {
            if (!open) return;
            open.setAttribute('aria-expanded', 'false');
            if (back) open.focus();
            open = null;
            pop.hidden = true;
        }
        form.addEventListener('click', function (e) {
            var b = e.target.closest('.cb-q[data-cb-help]');
            if (b) {
                e.preventDefault();
                if (open === b) { close(true); return; }
                close(false);
                var src = host.querySelector('[data-cb-helps] [data-key="' + b.dataset.cbHelp + '"]');
                text.textContent = src ? src.textContent : '';
                pop.hidden = false;
                open = b;
                b.setAttribute('aria-expanded', 'true');
                var hb = host.getBoundingClientRect(), bb = b.getBoundingClientRect(), w = Math.min(340, hb.width);
                pop.style.width = w + 'px';
                pop.style.top = (bb.bottom - hb.top + 6) + 'px';
                pop.style.left = Math.max(0, Math.min(bb.left - hb.left - 16, hb.width - w)) + 'px';
                return;
            }
            if (e.target.closest('[data-cb-pop-close]')) { close(true); return; }
            if (open && !pop.contains(e.target)) close(false);
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) close(true); });
        host.querySelectorAll('.table-responsive').forEach(function (s) { s.addEventListener('scroll', function () { close(false); }); });
    })();

    form.addEventListener('change', showSections);
    form.addEventListener('focusin', function (e) {
        if (e.target.matches('[data-ws] input[type="number"]') && !e.target.readOnly) e.target.select();
    });
    showSections();

    // The Review tab names each section in a line.
    window.pv2WsReview = function (row) {
        function count(k) { return form.querySelectorAll('[data-ws-editor="' + k + '"] [data-ws-rows] > [data-ws-row]').length; }
        function open(k) { var box = form.querySelector('[data-ws="' + k + '"] [data-ws-on]'); return !!box && !box.hidden; }
        if (open('recipe')) {
            var cost = form.querySelector('[data-ws-editor="recipe"] [data-ws-cost]');
            row('Recipe', count('recipe') ? count('recipe') + ' ingredient' + (count('recipe') === 1 ? '' : 's') + (cost && cost.textContent !== '—' ? ' · ' + cost.textContent : '') : 'No ingredients yet', 'recipe', !count('recipe'));
        }
        if (open('bom')) row('Bill of materials', count('bom') ? count('bom') + ' material' + (count('bom') === 1 ? '' : 's') : 'No materials yet', 'bom');
        if (open('dish')) {
            var m = form.querySelector('input[name="catering[costing_mode]"]:checked');
            row('Event costing', 'Priced by ' + (m && m.value === 'blocks' ? 'cost blocks' : 'recipe') + (form.querySelector('[data-ws-editor="cost_blocks"]') ? ' · ' + count('cost_blocks') + ' cost block' + (count('cost_blocks') === 1 ? '' : 's') : ''), 'catering');
        }
    };
})();
