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
                    <th>Reminder</th>
                    <th>Paper</th>
                    <th>IP / Port</th>
                    <th>Default</th>
                    <th>Status</th>
                    <th>Health</th>
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
                    <td>{{ $p->supports_reminder ? 'Capable' : 'No' }}</td>
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
                    <td>
                        @php
                            $net  = $p->printer_type === 'network' && $p->ip_address;
                            $ok   = $p->last_ping_ok;
                            $hcls = $ok === null ? 'secondary' : ($ok ? 'success' : 'danger');
                            $htxt = $ok === null ? 'Unknown' : ($ok ? 'Online' . ($p->last_ping_ms !== null ? ' · ' . $p->last_ping_ms . 'ms' : '') : 'Offline');
                        @endphp
                        <span class="printer-health badge bg-{{ $net ? $hcls : 'light text-muted' }}"
                              data-printer-id="{{ $p->id }}" data-net="{{ $net ? 1 : 0 }}"
                              @if($p->last_ping_at) title="checked {{ $p->last_ping_at->diffForHumans() }}" @endif>{{ $net ? $htxt : '—' }}</span>
                    </td>
                    <td class="text-end">
                        @if($p->printer_type === 'network' && $p->ip_address)
                            @can('tenant.printing.printers.ping')
                                <button class="btn btn-sm btn-outline-primary" data-printer-test="{{ $p->id }}" title="Test connection to this printer">Test</button>
                            @endcan
                            @can('tenant.printing.printers.reset')
                                <button class="btn btn-sm btn-outline-secondary" data-printer-reset="{{ $p->id }}" title="Soft reset — clear a stuck buffer">Reset</button>
                            @endcan
                            @can('tenant.printing.printers.reboot')
                                <button class="btn btn-sm btn-outline-warning" data-printer-reboot="{{ $p->id }}" title="Best-effort reboot via the printer's web module — works only on models that expose one. Verify on your printer before relying on it; if it can't, you'll be told.">Reboot<sup class="text-muted" style="font-size:.6rem">beta</sup></button>
                            @endcan
                        @endif
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
                <tr><td colspan="12" class="text-center text-muted py-4">No printers configured.</td></tr>
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

{{-- PRINTER-HEALTH-1: live status + Test / Reset / Reboot (the agent executes on the LAN) --}}
<script>
(function () {
    const csrf  = '{{ csrf_token() }}';
    const base  = @json(url('/printing/printers'));
    const toast = (msg) => window.Swal
        ? Swal.fire({ toast: true, position: 'top-end', timer: 4500, showConfirmButton: false, title: msg })
        : alert(msg);

    function paint(el, ok, ms) {
        el.className = 'printer-health badge bg-' + (ok === null ? 'secondary' : (ok ? 'success' : 'danger'));
        el.textContent = ok === null ? 'Unknown' : (ok ? ('Online' + (ms != null ? ' · ' + ms + 'ms' : '')) : 'Offline');
    }
    async function refresh(id) {
        const el = document.querySelector('.printer-health[data-printer-id="' + id + '"]');
        if (!el || el.dataset.net !== '1') return null;
        try {
            const d = await fetch(base + '/' + id + '/status', { headers: { Accept: 'application/json' } }).then(r => r.json());
            paint(el, d.last_ping_ok, d.last_ping_ms);
            return d;
        } catch (e) { return null; }
    }
    const refreshAll = () => document.querySelectorAll('.printer-health[data-net="1"]').forEach(el => refresh(el.dataset.printerId));

    // Test / Reboot ride the command queue → poll the command result and toast it.
    async function command(id, action, label) {
        const el = document.querySelector('.printer-health[data-printer-id="' + id + '"]');
        if (el) { el.className = 'printer-health badge bg-info'; el.textContent = label + '…'; }
        const r = await fetch(base + '/' + id + '/' + action, {
            method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        }).catch(() => null);
        if (!r || !r.ok) { toast(label + ' failed' + (r ? ' (' + r.status + ')' : '')); refresh(id); return; }
        let tries = 0;
        const iv = setInterval(async () => {
            tries++;
            const s = await refresh(id);
            if (s && s.command && (s.command.status === 'done' || s.command.status === 'failed')) {
                clearInterval(iv);
                toast(label + ' — ' + s.command.status + (s.command.result ? ': ' + s.command.result : ''));
            } else if (tries > 8) {
                clearInterval(iv);
                toast(label + ' sent — no reply yet. Is the print agent running?');
            }
        }, 1800);
    }

    document.querySelectorAll('[data-printer-test]').forEach((b) =>
        b.addEventListener('click', () => command(b.dataset.printerTest, 'ping', 'Test')));
    document.querySelectorAll('[data-printer-reboot]').forEach((b) =>
        b.addEventListener('click', () => { if (confirm('Try to reboot this printer? This works only on models with a web reboot page — you will be told if it cannot.')) command(b.dataset.printerReboot, 'reboot', 'Reboot'); }));
    // Soft reset rides the print pipeline (ESC @) — fire and confirm; no command result to poll.
    document.querySelectorAll('[data-printer-reset]').forEach((b) =>
        b.addEventListener('click', async () => {
            if (!confirm('Send a soft reset (clear the buffer) to this printer?')) return;
            const r = await fetch(base + '/' + b.dataset.printerReset + '/reset', {
                method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            }).catch(() => null);
            toast(r && r.ok ? 'Reset sent to the printer.' : 'Reset failed.');
        }));

    refreshAll();
    setInterval(refreshAll, 8000);
})();
</script>
@endsection
