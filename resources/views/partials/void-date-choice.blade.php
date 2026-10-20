{{-- VOID-ON-ORIGINAL-DATE-1 — the question every money void asks. Include inside the void <form>:
     @include('partials.void-date-choice', ['originalDate' => $payment->payment_date, 'idPrefix' => 'pay'])
     Posts `void_date_mode` = original | today. Nothing is pre-picked: the person must answer, because the two
     choices move different days' balances. A form left without it is read as "today" (the old behaviour).
     `originalDate` may be null when the page fills it per row (the Cash Book modal sets data-rd-original). --}}
@php
    $vdPrefix = $idPrefix ?? 'vd';
    $vdDate = isset($originalDate) && $originalDate ? \Illuminate\Support\Carbon::parse($originalDate) : null;
    $vdDateText = $vdDate ? $vdDate->format('d M Y') : null;
    $vdToday = now()->format('d M Y');
@endphp
<fieldset class="vd-choice mb-3" data-vd-choice>
    <legend class="form-label fw-semibold mb-1" style="font-size:inherit">Which day should this be undone on? <span class="text-danger">*</span></legend>
    <div class="small text-muted mb-2">Kis din ulta ho? Dono mein se ek chunein.</div>
    <div class="d-grid gap-2">
        <label class="vd-card border rounded-3 p-2 d-flex gap-2 align-items-start" for="{{ $vdPrefix }}-vd-original" style="cursor:pointer">
            <input class="form-check-input mt-1" type="radio" name="void_date_mode" value="{{ \App\Support\VoidDate::ORIGINAL }}" id="{{ $vdPrefix }}-vd-original" required>
            <span>
                <span class="fw-semibold d-block"><i class="ti ti-eraser me-1" aria-hidden="true"></i>{{ \App\Support\VoidDate::LABELS['original']['title'] }}</span>
                <span class="small text-muted d-block">{{ \App\Support\VoidDate::LABELS['original']['help'] }}</span>
                <span class="small d-block mt-1">Posted on <strong data-vd-original-text>{{ $vdDateText ?? 'its own date' }}</strong> — that day's balance and every day after it come out as if this never happened.</span>
            </span>
        </label>
        <label class="vd-card border rounded-3 p-2 d-flex gap-2 align-items-start" for="{{ $vdPrefix }}-vd-today" style="cursor:pointer">
            <input class="form-check-input mt-1" type="radio" name="void_date_mode" value="{{ \App\Support\VoidDate::TODAY }}" id="{{ $vdPrefix }}-vd-today" required>
            <span>
                <span class="fw-semibold d-block"><i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i>{{ \App\Support\VoidDate::LABELS['today']['title'] }}</span>
                <span class="small text-muted d-block">{{ \App\Support\VoidDate::LABELS['today']['help'] }}</span>
                <span class="small d-block mt-1">Posted on <strong>{{ $vdToday }}</strong> — {{ $vdDateText ? 'the books for ' . $vdDateText . ' to yesterday stay as they are.' : 'earlier days stay as they are.' }}</span>
            </span>
        </label>
    </div>
</fieldset>
