<div class="table-responsive">
    <table class="table table-nowrap align-middle">
        <caption class="visually-hidden">{{ $caption ?? 'Product lines' }}</caption>
        <thead>
        <tr>
            <th scope="col">Product</th>
            <th scope="col">Variant</th>

            @if($showBatch ?? false)
                <th scope="col">Batch No</th>
                <th scope="col">Expiry</th>
            @endif

            <th scope="col">Quantity</th>
            <th scope="col">Unit Cost</th>

            @if($showDiscountTax ?? true)
                <th scope="col">Discount</th>
                <th scope="col">Tax</th>
            @endif

            @if($showNotes ?? false)
                <th scope="col">Notes</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @for($i = 0; $i < 5; $i++)
            <tr>
                <td>
                    <label for="lines_{{ $i }}_product_id" class="visually-hidden">Product line {{ $i + 1 }}</label>
                    <select id="lines_{{ $i }}_product_id" name="lines[{{ $i }}][product_id]"
                            class="form-select form-select-sm">
                        <option value="">— Select —</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}">
                                {{ $product->name }} ({{ $product->sku }})
                            </option>
                        @endforeach
                    </select>
                </td>
                <td>
                    <label for="lines_{{ $i }}_product_variant_id" class="visually-hidden">Variant line {{ $i + 1 }}</label>
                    <select id="lines_{{ $i }}_product_variant_id" name="lines[{{ $i }}][product_variant_id]"
                            class="form-select form-select-sm">
                        <option value="">Default</option>
                        @foreach($products as $product)
                            @foreach($product->variants->where('is_default', false) as $variant)
                                <option value="{{ $variant->id }}">
                                    {{ $product->name }} / {{ $variant->name }}
                                </option>
                            @endforeach
                        @endforeach
                    </select>
                </td>

                @if($showBatch ?? false)
                    <td>
                        <label for="lines_{{ $i }}_batch_no" class="visually-hidden">Batch number line {{ $i + 1 }}</label>
                        <input id="lines_{{ $i }}_batch_no" type="text" name="lines[{{ $i }}][batch_no]"
                               class="form-control form-control-sm" maxlength="100">
                    </td>
                    <td>
                        <label for="lines_{{ $i }}_expiry_date" class="visually-hidden">Expiry date line {{ $i + 1 }}</label>
                        <input id="lines_{{ $i }}_expiry_date" type="date" name="lines[{{ $i }}][expiry_date]"
                               class="form-control form-control-sm">
                    </td>
                @endif

                <td>
                    <label for="lines_{{ $i }}_quantity" class="visually-hidden">Quantity line {{ $i + 1 }}</label>
                    <input id="lines_{{ $i }}_quantity" type="number" step="0.001" min="0"
                           name="lines[{{ $i }}][{{ $quantityField ?? 'quantity_ordered' }}]"
                           class="form-control form-control-sm">
                </td>
                <td>
                    <label for="lines_{{ $i }}_unit_cost" class="visually-hidden">Unit cost line {{ $i + 1 }}</label>
                    <input id="lines_{{ $i }}_unit_cost" type="number" step="0.0001" min="0"
                           name="lines[{{ $i }}][unit_cost]"
                           class="form-control form-control-sm" value="0">
                </td>

                @if($showDiscountTax ?? true)
                    <td>
                        <label for="lines_{{ $i }}_discount_amount" class="visually-hidden">Discount line {{ $i + 1 }}</label>
                        <input id="lines_{{ $i }}_discount_amount" type="number" step="0.01" min="0"
                               name="lines[{{ $i }}][discount_amount]"
                               class="form-control form-control-sm" value="0">
                    </td>
                    <td>
                        <label for="lines_{{ $i }}_tax_amount" class="visually-hidden">Tax line {{ $i + 1 }}</label>
                        <input id="lines_{{ $i }}_tax_amount" type="number" step="0.01" min="0"
                               name="lines[{{ $i }}][tax_amount]"
                               class="form-control form-control-sm" value="0">
                    </td>
                @endif

                @if($showNotes ?? false)
                    <td>
                        <label for="lines_{{ $i }}_notes" class="visually-hidden">Notes line {{ $i + 1 }}</label>
                        <input id="lines_{{ $i }}_notes" type="text" name="lines[{{ $i }}][notes]"
                               class="form-control form-control-sm">
                    </td>
                @endif
            </tr>
        @endfor
        </tbody>
    </table>
</div>
