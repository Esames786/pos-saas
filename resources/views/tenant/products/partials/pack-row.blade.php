{{-- PHARMACY-PACKS-1 — one pack: "1 Strip holds 10 Tablet". $i = position (or __I__ in the template). --}}
@php $n = is_numeric($i) ? $i + 1 : 1; @endphp
<li class="pack-row">
    <fieldset>
        <legend>Pack <span data-n>{{ $n }}</span></legend>
        <input type="hidden" name="packs[{{ $i }}][id]" value="{{ $row['id'] ?? '' }}">
        <div class="pack-grid">
            <div>
                <label class="form-label" for="pack-{{ $i }}-name">Name</label>
                <input id="pack-{{ $i }}-name" name="packs[{{ $i }}][name]" data-f="name" list="pack-names" maxlength="{{ \App\Support\PackUnits::MAX_NAME }}"
                       class="form-control @error('packs.' . $i . '.name') is-invalid @enderror" value="{{ $row['name'] ?? '' }}" placeholder="Strip" autocomplete="off">
            </div>
            <div>
                <label class="form-label" for="pack-{{ $i }}-contains">One holds</label>
                <div class="pack-holds">
                    <input id="pack-{{ $i }}-contains" name="packs[{{ $i }}][contains]" data-f="contains" inputmode="numeric" pattern="[0-9]*"
                           class="form-control @error('packs.' . $i . '.contains') is-invalid @enderror" value="{{ $row['contains'] ?? '' }}" placeholder="10"
                           aria-describedby="pack-{{ $i }}-eq">
                    <span class="of" data-prev aria-hidden="true">{{ $packBaseName }}</span>
                </div>
            </div>
            <div>
                <label class="form-label" for="pack-{{ $i }}-price">Price (Rs)</label>
                <input id="pack-{{ $i }}-price" name="packs[{{ $i }}][price]" data-f="price" inputmode="decimal"
                       class="form-control @error('packs.' . $i . '.price') is-invalid @enderror" value="{{ $row['price'] ?? '' }}" placeholder="Auto">
            </div>
            <div class="pack-bc">
                <label class="form-label" for="pack-{{ $i }}-barcode">Barcode on this pack</label>
                <div class="input-group">
                    <input id="pack-{{ $i }}-barcode" name="packs[{{ $i }}][barcode]" data-f="barcode" inputmode="numeric" autocomplete="off"
                           class="form-control @error('packs.' . $i . '.barcode') is-invalid @enderror" value="{{ $row['barcode'] ?? '' }}" placeholder="Scan or make">
                    <button type="button" class="btn btn-outline-secondary" data-make-barcode>Make</button>
                    <button type="button" class="btn btn-outline-primary" data-labels>Labels</button>
                </div>
            </div>
        </div>
        <div class="pack-flags">
            <span class="pack-eq" id="pack-{{ $i }}-eq" data-eq></span>
            <span class="form-check m-0">
                <input type="hidden" name="packs[{{ $i }}][sell]" value="0">
                <input class="form-check-input" type="checkbox" name="packs[{{ $i }}][sell]" value="1" id="pack-{{ $i }}-sell" @checked($tick($row, 'sell'))>
                <label class="form-check-label" for="pack-{{ $i }}-sell">Sold at the counter</label>
            </span>
            <span class="form-check m-0">
                <input type="hidden" name="packs[{{ $i }}][buy]" value="0">
                <input class="form-check-input" type="checkbox" name="packs[{{ $i }}][buy]" value="1" id="pack-{{ $i }}-buy" @checked($tick($row, 'buy'))>
                <label class="form-check-label" for="pack-{{ $i }}-buy">Bought from the supplier</label>
            </span>
            <button type="button" class="pack-remove ms-auto" data-remove aria-label="Remove pack {{ $row['name'] ?? '' }}">✕ Remove</button>
        </div>
        @foreach(['name', 'contains', 'price', 'barcode'] as $f)
            @error('packs.' . $i . '.' . $f)<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror
        @endforeach
    </fieldset>
</li>
