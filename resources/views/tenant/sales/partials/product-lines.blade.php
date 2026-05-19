{{-- Reusable product lines table. Expects: $products, $tableId (optional), $caption (optional) --}}
<div class="table-responsive">
    <table class="table table-nowrap align-middle mb-0" id="{{ $tableId ?? 'sales-lines-table' }}">
        <caption class="visually-hidden">{{ $caption ?? 'Sale product lines' }}</caption>
        <thead>
        <tr>
            <th scope="col">Product</th>
            <th scope="col">Variant</th>
            <th scope="col" style="width:90px">Qty</th>
            <th scope="col" style="width:110px">Unit Price</th>
            <th scope="col" style="width:100px">Discount</th>
            <th scope="col" style="width:100px">Tax</th>
            <th scope="col" style="width:110px">Line Total</th>
            <th scope="col" style="width:40px"></th>
        </tr>
        </thead>
        <tbody class="lines-body">
        @php $lineCount = max(count(old('lines', [])), 3); @endphp
        @for($i = 0; $i < $lineCount; $i++)
        <tr class="line-row">
            <td>
                <select name="lines[{{ $i }}][product_id]"
                        class="form-select form-select-sm product-select"
                        aria-label="Product for line {{ $i + 1 }}">
                    <option value="">— Select —</option>
                    @foreach($products as $product)
                        @php
                            $bpMap = $product->branchPrices->whereNull('product_variant_id')
                                ->pluck('selling_price', 'branch_id')->toArray();
                            $bcList = $product->barcodes->pluck('barcode')->implode(',');
                            $variantData = $product->variants->map(fn($v) => [
                                'id' => $v->id, 'name' => $v->name,
                                'selling_price' => (string)($v->selling_price ?? '0'),
                                'branch_prices' => $product->branchPrices
                                    ->where('product_variant_id', $v->id)
                                    ->pluck('selling_price', 'branch_id')->toArray(),
                            ]);
                        @endphp
                        <option value="{{ $product->id }}"
                                data-price="{{ $product->default_selling_price ?? 0 }}"
                                data-taxable="{{ $product->is_taxable ? 1 : 0 }}"
                                data-tax-rate="{{ $product->tax_rate_percent ?? 0 }}"
                                data-branch-prices="{{ json_encode($bpMap) }}"
                                data-barcodes="{{ $bcList }}"
                                data-variants="{{ $variantData->toJson() }}"
                                @selected(old("lines.$i.product_id") == $product->id)>
                            {{ $product->name }}{{ $product->sku ? ' — ' . $product->sku : '' }}
                        </option>
                    @endforeach
                </select>
            </td>
            <td>
                <select name="lines[{{ $i }}][product_variant_id]"
                        class="form-select form-select-sm variant-select"
                        aria-label="Variant for line {{ $i + 1 }}">
                    <option value="">—</option>
                </select>
            </td>
            <td>
                <input type="number" step="0.001" min="0.001"
                       name="lines[{{ $i }}][quantity]"
                       class="form-control form-control-sm qty-input"
                       aria-label="Quantity for line {{ $i + 1 }}"
                       value="{{ old("lines.$i.quantity", 1) }}">
            </td>
            <td>
                <input type="number" step="0.01" min="0"
                       name="lines[{{ $i }}][unit_price]"
                       class="form-control form-control-sm price-input"
                       aria-label="Unit price for line {{ $i + 1 }}"
                       value="{{ old("lines.$i.unit_price", 0) }}">
            </td>
            <td>
                <input type="number" step="0.01" min="0"
                       name="lines[{{ $i }}][discount_amount]"
                       class="form-control form-control-sm disc-input"
                       aria-label="Discount for line {{ $i + 1 }}"
                       value="{{ old("lines.$i.discount_amount", 0) }}">
            </td>
            <td>
                <input type="number" step="0.01" min="0"
                       name="lines[{{ $i }}][tax_amount]"
                       class="form-control form-control-sm tax-input"
                       aria-label="Tax for line {{ $i + 1 }}"
                       value="{{ old("lines.$i.tax_amount", 0) }}">
            </td>
            <td><span class="line-total fw-medium">0.00</span></td>
            <td>
                <button type="button" class="btn btn-sm btn-link text-danger remove-line"
                        aria-label="Remove line {{ $i + 1 }}">
                    <i class="ti ti-x" aria-hidden="true"></i>
                </button>
            </td>
        </tr>
        @endfor
        </tbody>
    </table>
</div>
