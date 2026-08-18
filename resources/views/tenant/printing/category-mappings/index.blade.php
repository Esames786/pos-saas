@extends('layouts.app')

@section('title', 'Print Routing')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">KOT &amp; Reminder Routing</h1>
    @can('tenant.printing.category-mappings.store')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMappingModal">Add Mapping</button>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body pb-0">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <select name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($selectedBranchId == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Filter</button>
                <a href="{{ url('/printing/category-mappings') }}" class="btn btn-light">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Terminal</th>
                    <th>Order Type</th>
                    <th>Category</th>
                    <th>Printer</th>
                    <th>Document</th>
                    <th>Addition Policy</th>
                    <th>Active</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($mappings as $m)
                <tr>
                    <td>{{ $m->branch?->name ?? 'All Branches' }}</td>
                    <td>{{ $m->terminal?->name ?? 'All terminals' }}</td>
                    <td>{{ $m->order_type === 'all' ? 'All' : ucwords(str_replace('_', ' ', $m->order_type)) }}</td>
                    <td>{{ $m->category?->name ?? 'All categories' }}</td>
                    <td>{{ $m->printer?->name }}</td>
                    <td>{{ ucfirst($m->print_role) }}</td>
                    <td>{{ $m->print_role === 'reminder' ? ($m->reminder_confirm_on_addition ? 'Ask' : 'Automatic') : '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $m->is_active ? 'success' : 'secondary' }}">
                            {{ $m->is_active ? 'Yes' : 'No' }}
                        </span>
                    </td>
                    <td class="text-end">
                        @can('tenant.printing.category-mappings.destroy')
                            <form method="POST"
                                  action="{{ url('/printing/category-mappings/' . $m->id) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Remove this mapping?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger">Remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No mappings configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('tenant.printing.category-mappings.store')
<div class="modal fade" id="addMappingModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ url('/printing/category-mappings') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add Print Routing</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-md-6">
                    <label class="form-label required">Branch</label>
                    <select name="branch_id" class="form-select">
                        <option value="">All Branches</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Terminal</label>
                    <select name="terminal_id" class="form-select">
                        <option value="0" @selected(! old('terminal_id'))>— All terminals —</option>
                        @foreach($terminals as $tm)
                            <option value="{{ $tm->id }}" @selected(old('terminal_id') == $tm->id)>{{ $tm->name }}@if($tm->branch) · {{ $tm->branch->name }}@endif</option>
                        @endforeach
                    </select>
                    <div class="form-text">Pin this rule to one counter. A terminal rule <strong>wins</strong> over “All terminals”, so a counter routes its own KOTs regardless of order type.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Order Type</label>
                    <select name="order_type" class="form-select" required>
                        @foreach(['all' => 'All', 'dine_in' => 'Dine In', 'takeaway' => 'Takeaway', 'quick_sale' => 'Quick Sale', 'delivery' => 'Delivery'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('order_type', 'all') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Category</label>
                    <select name="category_id" class="form-select" required>
                        <option value="">— Select —</option>
                        <option value="0" @selected(old('category_id') === '0')>— All categories (whole order) —</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-text" id="all-categories-hint" hidden>Reminder always prints the complete order, so “All categories” is the natural choice.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Printer</label>
                    <select name="printer_id" id="mapping-printer" class="form-select" required>
                        <option value="">— Select —</option>
                        @foreach($printers as $p)
                            <option value="{{ $p->id }}" data-reminder="{{ $p->supports_reminder ? '1' : '0' }}" @selected(old('printer_id') == $p->id)>{{ $p->name }} ({{ strtoupper($p->print_role) }}){{ $p->supports_reminder ? ' · Reminder' : '' }}</option>
                        @endforeach
                    </select>
                    <div class="form-text" id="reminder-printer-hint" hidden>Only Reminder-capable printers are shown. Turn on “Reminder capable” on a printer to use it here.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Document</label>
                    <select name="print_role" id="mapping-document-type" class="form-select" required>
                        <option value="kot" @selected(old('print_role') === 'kot')>KOT</option>
                        <option value="reminder" @selected(old('print_role') === 'reminder')>Reminder</option>
                    </select>
                </div>
                <div class="col-md-6" id="reminder-addition-policy" hidden>
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" name="reminder_confirm_on_addition" value="1" @checked(old('reminder_confirm_on_addition'))>
                        <label class="form-check-label">Ask before updated Reminder</label>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
                        <label class="form-check-label">Active</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary">Add Mapping</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endcan
<script>
document.addEventListener('DOMContentLoaded', function () {
    const type    = document.getElementById('mapping-document-type');
    const policy  = document.getElementById('reminder-addition-policy');
    const printer = document.getElementById('mapping-printer');
    const hint    = document.getElementById('reminder-printer-hint');
    const catHint = document.getElementById('all-categories-hint');
    if (!type) return;
    const sync = () => {
        const isReminder = type.value === 'reminder';
        if (policy)  policy.hidden  = !isReminder;
        if (hint)    hint.hidden    = !isReminder;
        if (catHint) catHint.hidden = !isReminder;
        // Reminder documents can only go to Reminder-capable printers — hide the rest
        // so no dead (never-printing) mapping can be created.
        if (printer) {
            Array.prototype.forEach.call(printer.options, function (opt) {
                if (!opt.value) return; // keep the "— Select —" placeholder
                const hide = isReminder && opt.dataset.reminder !== '1';
                opt.disabled = hide;
                opt.hidden   = hide;
                if (hide && opt.selected) { printer.value = ''; }
            });
        }
    };
    type.addEventListener('change', sync);
    sync();
});
</script>
@endsection
