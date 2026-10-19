{{-- MASTER-UNIVERSE-1 — Business & switches.
     Five big pictures; picking a different one first shows what the tenant has (read-only impact), what
     changes, that NO data changes, and asks for the tenant code. Blocked when the plan lacks a module. --}}
@php
    $current = \App\Support\Business::exists($tenant->primary_business) ? $tenant->primary_business : null;
    // What each business offers on the "Add product" screen (plan §4.1) — shown so the owner sees what he picks.
    $productCards = [
        'restaurant' => ['Menu item (recipe inside)', 'Ready-made (cold drink, crate)', 'Kitchen ingredient', 'Packaging'],
        'catering'   => ['Menu dish (per head / kg / deg)', 'Ingredient', 'Disposables & packaging', 'Service / hire'],
        'pharmacy'   => ['Medicine', 'Store item (shampoo, soap, toothbrush)'],
        'mart'       => ['Packed product (barcode)', 'Loose by weight'],
        'industry'   => ['Raw material', 'Finished product', 'Semi-finished', 'Packing material'],
    ];
    $bizJson = [];
    foreach ($businesses as $key => $b) {
        $bizJson[$key] = [
            'label' => $b['label'], 'button' => $b['button']['label'], 'cards' => $productCards[$key] ?? [],
            'missing' => \App\Support\Business::missingModules($key, $planModules),
        ];
    }
    $canChange = (bool) auth('central')->user()?->can('central.tenants.business.update');
    $canImpact = (bool) auth('central')->user()?->can('central.tenants.business.impact');
    $impactUrl = $canImpact ? route('central.tenants.business.impact', $tenant) : null;
    $settingWords = function ($row) use ($businesses, $features) {
        if ($row->setting === 'primary_business') {
            return ['Business type', $businesses[$row->old_value]['label'] ?? ($row->old_value ?: 'Not set'), $businesses[$row->new_value]['label'] ?? ($row->new_value ?: 'Not set')];
        }
        if (str_starts_with($row->setting, 'feature:')) {
            $k = substr($row->setting, 8);

            return ['Switch: ' . ($features[$k]['label'] ?? $k), $row->old_value, $row->new_value];
        }
        if ($row->setting === 'branch:add') {
            return ['Branch added', null, $row->new_value];
        }

        return [$row->setting, $row->old_value, $row->new_value];
    };
@endphp

