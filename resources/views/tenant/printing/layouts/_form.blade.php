@php $l = $layout ?? null; @endphp
<div class="col-md-4">
    <label class="form-label required">Paper Size</label>
    <select name="paper_size" class="form-select" required>
        @foreach(['58mm', '80mm', 'A4'] as $size)
            <option value="{{ $size }}" @selected(old('paper_size', $l?->paper_size) === $size)>{{ $size }}</option>
        @endforeach
    </select>
</div>
@php
    // These bands must match EscPosPayloadService::scaleFor(). The value drove only the browser
    // preview before — the thermal printer ignored it entirely, so raising it appeared to do
    // nothing on paper. It now selects the printer's character size as well.
    $scaleHelp = '8–14 normal · 15–17 tall · 18–20 tall &amp; wide (21 chars per line) · 21+ largest (14 chars)';
@endphp
<div class="col-md-4">
    <label class="form-label" for="font_size">Font Size (px)</label>
    <input id="font_size" type="number" name="font_size" value="{{ old('font_size', $l?->font_size ?? 12) }}" class="form-control" min="8" max="24">
    <div class="form-text">Receipt &amp; Reminder. {!! $scaleHelp !!}</div>
</div>
<div class="col-md-4">
    <label class="form-label" for="kot_font_size">KOT Font Size (px)</label>
    <input id="kot_font_size" type="number" name="kot_font_size" value="{{ old('kot_font_size', $l?->kot_font_size ?? 14) }}" class="form-control" min="8" max="24">
    <div class="form-text">Kitchen ticket. {!! $scaleHelp !!}</div>
</div>
<div class="col-md-6">
    <label class="form-label" for="item_font_size">Item Row Font (px)</label>
    <input id="item_font_size" type="number" name="item_font_size" value="{{ old('item_font_size', $l?->item_font_size) }}" class="form-control" min="8" max="24" placeholder="Same as document font">
    <div class="form-text">Just the item rows. Leave blank to match the document font above. {!! $scaleHelp !!}</div>
</div>
<div class="col-md-6">
    <label class="form-label" for="time_font_size">Time Line Font (px)</label>
    <input id="time_font_size" type="number" name="time_font_size" value="{{ old('time_font_size', $l?->time_font_size) }}" class="form-control" min="8" max="24" placeholder="Same as KOT font">
    <div class="form-text">KOT / Reminder TIME line only. Leave blank to match the KOT font. {!! $scaleHelp !!}</div>
</div>
<div class="col-12">
    <div class="alert alert-info py-2 mb-0 small">
        Bigger text means fewer characters per line — the printer does not wrap for you, so we wrap
        long item names onto extra lines. Above 17px a ticket roughly doubles in length.
    </div>
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
            'show_order_time'        => 'Order Time',
            'show_updated_time'      => 'Updated Time',
            'show_print_time'        => 'Print Time',
            'show_item_codes'        => 'Item Codes',
            'show_payment_breakdown' => 'Payment Breakdown',
            'show_bingoo_branding'   => 'Bingoo Branding',
            'show_delivery_details'  => 'Delivery Details',
            'show_vehicle_number'    => 'Vehicle Number',
            'show_order_type'        => 'Order Type',
            'show_column_dividers'   => 'Column Divider Lines',
            'show_category_header'   => 'KOT Category Header',
        ] as $field => $label)
        @php
            // Most toggles default ON for a brand-new layout; dividers are the exception (today's
            // tickets have none), so they default OFF until an operator turns them on.
            $switchDefault = $field === 'show_column_dividers' ? false : true;
        @endphp
        <div class="col-md-4 layout-option" data-layout-field="{{ $field }}">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1"
                       @checked(old($field, $l?->{$field} ?? $switchDefault))>
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
