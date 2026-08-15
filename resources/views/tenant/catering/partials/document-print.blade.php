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
                        @foreach($printers as $printer)
                            <option value="{{ $printer->id }}">
                                {{ $printer->name }}@if($printer->paper_size) · {{ $printer->paper_size }}@endif
                            </option>
                        @endforeach
                    </select>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="reprint" value="1" id="reprint-{{ md5($action) }}">
                        <label class="form-check-label fs-12" for="reprint-{{ md5($action) }}">
                            Mark as a reprint (extra copy)
                        </label>
                    </div>

                    <div class="alert alert-light border py-2 px-2 fs-12 mb-2">
                        <i class="ti ti-language-off me-1"></i><strong>English only.</strong>
                        A thermal printer cannot render Urdu — use the A4 document for that.
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
