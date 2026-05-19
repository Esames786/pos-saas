@php $l = $layout ?? null; @endphp
<div class="col-md-4">
    <label class="form-label required">Paper Size</label>
    <select name="paper_size" class="form-select" required>
        @foreach(['58mm', '80mm', 'A4'] as $size)
            <option value="{{ $size }}" @selected(old('paper_size', $l?->paper_size) === $size)>{{ $size }}</option>
        @endforeach
    </select>
</div>
<div class="col-md-4">
    <label class="form-label">Font Size (px)</label>
    <input type="number" name="font_size" value="{{ old('font_size', $l?->font_size ?? 12) }}" class="form-control" min="8" max="24">
</div>
<div class="col-md-4">
    <label class="form-label">KOT Font Size (px)</label>
    <input type="number" name="kot_font_size" value="{{ old('kot_font_size', $l?->kot_font_size ?? 14) }}" class="form-control" min="8" max="24">
</div>
<div class="col-12">
    <label class="form-label">Logo (image, max 1MB)</label>
    <input type="file" name="logo" class="form-control" accept="image/*">
    @if($l?->logo_path)
        <small class="text-muted">Current: {{ $l->logo_path }}</small>
    @endif
</div>
<div class="col-12">
    <label class="form-label">Header Text</label>
    <textarea name="header_text" class="form-control" rows="2" maxlength="500">{{ old('header_text', $l?->header_text) }}</textarea>
</div>
<div class="col-12">
    <label class="form-label">Footer Text</label>
    <textarea name="footer_text" class="form-control" rows="2" maxlength="500">{{ old('footer_text', $l?->footer_text) }}</textarea>
</div>
<div class="col-12">
    <h6 class="mb-2">Show / Hide Sections</h6>
    <div class="row g-2">
        @foreach([
            'show_logo'              => 'Logo',
            'show_branch_name'       => 'Branch Name',
            'show_branch_address'    => 'Branch Address',
            'show_branch_phone'      => 'Branch Phone',
            'show_tax_number'        => 'Tax Number',
            'show_cashier_name'      => 'Cashier Name',
            'show_customer_name'     => 'Customer Name',
            'show_table_info'        => 'Table Info',
            'show_order_no'          => 'Order No',
            'show_item_codes'        => 'Item Codes',
            'show_payment_breakdown' => 'Payment Breakdown',
        ] as $field => $label)
        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1"
                       @checked(old($field, $l?->{$field} ?? true))>
                <label class="form-check-label">{{ $label }}</label>
            </div>
        </div>
        @endforeach
    </div>
</div>
<div class="col-12">
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $l?->is_active ?? true))>
        <label class="form-check-label">Active</label>
    </div>
</div>
