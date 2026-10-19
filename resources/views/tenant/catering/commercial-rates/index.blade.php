@extends('layouts.app')

@section('title', 'Commercial Material Rates')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Commercial Charge Rates</h1>
        <p class="fw-medium mb-0">
            The recommended <strong>customer charge</strong> for a material — not what it costs us.
            Changing a rate here never reprices an existing quotation on its own.
        </p>
        <div class="fs-13 mt-1">
            Looking for what a material <strong>costs the business</strong>?
            <a href="{{ url('/catering/material-rates') }}">Material Cost Rates &rsaquo;</a>
        </div>
    </div>
    @can('tenant.catering.commercial-rates.store')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rateModal">
            <i class="ti ti-plus me-1"></i>Set a rate
        </button>
    @endcan
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.screen-impact', [
    'manages' => 'The house price for each material — what a customer is charged for chicken, per kilo of chicken.',
    'managesUr' => 'ہر خام مال کی وہ قیمت جو گاہک سے لی جاتی ہے۔',
    'reversible' => 'safe',
    'note' => 'Setting a rate here reprices nothing on its own. It records what the house now charges, and the impact review is where you choose which dishes and which quotations should follow it.',
    'noteUr' => 'یہاں ریٹ بدلنے سے کوئی قیمت خود بخود تبدیل نہیں ہوتی۔',
])

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

{{-- The distinction this whole screen exists to hold. Two books, two questions,
     and they move for entirely different reasons. --}}
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card h-100 border-primary-subtle">
            <div class="card-body">
                <h6 class="mb-2"><i class="ti ti-receipt-2 text-primary me-1"></i>Commercial Charge Rate</h6>
                <p class="mb-0 fs-13">
                    What the <strong>customer pays</strong> for a material — chicken at 120 a kilo of chicken.
                    A commercial decision. This screen.
                </p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100 border-secondary-subtle">
            <div class="card-body">
                <h6 class="mb-2"><i class="ti ti-truck-delivery text-secondary me-1"></i>Material Cost Rate</h6>
                <p class="mb-2 fs-13">
                    What the material <strong>costs us</strong> to buy — chicken at 80 a kilo. A purchasing fact.
                </p>
                <a href="{{ url('/catering/material-rates') }}" class="fs-13">Material Cost Rates &rsaquo;</a>
            </div>
        </div>
    </div>
</div>

{{-- Recorded, dated, and not yet in force. Shown apart from the current rates
     rather than above them: a rate that starts next Monday listed as "current"
     is how somebody quotes today at a price nobody is charged today. --}}
