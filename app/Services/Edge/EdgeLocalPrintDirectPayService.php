<?php

namespace App\Services\Edge;

use App\Models\Tenant\SalesOrder;
use App\Services\Printing\DirectPayPrintOrchestrator;
use Illuminate\Support\Facades\Log;

/**
 * W5 (Team 5) — D-08 DIRECT PAY printing on the Branch Server: the KOT (+ Reminder) and the receipt of a sale
 * paid WITHOUT Hold (quick sale / takeaway / delivery), decided by the operator's `kot_print_intent` /
 * `receipt_print_intent` exactly as Online SalesOrderController::store does (:111-118, :616) — through the
 * SHARED DirectPayPrintOrchestrator (durable `direct_pay_print_state`, ensure-once receipt, existing-KOT reuse,
 * Reminder planning, retryable pending state that never unwinds the paid sale).
 *
 * The caller is the sale endpoint (Team 2 — POST /edge/local/pos/sales), AFTER the paid sale committed:
 *
 *     $printing = app(\App\Services\Edge\EdgeLocalPrintDirectPayService::class)
 *         ->afterPaidSale($sale, $data['kot_print_intent'] ?? null, $data['receipt_print_intent'] ?? null);
 *     // → include 'printing' => $printing in the JSON response (null when no intent was sent)
 *
 * and an idempotent REPLAY of the same client_uuid calls it again with the same intents (Online
 * idempotentReplayOrThrow re-orchestrates; the orchestrator reuses the stored jobs, so nothing duplicates).
 * Printing never fails the sale: any error is captured as retryable state / logged.
 */
class EdgeLocalPrintDirectPayService
{
    public const INTENTS = ['print', 'skip'];

    public function __construct(private readonly DirectPayPrintOrchestrator $orchestrator)
    {
    }

    /** @return array|null the Online `printing` block with Edge document URLs, or null when no intent pair was sent */
    public function afterPaidSale(SalesOrder $sale, ?string $kotIntent, ?string $receiptIntent): ?array
    {
        $fresh = SalesOrder::on('tenant')->find($sale->id);
        if (! $fresh || (string) $fresh->status !== 'paid') {
            return null;
        }
        if (! $fresh->direct_pay_print_state) {
            if (! in_array($kotIntent, self::INTENTS, true) || ! in_array($receiptIntent, self::INTENTS, true)) {
                return null; // Online: no intent pair → no orchestration (the POS page always sends both)
            }
            $fresh->forceFill(['direct_pay_print_state' => DirectPayPrintOrchestrator::initialState($kotIntent, $receiptIntent)])->save();
        }

        try {
            return $this->edgeUrls($this->orchestrator->orchestrate($fresh));
        } catch (\Throwable $e) {
            Log::warning('Edge direct-pay printing remains pending after the paid sale.', ['sales_order_id' => $sale->id, 'error' => $e->getMessage()]);

            return ['configured' => true, 'stable' => false, 'sale_paid' => true, 'retry_available' => true, 'kot_jobs' => [], 'receipt' => null,
                'reminder' => ['revision' => null, 'auto_jobs' => [], 'ask_printers' => [], 'confirmation_token' => null, 'warning' => 'Printing is pending — retry from Recent Prints.']];
        }
    }

    /** Online `POST /pos/{salesOrder}/printing/retry` parity. @throws \RuntimeException with the Online business message */
    public function retry(SalesOrder $sale): array
    {
        if (! $sale->direct_pay_print_state) {
            throw new \RuntimeException('This sale has no Direct Pay print intent.');
        }
        if ((string) $sale->status !== 'paid') {
            throw new \RuntimeException('Only a paid sale can resume Direct Pay printing.');
        }

        return $this->edgeUrls($this->orchestrator->orchestrate($sale));
    }

    /** The orchestrator answers with Cloud document URLs; the appliance serves the same documents locally. */
    private function edgeUrls(array $printing): array
    {
        $local = fn (?array $job) => $job === null ? null : array_merge($job, [
            'id' => (int) $job['job_id'],
            'preview_url' => url('/edge/local/pos/print-jobs/' . (int) $job['job_id'] . '/document'),
        ]);
        $printing['kot_jobs'] = array_map($local, $printing['kot_jobs'] ?? []);
        $printing['receipt'] = $local($printing['receipt'] ?? null);

        return $printing;
    }
}
