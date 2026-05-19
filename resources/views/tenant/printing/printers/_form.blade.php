@php $p = $printer ?? null; @endphp
<div class="col-md-6">
    <label class="form-label required">Name</label>
    <input type="text" name="name" value="{{ old('name', $p?->name) }}" class="form-control" required maxlength="100">
</div>
<div class="col-md-6">
    <label class="form-label required">Code</label>
    <input type="text" name="code" value="{{ old('code', $p?->code) }}" class="form-control" required maxlength="50" style="text-transform:uppercase">
</div>
<div class="col-md-6">
    <label class="form-label">Branch</label>
    <select name="branch_id" class="form-select">
        <option value="">— All Branches —</option>
        @foreach($branches as $b)
            <option value="{{ $b->id }}" @selected(old('branch_id', $p?->branch_id) == $b->id)>{{ $b->name }}</option>
        @endforeach
    </select>
</div>
<div class="col-md-6">
    <label class="form-label required">Type</label>
    <select name="printer_type" class="form-select" required>
        @foreach(['browser' => 'Browser (print dialog)', 'network' => 'Network (IP/Port)', 'usb' => 'USB'] as $val => $label)
            <option value="{{ $val }}" @selected(old('printer_type', $p?->printer_type) === $val)>{{ $label }}</option>
        @endforeach
    </select>
</div>
<div class="col-md-6">
    <label class="form-label required">Role</label>
    <select name="print_role" class="form-select" required>
        @foreach(['receipt' => 'Receipt', 'kot' => 'KOT', 'both' => 'Both'] as $val => $label)
            <option value="{{ $val }}" @selected(old('print_role', $p?->print_role) === $val)>{{ $label }}</option>
        @endforeach
    </select>
</div>
<div class="col-md-6">
    <label class="form-label required">Paper Size</label>
    <select name="paper_size" class="form-select" required>
        @foreach(['58mm', '80mm', 'A4'] as $size)
            <option value="{{ $size }}" @selected(old('paper_size', $p?->paper_size) === $size)>{{ $size }}</option>
        @endforeach
    </select>
</div>
<div class="col-md-6">
    <label class="form-label">IP Address</label>
    <input type="text" name="ip_address" value="{{ old('ip_address', $p?->ip_address) }}" class="form-control" maxlength="50">
</div>
<div class="col-md-3">
    <label class="form-label">Port</label>
    <input type="number" name="port" value="{{ old('port', $p?->port ?? 9100) }}" class="form-control" min="1" max="65535">
</div>
<div class="col-md-3">
    <label class="form-label">Chars/Line</label>
    <input type="number" name="characters_per_line" value="{{ old('characters_per_line', $p?->characters_per_line ?? 42) }}" class="form-control" min="20" max="80">
</div>
<div class="col-12">
    <label class="form-label">Notes</label>
    <input type="text" name="notes" value="{{ old('notes', $p?->notes) }}" class="form-control" maxlength="500">
</div>
<div class="col-6">
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="is_default" value="1" @checked(old('is_default', $p?->is_default))>
        <label class="form-check-label">Default printer</label>
    </div>
</div>
<div class="col-6">
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $p?->is_active ?? true))>
        <label class="form-check-label">Active</label>
    </div>
</div>