<section class="mu-panel" id="business">
    <h2>What kind of business is this?</h2>
    <p class="mu-panel-sub">Pick the picture that fits. It decides the top button and the product types the tenant sees on the new screens.
        Changing it <strong>never changes data</strong> — products, sales, stock and accounts stay exactly as they are.</p>

    <div class="mu-grid is-five" role="list">
        @foreach($businesses as $key => $b)
            @php
                $missing = $bizJson[$key]['missing'];
                $isCurrent = $current === $key;
                $blocked = ! $isCurrent && $missing !== [];
            @endphp
            <button type="button" role="listitem"
                    class="mu-biz {{ $isCurrent ? 'is-current' : '' }} {{ $blocked ? 'is-blocked' : '' }}"
                    data-biz="{{ $key }}" @if($isCurrent) aria-current="true" @endif
                    @if($blocked || ! $canChange) aria-disabled="true" @endif
                    aria-label="{{ $b['label'] }}{{ $isCurrent ? ' (current)' : '' }}{{ $blocked ? ' — blocked: plan needs ' . implode(', ', $missing) : '' }}">
                @if($isCurrent)
                    <span class="mu-biz-flag mu-pill is-on">Current</span>
                @elseif($blocked)
                    <span class="mu-biz-flag mu-pill is-blocked">Blocked</span>
                @endif
                <img src="{{ asset($b['image']) }}" alt="">
                <span class="mu-biz-name">{{ $b['label'] }}</span>
                <span class="mu-biz-ur" lang="ur" dir="rtl">{{ $b['label_ur'] }}</span>
                <span class="mu-biz-hint">{{ $b['hint'] }}</span>
                @if($blocked)
                    <span class="mu-biz-why">The plan has no {{ implode(', ', $missing) }} module. Change the plan first.</span>
                @endif
            </button>
        @endforeach
    </div>
    @if(! $current)
        <p class="mu-note mt-2 mb-0"><i class="ti ti-info-circle me-1" aria-hidden="true"></i>Not set yet: the tenant's screens behave exactly as before until you pick one.</p>
    @endif

    @if($canChange)
        <div class="mu-confirm d-none" id="biz-confirm" aria-live="polite">
            <h3 class="mb-1" style="font-size:15px;font-weight:800">Change business to <span data-biz-name>…</span>?</h3>
            <p class="mu-panel-sub mb-3">Read this first. Nothing happens until you type the tenant code and press the button.</p>
            <div class="mu-confirm-cols">
                <div>
                    <h4 class="mu-k mb-2" style="font-size:12px">What this tenant has today</h4>
                    <ul class="mu-list" id="biz-impact">
                        <li>{{ $canImpact ? 'Loading…' : 'You do not have permission to read the counts.' }}</li>
                    </ul>
                </div>
                <div>
                    <h4 class="mu-k mb-2" style="font-size:12px">What changes</h4>
                    <ul class="mu-list" id="biz-changes"></ul>
                    <div class="mu-safe mt-2"><i class="ti ti-shield-check" aria-hidden="true"></i>No data is changed. You can switch back any time.</div>
                    <div class="alert alert-danger mt-2 mb-0 py-2 d-none" id="biz-live-warning">
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>This tenant works every day (it has sales). Its staff will see different screens.
                    </div>
                </div>
            </div>
            <form method="POST" action="{{ route('central.tenants.business.update', $tenant) }}" class="row g-2 align-items-end mt-2" id="biz-form">
                @csrf
                @method('PUT')
                <input type="hidden" name="primary_business" id="biz-input" value="{{ old('primary_business') }}">
                <div class="col-md-4">
                    <label class="form-label" for="biz-code">Type the tenant code <code>{{ $tenant->tenant_code }}</code> to confirm</label>
                    <input type="text" class="form-control" name="confirm_code" id="biz-code" autocomplete="off" required data-expect="{{ strtolower($tenant->tenant_code) }}">
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="biz-note">Note (optional)</label>
                    <input type="text" class="form-control" name="note" id="biz-note" maxlength="255" placeholder="Why — for the history list">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary flex-fill" id="biz-submit" disabled>Change business</button>
                    <button type="button" class="btn btn-light" id="biz-cancel">Cancel</button>
                </div>
            </form>
        </div>
    @endif
</section>

<section class="mu-panel" id="features">
    <h2>Switches for this tenant</h2>
    <p class="mu-panel-sub">Turn a finished feature on or off for this tenant only. Every change is written to the history below.</p>
    @can('central.tenants.features.update')
        <form method="POST" action="{{ route('central.tenants.features.update', $tenant) }}">
            @csrf
            @method('PUT')
            @forelse($features as $key => $f)
                @php $on = (bool) (($tenant->features ?? [])[$key] ?? false); @endphp
                <div class="mu-switch">
                    <div class="mu-switch-text">
                        <strong>{{ $f['label'] }}</strong>
                        <span>{{ $f['says'] }}</span>
                        <em>Where: {{ $f['where'] }} · now <b>{{ $on ? 'ON' : 'OFF' }}</b>@if(! empty($f['needs'])) · needs: {{ collect($f['needs'])->map(fn ($n) => $features[$n]['label'] ?? $n)->implode(', ') }}@endif</em>
                    </div>
                    <div class="form-check form-switch m-0">
                        <input type="hidden" name="features[{{ $key }}]" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" name="features[{{ $key }}]" value="1" id="feature-{{ $key }}" @checked($on)
                               aria-label="{{ $f['label'] }}">
                    </div>
                </div>
            @empty
                <div class="mu-empty">No switches yet.</div>
            @endforelse
            @if(count($features))
                <button class="btn btn-primary"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Save switches</button>
            @endif
        </form>
    @else
        @foreach($features as $key => $f)
            @php $on = (bool) (($tenant->features ?? [])[$key] ?? false); @endphp
            <div class="mu-switch">
                <div class="mu-switch-text"><strong>{{ $f['label'] }}</strong><span>{{ $f['says'] }}</span><em>Where: {{ $f['where'] }}</em></div>
                <span class="mu-pill {{ $on ? 'is-on' : 'is-off' }}">{{ $on ? 'ON' : 'OFF' }}</span>
            </div>
        @endforeach
    @endcan
</section>