@if($scheduled->isNotEmpty())
<div class="card mb-3 border-warning-subtle">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0"><i class="ti ti-clock text-warning me-1"></i>Scheduled — not in effect yet</h5>
        <span class="text-muted fs-12">Nothing is charged at these rates until their date arrives</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Material</th>
                    <th class="text-end">Will be charged</th>
                    <th>Unit</th>
                    <th>Starts</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
            @foreach($scheduled as $rate)
                <tr>
                    <td>{{ $rate->product?->name }}</td>
                    <td class="text-end">{{ number_format($rate->rate, 2) }}</td>
                    <td>{{ $rate->unit?->code ?? '—' }}</td>
                    <td>{{ $rate->effective_from?->format('d M Y') }}</td>
                    <td class="text-muted fs-13">{{ $rate->note }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0">Current house rates</h5>
        <span class="text-muted fs-12">In force today</span>
    </div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>Material</th>
                    <th class="text-end">Charged per unit</th>
                    <th>Unit</th>
                    <th>In effect since</th>
                    <th>Note</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($rates as $rate)
                <tr>
                    <td>
                        {{ $rate->product?->name }}
                        <div class="text-muted fs-12">{{ $rate->product?->sku }}</div>
                    </td>
                    <td class="text-end fw-semibold">{{ number_format($rate->rate, 2) }}</td>
                    <td>{{ $rate->unit?->code ?? '—' }}</td>
                    <td>{{ $rate->effective_from?->format('d M Y') }}</td>
                    <td class="text-muted fs-13">{{ $rate->note }}</td>
                    <td class="text-end">
                        @can('tenant.catering.commercial-rates.impact')
                            <a href="{{ url('/catering/commercial-rates/' . $rate->product_id . '/impact') }}"
                               class="btn btn-sm btn-light">What would change?</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">
                    No commercial rates set yet. A dish can still carry its own rate typed by hand.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Every commercial decision, in the order they were made. Two rates on one day
     are not an inconsistency to be tidied away — they are two decisions, and a
     quotation applied against the morning's one has to stay explicable. --}}
@if($history->isNotEmpty())
<div class="card mt-4">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0">Rate history</h5>
        <span class="text-muted fs-12">Nothing here is ever overwritten</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Material</th>
                    <th class="text-end">Charged per unit</th>
                    <th>Unit</th>
                    <th>In effect from</th>
                    <th>Recorded</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
            @foreach($history as $row)
                <tr>
                    <td>{{ $row->product?->name }}</td>
                    <td class="text-end">{{ number_format($row->rate, 2) }}</td>
                    <td>{{ $row->unit?->code ?? '—' }}</td>
                    <td>{{ $row->effective_from?->format('d M Y') }}</td>
                    <td class="text-muted fs-13">{{ $row->created_at?->format('d M Y H:i') }}</td>
                    <td class="text-muted fs-13">{{ $row->note }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@can('tenant.catering.commercial-rates.store')
<div class="modal fade" id="rateModal" tabindex="-1">
    {{-- Malik: "modal ko size xl karo." Do chunav, ginti, aur pichli rates —
         sab ek saath dekhne ke liye chaurai chahiye. --}}
    <div class="modal-dialog modal-xl">
        <form method="POST" action="{{ url('/catering/commercial-rates') }}" class="modal-content"
              data-rate-history='@json($historyByMaterial ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)'>
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Set a commercial rate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border fs-13">
                    <i class="ti ti-info-circle me-1"></i>
                    This records what the house now charges. It does not reprice any dish or any
                    quotation — you choose what follows it on the next screen.
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Material <span class="text-danger">*</span></label>
                        <select name="product_id" class="form-select" required>
                            <option value="">Choose a material…</option>
                            @foreach($materials as $material)
                                <option value="{{ $material->id }}">{{ $material->name }} ({{ $material->sku }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Charged per unit <span class="text-danger">*</span></label>
                        <input type="number" step="0.0001" min="0" name="rate" class="form-control" required>
                        <div class="form-text">Per unit of the material, e.g. per KG of chicken.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Unit <span class="text-danger">*</span></label>
                        <select name="unit_id" class="form-select" required>
                            <option value="">Choose a unit…</option>
                            @foreach($units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->code }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            A rate of 120 means nothing until it says 120 per what. A dish can only
                            follow this rate if it measures the material in the same unit.
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">In effect from <span class="text-danger">*</span></label>
                        <input type="date" name="effective_from" class="form-control"
                               value="{{ app(\App\Support\TenantClock::class)->now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Note</label>
                        <input type="text" name="note" class="form-control" maxlength="255"
                               placeholder="e.g. market rose">
                        <div class="form-text">
                            Every rate you record is kept, including a second one on the same day.
                            The most recent one is the current one.
                        </div>
                    </div>

                    {{-- RATE-APPLY-ON-SAVE-1 (28 Sep) — malik: "rate change karte
                         he do checkbox dedo, click karne pe sab pe apply ho jaye."

                         Pehle rate LIKHNA aur rate LAGANA do alag safhe thay, aur
                         doosra safha itna lamba tha ke us ka asal button neeche
                         dab jata tha. Ab chunav wahin hai jahan rate likha jata
                         hai.

                         Har tick ke saath GINTI likhi hai. Ek tick jis ke saath
                         koi adad na ho wo chunav nahi, andaza hai — aur yahan
                         andaza lagana ek hi click me sau qeematein hila deta
                         hai. --}}
                    <div class="col-12">
                        <div class="border rounded p-2" id="rate-apply-choices"
                             data-block-counts='@json($blockCounts ?? [], JSON_UNESCAPED_SLASHES)'>
                            {{-- Malik ne is poore hisse par laal cross laga kar
                                 likha: "COLLAPSED".

                                 Band hai, magar KHALI NAHI. Unwaan par kul ginti
                                 likhi rehti hai, is liye band haalat me bhi ye
                                 nazar aata hai ke save kitni dishes ko chhuega —
                                 aur "Sab par" bahar hi rehta hai, kyunke rozana
                                 ka kaam wahi ek tick hai. Andar ki do satrein
                                 sirf tab chahiyen jab operator un me se ek
                                 chhorna chahe.

                                 Ginti chhupa dena aasan tha aur ghalat hota: phir
                                 ye "chhupana" nahi, "mitana" hota. --}}
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <span class="fw-semibold fs-13" role="button"
                                      data-bs-toggle="collapse" data-bs-target="#apply-which">
                                    Save karte hi kin dishes par lag jaye?
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1"
                                          data-count="total">—</span>
                                    <i class="ti ti-chevron-down ms-1 text-muted"></i>
                                </span>
                                {{-- "Sab par" apna koi alag amal nahi hai — sirf
                                     andar wale dono ko ek saath uthata aur girata
                                     hai. Ek teesra raasta banane ka matlab hota
                                     teesri jagah jo kal in dono se alag ho jati. --}}
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="checkbox" id="apply-all" checked>
                                    <label class="form-check-label fs-13 fw-semibold" for="apply-all">Sab par</label>
                                </div>
                            </div>

                            <div class="collapse mt-2" id="apply-which">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="apply_following"
                                       value="1" id="apply-following" checked>
                                <label class="form-check-label fs-13" for="apply-following">
                                    Jo dishes pehle se house rate par hain
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1"
                                          data-count="book">—</span>
                                </label>
                            </div>

                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="apply_manual"
                                       value="1" id="apply-manual" checked>
                                <label class="form-check-label fs-13" for="apply-manual">
                                    Jo haath se likhi gayi hain, unhe bhi house rate par le aao
                                    <span class="badge bg-warning-subtle text-warning-emphasis ms-1"
                                          data-count="manual">—</span>
                                </label>
                            </div>

                            </div>

                            {{-- Malik: "neeche ki detail hide ho, collapse ho, show
                                 karne pe pata ho neeche kya hai."

                                 Tafseel chhupi hai, magar us ka UNWAAN nazar aata
                                 hai — warna ye chhupana nahi, mitana hota. Jo
                                 operator pehli baar ye safha khol raha hai usay
                                 parhna chahiye; jo rozana kholta hai usay har baar
                                 nahi. --}}
                            <button class="btn btn-link btn-sm p-0 fs-12 text-decoration-none mt-2" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#apply-notes">
                                <i class="ti ti-info-circle me-1"></i>Ye kya karega?
                                <i class="ti ti-chevron-down ms-1"></i>
                            </button>
                            <div class="collapse" id="apply-notes">
                                <div class="fs-12 text-body-secondary mt-1">
                                    <div>
                                        <strong>Haath wali dishes:</strong> in ka rate kisi ne alag rakha tha.
                                        Tick lagane par wo house rate par aa jayengi. Har tabdeeli log me darj hoti hai.
                                    </div>
                                    <div class="mt-1">
                                        <strong>Pehle se bani hui quotations kisi soorat nahi badaltin</strong> —
                                        un ke liye impact screen hai, jahan har booking ka pehle/baad ka total dikhta hai.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    @include('tenant.catering.partials.rate-history-panel', [
                        'historyLabel' => 'Is material ki pichli house rates',
                    ])
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Record rate</button>
            </div>
        </form>
    </div>
</div>

{{-- Ginti material ke sath badalti hai. Alag IIFE — history panel ka apna
     handler hai aur us me ghusna dono ko aapas me uljha deta. --}}
<script>
    (function () {
        const box = document.getElementById('rate-apply-choices');
        if (! box) return;
        const form = box.closest('form');
        const product = form ? form.querySelector('[name=product_id]') : null;
        if (! product) return;

        let counts = {};
        try {
            counts = JSON.parse(box.dataset.blockCounts || '{}');
        } catch (e) {
            return; // Kharab data modal ko sath le kar na doobe.
        }

        function paint() {
            const row = counts[product.value] || {book: 0, manual: 0};
            const total = (row.book || 0) + (row.manual || 0);

            box.querySelectorAll('[data-count]').forEach(function (el) {
                const n = el.dataset.count === 'total' ? total : (row[el.dataset.count] || 0);
                el.textContent = n === 1 ? '1 dish' : n + ' dishes';

                const check = el.closest('.form-check');
                if (! check) return; // unwaan wali ginti ka apna koi tick nahi

                // MALIK: "all pe check pehle se ho jaise hai, main item pe select
                // karun." Pehle yahan keeda tha: ginti 0 par tick hata diya jata
                // tha aur material chunne par DOBARA NAHI lagta tha — yani chunav
                // ke baad sab khali mil te. Ab har material par default wapas aa
                // jata hai, aur jis par kuch hai hi nahi us par tick ka matlab
                // hi nahi.
                const input = check.querySelector('input');
                input.disabled = n === 0;
                input.checked = n > 0;
            });
        }

        // "Sab par" ka apna koi asar nahi — wo sirf dono ko ek saath uthata hai,
        // aur agar koi ek haath se hata diya jaye to khud bhi utar jata hai.
        const all = document.getElementById('apply-all');
        const boxes = Array.from(box.querySelectorAll('input[name^=apply_]'));

        function syncAll() {
            const live = boxes.filter(b => ! b.disabled);
            all.checked = live.length > 0 && live.every(b => b.checked);
            all.disabled = live.length === 0;
        }

        all.addEventListener('change', function () {
            boxes.forEach(function (b) { if (! b.disabled) b.checked = all.checked; });
        });
        boxes.forEach(b => b.addEventListener('change', syncAll));

        product.addEventListener('change', function () { paint(); syncAll(); });
        paint();
        syncAll();

        // Ab dono tick DEFAULT ON hain (malik ki sarih maang). Is liye save par
        // ek confirm lazmi hai jo GINTI bolta hai: ek default-on tick wo cheez
        // hai jo bina dekhe chal jati hai, aur yahan us ka matlab ek click me
        // sau qeematein hai.
        form.addEventListener('submit', function (e) {
            const row = counts[product.value] || {book: 0, manual: 0};
            const f = document.getElementById('apply-following');
            const m = document.getElementById('apply-manual');
            const parts = [];
            if (f && f.checked && ! f.disabled && row.book) parts.push(row.book + ' dishes jo pehle se house rate par hain');
            if (m && m.checked && ! m.disabled && row.manual) parts.push(row.manual + ' dishes jo haath se likhi gayi hain');
            if (! parts.length) return;

            const rate = form.querySelector('[name=rate]');
            if (! confirm('Ye rate (' + (rate ? rate.value : '') + ') in par lag jayega:\n\n· '
                    + parts.join('\n· ')
                    + '\n\nKisi dish ka rate BARH bhi sakta hai aur GIR bhi. Pehle se bani hui quotations nahi badlengi.\n\nAage barhein?')) {
                e.preventDefault();
            }
        });
    })();
</script>
@endcan
@endsection
