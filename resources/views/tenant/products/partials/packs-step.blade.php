@php
    /*
     * PHARMACY-PACKS-1 — "4. Packs" on the new product screen (only where Master switched Packs on).
     * Rows form a chain: each pack holds a whole number of the one before it; the first holds the item's own
     * unit ("Sold and counted in"). The server (App\Support\PackUnits) re-checks everything; this script only
     * shows the sums.
     */
    $packRows = old('packs', $savedPacks);
    $packRows = is_array($packRows) ? array_values($packRows) : [];
    $packsYes = (bool) (int) old('_packs', count($savedPacks) ? 1 : 0);
    $baseSell = (bool) (int) old('pack_base_sell', $packBaseSell ? 1 : 0);
    $tick = fn ($row, $k) => array_key_exists($k, $row) ? (bool) (int) $row[$k] : true;
@endphp

@push('styles')
<style>
    .pv2 .pack-list { list-style:none; margin:10px 0 0; padding:0; display:flex; flex-direction:column; gap:10px; }
    .pv2 .pack-row { border:1px solid var(--line); border-radius:12px; padding:12px 14px; background:#FBFCFD; }
    .pv2 .pack-row > fieldset > legend { font-size:.95rem; font-weight:800; color:var(--navy); float:none; width:auto; margin:0 0 6px; padding:0; }
    .pv2 .pack-grid { display:grid; grid-template-columns:minmax(120px,1fr) minmax(150px,1fr) minmax(120px,1fr); gap:10px; align-items:end; }
    .pv2 .pack-grid .pack-bc { grid-column:1 / -1; max-width:560px; }
    .pv2 .pack-grid .form-label { font-size:.85rem; margin-bottom:2px; }
    .pv2 .pack-holds { display:flex; align-items:center; gap:6px; }
    .pv2 .pack-holds input { max-width:90px; }
    .pv2 .pack-holds .of { font-weight:700; color:var(--ink2); white-space:nowrap; }
    .pv2 .pack-flags { display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-top:8px; }
    .pv2 .pack-eq { color:#8A6A12; font-size:.85rem; }
    .pv2 .pack-sum { margin-top:12px; background:var(--navy); color:#fff; border-radius:10px; padding:10px 14px; font-weight:800; }
    .pv2 .pack-sum .ur-inline { color:#F2DFA8; }
    .pv2 .presets { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
    .pv2 .presets button { border:1px dashed #B8902E; background:#fff; color:#6B5310; border-radius:999px; padding:6px 14px; font-weight:700; }
    .pv2 .presets button:focus-visible { outline:3px solid var(--navy); outline-offset:2px; }
    .pv2 .pack-remove { border:0; background:transparent; color:var(--bad); font-weight:700; }
    @media (max-width: 767px) { .pv2 .pack-grid { grid-template-columns:1fr 1fr; } .pv2 .pack-grid .pack-bc { max-width:none; } }
</style>
@endpush

<fieldset class="step" id="packs-step">
    <legend>Packs</legend>

    <div class="q" data-q="packs">
        <div class="q-head">
            <div>
                <span class="q-text" id="q-packs-label">Does it come in packs?</span>
                <span class="help d-block">For example: 1 Box = 10 Strip, 1 Strip = 10 Tablet.</span>
            </div>
            <div class="yn" role="radiogroup" aria-labelledby="q-packs-label">
                <input type="radio" id="q-packs-y" name="_packs" value="1" @checked($packsYes)><label for="q-packs-y">Yes</label>
                <input type="radio" id="q-packs-n" name="_packs" value="0" @checked(! $packsYes)><label for="q-packs-n">No</label>
            </div>
        </div>
        @error('_packs')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror

        <div class="q-body" data-body="packs" @if(! $packsYes) hidden @endif>
            <p class="help mb-1">
                Stock is counted in <b data-base-name>{{ $packBaseName }}</b> — the "Sold and counted in" unit above. A pack is just a bigger amount of it, with its own price and barcode.
            </p>
            <p class="help mb-1" data-part-unit-warn hidden>A kg, litre or metre item: the first pack can hold a part — for example 1 Bag holds 2.5 kg, 1 Bottle holds 1.5 litre. A pack above it holds a whole number of the one before (1 Sack = 10 Bag).</p>

            @if(count($packPresets))
                <div class="presets" role="group" aria-label="Quick start: fill the pack names">
                    @foreach($packPresets as $i => $preset)
                        <button type="button" data-preset="{{ $i }}">{{ $preset['label'] }}</button>
                    @endforeach
                </div>
            @endif

            <ol class="pack-list" id="pack-rows" aria-label="Packs, smallest first">
                @foreach($packRows as $i => $row)
                    @include('tenant.products.partials.pack-row', ['i' => $i, 'row' => $row, 'tick' => $tick])
                @endforeach
            </ol>

            <button type="button" class="btn btn-outline-primary mt-2" id="add-pack">+ Add a bigger pack</button>

            <div class="pack-sum" id="pack-sum" aria-live="polite"></div>

            <div class="q mt-2">
                <div class="q-head">
                    <div><span class="q-text" id="q-loose-label">Do you sell a single <span data-base-name>{{ $packBaseName }}</span> (loose)?</span>
                        </div>
                    <div class="yn" role="radiogroup" aria-labelledby="q-loose-label">
                        <input type="radio" id="loose-y" name="pack_base_sell" value="1" @checked($baseSell)><label for="loose-y">Yes</label>
                        <input type="radio" id="loose-n" name="pack_base_sell" value="0" @checked(! $baseSell)><label for="loose-n">No</label>
                    </div>
                </div>
            </div>

            @if($packStockSays)
                <div class="note mt-2">In stock now: <b>{{ $packStockSays }}</b></div>
            @endif
            <p class="help mt-2 mb-0">Coming next: goods receipts and the counter in packs. Until then they keep working in <span data-base-name>{{ $packBaseName }}</span>.</p>
        </div>
    </div>

    <datalist id="pack-names">
        @foreach(['Strip', 'Box', 'Carton', 'Packet', 'Bottle', 'Crate', 'Dozen', 'Bag', 'Tray', 'Pack'] as $n)<option value="{{ $n }}"></option>@endforeach
    </datalist>
    <template id="pack-row-tpl">@include('tenant.products.partials.pack-row', ['i' => '__I__', 'row' => [], 'tick' => $tick])</template>
</fieldset>

@push('scripts')
<script>
(function () {
    var PRESETS = @json($packPresets);
    var step = document.getElementById('packs-step');
    var form = document.getElementById('pv2-form');
    if (!step || !form) return;
    var list = document.getElementById('pack-rows');
    var tpl = document.getElementById('pack-row-tpl');
    var MAX = {{ \App\Support\PackUnits::MAX_PACKS }};

    function packsYes() { var r = form.querySelector('input[name="_packs"]:checked'); return !!r && r.value === '1'; }
    function baseName() {
        var u = document.getElementById('unit_id');
        if (!u || !u.value) return 'Piece';
        return (u.options[u.selectedIndex].text || 'Piece').split(' — ')[0].trim() || 'Piece';
    }
    function partUnit() {
        var u = document.getElementById('unit_id');
        return !!(u && u.value && /can sell 1\.250/.test(u.options[u.selectedIndex].text));
    }
    function money(n) { return (Math.round(n * 100) / 100).toLocaleString('en-PK', { maximumFractionDigits: 2 }); }
    function rows() { return Array.prototype.slice.call(list.querySelectorAll('.pack-row')); }

    function renumber() {
        rows().forEach(function (li, i) {
            li.querySelectorAll('[name^="packs["]').forEach(function (el) { el.name = el.name.replace(/^packs\[[^\]]*\]/, 'packs[' + i + ']'); });
            li.querySelectorAll('[id*="pack-"]').forEach(function (el) { el.id = el.id.replace(/pack-[^-]+-/, 'pack-' + i + '-'); });
            li.querySelectorAll('label[for*="pack-"]').forEach(function (el) { el.htmlFor = el.htmlFor.replace(/pack-[^-]+-/, 'pack-' + i + '-'); });
            li.querySelectorAll('[aria-describedby*="pack-"]').forEach(function (el) { el.setAttribute('aria-describedby', el.getAttribute('aria-describedby').replace(/pack-[^-]+-/, 'pack-' + i + '-')); });
            var lg = li.querySelector('legend [data-n]'); if (lg) lg.textContent = String(i + 1);
        });
        document.getElementById('add-pack').hidden = rows().length >= MAX;
    }

    function addRow(focus) {
        if (rows().length >= MAX) return null;
        var holder = document.createElement('div');
        holder.innerHTML = tpl.innerHTML.replace(/__I__/g, String(rows().length));
        var li = holder.firstElementChild;
        list.appendChild(li);
        renumber();
        if (focus) li.querySelector('[data-f="name"]').focus();
        refreshPacks();
        return li;
    }

    function refreshPacks() {
        var base = baseName(), price = parseFloat((document.getElementById('default_selling_price') || {}).value) || 0;
        step.querySelectorAll('[data-base-name]').forEach(function (el) { el.textContent = base; });
        step.querySelector('[data-part-unit-warn]').hidden = !(packsYes() && partUnit());
        var factor = 1, prev = base, chain = [], broken = false;
        rows().forEach(function (li) {
            var name = (li.querySelector('[data-f="name"]').value || '').trim();
            var raw = (li.querySelector('[data-f="contains"]').value || '').trim();
            var n = parseFloat(raw);
            li.querySelector('[data-prev]').textContent = prev;
            // UNITS-PACKS: the first pack of a kg / litre / metre item may hold a part (2.5 kg, up to 3 decimals, more than 1);
            // every other pack holds a whole number of 2 or more. A row that breaks this breaks every sum after it.
            var firstPart = partUnit() && li === rows()[0];
            var ok = !broken && (firstPart ? (n > 1 && /^\d+(\.\d{1,3})?$/.test(raw)) : (n >= 2 && /^\d+$/.test(raw)));
            if (ok) factor = Math.round(factor * n * 10000) / 10000; else broken = true;
            var label = name || 'this pack';
            li.querySelector('[data-eq]').textContent = ok ? '1 ' + label + ' = ' + factor.toLocaleString('en-PK') + ' ' + base : '';
            var pr = li.querySelector('[data-f="price"]');
            pr.placeholder = ok && price ? 'Auto: ' + money(factor * price) : 'Auto';
            var rm = li.querySelector('[data-remove]'); if (rm) rm.setAttribute('aria-label', 'Remove pack ' + (name || ''));
            if (ok && name) chain.push({ name: name, factor: factor });
            prev = name || prev;
        });
        var sum = document.getElementById('pack-sum');
        if (!chain.length) { sum.innerHTML = 'Type a name and how many are in it, e.g. Strip · 10.'; }
        else {
            var parts = chain.slice().reverse().map(function (c, i, a) {
                return (i === 0 ? '1 ' : (a[i - 1].factor / c.factor).toLocaleString('en-PK') + ' ') + c.name;
            });
            parts.push(chain[chain.length - 1].factor.toLocaleString('en-PK') + ' ' + base);
            sum.textContent = parts.join(' = ');
        }
        // The single-item barcode question says which barcode it means once packs exist.
        var bcq = document.getElementById('q-bc-label');
        if (bcq) bcq.textContent = packsYes() ? 'Does a single ' + base + ' have its own barcode?' : 'Does it have a barcode?';
        var sp = document.getElementById('sp-help');
        if (sp && packsYes()) sp.firstChild.textContent = 'The price for one ' + base + '. ';
    }

    window.pv2PackWhat = function () {
        if (!packsYes()) return [];
        var base = baseName(), out = [], sum = document.getElementById('pack-sum').textContent;
        if (/=/.test(sum)) out.push('It comes in packs: ' + sum + '. Stock is still counted in ' + base + '.');
        return out;
    };

    list.addEventListener('click', function (e) {
        var rm = e.target.closest('[data-remove]');
        if (rm) { rm.closest('.pack-row').remove(); renumber(); refreshPacks(); document.getElementById('add-pack').focus(); return; }
        var mk = e.target.closest('[data-make-barcode]');
        if (mk) {
            var input = mk.closest('.pack-row').querySelector('[data-f="barcode"]');
            input.value = window.pv2MakeEan ? window.pv2MakeEan() : '';
            input.focus();
            return;
        }
        var lb = e.target.closest('[data-labels]');
        if (lb && window.pv2PrintLabels) {
            var li = lb.closest('.pack-row');
            var name = (document.getElementById('name').value || '') + ' — ' + (li.querySelector('[data-f="name"]').value || '');
            var pr = li.querySelector('[data-f="price"]');
            window.pv2PrintLabels((li.querySelector('[data-f="barcode"]').value || '').trim(), name, pr.value || (pr.placeholder || '').replace('Auto: ', '').replace('Auto', ''));
        }
    });
    document.getElementById('add-pack').addEventListener('click', function () { addRow(true); });
    step.querySelectorAll('[data-preset]').forEach(function (b) {
        b.addEventListener('click', function () {
            var p = PRESETS[+b.dataset.preset]; if (!p) return;
            // Pick the matching "Sold and counted in" unit too — only when none is chosen yet.
            var u = document.getElementById('unit_id');
            if (u && !u.value && p.base) {
                for (var k = 0; k < u.options.length; k++) {
                    if (u.options[k].value && u.options[k].text.split(' — ')[0].trim().toLowerCase() === p.base.toLowerCase()) { u.value = u.options[k].value; break; }
                }
            }
            var yes = document.getElementById('q-packs-y'); yes.checked = true;
            form.querySelector('[data-body="packs"]').hidden = false;
            while (rows().length < p.names.length) addRow(false);
            rows().forEach(function (li, i) {
                if (i >= p.names.length) return;
                li.querySelector('[data-f="name"]').value = p.names[i];
                var c = li.querySelector('[data-f="contains"]');
                c.placeholder = 'e.g. ' + p.eg[i];
            });
            refreshPacks();
            rows()[0].querySelector('[data-f="contains"]').focus();
        });
    });
    form.addEventListener('input', function (e) {
        if (e.target.closest && (e.target.closest('#packs-step') || e.target.id === 'default_selling_price')) { refreshPacks(); if (window.pv2Refresh) window.pv2Refresh(); }
    });
    form.addEventListener('change', function (e) {
        if (e.target.name === '_packs') {
            form.querySelector('[data-body="packs"]').hidden = !packsYes();
            if (packsYes() && !rows().length) addRow(true);
        }
        if (e.target.name === '_packs' || e.target.id === 'unit_id') refreshPacks();
    });
    renumber();
    refreshPacks();
})();
</script>
@endpush
