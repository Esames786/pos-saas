<?php

namespace App\Services\Printing;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Printer;
use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PrintJobService
{
    public function __construct(
        private readonly PrintRoutingService $routingService,
    ) {}

    public function queueReceipt(SalesOrder $sale, ?Printer $printer = null, ?string $terminalId = null): PrintJob
    {
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
                $jobs[] = $this->createKotJob($sale, $printer, $payloadLineIds, $payloadQuantities, $terminalId, $isReprint);

                if (!$isReprint) {
                    $this->markKotLinesQueued($sale, $payloadLineIds);
                }
            }

            return $jobs;
        }

        // No explicit printer — use routing service.
        $routes = $this->routingService->kotRoutesForSale($sale, $lineIds, $isReprint);

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
                $isReprint
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
                $this->markKotLinesPrinted($sale, $job);
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
    ): PrintJob {
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
                'fallback'        => $printer === null,
            ],
            'attempts'           => 0,
            'created_by_user_id' => Auth::id(),
        ]);

        $job->update(['raw_payload' => app(EscPosPayloadService::class)->build($job)]);

        return $job;
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