<section class="mu-panel" id="history">
    <h2>History</h2>
    <p class="mu-panel-sub">Who changed what, and when.</p>
    @if($history->isEmpty())
        <div class="mu-empty"><i class="ti ti-history" aria-hidden="true"></i>No changes recorded yet.</div>
    @else
        <ul class="mu-timeline">
            @foreach($history as $row)
                @php
                    [$what, $from, $to] = $settingWords($row);
                @endphp
                <li>
                    <b>{{ $what }}</b>:
                    @if($from !== null)
                        {{ $from }} → <b>{{ $to }}</b>
                    @else
                        <b>{{ $to }}</b>
                    @endif
                    <div class="mu-note">
                        {{ \App\Services\Central\MasterOverviewService::pkt(\Illuminate\Support\Carbon::parse($row->created_at)) }} · by {{ $row->by ?? 'system' }}
                        @if($row->note)
                            · “{{ $row->note }}”
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

@if($canChange)
@push('scripts')
<script>
/* MASTER-UNIVERSE-1 — business change: impact first, then the tenant code. */
(function () {
    var BIZ = @json($bizJson);
    var CURRENT = @json($current);
    var IMPACT_URL = @json($impactUrl);
    var panel = document.getElementById('biz-confirm');
    if (!panel) return;
    var input = document.getElementById('biz-input');
    var code = document.getElementById('biz-code');
    var submit = document.getElementById('biz-submit');
    var impactList = document.getElementById('biz-impact');
    var changes = document.getElementById('biz-changes');
    var liveWarn = document.getElementById('biz-live-warning');
    var impact = null;

    function li(label, value) {
        var el = document.createElement('li');
        var a = document.createElement('span'); a.textContent = label;
        var b = document.createElement('b'); b.textContent = value;
        el.appendChild(a); el.appendChild(b);
        return el;
    }
    function renderImpact() {
        impactList.innerHTML = '';
        if (!impact) { impactList.appendChild(li('Loading…', '')); return; }
        if (!impact.ok) { impactList.appendChild(li(impact.error || 'Could not read this tenant right now.', '—')); return; }
        var n = 0;
        Object.keys(impact.products || {}).forEach(function (k) { impactList.appendChild(li(k, Number(impact.products[k]).toLocaleString())); n++; });
        if (!n) impactList.appendChild(li('Products', '0'));
        impactList.appendChild(li('Recipes', Number(impact.recipes || 0).toLocaleString()));
        impactList.appendChild(li('Catering product settings', Number(impact.catering_profiles || 0).toLocaleString()));
        impactList.appendChild(li('Sales bills (all time)', Number(impact.sales || 0).toLocaleString()));
        liveWarn.classList.toggle('d-none', !(impact.sales > 0));
    }
    function renderChanges(key) {
        var to = BIZ[key], from = CURRENT ? BIZ[CURRENT] : null;
        changes.innerHTML = '';
        changes.appendChild(li('Business type', (from ? from.label : 'Not set') + ' → ' + to.label));
        changes.appendChild(li('Top button', (from ? from.button : 'as today') + ' → ' + to.button));
        changes.appendChild(li('New product screen offers', to.cards.join(' · ')));
        var note = document.createElement('li');
        note.textContent = 'Screens follow this setting as they go live. Products that do not fit the new business stay, listed separately — never deleted.';
        changes.appendChild(note);
    }
    function open(key) {
        input.value = key;
        panel.querySelector('[data-biz-name]').textContent = BIZ[key].label;
        renderChanges(key);
        panel.classList.remove('d-none');
        code.value = ''; submit.disabled = true;
        if (IMPACT_URL && !impact) {
            renderImpact();
            fetch(IMPACT_URL, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : { ok: false, error: 'Could not load the counts (HTTP ' + r.status + ').' }; })
                .catch(function () { return { ok: false, error: 'Could not reach the server.' }; })
                .then(function (data) { impact = data; renderImpact(); });
        } else if (impact) {
            renderImpact();
        }
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(function () { code.focus(); }, 250);
    }
    document.querySelectorAll('#business .mu-biz').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = btn.getAttribute('data-biz');
            if (btn.getAttribute('aria-disabled') === 'true' || key === CURRENT) return;
            open(key);
        });
    });
    code.addEventListener('input', function () {
        submit.disabled = code.value.trim().toLowerCase() !== code.getAttribute('data-expect');
    });
    document.getElementById('biz-cancel').addEventListener('click', function () { panel.classList.add('d-none'); });
    // Came back with an error: open the panel again for the business that was picked.
    if (input.value && BIZ[input.value] && input.value !== CURRENT) open(input.value);
})();
</script>
@endpush
@endif
