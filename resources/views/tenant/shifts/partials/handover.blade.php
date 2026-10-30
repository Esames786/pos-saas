{{-- SHIFT-HANDOVER-1: open orders go with the counter to the next cashier, whose shift opens here.
     $h      = ShiftController::handoverInfo()
     $prefix = input name prefix: "handover" (one shift) or "handover[<shift id>]" (Close Branch)
     $terminalName = the counter's name, for the "what will happen" line --}}
@php
    $oldKey = str_replace(['[', ']'], ['.', ''], $prefix);
    $orderCount = count($h['orders']);
    $tableCount = count($h['tables']);
    $chosen = (string) old($oldKey . '.user_id', '');
@endphp
<div class="handover border border-warning rounded p-3" data-handover data-orders="{{ $orderCount }}" data-tables="{{ $tableCount }}" data-terminal="{{ $terminalName }}">
    <div class="fw-semibold mb-1">
        <i class="ti ti-arrows-exchange me-1" aria-hidden="true"></i>
        {{ $orderCount }} open order{{ $orderCount === 1 ? '' : 's' }}@if($tableCount) and {{ $tableCount }} open table{{ $tableCount === 1 ? '' : 's' }}@endif still on this shift
        @if($h['total'] !== null)
            <span class="text-muted fw-normal">· {{ number_format($h['total'], 2) }}</span>
        @endif
    </div>
    <p class="small text-muted mb-2">
        These are not paid yet, so they are not in your cash. Count and hand in your own cash, then open the next cashier's shift on this counter — these orders move into it.
        <br><span lang="ur-Latn">Ye orders abhi pay nahi hue, is liye aap ke cash me nahi. Apna cash gin kar jama karein, phir agle cashier ki shift isi counter par kholein — ye orders us ki shift me chale jayenge.</span>
    </p>

    <ul class="small mb-2 ps-3">
        @foreach($h['orders'] as $o)
            <li>
                {{ $o['sale_no'] }} · {{ $o['type'] }}@if($o['table']) · Table {{ $o['table'] }}@endif · {{ $o['time'] }}
                @if($o['amount'] !== null) · {{ number_format($o['amount'], 2) }}@endif
            </li>
        @endforeach
        @if($tableCount && ! $orderCount)
            <li>{{ implode(', ', $h['tables']) }}</li>
        @endif
    </ul>

    @if(! $h['sameDay'])
        <div class="alert alert-secondary small mb-0" role="note">
            It is past midnight: a shift opened now belongs to the next day. Settle these orders first, then close this shift.
            <br><span lang="ur-Latn">Raat 12 ke baad nayi shift agle din ki hoti hai — pehle ye orders settle karein, phir shift band karein.</span>
        </div>
    @elseif(! $h['candidates'])
        <div class="alert alert-secondary small mb-0" role="note">
            No other user works on this counter. Settle these orders first, or give the next cashier this counter in Users.
            <br><span lang="ur-Latn">Is counter par koi aur user nahi — pehle orders settle karein, ya Users me agle cashier ko ye counter dein.</span>
        </div>
    @else
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1" for="{{ $idp }}-user">Next cashier</label>
                <select id="{{ $idp }}-user" name="{{ $prefix }}[user_id]" class="form-select form-select-sm" data-handover-user>
                    <option value="">— Leave these orders here —</option>
                    @foreach($h['candidates'] as $c)
                        <option value="{{ $c['id'] }}" data-hidden="{{ $c['hidden'] }}" @selected($chosen === (string) $c['id'])>{{ $c['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1" for="{{ $idp }}-password">Next cashier's password</label>
                <input type="password" id="{{ $idp }}-password" name="{{ $prefix }}[password]" class="form-control form-control-sm"
                    autocomplete="new-password" placeholder="He types it himself" data-handover-password>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1" for="{{ $idp }}-opening">His opening cash</label>
                <input type="number" id="{{ $idp }}-opening" name="{{ $prefix }}[opening_cash]" class="form-control form-control-sm"
                    min="0" step="0.01" value="{{ old($oldKey . '.opening_cash', '0') }}" data-handover-opening>
            </div>
        </div>
        <p class="small text-warning mt-2 mb-0 d-none" data-handover-warning></p>
        <p class="small mt-2 mb-0" data-handover-says aria-live="polite"></p>
    @endif
</div>

@once
    @push('scripts')
    <script>
    (function () {
        /* SHIFT-HANDOVER-1: say what will happen, and require the password + opening cash once a next cashier is chosen. */
        function refresh(box) {
            var select = box.querySelector('[data-handover-user]');
            if (!select) { return; }
            var option = select.options[select.selectedIndex];
            var chosen = select.value !== '';
            var orders = parseInt(box.dataset.orders || '0', 10);
            var tables = parseInt(box.dataset.tables || '0', 10);
            var pw = box.querySelector('[data-handover-password]');
            var opening = box.querySelector('[data-handover-opening]');
            var says = box.querySelector('[data-handover-says]');
            var warn = box.querySelector('[data-handover-warning]');
            pw.required = chosen;
            opening.required = chosen;
            if (!chosen) {
                says.textContent = 'This shift cannot close while these orders are open.';
                says.className = 'small mt-2 mb-0 text-muted';
                warn.classList.add('d-none');
                return;
            }
            var name = option.textContent.trim();
            says.textContent = 'On close: your shift closes on your count, ' + name + '\'s shift opens on ' + box.dataset.terminal
                + ', and ' + orders + ' open order' + (orders === 1 ? '' : 's')
                + (tables ? ' and ' + tables + ' table' + (tables === 1 ? '' : 's') : '') + ' move into it.';
            says.className = 'small mt-2 mb-0 fw-semibold';
            var hidden = parseInt(option.dataset.hidden || '0', 10);
            if (hidden > 0) {
                warn.textContent = name + ' will not see ' + hidden + ' of these orders — his order types do not include them. Give him access in Users, or settle them first.';
                warn.classList.remove('d-none');
            } else {
                warn.classList.add('d-none');
            }
        }
        document.querySelectorAll('[data-handover]').forEach(function (box) {
            var select = box.querySelector('[data-handover-user]');
            if (select) { select.addEventListener('change', function () { refresh(box); }); }
            refresh(box);
        });
    })();
    </script>
    @endpush
@endonce
