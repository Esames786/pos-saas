<?php

namespace App\Services\Printing;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Printer;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\KotBatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
        if ($ensureOnce) {
            $existing = PrintJob::where('reference_type', 'sales_order')
                ->where('reference_id', $sale->id)
                ->where('document_type', 'receipt')
                ->whereIn('print_status', ['queued', 'printed'])
                ->latest('id')
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $sale->loadMissing(['branch', 'terminal', 'customer', 'lines', 'payments.method']);

        $printer = $printer ?: $this->routingService->receiptPrinter($sale);

        $job = PrintJob::create([
            'job_no'             => $this->nextJobNo(),
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
        ]);

        $job->update(['raw_payload' => app(EscPosPayloadService::class)->build($job)]);

        return $job;
    }

    public function queueKot(
        SalesOrder $sale,
        ?Printer   $printer     = null,
        array      $lineIds     = [],
        ?string    $terminalId  = null,
        bool       $isReprint   = false,
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
                $batch
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
                'cancel'
            );
        }

        return ['batch' => $batch, 'jobs' => $jobs];
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

    public function markFailed(PrintJob $job, string $message): void
    {
        $job->update([
            'print_status'  => 'failed',
            'failed_at'     => now(),
            'attempts'      => (int) $job->attempts + 1,
            'error_message' => $message,
        ]);
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
    ): PrintJob {
        $latestBatch = $batch ?: $sale->kotBatches()->whereIn('event_type', ['normal', 'addition'])->latest('sequence_no')->first();
        $resolvedEventType = $eventType ?: ($isReprint ? 'duplicate' : ($batch?->event_type ?? 'normal'));
        $job = PrintJob::create([
            'job_no'             => $this->nextJobNo(),
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
                'kot_sequence_no' => $latestBatch?->sequence_no,
                'kot_event_type'  => $resolvedEventType,
                'copy_no'         => $isReprint ? max((int) $sale->kot_print_count + 1, 2) : 1,
                'line_snapshots'  => $this->lineSnapshots($sale, $lineQuantities),
                'fallback'        => $printer === null,
            ],
            'attempts'           => 0,
            'created_by_user_id' => Auth::id(),
        ]);

        $job->update(['raw_payload' => app(EscPosPayloadService::class)->build($job)]);

        return $job;
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
