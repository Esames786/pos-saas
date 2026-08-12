<?php

namespace App\Services\Printing;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Printer;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\KotBatch;
use App\Models\Tenant\SalesOrderLineCancellation;
use App\Models\Tenant\ReceiptLayoutSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrintJobService
{
    public function __construct(
        private readonly PrintRoutingService $routingService,
    ) {}

    public function queueReceipt(SalesOrder $sale, ?Printer $printer = null, ?string $terminalId = null, bool $ensureOnce = false): PrintJob
    {
        // SALE-IDEMPOTENCY-HARDEN-1: auto receipt after a sale is "ensure-once" —
        // if a live (queued/printed) receipt job already exists for this sale, reuse
        // it (a replay/timeout-retry must not create a duplicate). An explicit
        // reprint passes ensureOnce=false and always makes a fresh job. A previously
        // FAILED receipt is not reused, so a genuine miss can still be recovered.
        // A receipt raised BEFORE payment (the "Bill / Preview" proforma) is a different document
        // from the customer's FINAL bill. Treating them as one made the preview satisfy
        // ensure-once, so the closing bill never printed after Review & Pay. The phase is part of
        // the identity, and the final bill is only ever satisfied by a job raised after payment.
        $isFinal = $sale->completed_at !== null
            && in_array($sale->status, ['paid', 'partially_returned', 'returned'], true);
        $logicalKey = $ensureOnce
            ? 'receipt:' . ($isFinal ? 'final' : 'proforma') . ':sale-' . $sale->id
            : null;

        if ($ensureOnce) {
            $existing = PrintJob::where('reference_type', 'sales_order')
                ->where('reference_id', $sale->id)
                ->where('document_type', 'receipt')
                ->whereIn('print_status', ['queued', 'printed'])
                ->where(function ($q) use ($isFinal, $logicalKey, $sale) {
                    // The phase key decides — never a timestamp, because a preview printed in the
                    // SAME SECOND as the payment would otherwise pass for the final bill.
                    $q->where('logical_key', $logicalKey);
                    if ($isFinal) {
                        // legacy jobs carry the old 'receipt:auto:…' key; honour one only when it
                        // was raised after payment, so old paid sales never surprise-reprint.
                        $q->orWhere(fn ($legacy) => $legacy
                            ->where('logical_key', 'like', 'receipt:auto:%')
                            ->where('created_at', '>=', $sale->completed_at));
                    }
                })
                ->latest('id')
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $sale->loadMissing(['branch', 'terminal', 'customer', 'lines', 'payments.method']);

        $printer = $printer ?: $this->routingService->receiptPrinter($sale);

        $attributes = [
            'job_no'             => $this->nextJobNo(),
            'logical_key'         => $logicalKey,
            'copy_no'            => 1,
            'branch_id'          => $sale->branch_id,
            'terminal_id'        => $terminalId ?: $sale->terminal_id,
            'printer_id'         => $printer?->id,
            'document_type'      => 'receipt',
            'print_status'       => 'queued',
            'reference_type'     => 'sales_order',
            'reference_id'       => $sale->id,
            'reference_no'       => $sale->sale_no,
            'payload'            => [
                'sales_order_id' => $sale->id,
                'sale_no'        => $sale->sale_no,
                'fallback'       => $printer === null,
            ],
            'attempts'           => 0,
            'created_by_user_id' => Auth::id(),
        ];

        return $ensureOnce
            ? $this->createLogicalJob($attributes)
            : tap(PrintJob::create($attributes), function (PrintJob $job) {
                $job->update(['raw_payload' => app(EscPosPayloadService::class)->build($job)]);
            });
    }

    public function queueKot(
        SalesOrder $sale,
        ?Printer   $printer     = null,
        array      $lineIds     = [],
        ?string    $terminalId  = null,
        bool       $isReprint   = false,
    ): array {
        if ($isReprint) {
            return $this->queueKotResolved($sale, $printer, $lineIds, $terminalId, true);
        }

        // Serialize delta calculation, immutable batch creation, and sent-quantity
        // reservation so simultaneous holds cannot create two automatic KOT events.
        return DB::connection('tenant')->transaction(function () use ($sale, $printer, $lineIds, $terminalId) {
            $lockedSale = SalesOrder::whereKey($sale->id)->lockForUpdate()->firstOrFail();

            return $this->queueKotResolved($lockedSale, $printer, $lineIds, $terminalId, false);
        }, 3);
    }

    private function queueKotResolved(
        SalesOrder $sale,
        ?Printer   $printer,
        array      $lineIds,
        ?string    $terminalId,
        bool       $isReprint,
    ): array {
        $sale->loadMissing([
            'branch', 'terminal', 'customer',
            'lines.product.category',
            'restaurantTable', 'restaurantWaiter',
        ]);

        $jobs = [];

        if ($printer !== null) {
            // Explicit printer supplied (backward-compat path) — build one job directly.
            $lines = $sale->lines;
            if (!empty($lineIds)) {
                $intIds = collect($lineIds)->map(fn ($id) => (int) $id)->all();
                $lines  = $lines->whereIn('id', $intIds);
            }

            $payloadLineIds   = [];
            $payloadQuantities = [];

            foreach ($lines as $line) {
                $qty = $isReprint
                    ? (float) $line->quantity
                    : max((float) $line->quantity - (float) ($line->kot_sent_quantity ?? 0), 0);

                if ($qty <= 0) {
                    continue;
                }

                $payloadLineIds[]                        = $line->id;
                $payloadQuantities[(string) $line->id]   = $qty;
            }

            if (!empty($payloadLineIds)) {
                $batch = $isReprint ? null : $this->createKotBatch($sale, $payloadQuantities);
                $jobs[] = $this->createKotJob($sale, $printer, $payloadLineIds, $payloadQuantities, $terminalId, $isReprint, $batch);

                if (!$isReprint) {
                    $this->markKotLinesQueued($sale, $payloadLineIds);
                }
            }

            return $jobs;
        }

        // No explicit printer — use routing service.
        $routes = $this->routingService->kotRoutesForSale($sale, $lineIds, $isReprint);

        if (!$isReprint && ($pendingFallback = $this->matchingQueuedBrowserFallback($sale, $routes))) {
            return [$pendingFallback];
        }

        $batchQuantities = [];
        foreach ($routes as $route) {
            foreach (($route['line_quantities'] ?? []) as $lineId => $quantity) {
                $batchQuantities[(string) $lineId] = (float) $quantity;
            }
        }
        $batch = (!$isReprint && $batchQuantities) ? $this->createKotBatch($sale, $batchQuantities) : null;

        foreach ($routes as $route) {
            $routeLineIds   = array_values(array_unique($route['line_ids'] ?? []));
            $routeQuantities = $route['line_quantities'] ?? [];

            if (empty($routeLineIds)) {
                continue;
            }

            $routePrinter = $route['printer'] ?? null;

            $jobs[] = $this->createKotJob(
                $sale,
                $routePrinter,
                $routeLineIds,
                $routeQuantities,
                $terminalId,
                $isReprint,
                $batch,
                null,
                $route['category_id'] ?? null,
                $route['category_name'] ?? null
            );

            // Only mark lines sent at queue time for real network printers.
            // For browser/manual fallback (null printer), lines are marked only
            // when the user clicks Mark Printed — otherwise the preview would show empty.
            if (!$isReprint && $routePrinter !== null) {
                $this->markKotLinesQueued($sale, $routeLineIds);
            }
        }

        return $jobs;
    }

    /** Queue an immutable kitchen cancellation event without mutating sent quantities. */
    public function queueCancellationKot(SalesOrder $sale, array $lineQuantities, ?string $terminalId = null): array
    {
        $sale->loadMissing(['lines.product.category']);
        $lineQuantities = collect($lineQuantities)
            ->mapWithKeys(fn ($quantity, $lineId) => [(string) ((int) $lineId) => (float) $quantity])
            ->filter(fn ($quantity) => $quantity > 0)
            ->all();

        if (!$lineQuantities) {
            return ['batch' => null, 'jobs' => []];
        }

        $routes = $this->routingService->kotRoutesForQuantities($sale, $lineQuantities);
        $batch = $this->createKotBatch($sale, $lineQuantities, 'cancel');
        $jobs = [];

        foreach ($routes as $route) {
            $jobs[] = $this->createKotJob(
                $sale,
                $route['printer'] ?? null,
                array_values(array_unique($route['line_ids'] ?? [])),
                $route['line_quantities'] ?? [],
                $terminalId,
                false,
                $batch,
                'cancel',
                $route['category_id'] ?? null,
                $route['category_name'] ?? null
            );
        }

        return ['batch' => $batch, 'jobs' => $jobs];
    }

    /** Queue automatic Reminder destinations and return any destinations needing POS confirmation. */
    public function planRemindersForKotJobs(SalesOrder $sale, array $kotJobs): array
    {
        $batchId = collect($kotJobs)->pluck('payload.kot_batch_id')->filter()->first();
        $batch = $batchId ? KotBatch::with('lines')->find($batchId) : null;

        if (!$batch || !in_array($batch->event_type, ['normal', 'addition'], true)) {
            return $this->emptyReminderPlan();
        }

        $revision = $this->reminderRevision($sale, $batch);
        $routes = $this->routingService->reminderRoutesForSale($sale);
        $autoRoutes = collect($routes)->filter(
            fn ($route) => $revision === 1 || !$route['ask_on_addition']
        );
        $askRoutes = collect($routes)->filter(
            fn ($route) => $revision > 1 && $route['ask_on_addition']
        )->values();

        $autoJobs = $autoRoutes->map(
            fn ($route) => $this->queueReminder($sale, $batch, $route['printer'], $revision)
        )->values();

        $token = null;
        if ($askRoutes->isNotEmpty()) {
            $token = Crypt::encryptString(json_encode([
                'sale_id' => (int) $sale->id,
                'batch_id' => (int) $batch->id,
                'event_uuid' => (string) $batch->event_uuid,
                'revision' => $revision,
                'printer_ids' => $askRoutes->pluck('printer.id')->map(fn ($id) => (int) $id)->all(),
                'branch_id' => (int) $sale->branch_id,
                'user_id' => (int) (auth('tenant')->id() ?: Auth::id()),
                'expires_at' => now()->addMinutes(30)->timestamp,
            ], JSON_THROW_ON_ERROR));
        }

        return [
            'revision' => $revision,
            'auto_jobs' => $autoJobs->all(),
            'ask_printers' => $askRoutes->map(fn ($route) => [
                'id' => (int) $route['printer']->id,
                'name' => $route['printer']->name,
            ])->all(),
            'confirmation_token' => $token,
        ];
    }

    public function queueConfirmedReminders(SalesOrder $sale, string $token): array
    {
        $context = $this->validateReminderConfirmation($sale, $token);

        return Printer::whereIn('id', $context['printer_ids'])->where('is_active', true)->where('supports_reminder', true)
            ->get()->map(fn ($printer) => $this->queueReminder(
                $sale,
                $context['batch'],
                $printer,
                $context['revision']
            ))->values()->all();
    }

    /** Validate a server-bound Ask decision without queuing it. */
    public function validateReminderConfirmation(SalesOrder $sale, string $token): array
    {
        try {
            $bound = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['confirmation_token' => 'Reminder confirmation is invalid or expired.']);
        }

        if ((int) ($bound['sale_id'] ?? 0) !== (int) $sale->id
            || (int) ($bound['branch_id'] ?? 0) !== (int) $sale->branch_id
            || (int) ($bound['user_id'] ?? 0) !== (int) (auth('tenant')->id() ?: Auth::id())
            || (int) ($bound['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['confirmation_token' => 'Reminder confirmation does not belong to this order.']);
        }

        $batch = KotBatch::where('sales_order_id', $sale->id)
            ->whereKey((int) ($bound['batch_id'] ?? 0))
            ->where('event_uuid', (string) ($bound['event_uuid'] ?? ''))
            ->whereIn('event_type', ['normal', 'addition'])
            ->firstOrFail();

        $boundRevision = (int) ($bound['revision'] ?? 0);
        if ($boundRevision !== $this->reminderRevision($sale, $batch)
            || $boundRevision !== $this->latestOrderRevision($sale)) {
            throw ValidationException::withMessages(['confirmation_token' => 'This Reminder round is stale; save the order again.']);
        }

        $allowedIds = collect($bound['printer_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
        $currentAskIds = collect($this->routingService->reminderRoutesForSale($sale))
            ->filter(fn ($route) => $route['ask_on_addition'])
            ->pluck('printer.id')->map(fn ($id) => (int) $id);

        if ($allowedIds->diff($currentAskIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['confirmation_token' => 'Reminder routing changed; save the order again.']);
        }

        return [
            'batch' => $batch,
            'revision' => (int) $bound['revision'],
            'printer_ids' => $allowedIds->values()->all(),
        ];
    }

    public function queueReminderReprint(PrintJob $source): PrintJob
    {
        if ($source->document_type !== 'reminder' || !$source->printer_id) {
            throw ValidationException::withMessages(['print_job' => 'Select a network Reminder job to reprint.']);
        }

        return DB::connection('tenant')->transaction(function () use ($source) {
            PrintJob::whereKey($source->id)->lockForUpdate()->firstOrFail();
            $eventUuid = (string) data_get($source->payload, 'event_uuid', 'legacy-' . $source->id);
            $prefix = 'reminder-copy:' . $eventUuid . ':printer-' . $source->printer_id . ':';
            $copyNo = (int) PrintJob::where('logical_key', 'like', $prefix . '%')->max('copy_no') + 1;
            $payload = $source->payload;
            $payload['copy_no'] = $copyNo;
            $payload['is_reprint'] = true;

            return $this->createLogicalJob([
                'job_no' => $this->nextJobNo(),
                'logical_key' => $prefix . $copyNo,
                'copy_no' => $copyNo,
                'branch_id' => $source->branch_id,
                'terminal_id' => $source->terminal_id,
                'printer_id' => $source->printer_id,
                'document_type' => 'reminder',
                'print_status' => 'queued',
                'reference_type' => $source->reference_type,
                'reference_id' => $source->reference_id,
                'reference_no' => $source->reference_no,
                'payload' => $payload,
                'attempts' => 0,
                'created_by_user_id' => Auth::id(),
            ]);
        }, 3);
    }

    /** Queue non-interactive correction Reminders after cancellation approval/audit is durable. */
    public function queueCancellationReminders(SalesOrder $sale, KotBatch $batch, bool $wholeOrder): array
    {
        $sale->loadMissing(['lines.product.category']);
        $cancellations = SalesOrderLineCancellation::with(['reason', 'requestedBy', 'approvedBy'])
            ->where('sales_order_id', $sale->id)->where('kot_batch_id', $batch->id)->get();
        $effective = $sale->lines->mapWithKeys(fn ($line) => [
            (string) $line->id => $wholeOrder ? 0.0 : (float) $line->quantity,
        ])->all();
        $current = collect($this->routingService->reminderRoutesForSale($sale, $effective))->pluck('printer');
        $historicalIds = PrintJob::where('reference_type', 'sales_order')
            ->where('reference_id', $sale->id)->where('document_type', 'reminder')
            ->whereNotNull('printer_id')->where('print_status', '!=', 'cancelled')
            ->pluck('printer_id');
        $printers = Printer::whereIn('id', $current->pluck('id')->merge($historicalIds)->unique())
            ->where('is_active', true)->where('supports_reminder', true)->get();

        return $printers->map(fn ($printer) => $this->queueReminder(
            $sale,
            $batch,
            $printer,
            $this->latestOrderRevision($sale),
            $wholeOrder ? 'cancelled_order' : 'cancelled_updated_order',
            [],
            $effective,
            $cancellations
        ))->values()->all();
    }

    private function queueReminder(
        SalesOrder $sale,
        KotBatch $batch,
        Printer $printer,
        int $revision,
        string $eventType = 'order',
        array $cancelledQuantities = [],
        array $effectiveQuantities = [],
        $cancellations = null,
    ): PrintJob {
        $payload = $this->reminderSnapshot(
            $sale, $batch, $printer, $revision, $eventType,
            $cancelledQuantities, $effectiveQuantities, $cancellations
        );

        return $this->createLogicalJob([
            'job_no' => $this->nextJobNo(),
            'logical_key' => 'reminder:' . $batch->event_uuid . ':' . $printer->id,
            'copy_no' => 1,
            'branch_id' => $sale->branch_id,
            'terminal_id' => $sale->terminal_id,
            'printer_id' => $printer->id,
            'document_type' => 'reminder',
            'print_status' => 'queued',
            'reference_type' => 'sales_order',
            'reference_id' => $sale->id,
            'reference_no' => $sale->sale_no,
            'payload' => $payload,
            'attempts' => 0,
            'created_by_user_id' => Auth::id(),
        ]);
    }


    public function markPrinted(PrintJob $job): void
    {
        DB::connection('tenant')->transaction(function () use ($job) {
            $job->refresh();

            if ($job->print_status === 'printed') {
                return;
            }

            $job->update([
                'print_status'  => 'printed',
                'printed_at'    => now(),
                'failed_at'     => null,
                'error_message' => null,
            ]);

            if ($job->reference_type !== 'sales_order' || !$job->reference_id) {
                return;
            }

            $sale = SalesOrder::with('lines')->find($job->reference_id);
            if (!$sale) {
                return;
            }

            if (in_array($job->document_type, ['receipt', 'invoice'], true)) {
                $sale->increment('receipt_print_count');
                $sale->forceFill(['last_receipt_printed_at' => now()])->save();
                return;
            }

            if ($job->document_type === 'kot') {
                $eventType = (string) data_get($job->payload, 'kot_event_type', 'normal');
                if (!in_array($eventType, ['cancel', 'duplicate'], true)) {
                    $this->markKotLinesPrinted($sale, $job);
                }
                $sale->increment('kot_print_count');
                $sale->forceFill(['last_kot_printed_at' => now()])->save();
            }
        });
    }

    /**
     * Abandon a print INTENT that is never going to be delivered — a genuine failure the operator
     * no longer wants sent, or a job made obsolete by a later successful copy.
     *
     * This is the correct home for "clear it off the queue", NOT markPrinted(): markPrinted claims
     * a PHYSICAL transport completed and carries real side effects (print counters, KOT sent
     * bookkeeping, last-printed timestamps). A dismissed job printed NOTHING, so it must move to a
     * terminal state WITHOUT any of those effects — the queue is clean and the history stays true.
     *
     * `cancelled` is the shared enum's long-idle terminal value, used here for its plain Cloud
     * meaning (intent abandoned). It is deliberately NOT Edge transport metadata — the Branch
     * Server keeps its own edge_local_print_deliveries state. A cancelled job may still be
     * requeued through the existing Retry path (requeueFailed accepts failed|cancelled).
     *
     * Only a job that has NOT printed may be dismissed; a `printed` job throws, so this can never
     * quietly erase a real print.
     */
    public function cancelObsolete(PrintJob $job, string $reason): void
    {
        DB::connection('tenant')->transaction(function () use ($job, $reason) {
            $job->refresh();

            if (! in_array($job->print_status, ['failed', 'queued'], true)) {
                throw new \RuntimeException(
                    "Only a failed or queued job can be dismissed — job #{$job->id} is [{$job->print_status}]."
                );
            }

            $job->update([
                'print_status'        => 'cancelled',
                'error_message'       => $reason,
                'claimed_by_agent_id' => null,
                'claimed_at'          => null,
                // printed_at stays NULL: nothing was printed. failed_at is left as the historical
                // last-failure stamp when present.
            ]);
        });
    }

    /**
     * The ONE shared requeue of a failed/cancelled job (EDGE-LOCAL-PRINT-1 §5 closure): Cloud
     * PrintJobController::retry and the Edge local retry both delegate here so the field contract
     * can never drift. Only failed|cancelled jobs are eligible — anything else throws.
     */
    public function requeueFailed(PrintJob $job): void
    {
        if (! in_array($job->print_status, ['failed', 'cancelled'], true)) {
            throw new \RuntimeException('Only failed or cancelled jobs can be retried.');
        }

        $job->update([
            'print_status'        => 'queued',
            'error_message'       => null,
            'failed_at'           => null,
            'claimed_by_agent_id' => null,
            'claimed_at'          => null,
        ]);
    }

    /**
     * How many times the SERVER will put a transiently-failed job back in the queue before giving
     * up. Port 9100 accepts one connection at a time, so a KOT and a receipt raised seconds apart
     * routinely collide: the second connection cannot open, the agent abandons it, and the ticket
     * is lost until someone notices. Khatri Biryani were re-printing 48.7% of bills by hand.
     */
    public const MAX_AUTO_REQUEUE = 3;

    /**
     * Failures we are confident happened BEFORE the payload reached the printer, so re-sending
     * cannot produce a second copy of the same bill. A connect that never opened wrote nothing.
     */
    private const RETRYABLE = ['connection timed out', 'econnrefused', 'ehostunreach', 'enetunreach', 'etimedout', 'econnreset', 'socket hang up'];

    public function markFailed(PrintJob $job, string $message): void
    {
        // A COMPLETED print can never be demoted to failed (EDGE-LOCAL-PRINT-1 §19, protects Cloud
        // too): an agent that already reported printed may double-report — its own claim ownership
        // still matches, so the controller check alone does not stop the stale printed→failed flip.
        $job->refresh();
        if ($job->print_status === 'printed') {
            return;
        }

        $attempts = (int) $job->attempts + 1;

        // AUTO-REQUEUE: hand it straight back to the queue so the agent retries on its next poll
        // (~3s). The printer is almost always free again within seconds — the live evidence was a
        // receipt that failed at 14:55:04 and printed at 14:55:10 on the very same printer.
        if ($attempts < self::MAX_AUTO_REQUEUE && $this->isTransient($message)) {
            $job->update([
                'print_status'  => 'queued',
                'attempts'      => $attempts,
                'error_message' => $message,
                'claimed_at'    => null,
                'claimed_by_agent_id' => null,
            ]);

            return;
        }

        $job->update([
            'print_status'  => 'failed',
            'failed_at'     => now(),
            'attempts'      => $attempts,
            'error_message' => $message,
        ]);
    }

    /** Only connect-phase failures are re-sent; anything else could mean the ticket already printed. */
    private function isTransient(string $message): bool
    {
        $message = strtolower($message);
        foreach (self::RETRYABLE as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function createKotJob(
        SalesOrder $sale,
        ?Printer   $printer,
        array      $lineIds,
        array      $lineQuantities,
        ?string    $terminalId,
        bool       $isReprint,
        ?KotBatch  $batch = null,
        ?string    $eventType = null,
        ?int       $routeCategoryId = null,
        ?string    $routeCategoryName = null,
    ): PrintJob {
        return DB::connection('tenant')->transaction(function () use (
            $sale,
            $printer,
            $lineIds,
            $lineQuantities,
            $terminalId,
            $isReprint,
            $batch,
            $eventType,
            $routeCategoryId,
            $routeCategoryName
        ) {
            $latestBatch = $batch ?: $sale->kotBatches()
                ->whereIn('event_type', ['normal', 'addition'])
                ->latest('sequence_no')
                ->first();
            $resolvedEventType = $eventType ?: ($isReprint ? 'duplicate' : ($batch?->event_type ?? 'normal'));
            // ONE KOT PER CATEGORY: the destination (and therefore the job's logical key) carries
            // the category, so two categories routed to the SAME printer produce two tickets
            // instead of the second deduping into the first.
            $destination = ($printer ? 'printer-' . $printer->id : 'browser')
                . ($routeCategoryId !== null ? '#cat-' . $routeCategoryId : '');
            $sourceIdentity = $latestBatch?->event_uuid ?: 'legacy-sale-' . $sale->id;
            $copyNo = 1;

            if ($isReprint) {
                if ($latestBatch) {
                    KotBatch::whereKey($latestBatch->id)->lockForUpdate()->firstOrFail();
                } else {
                    SalesOrder::whereKey($sale->id)->lockForUpdate()->firstOrFail();
                }

                $copyPrefix = 'kot-copy:' . $sourceIdentity . ':' . $destination . ':';
                $copyNo = (int) PrintJob::where('logical_key', 'like', $copyPrefix . '%')->max('copy_no') + 1;
                $logicalKey = $copyPrefix . $copyNo;
            } else {
                $logicalKey = $latestBatch
                    ? 'kot:' . $latestBatch->event_uuid . ':' . $destination
                    : null;
            }

            $attributes = [
                'job_no'             => $this->nextJobNo(),
                'logical_key'         => $logicalKey,
                'copy_no'            => $copyNo,
                'branch_id'          => $sale->branch_id,
                'terminal_id'        => $terminalId ?: $sale->terminal_id,
                'printer_id'         => $printer?->id,
                'document_type'      => 'kot',
                'print_status'       => 'queued',
                'reference_type'     => 'sales_order',
                'reference_id'       => $sale->id,
                'reference_no'       => $sale->sale_no,
                'payload'            => [
                    'sales_order_id'  => $sale->id,
                    'sale_no'         => $sale->sale_no,
                    'printer_id'      => $printer?->id,
                    'line_ids'        => array_values($lineIds),
                    'line_quantities' => $lineQuantities,
                    'is_reprint'      => $isReprint,
                    'kot_batch_id'    => $latestBatch?->id,
                    'kot_event_uuid'  => $latestBatch?->event_uuid,
                    'kot_sequence_no' => $latestBatch?->sequence_no,
                    'kot_event_type'  => $resolvedEventType,
                    'kot_category_id' => $routeCategoryId,
                    'kot_category'    => $routeCategoryName,
                    'copy_no'         => $copyNo,
                    'line_snapshots'  => $this->lineSnapshots($sale, $lineQuantities),
                    'fallback'        => $printer === null,
                ],
                'attempts'           => 0,
                'created_by_user_id' => Auth::id(),
            ];

            return $this->createLogicalJob($attributes);
        }, 3);
    }

    private function createLogicalJob(array $attributes): PrintJob
    {
        try {
            $job = PrintJob::create($attributes);
            $job->update(['raw_payload' => app(EscPosPayloadService::class)->build($job)]);

            return $job;
        } catch (QueryException $exception) {
            $logicalKey = $attributes['logical_key'] ?? null;
            $existing = $logicalKey
                ? PrintJob::where('logical_key', $logicalKey)->first()
                : null;

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function createKotBatch(SalesOrder $sale, array $lineQuantities, ?string $eventType = null): KotBatch
    {
        return DB::connection('tenant')->transaction(function () use ($sale, $lineQuantities, $eventType) {
            SalesOrder::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sequence = (int) KotBatch::where('sales_order_id', $sale->id)->max('sequence_no') + 1;
            $type = $eventType ?: ($sequence === 1 ? 'normal' : 'addition');

            $batch = KotBatch::create([
                'sales_order_id' => $sale->id,
                'sequence_no' => $sequence,
                'event_type' => $type,
                'copy_no' => 1,
                'created_by_user_id' => auth('tenant')->id() ?: Auth::id(),
            ]);

            foreach ($this->lineSnapshots($sale, $lineQuantities) as $snapshot) {
                $batch->lines()->create([
                    'sales_order_line_id' => $snapshot['line_id'],
                    // EDGE-IDENTITY: immutable snapshot of the source line's canonical identity — survives the
                    // Add Round line delete+recreate churn and divergent Cloud/Edge numeric ids.
                    'source_line_uuid' => $snapshot['line_uuid'],
                    'product_id' => $snapshot['product_id'],
                    'product_variant_id' => $snapshot['product_variant_id'],
                    'product_name' => $snapshot['product_name'],
                    'variant_name' => $snapshot['variant_name'],
                    'unit_code' => $snapshot['unit_code'],
                    'line_kind' => $snapshot['line_kind'],
                    'combo_id' => $snapshot['combo_id'],
                    'quantity' => $snapshot['quantity'],
                    'modifiers' => $snapshot['modifiers'],
                    'kitchen_note' => $snapshot['kitchen_note'],
                ]);
            }

            return $batch;
        });
    }

    private function lineSnapshots(SalesOrder $sale, array $lineQuantities): array
    {
        $sale->loadMissing('lines');

        return $sale->lines->whereIn('id', array_map('intval', array_keys($lineQuantities)))
            ->map(fn ($line) => [
                'line_id' => (int) $line->id,
                'line_uuid' => $line->line_uuid,
                'product_id' => $line->product_id ? (int) $line->product_id : null,
                'product_variant_id' => $line->product_variant_id ? (int) $line->product_variant_id : null,
                'product_name' => $line->product_name,
                'variant_name' => $line->variant_name,
                'unit_code' => $line->unit_code,
                'line_kind' => $line->line_kind ?? 'standard',
                'combo_id' => $line->combo_id ? (int) $line->combo_id : null,
                'quantity' => (float) ($lineQuantities[(string) $line->id] ?? $lineQuantities[$line->id] ?? 0),
                'modifiers' => $line->modifiers ?? [],
                'kitchen_note' => $line->kitchen_note,
            ])
            ->filter(fn ($snapshot) => $snapshot['quantity'] > 0)
            ->values()
            ->all();
    }

    private function reminderSnapshot(
        SalesOrder $sale,
        KotBatch $batch,
        Printer $printer,
        int $revision,
        string $eventType,
        array $cancelledQuantities,
        array $effectiveQuantities,
        $cancellations,
    ): array {
        $sale->loadMissing([
            'branch', 'customer', 'createdBy', 'restaurantTable', 'restaurantWaiter',
            'lines.product.category', 'lines.variant',
        ]);
        $batch->loadMissing('lines');
        $roundDeltas = $batch->lines->mapWithKeys(
            fn ($line) => [(string) $line->sales_order_line_id => (float) $line->quantity]
        );
        $effective = collect($effectiveQuantities);

        $lines = $sale->lines->map(function ($line) use ($roundDeltas, $effective, $effectiveQuantities) {
            $quantity = array_key_exists((string) $line->id, $effectiveQuantities)
                ? (float) $effective->get((string) $line->id)
                : (float) $line->quantity;

            return [
                'line_id' => (int) $line->id,
                'parent_line_id' => $line->parent_sales_order_line_id ? (int) $line->parent_sales_order_line_id : null,
                'line_kind' => $line->line_kind ?? 'standard',
                'combo_id' => $line->combo_id ? (int) $line->combo_id : null,
                'product_name' => (string) $line->product_name,
                'variant_name' => $line->variant_name,
                'unit_code' => $line->unit_code,
                'quantity' => $quantity,
                'round_delta' => (float) $roundDeltas->get((string) $line->id, 0),
                'modifiers' => collect($line->modifiers ?? [])->filter(fn ($modifier) => is_array($modifier))
                    ->map(fn ($modifier) => ['name' => (string) ($modifier['name'] ?? '')])
                    ->filter(fn ($modifier) => $modifier['name'] !== '')->values()->all(),
                'kitchen_note' => $line->kitchen_note,
            ];
        })->filter(fn ($line) => $line['quantity'] > 0)->values()->all();

        $cancelled = collect($cancellations ?? [])->map(fn ($event) => [
            'line_id' => $event->sales_order_line_id ? (int) $event->sales_order_line_id : null,
            'product_name' => (string) $event->product_name,
            'variant_name' => $event->variant_name,
            'unit_code' => null,
            'quantity' => (float) $event->quantity,
        ])->filter(fn ($line) => $line['quantity'] > 0)->values()->all();

        if (!$cancelled && $cancelledQuantities) {
            $cancelled = $sale->lines->map(function ($line) use ($cancelledQuantities) {
                $quantity = (float) ($cancelledQuantities[(string) $line->id] ?? 0);

                return [
                    'line_id' => (int) $line->id,
                    'product_name' => (string) $line->product_name,
                    'variant_name' => $line->variant_name,
                    'unit_code' => $line->unit_code,
                    'quantity' => $quantity,
                ];
            })->filter(fn ($line) => $line['quantity'] > 0)->values()->all();
        }

        $audit = collect($cancellations ?? [])->map(fn ($event) => [
            'reason' => $event->reason?->name ?? $event->reason?->reason ?? null,
            'requested_by' => $event->requestedBy?->name,
            'approved_by' => $event->approvedBy?->name,
            'requested_at' => $event->created_at?->toIso8601String(),
            'approved_at' => $event->cancelled_at?->toIso8601String(),
        ])->values()->all();
        $layout = ReceiptLayoutSetting::where('branch_id', $sale->branch_id)
            ->where('document_type', 'reminder')->where('is_active', true)->first();

        return [
            'event_uuid' => (string) $batch->event_uuid,
            'kot_batch_id' => (int) $batch->id,
            'event_type' => $eventType,
            'heading' => match ($eventType) {
                'cancelled_order' => 'CANCELLED ORDER',
                'cancelled_updated_order' => 'CANCELLED / UPDATED ORDER',
                default => $revision > 1 ? 'UPDATED ORDER' : 'REMINDER',
            },
            'revision' => $revision,
            'copy_no' => 1,
            'is_reprint' => false,
            'printer_id' => (int) $printer->id,
            'sale_no' => $sale->sale_no,
            'order_type' => $sale->order_type,
            'table' => $sale->restaurantTable?->table_no,
            'waiter' => $sale->restaurantWaiter?->name,
            'cashier' => $sale->createdBy?->name,
            'customer' => $sale->customer?->name ?? $sale->customer_name,
            'vehicle' => $sale->vehicle_number,
            'order_note' => $sale->notes,
            'order_time' => optional($sale->sale_date)->toIso8601String(),
            'updated_time' => optional($sale->updated_at)->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
            'layout' => [
                'paper_size' => $layout?->paper_size ?? $printer->paper_size ?? '80mm',
                'font_size' => (int) ($layout?->font_size ?? 12),
                'header_text' => $layout?->header_text,
                'footer_text' => $layout?->footer_text,
                'show_order_time' => (bool) ($layout?->show_order_time ?? true),
                'show_updated_time' => (bool) ($layout?->show_updated_time ?? true),
                'show_print_time' => (bool) ($layout?->show_print_time ?? true),
                'show_order_no' => (bool) ($layout?->show_order_no ?? true),
                'show_cashier_name' => (bool) ($layout?->show_cashier_name ?? true),
                'show_customer_name' => (bool) ($layout?->show_customer_name ?? false),
                'show_table_info' => (bool) ($layout?->show_table_info ?? true),
                'show_bingoo_branding' => (bool) ($layout?->show_bingoo_branding ?? false),
            ],
            'lines' => $lines,
            'cancelled_lines' => $cancelled,
            'cancellation_audit' => $audit,
        ];
    }

    private function reminderRevision(SalesOrder $sale, KotBatch $batch): int
    {
        return max(1, (int) KotBatch::where('sales_order_id', $sale->id)
            ->whereIn('event_type', ['normal', 'addition'])
            ->where('sequence_no', '<=', $batch->sequence_no)
            ->count());
    }

    private function latestOrderRevision(SalesOrder $sale): int
    {
        return max(1, (int) KotBatch::where('sales_order_id', $sale->id)
            ->whereIn('event_type', ['normal', 'addition'])->count());
    }

    private function emptyReminderPlan(): array
    {
        return [
            'revision' => null,
            'auto_jobs' => [],
            'ask_printers' => [],
            'confirmation_token' => null,
        ];
    }

    private function matchingQueuedBrowserFallback(SalesOrder $sale, array $routes): ?PrintJob
    {
        if (count($routes) !== 1 || ($routes[0]['printer'] ?? null) !== null) {
            return null;
        }

        $expected = collect($routes[0]['line_quantities'] ?? [])
            ->mapWithKeys(fn ($quantity, $lineId) => [(string) $lineId => (float) $quantity])
            ->sortKeys()
            ->all();

        $candidate = PrintJob::where('reference_type', 'sales_order')
            ->where('reference_id', $sale->id)
            ->where('document_type', 'kot')
            ->whereNull('printer_id')
            ->where('print_status', 'queued')
            ->whereNotNull('logical_key')
            ->latest('id')
            ->first();

        if (!$candidate || !in_array(data_get($candidate->payload, 'kot_event_type'), ['normal', 'addition'], true)) {
            return null;
        }

        $actual = collect(data_get($candidate->payload, 'line_quantities', []))
            ->mapWithKeys(fn ($quantity, $lineId) => [(string) $lineId => (float) $quantity])
            ->sortKeys()
            ->all();

        return $actual === $expected ? $candidate : null;
    }

    private function markKotLinesQueued(SalesOrder $sale, array $lineIds): void
    {
        $sale->lines()
            ->whereIn('id', $lineIds)
            ->get()
            ->each(fn ($line) => $line->update([
                'kot_sent'          => true,
                'kot_sent_quantity' => $line->quantity,
            ]));
    }

    private function markKotLinesPrinted(SalesOrder $sale, PrintJob $job): void
    {
        $this->applyKotSentBookkeeping($sale, $job);
    }

    /**
     * EDGE-LOCAL-POS-1 — acknowledge the KOT BUSINESS EVENT's sent quantities WITHOUT claiming a
     * physical print. This is exactly the bookkeeping the browser flow applies at markPrinted() and the
     * network-printer flow applies at queue time (markKotLinesQueued): set-to-line-quantity, so it is
     * idempotent and never over-advances. A Branch Server calls this right after queueKot() commits the
     * batch — the print_jobs row stays `queued` with printed_at NULL (no transport ran; a future
     * EDGE-LOCAL-PRINT-1 delivers the intent). LOCKED RULE: `printed` means an actual transport/preview
     * completion — nothing in the Edge runtime may set it until one exists.
     */
    public function applyKotSentBookkeeping(SalesOrder $sale, PrintJob $job): void
    {
        $payload = $job->payload ?? [];

        if (!empty($payload['is_reprint'])) {
            return;
        }

        $lineIds = collect($payload['line_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        if ($lineIds->isEmpty()) {
            return;
        }

        $sale->lines()
            ->whereIn('id', $lineIds)
            ->get()
            ->each(fn ($line) => $line->update([
                'kot_sent'          => true,
                'kot_sent_quantity' => $line->quantity,
            ]));
    }

    private function nextJobNo(): string
    {
        return 'PJ-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }
}
