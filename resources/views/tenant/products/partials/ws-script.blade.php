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
            // A cost block shows material boxes only when it is a material.
            var type = row.querySelector('[data-f="type"]');
            if (type) {
                var mat = type.value === 'material';
                row.querySelectorAll('[data-only-material]').forEach(function (el) { el.hidden = !mat; });
                row.querySelectorAll('[data-only-charge]').forEach(function (el) { el.hidden = mat; });
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
                var price = row.dataset.override !== undefined && row.dataset.overrideItem === o.value ? parseFloat(row.dataset.override) : parseFloat(o.dataset.price || '0');
                var amount = qty * price;
                cell.textContent = money(amount);
                sum += amount; any = true;
            });
            total.textContent = any ? money(sum) : '—';
        }
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
        ed.addEventListener('change', function () { sync(ed); });
        sync(ed);
    });

    form.addEventListener('change', showSections);
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
