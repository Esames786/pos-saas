<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Printer;
use Illuminate\Http\Request;

class PrintReportController extends Controller
{
    public function jobs(Request $request)
    {
        $filters = [
            'date_from'      => $request->input('date_from', today()->subDays(29)->format('Y-m-d')),
            'date_to'        => $request->input('date_to',   today()->format('Y-m-d')),
            'document_type'  => $request->input('document_type'),
            'print_status'   => $request->input('print_status'),
            'printer_id'     => $request->input('printer_id'),
            'branch_id'      => $request->input('branch_id'),
        ];

        $query = PrintJob::query()
            ->with(['printer', 'branch'])
            ->when(!empty($filters['document_type']),
                fn ($q) => $q->where('document_type', $filters['document_type']))
            ->when(!empty($filters['print_status']),
                fn ($q) => $q->where('print_status', $filters['print_status']))
            ->when(!empty($filters['printer_id']),
                fn ($q) => $q->where('printer_id', $filters['printer_id']))
            ->when(!empty($filters['branch_id']),
                fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['date_from']),
                fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),
                fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->orderByDesc('created_at');

        $jobs = $query->paginate(30)->withQueryString();
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $printers = Printer::where('is_active', true)->orderBy('name')->get();
        $documentTypes = ['kot', 'receipt'];
        $statuses = ['pending', 'queued', 'printed', 'failed'];

        if ($request->boolean('export_csv')) {
            return response()->streamDownload(function () use ($query) {
                $fp = fopen('php://output', 'w');
                fputcsv($fp, ['Job No', 'Document', 'Reference No', 'Printer', 'Branch', 'Status', 'Attempts', 'Error', 'Created At', 'Printed At']);
                foreach ($query->get() as $j) {
                    fputcsv($fp, [
                        $j->job_no, $j->document_type, $j->reference_no, $j->printer?->name,
                        $j->branch?->name, $j->print_status, $j->attempts,
                        $j->error_message, $j->created_at?->format('Y-m-d H:i'), $j->printed_at?->format('Y-m-d H:i'),
                    ]);
                }
                fclose($fp);
            }, 'print-jobs-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
        }

        return view('tenant.reports.printing.jobs', compact('jobs', 'filters', 'branches', 'printers', 'documentTypes', 'statuses'));
    }
}
