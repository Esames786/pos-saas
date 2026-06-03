@extends('layouts.app')

@section('title', 'Printers')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Printers</h1>
    @can('tenant.printing.printers.store')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPrinterModal">Add Printer</button>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

{{-- Printers table --}}
<div class="card mb-4">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Code</th>
                    <th>Branch</th>
                    <th>Type</th>
                    <th>Role</th>
                    <th>Paper</th>
                    <th>IP / Port</th>
                    <th>Default</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($printers as $p)
                <tr>
                    <td>{{ $p->name }}</td>
                    <td><code>{{ $p->code }}</code></td>
                    <td>{{ $p->branch?->name ?? 'All' }}</td>
                    <td>{{ ucfirst($p->printer_type) }}</td>
                    <td>{{ ucfirst($p->print_role) }}</td>
                    <td>{{ $p->paper_size }}</td>
                    <td>
                        @if($p->ip_address)
                            {{ $p->ip_address }}:{{ $p->port }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $p->is_default ? 'Yes' : '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $p->is_active ? 'success' : 'secondary' }}">
                            {{ $p->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="text-end">
                        @can('tenant.printing.printers.update')
                            <button class="btn btn-sm btn-light"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editPrinterModal{{ $p->id }}">Edit</button>
                        @endcan
                        @can('tenant.printing.printers.destroy')
                            <form method="POST" action="{{ url('/printing/printers/' . $p->id) }}"
                                  class="d-inline" onsubmit="return confirm('Delete printer?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger">Del</button>
                            </form>
                        @endcan
                    </td>
                </tr>
                @empty
                <tr><td colspan="10" class="text-center text-muted py-4">No printers configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Terminal Settings --}}
@can('tenant.printing.terminal-settings.save')
<div class="card mb-4">
    <div class="card-header"><strong>Terminal Printer Settings</strong></div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Terminal</th>
                    <th>Receipt Printer</th>
                    <th>KOT Printer</th>
                    <th>Auto Print Receipt</th>
                    <th>Auto Print KOT</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($terminals as $t)
                @php $ts = $terminalSettings[$t->id] ?? null; @endphp
                <tr>
                    <td>{{ $t->name }}</td>
                    <td>{{ $ts?->receiptPrinter?->name ?? '—' }}</td>
                    <td>{{ $ts?->kotPrinter?->name ?? '—' }}</td>
                    <td>
                        @if($ts?->auto_print_receipt)
                            <span class="badge bg-success">Yes</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($ts?->auto_print_kot)
                            <span class="badge bg-success">Yes</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-light"
                                data-bs-toggle="modal"
                                data-bs-target="#terminalSettingsModal{{ $t->id }}">Edit</button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endcan

{{-- ══════════════════════════════════════════════════════════════════════════
     MODALS — must live outside <table> so browsers don't mangle the DOM
     ══════════════════════════════════════════════════════════════════════════ --}}

{{-- Edit Printer Modals --}}
@can('tenant.printing.printers.update')
    @foreach($printers as $p)
    <div class="modal fade" id="editPrinterModal{{ $p->id }}" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" action="{{ url('/printing/printers/' . $p->id) }}" class="modal-content">
                @csrf @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Edit Printer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    @include('tenant.printing.printers._form', ['printer' => $p])
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    @endforeach
@endcan

{{-- Terminal Printer Settings Modals --}}
@can('tenant.printing.terminal-settings.save')
    @foreach($terminals as $t)
    @php $ts = $terminalSettings[$t->id] ?? null; @endphp
    <div class="modal fade" id="terminalSettingsModal{{ $t->id }}" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" action="{{ url('/printing/terminal-settings') }}" class="modal-content">
                @csrf
                <input type="hidden" name="terminal_id" value="{{ $t->id }}">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $t->name }} — Printer Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label">Receipt Printer</label>
                        <select name="receipt_printer_id" class="form-select">
                            <option value="">— None —</option>
                            @foreach($printers as $pr)
                                <option value="{{ $pr->id }}" @selected($ts?->receipt_printer_id == $pr->id)>
                                    {{ $pr->name }} ({{ ucfirst($pr->print_role) }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">KOT Printer</label>
                        <select name="kot_printer_id" class="form-select">
                            <option value="">— None —</option>
                            @foreach($printers as $pr)
                                <option value="{{ $pr->id }}" @selected($ts?->kot_printer_id == $pr->id)>
                                    {{ $pr->name }} ({{ ucfirst($pr->print_role) }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auto_receipt_{{ $t->id }}"
                                   name="auto_print_receipt" value="1" @checked($ts?->auto_print_receipt)>
                            <label class="form-check-label" for="auto_receipt_{{ $t->id }}">Auto Print Receipt</label>
                        </div>
                        <small class="text-muted">Fire receipt automatically on Complete Sale</small>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auto_kot_{{ $t->id }}"
                                   name="auto_print_kot" value="1" @checked($ts?->auto_print_kot)>
                            <label class="form-check-label" for="auto_kot_{{ $t->id }}">Auto Print KOT</label>
                        </div>
                        <small class="text-muted">Skip "Print KOT?" prompt on this terminal</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    @endforeach
@endcan

{{-- Add Printer Modal --}}
@can('tenant.printing.printers.store')
<div class="modal fade" id="addPrinterModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ url('/printing/printers') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add Printer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                @include('tenant.printing.printers._form')
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary">Add</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
