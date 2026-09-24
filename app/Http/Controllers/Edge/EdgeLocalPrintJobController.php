<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPrintDeliveryService;
use App\Services\Edge\EdgeLocalPrintDirectPayService;
use App\Services\Edge\EdgeLocalPrintDocumentService;
use App\Services\Edge\EdgeLocalPrintKotService;
use App\Services\Printing\PrintJobService;
use App\Services\Security\UserDataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Team 5 (W5) — printing on the Branch Server: receipt / KOT (+ Reminder plan) / KOT reprint / Reminder confirm +
 * reprint / Recent Prints + per-sale Last Print / Print Here document / Mark Printed / Retry / Dismiss / Direct Pay
 * printing retry / bill-preview document / print preferences. The SHARED PrintJobService + canonical document
 * renderer do the work (same paper as Online).
 *
 * PERMISSION PARITY: Online exempts `tenant.printing.jobs.*` and `tenant.printing.documents.*` from the route
 * permission middleware (EnsureRoutePermission) and scopes each action by UserDataScope instead
 * (PrintJobController::assertSaleAccess / assertPrintJobAccess). The appliance does exactly that: no route
 * permission, `deniesSale` on the sale (or the job's sale / branch / terminal), 403 when outside the scope.
 * The routing terminal is the operator's selected terminal (re-validated by selectedTerminal()) — Online's
 * `operatorTerminalId(terminal_id)`.
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
        $order = $this->scopedSale($sale);
        if ($order instanceof JsonResponse) {
            return $order;
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
        $order = $this->scopedSale($sale);
        if ($order instanceof JsonResponse) {
            return $order;
        }
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $jobs = $this->printJobs->queueKot($order, null, [], (string) $terminal->id, true);

        return response()->json(['jobs' => collect($jobs)->map(fn (PrintJob $j) => $this->printJobView($j->fresh()))->values()], 201);
    }

    /**
     * W5 D-01/D-02/D-04 — Online `POST /printing/jobs/kot/{salesOrder}` parity: the unsent delta (one ticket per
     * category per printer, terminal-aware routing at the operator's CURRENT counter), or `reprint` = DUPLICATE of all
     * lines, then the Reminder plan (auto jobs + Ask-on-addition printers with a server-bound confirmation token).
     */
    public function queueKot(Request $request, int $sale): JsonResponse
    {
        $data = $request->validate([
            'reprint' => ['nullable', 'boolean'],
            'line_ids' => ['nullable', 'array'],
            'line_ids.*' => ['integer'],
        ]);
        $order = $this->scopedSale($sale);
        if ($order instanceof JsonResponse) {
            return $order;
        }
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        try {
            $result = app(EdgeLocalPrintKotService::class)->queueKot($order, $terminal, $data['line_ids'] ?? [], (bool) ($data['reprint'] ?? false));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $jobs = collect($result['jobs'])->map(fn (PrintJob $j) => $this->printJobView($j->fresh()) + ['line_quantities' => $j->payload['line_quantities'] ?? []])->values();

        return response()->json([
            'jobs' => $jobs,
            'message' => $jobs->isEmpty() ? 'No new items to send to kitchen' : null,
            'reminder' => $this->reminderView($result['reminder']),
        ], $jobs->isEmpty() ? 200 : 201);
    }

    /** W5 D-04 — Online `POST /printing/jobs/reminder/{salesOrder}/confirm` parity ("Resend updated Reminder?" Yes / No). */
    public function confirmReminders(Request $request, int $sale): JsonResponse
    {
        $data = $request->validate([
            'confirmation_token' => ['required', 'string'],
            'decision' => ['nullable', 'in:confirm,decline'],
        ]);
        $order = $this->scopedSale($sale);
        if ($order instanceof JsonResponse) {
            return $order;
        }
        $result = app(EdgeLocalPrintKotService::class)->confirmReminders($order, $data['confirmation_token'], $data['decision'] ?? 'confirm');

        return response()->json([
            'jobs' => collect($result['jobs'])->map(fn (PrintJob $j) => $this->printJobView($j->fresh()))->values(),
            'declined' => $result['declined'],
        ]);
    }

    /** W5 D-04 — Online `POST /printing/jobs/{printJob}/reminder-reprint` parity (DUPLICATE n of a network Reminder). */
    public function reprintReminder(int $job): JsonResponse
    {
        $printJob = $this->scopedJob($job);
        if ($printJob instanceof JsonResponse) {
            return $printJob;
        }
        $copy = app(EdgeLocalPrintKotService::class)->reprintReminder($printJob);

        return response()->json($this->printJobView($copy->fresh()), 201);
    }

    /**
     * Recent Prints — the branch's latest print jobs, or ONE sale's jobs (= the Online per-sale Last Print list,
     * `GET /api/pos/print-jobs/{saleId}`), newest first, inside the operator's data scope.
     */
    public function printJobs(Request $request): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $q = PrintJob::on('tenant')->with('printer')->where('branch_id', $branchId)->orderByDesc('id');
        $sale = null;
        if ($request->filled('sale_id')) {
            $sale = $this->scopedSale((int) $request->input('sale_id'));
            if ($sale instanceof JsonResponse) {
                return $sale;
            }
            $q->where('reference_type', 'sales_order')->where('reference_id', $sale->id); // Online: every job of the sale
        } else {
            $q->limit(50);
        }
        // USER DATA SCOPE parity (W0b, Online PrintJobController::assertSaleAccess): a terminal / order-type scoped
        // operator sees only the print jobs of sales inside that scope.
        $user = $request->user('tenant');
        $scope = app(UserDataScope::class);
        if ($scope->isScoped($user)) {
            $q->where('reference_type', 'sales_order')->whereIn('reference_id',
                $scope->applyToSales(SalesOrder::on('tenant')->where('branch_id', $branchId)->select('id'), $user));
        }

        return response()->json([
            'sale' => $sale ? ['id' => (int) $sale->id, 'sale_no' => $sale->sale_no, 'status' => $sale->status] : null,
            'jobs' => $q->get()->map(fn (PrintJob $j) => $this->printJobView($j))->values(),
        ]);
    }

    /**
     * Print Here — the document rendered for the browser (receipt / KOT / reminder) by the CANONICAL
     * renderer, so the appliance prints the same paper as Online (KOT deal names, snapshot fallback, layout).
     */
    public function printDocument(int $job)
    {
        $printJob = $this->scopedJob($job);
        if ($printJob instanceof JsonResponse) {
            abort($printJob->getStatusCode(), (string) ($printJob->getData(true)['message'] ?? ''));
        }
        if (! in_array($printJob->document_type, ['receipt', 'invoice', 'kot', 'reminder'], true)) {
            // A thermal report job has no sales order to render (the canonical renderer 404s) — its browser copy is the
            // Quick Report view; say so instead of a bare 404.
            abort(404, 'This print job has no browser document — use Quick Report → View / Print here.');
        }
        // The canonical documents carry a "Mark Printed" form aimed at the Cloud print-jobs route; on the
        // appliance that button must land on the Edge endpoint instead (the Blade reads this when set).
        view()->share('edgeMarkPrintedUrl', url('/edge/local/pos/print-jobs/' . $printJob->id . '/printed'));

        return app(\App\Http\Controllers\Tenant\PrintDocumentController::class)->preview($printJob);
    }

    /** The operator confirms a browser (fallback) print — network jobs are completed by the print worker only. */
    public function markPrinted(Request $request, int $job)
    {
        $printJob = $this->scopedJob($job);
        if ($printJob instanceof JsonResponse) {
            return $printJob;
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

    /** Retry a terminally-failed local network delivery, or a dismissed job (Edge print authority; shared requeue). */
    public function retryPrintJob(int $job): JsonResponse
    {
        $printJob = $this->scopedJob($job);
        if ($printJob instanceof JsonResponse) {
            return $printJob;
        }
        try {
            app(EdgeLocalPrintDeliveryService::class)->retryTerminalFailed($printJob->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->printJobView($printJob->fresh()) + ['status' => 'queued']);
    }

    /**
     * W5 D-14 — Online `POST /printing/jobs/{printJob}/dismiss` parity (permission-free, data-scoped): abandon a
     * queued/failed job with no counters (shared PrintJobService::cancelObsolete); never a printed job.
     */
    public function dismissPrintJob(Request $request, int $job): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $printJob = $this->scopedJob($job);
        if ($printJob instanceof JsonResponse) {
            return $printJob;
        }
        $reason = trim((string) ($data['reason'] ?? '')) ?: 'Dismissed by ' . (auth('tenant')->user()?->name ?? 'operator') . ' at the Branch Server';
        try {
            app(EdgeLocalPrintDeliveryService::class)->dismiss($printJob->id, $reason);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->printJobView($printJob->fresh()) + ['status' => 'cancelled']);
    }

    /** W5 D-08 — Online `POST /pos/{salesOrder}/printing/retry` parity: resume pending Direct Pay KOT / receipt / Reminder. */
    public function retryDirectPayPrinting(int $sale): JsonResponse
    {
        $order = $this->scopedSale($sale);
        if ($order instanceof JsonResponse) {
            return $order;
        }
        try {
            $printing = app(EdgeLocalPrintDirectPayService::class)->retry($order);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sale_id' => (int) $order->id, 'sale_no' => $order->sale_no, 'printing' => $printing]);
    }

    /**
     * W5 D-10 — the BILL PREVIEW document (canonical receipt Blade, "BILL PREVIEW"): for a saved check (`sale_id`)
     * or for the current cart (the /preview-bill body). Zero mutation. Online `POST /api/pos/bill-preview`.
     */
    public function billPreviewDocument(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sale_id' => ['nullable', 'integer'],
            'order_type' => ['nullable', 'string'],
            'discount_type' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric'],
            'promo_code' => ['nullable', 'string'],
            'customer_name' => ['nullable', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_charge_amount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'restaurant_table_session_id' => ['nullable', 'integer'],
            'lines' => ['required_without:sale_id', 'array'],
            'lines.*.product_id' => ['required_without:lines.*.combo_id', 'nullable', 'integer'],
            'lines.*.combo_id' => ['nullable', 'integer'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'min:0.001'],
            'lines.*.modifiers' => ['nullable', 'array'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $documents = app(EdgeLocalPrintDocumentService::class);
        try {
            if (! empty($data['sale_id'])) {
                $order = $this->scopedSale((int) $data['sale_id']);
                if ($order instanceof JsonResponse) {
                    return $order;
                }
                if (! in_array((string) $order->status, ['held', 'paid', 'partially_returned'], true)) {
                    return response()->json(['message' => 'Only an open or paid order has a bill to preview.'], 422);
                }

                return response()->json(['ok' => true, 'sale_id' => (int) $order->id, 'sale_no' => $order->sale_no, 'html' => $documents->heldPreviewHtml($order)]);
            }

            return response()->json(['ok' => true, 'sale_id' => null, 'html' => $documents->cartPreviewHtml($data, auth('tenant')->user(), $terminal)]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** W5 D-24 — the synced terminal auto-print preferences + routed printers (Online terminalPrintConfig). */
    public function printPreferences(Request $request): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $current = null;
        if ((int) $request->session()->get(self::TERMINAL_SESSION_KEY, 0) > 0) {
            $t = $this->selectedTerminal($request);
            $current = $t instanceof JsonResponse ? null : $t;
        }

        return response()->json(app(EdgeLocalPrintDocumentService::class)->preferences($branchId, $current, auth('tenant')->user()));
    }

    // ───────────────────────────── scope (Online assertSaleAccess / assertPrintJobAccess) ─────────────────────────────

    /** The bound branch's sale, inside the operator's UserDataScope (branch / terminals / order types). */
    private function scopedSale(int $saleId): SalesOrder|JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $order = SalesOrder::on('tenant')->where('id', $saleId)->where('branch_id', $branchId)->first();
        if (! $order) {
            return response()->json(['message' => 'No sale found.'], 404);
        }
        if (app(UserDataScope::class)->deniesSale(auth('tenant')->user(), $order)) {
            return response()->json(['message' => 'This order is outside your terminal / order-type scope.'], 403);
        }

        return $order;
    }

    /** The bound branch's print job; a sale's job follows the sale's scope, any other job the terminal scope. */
    private function scopedJob(int $jobId): PrintJob|JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $printJob = PrintJob::on('tenant')->where('id', $jobId)->where('branch_id', $branchId)->first();
        if (! $printJob) {
            return response()->json(['message' => 'No print job found.'], 404);
        }
        $user = auth('tenant')->user();
        $scope = app(UserDataScope::class);
        if ($printJob->reference_type === 'sales_order' && $printJob->reference_id) {
            $sale = SalesOrder::on('tenant')->find($printJob->reference_id);
            if ($sale && $scope->deniesSale($user, $sale)) {
                return response()->json(['message' => 'This print job is outside your terminal / order-type scope.'], 403);
            }

            return $printJob;
        }
        if (($terminalIds = $scope->terminalIds($user)) && ! in_array((int) $printJob->terminal_id, $terminalIds, true)) {
            return response()->json(['message' => 'This print job is outside your terminal scope.'], 403);
        }

        return $printJob;
    }

    private function reminderView(array $reminder): array
    {
        return [
            'revision' => $reminder['revision'] ?? null,
            'auto_jobs' => collect($reminder['auto_jobs'] ?? [])->map(fn (PrintJob $j) => $this->printJobView($j->fresh()))->values()->all(),
            'ask_printers' => $reminder['ask_printers'] ?? [],
            'confirmation_token' => $reminder['confirmation_token'] ?? null,
            'warning' => $reminder['warning'] ?? null,
        ];
    }

    private function printJobView(PrintJob $j): array
    {
        $j->loadMissing('printer');
        $isReminder = $j->document_type === 'reminder';

        return [
            'id' => (int) $j->id, 'job_no' => $j->job_no,
            'document_type' => $j->document_type, 'print_status' => $j->print_status,
            'event_type' => $isReminder ? data_get($j->payload, 'event_type') : data_get($j->payload, 'kot_event_type'),
            'printer_name' => $j->printer?->name ?? 'Print here (browser)',
            'printer_type' => $j->printer?->printer_type ?? 'browser',
            'fallback' => empty($j->printer_id),
            'terminal_id' => $j->terminal_id !== null ? (int) $j->terminal_id : null,
            'reference_no' => $j->reference_no,
            'reference_id' => $j->reference_id ? (int) $j->reference_id : null,
            // Online ajaxForSale: KOT/Reminder item count, Reminder revision + duplicate copy number.
            'line_count' => $isReminder ? count($j->payload['lines'] ?? []) : count($j->payload['line_ids'] ?? []),
            'revision' => $isReminder ? (int) ($j->payload['revision'] ?? 1) : null,
            'copy_no' => ($isReminder || $j->document_type === 'kot') ? (int) ($j->copy_no ?? 1) : null,
            'is_reprint' => (bool) data_get($j->payload, 'is_reprint', false),
            'error_message' => $j->error_message,
            'has_document' => in_array($j->document_type, ['receipt', 'invoice', 'kot', 'reminder'], true),
            'preview_url' => url('/edge/local/pos/print-jobs/' . $j->id . '/document'),
            'created_at' => $j->created_at?->toIso8601String(),
        ];
    }
}
