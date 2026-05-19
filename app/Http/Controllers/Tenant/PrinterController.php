<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Printer;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\TerminalPrinterSetting;
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

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'            => ['nullable', 'exists:branches,id'],
            'name'                 => ['required', 'string', 'max:100'],
            'code'                 => ['required', 'string', 'max:50', 'unique:printers,code'],
            'printer_type'         => ['required', Rule::in(['network', 'usb', 'browser'])],
            'print_role'           => ['required', Rule::in(['receipt', 'kot', 'both'])],
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
