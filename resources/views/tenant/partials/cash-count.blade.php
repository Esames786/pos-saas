@if($currency && $currency->denominations->count())
    <fieldset class="border rounded p-3 mb-3">
        <legend class="float-none w-auto px-2 fs-6">Optional Cash Denomination Count</legend>

        <p class="form-help">Leave all quantities empty if you want to enter counted cash manually.</p>

        <div class="row">
            @foreach($currency->denominations->sortByDesc('denomination_value') as $denomination)
                <div class="col-md-3 col-sm-6 mb-3">
                    <label for="denomination_{{ $denomination->id }}" class="form-label">
                        {{ $currency->symbol }} {{ number_format($denomination->denomination_value, 2) }}
                        <span class="badge bg-light text-dark ms-1">{{ ucfirst($denomination->denomination_type) }}</span>
                    </label>
                    <input
                        type="number"
                        min="0"
                        step="1"
                        id="denomination_{{ $denomination->id }}"
                        name="denominations[{{ $denomination->id }}]"
                        class="form-control cash-denomination"
                        data-value="{{ $denomination->denomination_value }}"
                        value="{{ old('denominations.' . $denomination->id, 0) }}"
                        aria-label="{{ $currency->symbol }} {{ number_format($denomination->denomination_value, 2) }} quantity"
                    >
                </div>
            @endforeach
        </div>

        <div class="alert alert-light mb-0" aria-live="polite">
            Calculated Cash Count: <strong id="cash-count-total">0.00</strong>
        </div>
    </fieldset>

    @push('scripts')
    <script>
        (function () {
            function updateCashCountTotal() {
                var total = 0;
                document.querySelectorAll('.cash-denomination').forEach(function (input) {
                    var qty = parseInt(input.value || '0', 10);
                    var val = parseFloat(input.dataset.value || '0');
                    total += qty * val;
                });
                var el = document.getElementById('cash-count-total');
                if (el) el.innerText = total.toFixed(2);
            }
            document.querySelectorAll('.cash-denomination').forEach(function (input) {
                input.addEventListener('input', updateCashCountTotal);
            });
            updateCashCountTotal();
        })();
    </script>
    @endpush
@else
    <div class="alert alert-warning" role="alert">
        <i class="ti ti-alert-triangle me-1"></i>
        No default currency denominations found. <a href="{{ url('/currencies') }}">Configure currencies first.</a>
    </div>
@endif
