<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintAgentCommand;
use App\Models\Tenant\Printer;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\TerminalPrinterSetting;
use App\Services\Printing\PrintJobFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrinterController extends Controller
{
    public function index(Request $request)
    {
        $printers  = Printer::with('branch')->orderBy('name')->get();
        $branches  = Branch::where('status', 'active')->orderBy('name')->get();
        $terminals = Terminal::orderBy('name')->get();

        $terminalSettings = TerminalPrinterSetting::with(['receiptPrinter', 'kotPrinter'])->get()->keyBy('terminal_id');

        return view('tenant.printing.printers.index', compact('printers', 'branches', 'terminals', 'terminalSettings'));
    }

    /* ── PRINTER-HEALTH-1: live status + remote Test / Reset / Reboot ─────────────────────────── */

    /** Live reachability + the latest command outcome for a printer — polled by the screen. */
    public function status(Printer $printer)
    {
        // Resolve any command the agent claimed but never reported, so the pill/toast stops waiting on
        // a dead command even when no agent is polling /commands to trigger the sweep.
        PrintAgentCommand::expireStale();

        $command = PrintAgentCommand::where('printer_id', $printer->id)->latest('id')->first();

        return response()->json([
            'id'           => $printer->id,
            'last_ping_ok' => $printer->last_ping_ok === null ? null : (bool) $printer->last_ping_ok,
            'last_ping_ms' => $printer->last_ping_ms,
            'last_ping_at' => $printer->last_ping_at?->toIso8601String(),
            'last_seen_at' => $printer->last_seen_at?->toIso8601String(),
            'last_error'   => $printer->last_error,
            'command'      => $command ? [
                'id'         => $command->id,
                'type'       => $command->type,
                'status'     => $command->status,
                'result'     => $command->result,
                'latency_ms' => $command->latency_ms,
            ] : null,
        ]);
    }

    /** Queue a "test connection" command the agent runs against the printer's print port. */
    public function ping(Printer $printer)
    {
        return response()->json(['ok' => true, 'command_id' => $this->queueCommand($printer, 'ping')->id]);
    }

    /** Queue a "reboot" command the agent sends to the printer's built-in web module. */
    public function reboot(Printer $printer)
    {
        return response()->json(['ok' => true, 'command_id' => $this->queueCommand($printer, 'reboot')->id]);
    }

    /** Soft reset: send ESC @ (initialize) through the normal print pipeline to unstick a jammed printer. */
    public function reset(Printer $printer)
    {
        abort_if($printer->printer_type !== 'network' || ! $printer->ip_address, 422, 'Reset needs a network printer with an IP.');

        app(PrintJobFactory::class)->create([
            'branch_id'          => $printer->branch_id,
            'printer_id'         => $printer->id,
            'document_type'      => 'reset',
            'print_status'       => 'queued',
            'reference_type'     => 'printer_reset',
            'reference_id'       => $printer->id,
            'reference_no'       => $printer->code,
            'payload'            => ['reset' => true],
            'raw_payload'        => "\x1B@",   // ESC @ — initialize / clear the buffer
            'attempts'           => 0,
            'created_by_user_id' => auth('tenant')->id(),
        ], 'PJ-RESET');

        return response()->json(['ok' => true]);
    }

    private function queueCommand(Printer $printer, string $type): PrintAgentCommand
    {
        abort_if($printer->printer_type !== 'network' || ! $printer->ip_address, 422, 'This needs a network printer with an IP address.');

        return PrintAgentCommand::create([
            'printer_id'           => $printer->id,
            'branch_id'            => $printer->branch_id,
            'type'                 => $type,
            'status'               => 'queued',
            'requested_by_user_id' => auth('tenant')->id(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'            => ['nullable', 'exists:branches,id'],
            'name'                 => ['required', 'string', 'max:100'],
            'code'                 => ['required', 'string', 'max:50', 'unique:printers,code'],
            'printer_type'         => ['required', Rule::in(['network', 'usb', 'browser'])],
            'print_role'           => ['required', Rule::in(['receipt', 'kot', 'both'])],
            'supports_reminder'    => ['nullable', 'boolean'],
            'ip_address'           => ['nullable', 'string', 'max:50'],
            'port'                 => ['nullable', 'integer', 'min:1', 'max:65535'],
            'paper_size'           => ['required', Rule::in(['58mm', '80mm', 'A4'])],
            'characters_per_line'  => ['required', 'integer', 'min:20', 'max:80'],
            'is_default'           => ['nullable', 'boolean'],
            'is_active'            => ['nullable', 'boolean'],
            'notes'                => ['nullable', 'string', 'max:500'],
        ]);

        $data['is_default'] = !empty($data['is_default']);
        $data['is_active']  = !empty($data['is_active']);
        $data['supports_reminder'] = !empty($data['supports_reminder']);
        $data['code']       = strtoupper(trim($data['code']));

        Printer::create($data);

        return back()->with('status', 'Printer added successfully.');
    }

    public function update(Request $request, Printer $printer)
    {
        $data = $request->validate([
            'branch_id'            => ['nullable', 'exists:branches,id'],
            'name'                 => ['required', 'string', 'max:100'],
            'code'                 => ['required', 'string', 'max:50', Rule::unique('printers', 'code')->ignore($printer->id)],
            'printer_type'         => ['required', Rule::in(['network', 'usb', 'browser'])],
            'print_role'           => ['required', Rule::in(['receipt', 'kot', 'both'])],
            'supports_reminder'    => ['nullable', 'boolean'],
            'ip_address'           => ['nullable', 'string', 'max:50'],
            'port'                 => ['nullable', 'integer', 'min:1', 'max:65535'],
            'paper_size'           => ['required', Rule::in(['58mm', '80mm', 'A4'])],
            'characters_per_line'  => ['required', 'integer', 'min:20', 'max:80'],
            'is_default'           => ['nullable', 'boolean'],
            'is_active'            => ['nullable', 'boolean'],
            'notes'                => ['nullable', 'string', 'max:500'],
        ]);

        $data['is_default'] = !empty($data['is_default']);
        $data['is_active']  = !empty($data['is_active']);
        $data['supports_reminder'] = !empty($data['supports_reminder']);
        $data['code']       = strtoupper(trim($data['code']));

        $printer->update($data);

        return back()->with('status', 'Printer updated successfully.');
    }

    public function destroy(Printer $printer)
    {
        if ($printer->printJobs()->exists()) {
            return back()->withErrors(['printer' => 'Printer has print jobs and cannot be deleted.']);
        }

        $printer->delete();

        return back()->with('status', 'Printer deleted.');
    }

    public function saveTerminalSettings(Request $request)
    {
        $data = $request->validate([
            'terminal_id'        => ['required', 'exists:terminals,id'],
            'receipt_printer_id' => ['nullable', 'exists:printers,id'],
            'kot_printer_id'     => ['nullable', 'exists:printers,id'],
            'auto_print_receipt' => ['nullable', 'boolean'],
            'auto_print_kot'     => ['nullable', 'boolean'],
        ]);

        TerminalPrinterSetting::updateOrCreate(
            ['terminal_id' => $data['terminal_id']],
            [
                'receipt_printer_id' => $data['receipt_printer_id'] ?? null,
                'kot_printer_id'     => $data['kot_printer_id'] ?? null,
                'auto_print_receipt' => !empty($data['auto_print_receipt']),
                'auto_print_kot'     => !empty($data['auto_print_kot']),
            ]
        );

        return back()->with('status', 'Terminal settings saved.');
    }
}
