{{-- REPORT-DELIVERY-V2-1 — "Daily report delivery": one card, three questions (how, when, what).
     Shown only to whoever may save it (tenant.reports.center.channels) on a tenant switched on in Bingoo
     Master. It posts to the same route as the old "Save delivery" box. Data: ReportDeliverySettings::read(). --}}
@php
    $rate = (float) $d['rate'];
    $perMonth = 30 + ($d['weekly'] ? 4 : 0) + ($d['monthly'] ? 1 : 0);
    $waCount = count($d['contacts']);
    $estimate = $d['whatsapp_on'] ? round($waCount * $perMonth * $rate) : 0;
    $time12 = function (string $t): string {
        [$h, $m] = array_pad(explode(':', $t), 2, '00');
        $h = (int) $h;

        return (($h % 12) ?: 12) . ':' . str_pad((string) (int) $m, 2, '0', STR_PAD_LEFT) . ($h >= 12 ? ' PM' : ' AM');
    };
    $nextLabel = $d['when'] === 'shift_close'
        ? 'When tonight’s last shift closes'
        : 'Today at ' . $time12($d['send_time']);
    $errorKeys = ['delivery', 'wa_numbers', 'emails', 'sections', 'send_time'];
@endphp

@push('styles')
<style>
    .rdv2 { --rd-gold: #8C6A12; --rd-gold-soft: #FBF4E2; --rd-gold-line: #E2C670; --rd-ok: #145C38; --rd-ok-soft: #E8F5EE; --rd-bad: #8A1C1C; --rd-muted: #4F5662; --rd-line: #E4E6EB; }
    .rdv2 .rd-step { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
    .rdv2 .rd-step-no { width: 32px; height: 32px; border-radius: 50%; background: var(--rd-gold); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; flex: none; }
    .rdv2 .rd-step h3 { margin: 0; font-size: 18px; font-weight: 800; }
    .rdv2 .rd-hint { margin: 0; color: var(--rd-muted); font-size: 14px; }
    .rdv2 .rd-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .rdv2 .rd-summary > div { background: #F8F9FB; border-radius: 10px; padding: 10px 14px; min-width: 0; }
    .rdv2 .rd-summary .rd-cost { background: var(--rd-gold-soft); }
    .rdv2 .rd-label { font-size: 12px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: #5B6270; }
    .rdv2 .rd-grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .rdv2 .rd-grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
    .rdv2 .rd-card { border: 2px solid var(--rd-line); border-radius: 14px; padding: 16px; background: #fff; min-width: 0; }
    .rdv2 .rd-card.is-picked { border-color: var(--rd-gold); background: #FFFBF0; }
    .rdv2 .rd-icon { width: 52px; height: 52px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; font-size: 28px; flex: none; }
    .rdv2 .rd-badge { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; }
    .rdv2 .rd-badge-gold { background: var(--rd-gold-soft); color: #6B5210; }
    .rdv2 .rd-badge-ok { background: var(--rd-ok-soft); color: var(--rd-ok); }
    .rdv2 .rd-badge-rec { background: var(--rd-gold); color: #fff; }
    .rdv2 .form-switch .form-check-input { width: 3.2em; height: 1.7em; cursor: pointer; }
    .rdv2 .form-switch .form-check-input:checked { background-color: #157347; border-color: #157347; }
    .rdv2 .rd-btn-soft { min-height: 44px; border: 1px solid #D5B65C; background: #fff; color: #5C450B; font-weight: 700; border-radius: 10px; padding: 0 16px; }
    .rdv2 .rd-btn-soft:hover { background: var(--rd-gold-soft); }
    .rdv2 .rd-row { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid var(--rd-line); border-radius: 12px; background: #fff; }
    .rdv2 .rd-avatar { width: 40px; height: 40px; border-radius: 50%; background: #E3F4EA; color: var(--rd-ok); font-weight: 800; display: inline-flex; align-items: center; justify-content: center; flex: none; }
    .rdv2 .rd-x { width: 40px; height: 40px; border-radius: 8px; border: 1px solid var(--rd-line); background: #fff; color: var(--rd-bad); display: inline-flex; align-items: center; justify-content: center; }
    .rdv2 .rd-add { border: 1px dashed #C9CDD4; border-radius: 12px; padding: 14px; background: #FAFBFC; }
    .rdv2 .rd-prefix { display: inline-flex; align-items: center; padding: 0 12px; background: #F1F3F5; font-weight: 700; border: 1px solid #ced4da; border-right: 0; border-radius: 8px 0 0 8px; }
    .rdv2 .rd-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 6px 6px 14px; border-radius: 999px; background: #E6EEFA; color: #123F80; font-weight: 700; }
    .rdv2 .rd-chip button { width: 32px; height: 32px; border-radius: 50%; border: 0; background: #fff; color: #123F80; }
    .rdv2 .rd-costbox { border-radius: 14px; background: var(--rd-gold-soft); padding: 16px 18px; }
    .rdv2 .rd-phone { border-radius: 18px; background: #E7DED3; padding: 14px; }
    .rdv2 .rd-bubble { background: #fff; border-radius: 4px 14px 14px 14px; padding: 10px 12px; max-width: 92%; box-shadow: 0 1px 1px rgba(0,0,0,.08); }
    .rdv2 .rd-pick { cursor: pointer; display: block; }
    .rdv2 .rd-pick input[type=radio] { position: absolute; opacity: 0; pointer-events: none; }
    .rdv2 .rd-pick:focus-within { outline: 3px solid var(--rd-gold-line); outline-offset: 2px; border-radius: 14px; }
    .rdv2 .rd-ok { color: var(--rd-ok); }
    .rdv2 .rd-bad { color: var(--rd-bad); }
    .rdv2 .rd-numeric { font-variant-numeric: tabular-nums; }
    .rdv2 .rd-section { border-top: 1px solid var(--rd-line); padding-top: 20px; margin-top: 20px; }
    @media (max-width: 767.98px) {
        .rdv2 .rd-summary, .rdv2 .rd-grid2, .rdv2 .rd-grid3 { grid-template-columns: minmax(0, 1fr); }
    }
</style>
@endpush

<div id="report-delivery" class="card mb-4 rdv2"><div class="card-body p-4">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h2 class="h4 fw-bold mb-1">Daily report delivery</h2>
            <p class="rd-hint">Choose how the owner gets the day's sales report, when it goes, and what it shows.</p>
            <p class="rd-hint">Malik ko roz ki sale report kaise, kab aur kya mile — yahan tay karein.</p>
        </div>
    </div>

    <div class="rd-summary mb-3" aria-label="What happens next">
        <div><div class="rd-label">Next report</div><div class="fw-bold" data-rd-next>{{ $nextLabel }}</div></div>
        <div><div class="rd-label">WhatsApp</div><div class="fw-bold" data-rd-wa-summary>{{ $d['whatsapp_on'] ? $waCount . ' ' . \Illuminate\Support\Str::plural('number', $waCount) : 'Off' }}</div></div>
        <div><div class="rd-label">Email</div><div class="fw-bold" data-rd-em-summary>{{ $d['email_on'] ? (count($d['emails']) ? count($d['emails']) . ' ' . \Illuminate\Support\Str::plural('address', count($d['emails'])) : 'Owner’s email') : 'Off' }}</div></div>
        <div class="rd-cost"><div class="rd-label" style="color:#6B5210">Estimated cost</div><div class="fw-bold" style="color:#4A380A">Rs <span data-rd-estimate>{{ number_format($estimate) }}</span> / month</div></div>
    </div>

    @if(session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif
    @foreach($errorKeys as $key)
        @error($key)<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
    @endforeach
    @if($d['last_failure'] !== '')
        <div class="alert alert-warning small" role="status"><strong>Last send had a problem:</strong> {{ $d['last_failure'] }}</div>
    @endif

    <form id="rd-form" method="POST" action="{{ url('/reports/center/channels') }}" novalidate>
        @csrf
        <input type="hidden" name="v2" value="1">

        {{-- ── 1. How ── --}}
        <div class="rd-step"><span class="rd-step-no" aria-hidden="true">1</span>
            <div><h3>How should it reach you?</h3><p class="rd-hint">Jo service chahiye, use ON karein — andar uski tafseel aur qeemat hai.</p></div>
        </div>

        <div class="rd-grid2" role="tablist" aria-label="Delivery service">
            <div class="rd-card is-picked" data-rd-card="wa">
                <div class="d-flex align-items-start gap-3">
                    <span class="rd-icon" style="background:#E3F4EA;color:#157347" aria-hidden="true"><i class="ti ti-brand-whatsapp"></i></span>
                    <div class="flex-grow-1" style="min-width:0">
                        <div class="d-flex align-items-center gap-2 flex-wrap"><span class="fs-5 fw-bold">WhatsApp</span><span class="rd-badge rd-badge-gold">Rs {{ rtrim(rtrim(number_format($rate, 2), '0'), '.') }} per message</span></div>
                        <div class="rd-hint">The day's figures on the owner's phone, with a button to open the full report.</div>
                    </div>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="rd-wa-on" name="whatsapp_on" value="1" @checked($d['whatsapp_on']) aria-label="WhatsApp reports on or off">
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mt-3">
                    <span class="fw-bold" data-rd-wa-state>{{ $d['whatsapp_on'] ? 'On · ' . $waCount . ' ' . \Illuminate\Support\Str::plural('number', $waCount) : 'Off' }}</span>
                    <button type="button" class="rd-btn-soft" role="tab" aria-selected="true" aria-controls="rd-panel-wa" data-rd-tab="wa">Set up WhatsApp</button>
                </div>
            </div>
            <div class="rd-card" data-rd-card="em">
                <div class="d-flex align-items-start gap-3">
                    <span class="rd-icon" style="background:#E6EEFA;color:#1D5FBF" aria-hidden="true"><i class="ti ti-mail"></i></span>
                    <div class="flex-grow-1" style="min-width:0">
                        <div class="d-flex align-items-center gap-2 flex-wrap"><span class="fs-5 fw-bold">Email</span><span class="rd-badge rd-badge-ok">Free</span></div>
                        <div class="rd-hint">The full A4 report as a PDF in the inbox — ready to print or forward.</div>
                    </div>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="rd-em-on" name="email_on" value="1" @checked($d['email_on']) aria-label="Email reports on or off">
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mt-3">
                    <span class="fw-bold" data-rd-em-state>{{ $d['email_on'] ? 'On' : 'Off' }}</span>
                    <button type="button" class="rd-btn-soft" role="tab" aria-selected="false" aria-controls="rd-panel-em" data-rd-tab="em">Set up Email</button>
                </div>
            </div>
        </div>

        {{-- WhatsApp panel --}}
        <div id="rd-panel-wa" role="tabpanel" aria-label="WhatsApp settings" class="rd-section" data-rd-panel="wa">
            <div class="row g-4">
                <div class="col-12 col-lg-7">
                    <h4 class="h6 fw-bold mb-0">Who gets the WhatsApp</h4>
                    <p class="rd-hint mb-2">Har number ko alag message jata hai. Number jaise marzi likhein — 0300-1234567 bhi theek hai.</p>
                    <div class="alert alert-warning py-2" data-rd-wa-off @if($d['whatsapp_on']) hidden @endif>WhatsApp is off. The numbers stay saved — switch it on above to start sending.</div>
                    <ul class="list-unstyled d-flex flex-column gap-2 mb-3" data-rd-contacts aria-label="WhatsApp numbers">
                        @foreach($d['contacts'] as $c)
                            <li class="rd-row" data-rd-contact>
                                <input type="hidden" name="wa_names[]" value="{{ $c['name'] }}">
                                <input type="hidden" name="wa_numbers[]" value="{{ $c['number'] }}">
                                <span class="rd-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($c['name'] !== '' ? $c['name'] : '#', 0, 1)) }}</span>
                                <div class="flex-grow-1" style="min-width:0">
                                    <div class="fw-bold">{{ $c['name'] !== '' ? $c['name'] : 'No name' }}</div>
                                    <div class="rd-numeric" style="color:#4F5662">{{ $c['pretty'] }}</div>
                                </div>
                                <button type="submit" form="rd-test" name="test" value="{{ $c['number'] }}" class="btn btn-outline-secondary btn-sm" style="min-height:40px">Send test</button>
                                <button type="button" class="rd-x" data-rd-remove aria-label="Remove {{ $c['name'] !== '' ? $c['name'] : $c['pretty'] }}"><i class="ti ti-x" aria-hidden="true"></i></button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="rd-add">
                        <div class="fw-bold mb-2">Add a number</div>
                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-md-4">
                                <label for="rd-new-name" class="form-label small fw-bold mb-1">Name</label>
                                <input id="rd-new-name" type="text" class="form-control" maxlength="40" placeholder="e.g. Manager" data-rd-enter="add-number">
                            </div>
                            <div class="col-12 col-md-5">
                                <label for="rd-new-number" class="form-label small fw-bold mb-1">Mobile number</label>
                                <div class="d-flex">
                                    <span class="rd-prefix">+92</span>
                                    <input id="rd-new-number" type="tel" inputmode="numeric" class="form-control rd-numeric" style="border-radius:0 8px 8px 0" placeholder="300 1234567" aria-describedby="rd-number-hint" data-rd-enter="add-number">
                                </div>
                            </div>
                            <div class="col-12 col-md-3">
                                <button type="button" class="btn btn-primary w-100" style="min-height:44px" data-rd-add-number>Add</button>
                            </div>
                        </div>
                        <div id="rd-number-hint" class="small fw-semibold mt-2" role="status" aria-live="polite" style="color:#5B6270">Type the number the way you know it — it is checked as you type.</div>
                    </div>
                </div>
                <div class="col-12 col-lg-5 d-flex flex-column gap-3">
                    <div class="rd-costbox">
                        <div class="rd-label" style="color:#6B5210">What it costs</div>
                        <div class="fs-4 fw-bold" style="color:#3E2F08">Rs {{ rtrim(rtrim(number_format($rate, 2), '0'), '.') }} <span class="fs-6">per message</span></div>
                        <div style="color:#4A380A">1 report = 1 message to each number.</div>
                        <div class="d-flex justify-content-between border-top mt-2 pt-2 rd-numeric" style="border-color:#EAD9A6 !important">
                            <span><span data-rd-count>{{ $waCount }}</span> numbers × <span data-rd-permonth>{{ $perMonth }}</span> reports</span>
                            <span class="fw-bold"><span data-rd-msgs>{{ number_format($waCount * $perMonth) }}</span> messages</span>
                        </div>
                        <div class="d-flex justify-content-between fw-bold rd-numeric" style="color:#3E2F08"><span>About</span><span>Rs <span data-rd-estimate>{{ number_format($estimate) }}</span> / month</span></div>
                        <div class="small" style="color:#6B5210">Billed on your monthly Bingoo invoice, day by day.</div>
                    </div>
                    <div class="rd-phone" aria-label="What the owner will receive">
                        <div class="rd-label mb-2" style="color:#4F4436">On the phone</div>
                        <div class="rd-bubble">
                            <div class="fw-bold">Daily sales report for {{ app('tenant')->business_name }}</div>
                            <div class="small" style="color:#3A404A">Period: [business day]<br>Orders: [count]<br>Net sales: Rs [amount]<br>Net cash from sales: Rs [amount]</div>
                            <div class="small" style="color:#5B6270">Bingoo POS</div>
                            <div class="border-top mt-2 pt-2 text-center fw-bold small" style="color:#0B6BCB">Open full report</div>
                        </div>
                        <div class="small mt-2" style="color:#4F4436">The button opens the full PDF for 48 hours.</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Email panel --}}
        <div id="rd-panel-em" role="tabpanel" aria-label="Email settings" class="rd-section" data-rd-panel="em" hidden>
            <div class="row g-4">
                <div class="col-12 col-lg-7">
                    <h4 class="h6 fw-bold mb-0">Who gets the email</h4>
                    <p class="rd-hint mb-2">Khaali chhorein to report Owner ke email ({{ $d['owner_email'] !== '' ? $d['owner_email'] : 'not set' }}) par jayegi.</p>
                    <div class="alert alert-warning py-2" data-rd-em-off @if($d['email_on']) hidden @endif>Email is off. The addresses stay saved — switch it on above to start sending.</div>
                    <ul class="list-unstyled d-flex flex-wrap gap-2 mb-3" data-rd-emails aria-label="Email addresses">
                        @foreach($d['emails'] as $email)
                            <li class="rd-chip" data-rd-email>
                                <input type="hidden" name="emails[]" value="{{ $email }}">
                                <span>{{ $email }}</span>
                                <button type="button" data-rd-remove aria-label="Remove {{ $email }}"><i class="ti ti-x" aria-hidden="true"></i></button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="rd-add">
                        <label for="rd-new-email" class="fw-bold mb-2 d-block">Add an email</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <input id="rd-new-email" type="email" class="form-control" style="flex:1 1 240px" placeholder="name@business.com" aria-describedby="rd-email-hint" data-rd-enter="add-email">
                            <button type="button" class="btn btn-primary" style="min-height:44px" data-rd-add-email>Add</button>
                        </div>
                        <div id="rd-email-hint" class="small fw-semibold mt-2" role="status" aria-live="polite" style="color:#5B6270">One address at a time. Press Add after each.</div>
                    </div>
                </div>
                <div class="col-12 col-lg-5 d-flex flex-column gap-3">
                    <div class="rd-costbox" style="background:var(--rd-ok-soft)">
                        <div class="rd-label" style="color:var(--rd-ok)">What it costs</div>
                        <div class="fs-4 fw-bold" style="color:#0F4429">Free</div>
                        <div style="color:var(--rd-ok)">Send to as many addresses as you like.</div>
                    </div>
                    <div class="rd-card">
                        <div class="rd-label mb-1">In the inbox</div>
                        <div class="fw-bold">Daily sales report — {{ app('tenant')->business_name }}</div>
                        <span class="d-inline-flex align-items-center gap-2 mt-2 px-2 py-1 rounded" style="background:#F4F5F7"><i class="ti ti-file-type-pdf" style="color:#8A1C1C" aria-hidden="true"></i> report.pdf · A4</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── 2. When ── --}}
        <div class="rd-section">
            <div class="rd-step"><span class="rd-step-no" aria-hidden="true">2</span>
                <div><h3>When should it go?</h3><p class="rd-hint">Report khud jayegi — kisi ko button dabane ki zaroorat nahi.</p></div>
            </div>
            <div class="rd-grid2" role="radiogroup" aria-label="When to send">
                <label class="rd-pick rd-card @if($d['when'] === 'shift_close') is-picked @endif" data-rd-when-card="shift_close">
                    <input type="radio" name="when" value="shift_close" @checked($d['when'] === 'shift_close')>
                    <span class="d-flex gap-3 align-items-start">
                        <span class="rd-icon" style="background:var(--rd-gold-soft);color:#7A5C0F" aria-hidden="true"><i class="ti ti-lock"></i></span>
                        <span>
                            <span class="d-flex align-items-center gap-2 flex-wrap"><span class="fs-6 fw-bold">When the day's last shift closes</span><span class="rd-badge rd-badge-rec">Recommended</span></span>
                            <span class="rd-hint d-block">The moment the last counter closes its shift, the report goes — the day's figures are final by then.</span>
                        </span>
                    </span>
                    {{-- No display utility on the element that carries `hidden`: Bootstrap's d-flex is !important too and wins. --}}
                    <span style="display:block" data-rd-when-extra="shift_close" @if($d['when'] !== 'shift_close') hidden @endif>
                        <span class="d-flex align-items-center gap-2 flex-wrap mt-3 p-2 rounded bg-white border">
                            <span class="fw-bold">If no shift is closed by</span>
                            <input type="time" name="fallback_time" class="form-control" style="width:auto;min-height:44px" value="{{ $d['fallback_time'] }}" aria-label="Safety time">
                            <span>send it anyway.</span>
                        </span>
                    </span>
                </label>
                <label class="rd-pick rd-card @if($d['when'] === 'time') is-picked @endif" data-rd-when-card="time">
                    <input type="radio" name="when" value="time" @checked($d['when'] === 'time')>
                    <span class="d-flex gap-3 align-items-start">
                        <span class="rd-icon" style="background:#EEF0F3;color:#3A404A" aria-hidden="true"><i class="ti ti-clock"></i></span>
                        <span>
                            <span class="fs-6 fw-bold d-block">At a fixed time every day</span>
                            <span class="rd-hint d-block">Same time each day. A business day that runs past midnight still counts as one day.</span>
                        </span>
                    </span>
                    <span style="display:block" data-rd-when-extra="time" @if($d['when'] !== 'time') hidden @endif>
                        <span class="d-flex align-items-center gap-2 flex-wrap mt-3 p-2 rounded bg-white border">
                            <span class="fw-bold">Send at</span>
                            <input type="time" name="send_time" class="form-control" style="width:auto;min-height:44px" value="{{ $d['send_time'] }}" aria-label="Send time">
                        </span>
                    </span>
                </label>
            </div>
            <div class="rd-grid2 mt-3">
                <div class="rd-row justify-content-between">
                    <div><div class="fw-bold">Weekly summary</div><div class="rd-hint">Every Monday, last week in one report.</div></div>
                    <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" name="weekly" value="1" @checked($d['weekly']) aria-label="Weekly summary on or off" data-rd-extra-report></div>
                </div>
                <div class="rd-row justify-content-between">
                    <div><div class="fw-bold">Monthly summary</div><div class="rd-hint">On the 1st, the whole last month.</div></div>
                    <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" name="monthly" value="1" @checked($d['monthly']) aria-label="Monthly summary on or off" data-rd-extra-report></div>
                </div>
            </div>
        </div>

        {{-- ── 3. What ── --}}
        <div class="rd-section">
            <div class="rd-step"><span class="rd-step-no" aria-hidden="true">3</span>
                <div><h3>What should the report show?</h3><p class="rd-hint">WhatsApp par hamesha khaas figures jate hain; poori tafseel PDF aur email mein.</p></div>
            </div>
            <div class="rd-grid3" role="radiogroup" aria-label="Report size">
                @foreach([
                    'short' => ['Short', 'Total sale, orders and cash — one page.'],
                    'full' => ['Full', 'Everything: items, deals, waiters, order types, cancellations, cash and bank.'],
                    'custom' => ['Choose myself', 'Tick only the parts you want.'],
                ] as $key => [$title, $desc])
                    <label class="rd-pick rd-card @if($d['preset'] === $key) is-picked @endif" data-rd-preset-card="{{ $key }}">
                        <input type="radio" name="preset" value="{{ $key }}" @checked($d['preset'] === $key)>
                        <span class="fs-6 fw-bold d-block">{{ $title }}</span>
                        <span class="rd-hint d-block">{{ $desc }}</span>
                    </label>
                @endforeach
            </div>
            <fieldset class="border rounded-3 p-3 mt-3" data-rd-custom @if($d['preset'] !== 'custom') hidden @endif>
                <legend class="float-none w-auto px-2 fs-6 fw-bold mb-0">Choose the parts</legend>
                <div class="rd-grid3">
                    @foreach($d['parts'] as $key => [$label, $hint])
                        <div class="form-check d-flex align-items-center gap-2" style="min-height:44px">
                            <input class="form-check-input mt-0" type="checkbox" name="sections[]" value="{{ $key }}" id="rd-part-{{ $key }}" @checked(in_array($key, $d['sections'], true))>
                            <label class="form-check-label" for="rd-part-{{ $key }}"><span class="fw-bold">{{ $label }}</span> <span class="rd-hint">— {{ $hint }}</span></label>
                        </div>
                    @endforeach
                </div>
            </fieldset>
        </div>

        <div class="rd-section d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="rd-hint" style="max-width:640px">Applies to the automatic report and to the “Send” buttons on POS and Report Center. <span class="d-block">Test buttons use the <strong>saved</strong> settings — Save first.</span></div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" form="rd-test" name="test_all" value="1" class="rd-btn-soft" style="min-height:48px">Send a test now</button>
                <button type="submit" class="btn btn-primary px-4" style="min-height:48px;font-weight:800">Save</button>
            </div>
        </div>
    </form>

    {{-- Test sends live in their own form: a test button inside the main form would be what pressing
         Enter in a text box submits, and every test is a paid message. --}}
    <form id="rd-test" method="POST" action="{{ url('/reports/center/channels') }}" hidden>
        @csrf
        <input type="hidden" name="v2" value="1">
    </form>

    <div class="rd-section">
        <h3 class="h6 fw-bold">Recent deliveries</h3>
        @if($d['recent'] === [])
            <p class="rd-hint mb-0">Nothing sent yet. The first report will show here.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 rd-numeric">
                    <thead><tr><th>When</th><th>Day</th><th>Service</th><th>Result</th><th>Why it went</th></tr></thead>
                    <tbody>
                    @foreach($d['recent'] as $r)
                        <tr>
                            <td>{{ $r['when'] }}</td>
                            <td>{{ $r['day'] }}</td>
                            <td>{{ $r['service'] }}</td>
                            <td class="fw-bold {{ $r['bad'] ? 'rd-bad' : 'rd-ok' }}">{{ $r['result'] }}</td>
                            <td>{{ $r['why'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if($d['other_schedules']->isNotEmpty())
        <div class="rd-section">
            <h3 class="h6 fw-bold">Other scheduled reports</h3>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Name</th><th>How often</th><th>Time</th><th>Last sent</th><th></th></tr></thead>
                    <tbody>
                    @foreach($d['other_schedules'] as $s)
                        <tr>
                            <td>{{ $s->name }}{{ $s->is_active ? '' : ' (paused)' }}</td>
                            <td>{{ ucfirst($s->frequency) }}</td>
                            <td>{{ $s->send_time }}</td>
                            <td class="small">{{ $s->last_success_at ?? '—' }}</td>
                            <td>
                                @can('tenant.reports.center.schedules.destroy')
                                    <form method="POST" action="{{ url('/reports/center/schedules/' . $s->id) }}" onsubmit="return confirm('Remove this scheduled report?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div></div>

@push('scripts')
<script>
(function () {
    var root = document.getElementById('report-delivery');
    if (!root) { return; }
    var rate = @json($rate);
    var q = function (sel) { return root.querySelector(sel); };
    var qa = function (sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); };

    function norm(raw) {
        var d = String(raw || '').replace(/\D/g, '');
        if (d.indexOf('0092') === 0) { d = d.slice(2); }
        if (d.length === 11 && d.charAt(0) === '0') { d = '92' + d.slice(1); }
        if (d.length === 10 && d.charAt(0) === '3') { d = '92' + d; }
        return /^923\d{9}$/.test(d) ? d : null;
    }
    function pretty(d) { return '+92 ' + d.slice(2, 5) + ' ' + d.slice(5); }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
    function time12(t) {
        var p = String(t || '00:00').split(':'); var h = parseInt(p[0], 10) || 0; var ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12; if (h === 0) { h = 12; } return h + ':' + (p[1] || '00') + ' ' + ap;
    }

    // Tabs: WhatsApp / Email.
    function openTab(which) {
        qa('[data-rd-tab]').forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-rd-tab') === which ? 'true' : 'false'); });
        qa('[data-rd-card]').forEach(function (c) { c.classList.toggle('is-picked', c.getAttribute('data-rd-card') === which); });
        qa('[data-rd-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-rd-panel') !== which; });
    }
    qa('[data-rd-tab]').forEach(function (b) { b.addEventListener('click', function () { openTab(b.getAttribute('data-rd-tab')); }); });

    var waOn = q('#rd-wa-on'), emOn = q('#rd-em-on');

    function refresh() {
        var count = qa('[data-rd-contact]').length;
        var emails = qa('[data-rd-email]').length;
        var perMonth = 30 + (q('[name=weekly]').checked ? 4 : 0) + (q('[name=monthly]').checked ? 1 : 0);
        var msgs = waOn.checked ? count * perMonth : 0;
        var estimate = Math.round(msgs * rate).toLocaleString('en-PK');
        qa('[data-rd-estimate]').forEach(function (el) { el.textContent = estimate; });
        q('[data-rd-count]').textContent = count;
        q('[data-rd-permonth]').textContent = perMonth;
        q('[data-rd-msgs]').textContent = (count * perMonth).toLocaleString('en-PK');
        q('[data-rd-wa-summary]').textContent = waOn.checked ? plural(count, 'number', 'numbers') : 'Off';
        q('[data-rd-wa-state]').textContent = waOn.checked ? 'On · ' + plural(count, 'number', 'numbers') : 'Off';
        q('[data-rd-em-summary]').textContent = emOn.checked ? (emails ? plural(emails, 'address', 'addresses') : 'Owner’s email') : 'Off';
        q('[data-rd-em-state]').textContent = emOn.checked ? 'On' : 'Off';
        q('[data-rd-wa-off]').hidden = waOn.checked;
        q('[data-rd-em-off]').hidden = emOn.checked;
        var when = (q('[name=when]:checked') || {}).value;
        q('[data-rd-next]').textContent = when === 'shift_close'
            ? 'When tonight’s last shift closes'
            : 'Today at ' + time12((q('[name=send_time]') || {}).value);
    }
    waOn.addEventListener('change', function () { openTab('wa'); refresh(); });
    emOn.addEventListener('change', function () { openTab('em'); refresh(); });
    qa('[data-rd-extra-report]').forEach(function (el) { el.addEventListener('change', refresh); });

    // Remove a number or an email.
    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-rd-remove]');
        if (!btn) { return; }
        var item = btn.closest('[data-rd-contact],[data-rd-email]');
        if (item) { item.remove(); refresh(); }
    });

    // Numbers: checked as you type, added with a name.
    var numIn = q('#rd-new-number'), nameIn = q('#rd-new-name'), numHint = q('#rd-number-hint');
    function savedNumbers() { return qa('[data-rd-contact] input[name="wa_numbers[]"]').map(function (i) { return i.value; }); }
    function checkNumber() {
        var raw = numIn.value.trim();
        if (raw === '') { numHint.textContent = 'Type the number the way you know it — it is checked as you type.'; numHint.style.color = '#5B6270'; return null; }
        var n = norm(raw.indexOf('0') === 0 || raw.indexOf('92') === 0 || raw.indexOf('+') === 0 ? raw : '0' + raw);
        if (n && savedNumbers().indexOf(n) !== -1) { numHint.textContent = 'This number is already in the list.'; numHint.style.color = '#8A1C1C'; return null; }
        if (n) { numHint.textContent = 'Looks right: ' + pretty(n); numHint.style.color = '#145C38'; return n; }
        numHint.textContent = 'A Pakistani mobile has 10 digits after +92, starting with 3 — e.g. 300 1234567.'; numHint.style.color = '#8A1C1C';
        return null;
    }
    numIn.addEventListener('input', checkNumber);
    function addNumber() {
        var n = checkNumber();
        if (!n) { numIn.focus(); return; }
        var name = nameIn.value.trim().slice(0, 40);
        var li = document.createElement('li'); li.className = 'rd-row'; li.setAttribute('data-rd-contact', '');
        var hn = document.createElement('input'); hn.type = 'hidden'; hn.name = 'wa_names[]'; hn.value = name;
        var hv = document.createElement('input'); hv.type = 'hidden'; hv.name = 'wa_numbers[]'; hv.value = n;
        var av = document.createElement('span'); av.className = 'rd-avatar'; av.setAttribute('aria-hidden', 'true'); av.textContent = (name || '#').charAt(0).toUpperCase();
        var box = document.createElement('div'); box.className = 'flex-grow-1'; box.style.minWidth = '0';
        var nm = document.createElement('div'); nm.className = 'fw-bold'; nm.textContent = name || 'No name';
        var pr = document.createElement('div'); pr.className = 'rd-numeric'; pr.style.color = '#4F5662'; pr.textContent = pretty(n);
        box.appendChild(nm); box.appendChild(pr);
        var note = document.createElement('span'); note.className = 'small'; note.style.color = '#6B5210'; note.textContent = 'Save to send a test';
        var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'rd-x'; rm.setAttribute('data-rd-remove', ''); rm.setAttribute('aria-label', 'Remove ' + (name || pretty(n)));
        rm.innerHTML = '<i class="ti ti-x" aria-hidden="true"></i>';
        [hn, hv, av, box, note, rm].forEach(function (x) { li.appendChild(x); });
        q('[data-rd-contacts]').appendChild(li);
        numIn.value = ''; nameIn.value = ''; checkNumber(); nameIn.focus();
        if (!waOn.checked) { waOn.checked = true; }
        refresh();
    }
    q('[data-rd-add-number]').addEventListener('click', addNumber);

    // Emails.
    var emIn = q('#rd-new-email'), emHint = q('#rd-email-hint');
    function savedEmails() { return qa('[data-rd-email] input[name="emails[]"]').map(function (i) { return i.value; }); }
    function checkEmail() {
        var v = emIn.value.trim().toLowerCase();
        if (v === '') { emHint.textContent = 'One address at a time. Press Add after each.'; emHint.style.color = '#5B6270'; return null; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) { emHint.textContent = 'That does not look like an email address yet.'; emHint.style.color = '#8A1C1C'; return null; }
        if (savedEmails().indexOf(v) !== -1) { emHint.textContent = 'This address is already in the list.'; emHint.style.color = '#8A1C1C'; return null; }
        emHint.textContent = 'Looks right.'; emHint.style.color = '#145C38'; return v;
    }
    emIn.addEventListener('input', checkEmail);
    function addEmail() {
        var v = checkEmail();
        if (!v) { emIn.focus(); return; }
        var li = document.createElement('li'); li.className = 'rd-chip'; li.setAttribute('data-rd-email', '');
        var h = document.createElement('input'); h.type = 'hidden'; h.name = 'emails[]'; h.value = v;
        var t = document.createElement('span'); t.textContent = v;
        var rm = document.createElement('button'); rm.type = 'button'; rm.setAttribute('data-rd-remove', ''); rm.setAttribute('aria-label', 'Remove ' + v);
        rm.innerHTML = '<i class="ti ti-x" aria-hidden="true"></i>';
        li.appendChild(h); li.appendChild(t); li.appendChild(rm);
        q('[data-rd-emails]').appendChild(li);
        emIn.value = ''; checkEmail(); emIn.focus();
        if (!emOn.checked) { emOn.checked = true; }
        refresh();
    }
    q('[data-rd-add-email]').addEventListener('click', addEmail);

    // Enter in an "add" box adds; it never submits the form.
    qa('[data-rd-enter]').forEach(function (el) {
        el.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') { return; }
            e.preventDefault();
            if (el.getAttribute('data-rd-enter') === 'add-email') { addEmail(); } else { addNumber(); }
        });
    });

    // When / what cards.
    qa('[name=when]').forEach(function (r) {
        r.addEventListener('change', function () {
            qa('[data-rd-when-card]').forEach(function (c) { c.classList.toggle('is-picked', c.getAttribute('data-rd-when-card') === r.value); });
            qa('[data-rd-when-extra]').forEach(function (x) { x.hidden = x.getAttribute('data-rd-when-extra') !== r.value; });
            refresh();
        });
    });
    var sendTime = q('[name=send_time]'); if (sendTime) { sendTime.addEventListener('change', refresh); }
    qa('[name=preset]').forEach(function (r) {
        r.addEventListener('change', function () {
            qa('[data-rd-preset-card]').forEach(function (c) { c.classList.toggle('is-picked', c.getAttribute('data-rd-preset-card') === r.value); });
            q('[data-rd-custom]').hidden = r.value !== 'custom';
        });
    });

    // Errors about email land on the Email tab.
    @if($errors->has('emails')) openTab('em'); @endif
    refresh();
})();
</script>
@endpush
