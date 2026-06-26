<tr data-component-row>
    <td>
        <select name="components[{{ $index }}][product_id]" class="form-select" required>
            <option value="">Select product</option>
            @foreach($products as $product)
                <option value="{{ $product->id }}" @selected((string) ($row['product_id'] ?? '') === (string) $product->id)>
                    {{ $product->name }} @if($product->sku) ({{ $product->sku }}) @endif
                </option>
            @endforeach
        </select>
    </td>
    <td>
        <select name="components[{{ $index }}][product_variant_id]" class="form-select">
            <option value="">Default variant</option>
            @foreach($variants as $variant)
                <option value="{{ $variant->id }}" @selected((string) ($row['product_variant_id'] ?? '') === (string) $variant->id)>
                    {{ $variant->name }} @if($variant->sku) ({{ $variant->sku }}) @endif
                </option>
            @endforeach
        </select>
    </td>
    <td>
        <input type="number" step="0.001" min="0.001" name="components[{{ $index }}][quantity]" class="form-control" value="{{ $row['quantity'] ?? 1 }}" required>
    </td>
    <td>
        <input type="number" min="0" name="components[{{ $index }}][sort_order]" class="form-control" value="{{ $row['sort_order'] ?? '' }}">
    </td>
    <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-danger" data-remove-component>
            <i class="ti ti-trash"></i>
        </button>
    </td>
</tr>
