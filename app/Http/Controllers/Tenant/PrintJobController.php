<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use App\Services\Printing\PrintRoutingService;
use Illuminate\Http\Request;

class PrintJobController extends Controller
{
    public function __construct(
        private PrintJobService    $printJobService,
        private PrintRoutingService $printRoutingService,
    ) {}

    public function index(Request $request)
    {
        $query = PrintJob::with(['branch', 'printer', 'createdBy'])
            ->orderByDesc('id');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('document_type')) {
            $query->where('document_type', $request->document_type);
        }
        if ($request->filled('print_status')) {
            $query->where('print_status', $request->print_status);
        }

        $jobs     = $query->paginate(30)->withQueryString();
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.printing.jobs.index', compact('jobs', 'branches'));
    }

    public function queueReceipt(Request $request, SalesOrder $salesOrder)
    {
        $terminalId = $request->input('terminal_id');
        $printer    = $this->printRoutingService->receiptPrinter($salesOrder, $terminalId);
        $job        = $this->printJobService->queueReceipt($salesOrder, $printer, $terminalId);

        if ($request->expectsJson()) {
            return response()->json([
                'job_id'       => $job->id,
                'job_no'       => $job->job_no,
                'printer_type' => $printer?->printer_type ?? 'browser',
                'preview_url'  => url('/printing/documents/' . $job->id . '/receipt'),
            ]);
        }

        return redirect(url('/printing/documents/' . $job->id . '/receipt'));
    }

    public function queueKot(Request $request, SalesOrder $salesOrder)
    {
        $terminalId = $request->input('terminal_id');
        $groups     = $this->printRoutingService->kotPrintersForSale($salesOrder, $terminalId);

        $jobs = [];
        foreach ($groups as $group) {
            $jobs[] = $this->printJobService->queueKot(
                $salesOrder, $group['printer'], $group['line_ids'], $terminalId
            );
        }

        if (empty($jobs)) {
            $jobs[] = $this->printJobService->queueKot($salesOrder, null, [], $terminalId);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'jobs' => collect($jobs)->map(fn ($j) => [
                    'job_id'       => $j->id,
                    'job_no'       => $j->job_no,
                    'printer_type' => $j->printer?->printer_type ?? 'browser',
                    'preview_url'  => url('/printing/documents/' . $j->id . '/kot'),
                ])->all(),
            ]);
        }

        return redirect(url('/printing/documents/' . $jobs[0]->id . '/kot'));
    }

    public function markPrinted(Request $request, PrintJob $printJob)
    {
        $this->printJobService->markPrinted($printJob);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'printed']);
        }

        return back()->with('status', 'Job marked as printed.');
    }

    public function retry(PrintJob $printJob)
    {
        if (!in_array($printJob->print_status, ['failed', 'cancelled'])) {
            return back()->withErrors(['job' => 'Only failed or cancelled jobs can be retried.']);
        }

        $printJob->update(['print_status' => 'queued', 'error_message' => null, 'failed_at' => null]);

        return back()->with('status', 'Job re-queued.');
    }
}
