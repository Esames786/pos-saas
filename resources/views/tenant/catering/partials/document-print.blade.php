{{--
    KASHIF-CATERING-PRODUCT-UX-1 (item 7) — send a customer document straight to
    a printer, over the same print_jobs transport the kitchen sheet already uses.

    Two deliberate choices:

    English only, stated up front. The ESC/POS path emits plain bytes with no
    codepage selection and no raster image support, so Urdu genuinely cannot be
    rendered thermally. The control says so rather than offering a language
    choice that would fail after the click — and the server refuses it too, so
    the honesty is not merely cosmetic.

    Printing posts nothing. Queueing writes one row in print_jobs and touches no
    journal, no stock and no invoice, so a reprint can never produce a second
    final invoice. The button says that where the operator will read it.

    Parameters: action, label, printers, permission
--}}
@php
    // Kagaz DOCUMENT se aata hai, printer se nahi — aur wo faisla ek hi jagah
    // hota hai. Yahan dobara likhna do jagah do jawab bana deta.
    $dpPaper = \App\Services\Catering\CateringDocumentQueueService::paperLabel($kind ?? 'quotation');
@endphp
@can($permission)
    @if($printers->isNotEmpty())
        <div class="dropdown d-inline-block">
            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="ti ti-device-desktop me-1"></i>{{ $label }}
            </button>
            <div class="dropdown-menu p-3" style="min-width: 20rem">
                <form method="POST" action="{{ $action }}">
                    @csrf
                    <input type="hidden" name="lang" value="en">

                    <label class="form-label fs-12 text-uppercase text-muted">Printer</label>
                    <select name="printer_id" class="form-select form-select-sm mb-2" required>
                        {{-- PRINTER KE NAAM KE SAATH KAGAZ NAHI LIKHA JATA.

                             Pehle yahan "Office — HP M127fn · A5" likha aata tha,
                             aur theek niche isi panel me "Paper size is set for you"
                             bhi likha tha. Dono ek saath jhoot bolte thay: malik ne
                             11 Oct ko A4 ki invoice par "· A5" dekh kar poochha ke
                             "ye confusion kaise door hogi?"

                             Dono HP A4 aur A5 dono chhapte hain. `paper_size` ab sirf
                             itna tay karta hai ke kaun sa printer PEHLE SE chuna aaye
                             — kisi ko rokta nahi, aur naam ke saath likhne layak nahi. --}}
                        @foreach($printers as $printer)
                            <option value="{{ $printer->id }}"
                                @selected(strtoupper((string) ($printer->paper_size ?? '')) === $dpPaper)>{{ $printer->name }}</option>
                        @endforeach
                    </select>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="reprint" value="1" id="reprint-{{ md5($action) }}">
                        <label class="form-check-label fs-12" for="reprint-{{ md5($action) }}">
                            Mark as a reprint (extra copy)
                        </label>
                    </div>

                    @php
                        // CATERING-SEND-TO-PRINTER-1 — ye tanbeeh IS fehrist par
                        // munhasir hai. Jahan koi thermal printer hai hi nahi,
                        // wahan "Urdu nahi chhap sakti" likhna jhoot hai: A4/A5
                        // par wo bilkul theek chhapti hai, aur ye poora kaam usi
                        // ke liye hua hai. Ek aisi tanbeeh jo har waqt likhi ho,
                        // parhi jana chhor deti hai.
                        $anyThermal = collect($printers)->contains(
                            fn ($p) => ($p->printer_type ?? null) !== \App\Models\Tenant\Printer::TYPE_WINDOWS
                        );
                    @endphp
                    <div class="alert alert-light border py-2 px-2 fs-12 mb-2">
                        @if($anyThermal)
                            <i class="ti ti-language-off me-1"></i><strong>Thermal is English only.</strong>
                            A thermal printer cannot render Urdu — pick an A4/A5 printer for that.
                        @else
                            <i class="ti ti-printer me-1"></i><strong>Ye document {{ $dpPaper }} par chhapega.</strong>
                            The document decides A4 or A5 — you do not have to pick it.
                        @endif
                        <span class="d-block mt-1 text-muted">
                            <i class="ti ti-cash-off me-1"></i>Printing posts nothing to finance and moves no stock.
                        </span>
                    </div>

                    <button class="btn btn-sm btn-primary w-100" type="submit">
                        <i class="ti ti-send me-1"></i>Queue to printer
                    </button>
                </form>
            </div>
        </div>
    @else
        <button class="btn btn-outline-secondary" disabled
                data-bs-toggle="tooltip"
                title="No active printer is configured. Add one under Printing › Printers, then it will appear here.">
            <i class="ti ti-device-desktop me-1"></i>{{ $label }}
        </button>
    @endif
@endcan
