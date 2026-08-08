<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use App\Services\Printing\DirectPayPrintOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PrintJobController extends Controller
{
    public function __construct(
        private PrintJobService $printJobService,
        private DirectPayPrintOrchestrator $directPayPrintOrchestrator,
    ) {}

    public function index(Request $request)
    {
        $query = PrintJob::with(['branch', 'printer', 'createdBy', 'claimedByAgent'])
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
        // Auto print after a sale is ensure-once (idempotent); explicit reprint forces a new job.
        $ensureOnce = ! $request->boolean('reprint');
        $job = $this->printJobService->queueReceipt($salesOrder, ensureOnce: $ensureOnce);

        $previewUrl = url('/printing/documents/' . $job->id . '/receipt');

        if ($request->expectsJson()) {
            return response()->json([
                'job_id'       => $job->id,
                'job_no'       => $job->job_no,
                'printer_type' => $job->printer?->printer_type ?? 'browser',
                'preview_url'  => $previewUrl,
                'fallback'     => empty($job->printer_id),
            ]);
        }

        return redirect($previewUrl);
    }

    public function queueKot(Request $request, SalesOrder $salesOrder)
    {
        $isReprint = $request->boolean('reprint');

        $lineIds = collect($request->input('line_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        $jobs = $this->printJobService->queueKot(
            sale:       $salesOrder,
            printer:    null,
            lineIds:    $lineIds,
            terminalId: null,
            isReprint:  $isReprint,
        );

        $reminder = [
            'revision' => null,
            'auto_jobs' => [],
            'ask_printers' => [],
            'confirmation_token' => null,
        ];
        if (!$isReprint && !empty($jobs)) {
            try {
                $reminder = $this->printJobService->planRemindersForKotJobs($salesOrder, $jobs);
            } catch (\Throwable $exception) {
                Log::warning('Reminder planning failed after KOT was queued.', [
                    'sales_order_id' => $salesOrder->id,
                    'error' => $exception->getMessage(),
                ]);
                $reminder['warning'] = 'KOT was queued, but Reminder could not be queued.';
            }
        }

        if (empty($jobs)) {
            if ($request->expectsJson()) {
                return response()->json(['jobs' => [], 'message' => 'No new items to send to kitchen']);
            }
            return back()->with('status', 'No new items to send to kitchen.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'jobs' => collect($jobs)->map(fn ($j) => [
                    'job_id'       => $j->id,
                    'job_no'       => $j->job_no,
                    'printer_type' => $j->printer?->printer_type ?? 'browser',
                    'preview_url'  => url('/printing/documents/' . $j->id . '/kot'),
                    'fallback'     => empty($j->printer_id),
                    'line_quantities' => $j->payload['line_quantities'] ?? [],
                ])->values()->all(),
                'reminder' => [
                    'revision' => $reminder['revision'],
                    'auto_jobs' => collect($reminder['auto_jobs'])->map(fn ($job) => [
                        'job_id' => $job->id,
                        'job_no' => $job->job_no,
                        'printer_id' => $job->printer_id,
                    ])->values()->all(),
                    'ask_printers' => $reminder['ask_printers'],
                    'confirmation_token' => $reminder['confirmation_token'],
                    'warning' => $reminder['warning'] ?? null,
                ],
            ]);
        }

        $first = collect($jobs)->first();

        if (!$first->printer_id) {
            return redirect(url('/printing/documents/' . $first->id . '/kot'))
                ->with('status', 'KOT preview opened — use Print / Ctrl+P for USB printer.');
        }

        return back()->with('status', count($jobs) . ' KOT print job(s) queued.');
    }

    public function confirmReminders(Request $request, SalesOrder $salesOrder)
    {
        $data = $request->validate([
            'confirmation_token' => ['required', 'string'],
            'decision' => ['nullable', 'in:confirm,decline'],
        ]);
        $context = $this->printJobService->validateReminderConfirmation($salesOrder, $data['confirmation_token']);
        if (($data['decision'] ?? 'confirm') === 'decline') {
            $this->directPayPrintOrchestrator->markReminderDecision($salesOrder, $context['batch']->id, 'declined');
            return response()->json(['jobs' => [], 'declined' => true]);
        }

        $jobs = $this->printJobService->queueConfirmedReminders($salesOrder, $data['confirmation_token']);
        $this->directPayPrintOrchestrator->markReminderDecision($salesOrder, $context['batch']->id, 'confirmed');

        return response()->json([
            'jobs' => collect($jobs)->map(fn ($job) => [
                'job_id' => $job->id,
                'job_no' => $job->job_no,
                'printer_id' => $job->printer_id,
            ])->values(),
        ]);
    }

    public function reprintReminder(Request $request, PrintJob $printJob)
    {
        $job = $this->printJobService->queueReminderReprint($printJob);

        return response()->json([
            'job_id' => $job->id,
            'job_no' => $job->job_no,
            'copy_no' => $job->copy_no,
            'printer_id' => $job->printer_id,
        ]);
    }

    public function ajaxForSale(Request $request, int $saleId)
    {
        $jobs = PrintJob::where('reference_id', $saleId)
            ->where('reference_type', 'sales_order')
            ->with('printer')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'jobs' => $jobs->map(fn ($j) => [
                'id'            => $j->id,
                'job_no'        => $j->job_no,
                'document_type' => $j->document_type,
                'print_status'  => $j->print_status,
                'printer_name'  => $j->printer?->name ?? 'Default Printer',
                'printer_type'  => $j->printer?->printer_type ?? 'browser',
                'line_count'    => $j->document_type === 'reminder'
                    ? count($j->payload['lines'] ?? [])
                    : count($j->payload['line_ids'] ?? []),
                'revision'      => $j->document_type === 'reminder'
                    ? (int) ($j->payload['revision'] ?? 1)
                    : null,
                'copy_no'       => $j->document_type === 'reminder'
                    ? (int) ($j->copy_no ?? 0)
                    : null,
                'fallback'      => empty($j->printer_id),
                'preview_url'   => url('/printing/documents/' . $j->id . '/' . $j->document_type),
                'created_at'    => $j->created_at->diffForHumans(),
            ])->values()->all(),
        ]);
    }

    public function markPrinted(Request $request, PrintJob $printJob)
    {
        $this->printJobService->markPrinted($printJob);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'printed']);
        }

        return back()->with('status', 'Job marked as printed.');
    }

    public function retry(Request $request, PrintJob $printJob)
    {
        // the ONE shared requeue operation (also used by the Edge local retry) — never duplicated.
        try {
            app(\App\Services\Printing\PrintJobService::class)->requeueFailed($printJob);
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withErrors(['job' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'queued', 'job_no' => $printJob->job_no]);
        }

        return back()->with('status', 'Job re-queued.');
    }
}
