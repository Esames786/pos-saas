<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Printing\PrintJobService;
use App\Services\Security\UserDataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * W0c (Team 5 owns) — EDGE-CASHIER-UI-4 printing on the Branch Server: receipt / KOT reprint / Recent Prints /
 * Print Here / Mark Printed / Retry. Split out of EdgeLocalPosController; same routes, same behaviour. The SHARED
 * PrintJobService + canonical document renderer do the work (same paper as Online).
 */
class EdgeLocalPrintJobController extends Controller
{
    use ResolvesEdgePosContext;

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly PrintJobService $printJobs,
    ) {
    }

    /**
     * Queue the customer receipt through the SHARED PrintJobService — auto after payment is ensure-once
     * (a retry never duplicates the bill); `reprint` forces a fresh job. RECALL-REPRINT-TERMINAL parity:
     * routed at the CURRENT counter's receipt printer; the sale row keeps its own terminal.
     */
    public function queueReceipt(Request $request, int $sale): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $order = SalesOrder::on('tenant')->where('id', $sale)->where('branch_id', $branchId)->first();
        if (! $order) {
            return response()->json(['message' => 'No sale found.'], 404);
        }
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $reprint = $request->boolean('reprint');
        $job = $this->printJobs->queueReceipt($order, terminalId: (string) $terminal->id, ensureOnce: ! $reprint);

        return response()->json($this->printJobView($job->fresh()), 201);
    }

    /** Reprint the kitchen ticket (duplicate event) — the shared KOT path, so the stored copy fallback applies. */
    public function reprintKot(Request $request, int $sale): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $order = SalesOrder::on('tenant')->where('id', $sale)->where('branch_id', $branchId)->first();
        if (! $order) {
            return response()->json(['message' => 'No sale found.'], 404);
        }
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $jobs = $this->printJobs->queueKot($order, null, [], (string) $terminal->id, true);

        return response()->json(['jobs' => collect($jobs)->map(fn (PrintJob $j) => $this->printJobView($j->fresh()))->values()], 201);
    }

    /** Recent Prints — the branch's latest print jobs (optionally one sale's), newest first, inside the operator's data scope. */
    public function printJobs(Request $request): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $q = PrintJob::on('tenant')->with('printer')->where('branch_id', $branchId)->orderByDesc('id')->limit(50);
        if ($request->filled('sale_id')) {
            $q->where('reference_type', 'sales_order')->where('reference_id', (int) $request->input('sale_id'));
        }
        // USER DATA SCOPE parity (W0b, Online PrintJobController::assertSaleAccess): a terminal / order-type scoped
        // operator sees only the print jobs of sales inside that scope.
        $user = $request->user('tenant');
        $scope = app(UserDataScope::class);
        if ($scope->isScoped($user)) {
            $q->where('reference_type', 'sales_order')->whereIn('reference_id',
                $scope->applyToSales(SalesOrder::on('tenant')->where('branch_id', $branchId)->select('id'), $user));
        }

        return response()->json(['jobs' => $q->get()->map(fn (PrintJob $j) => $this->printJobView($j))->values()]);
    }

    /**
     * Print Here — the document rendered for the browser (receipt / KOT / reminder) by the CANONICAL
     * renderer, so the appliance prints the same paper as Online (KOT deal names, snapshot fallback, layout).
     */
    public function printDocument(int $job)
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $printJob = PrintJob::on('tenant')->where('id', $job)->where('branch_id', $branchId)->firstOrFail();
        // The canonical documents carry a "Mark Printed" form aimed at the Cloud print-jobs route; on the
        // appliance that button must land on the Edge endpoint instead (the Blade reads this when set).
        view()->share('edgeMarkPrintedUrl', url('/edge/local/pos/print-jobs/' . $printJob->id . '/printed'));

        return app(\App\Http\Controllers\Tenant\PrintDocumentController::class)->preview($printJob);
    }

    /** The operator confirms a browser (fallback) print — network jobs are completed by the print worker only. */
    public function markPrinted(Request $request, int $job)
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $printJob = PrintJob::on('tenant')->where('id', $job)->where('branch_id', $branchId)->first();
        if (! $printJob) {
            return response()->json(['message' => 'No print job found.'], 404);
        }
        if ($printJob->printer_id) {
            return response()->json(['message' => 'This job prints on a network printer — the print worker completes it.'], 422);
        }
        $this->printJobs->markPrinted($printJob);

        // The document's own "Mark Printed" form (a plain POST from the print window) returns to the document.
        if (! $request->expectsJson()) {
            return redirect(url('/edge/local/pos/print-jobs/' . $printJob->id . '/document'));
        }

        return response()->json($this->printJobView($printJob->fresh()));
    }

    /** Retry a terminally-failed local network delivery (Edge print authority). */
    public function retryPrintJob(int $job): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $printJob = PrintJob::on('tenant')->where('id', $job)->where('branch_id', $branchId)->first();
        if (! $printJob) {
            return response()->json(['message' => 'No print job found.'], 404);
        }
        try {
            app(\App\Services\Edge\EdgeLocalPrintDeliveryService::class)->retryTerminalFailed($printJob->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->printJobView($printJob->fresh()));
    }

    private function printJobView(PrintJob $j): array
    {
        $j->loadMissing('printer');

        return [
            'id' => (int) $j->id, 'job_no' => $j->job_no,
            'document_type' => $j->document_type, 'print_status' => $j->print_status,
            'event_type' => data_get($j->payload, 'kot_event_type'),
            'printer_name' => $j->printer?->name ?? 'Print here (browser)',
            'printer_type' => $j->printer?->printer_type ?? 'browser',
            'fallback' => empty($j->printer_id),
            'terminal_id' => $j->terminal_id !== null ? (int) $j->terminal_id : null,
            'reference_no' => $j->reference_no,
            'reference_id' => $j->reference_id ? (int) $j->reference_id : null,
            'preview_url' => url('/edge/local/pos/print-jobs/' . $j->id . '/document'),
            'created_at' => $j->created_at?->toIso8601String(),
        ];
    }
}
