<?php

namespace App\Services\Printing;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DirectPayPrintOrchestrator
{
    public function __construct(
        private readonly PrintJobService $printJobs,
    ) {}

    public static function initialState(string $kotIntent, string $receiptIntent): array
    {
        return [
            'version' => 1,
            'kot_intent' => $kotIntent,
            'kot_status' => $kotIntent === 'skip' ? 'skipped' : 'pending',
            'receipt_intent' => $receiptIntent,
            'receipt_status' => $receiptIntent === 'skip' ? 'skipped' : 'pending',
            'reminder_status' => $kotIntent === 'skip' ? 'not_applicable' : 'pending',
            'kot_batch_id' => null,
            'kot_job_ids' => [],
            'receipt_job_id' => null,
            'ask_printer_ids' => [],
            'errors' => [],
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Payment is already authoritative when this runs. Every failure is captured
     * as durable retryable state and must never unwind the paid sale.
     */
    public function orchestrate(SalesOrder $sale): array
    {
        return DB::connection('tenant')->transaction(function () use ($sale) {
            $sale = SalesOrder::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $state = $sale->direct_pay_print_state;

            if (!$state) {
                return ['configured' => false, 'stable' => true];
            }
            if ($sale->status !== 'paid') {
                throw new RuntimeException('Direct-pay printing is available only for a paid sale.');
            }

            $state['errors'] = [];
            $receiptJob = $this->ensureReceipt($sale, $state);
            [$kotJobs, $reminder] = $this->ensureKotAndReminder($sale, $state);
            $state['updated_at'] = now()->toIso8601String();

            $sale->forceFill([
                'direct_pay_print_state' => $state,
                'direct_pay_print_orchestrated_at' => now(),
            ])->save();

            return $this->response($sale, $state, $kotJobs, $receiptJob, $reminder);
        }, 3);
    }

    public function markReminderDecision(SalesOrder $sale, int $batchId, string $decision): void
    {
        DB::connection('tenant')->transaction(function () use ($sale, $batchId, $decision) {
            $sale = SalesOrder::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $state = $sale->direct_pay_print_state;
            if (!$state || (int) ($state['kot_batch_id'] ?? 0) !== $batchId) {
                return;
            }

            $state['reminder_status'] = $decision === 'confirmed' ? 'queued' : 'declined';
            $state['updated_at'] = now()->toIso8601String();
            $sale->forceFill(['direct_pay_print_state' => $state])->save();
        });
    }

    private function ensureReceipt(SalesOrder $sale, array &$state): ?PrintJob
    {
        if (($state['receipt_intent'] ?? 'skip') !== 'print') {
            $state['receipt_status'] = 'skipped';
            return null;
        }

        try {
            $job = $this->printJobs->queueReceipt($sale, terminalId: $sale->terminal_id, ensureOnce: true);
            $state['receipt_status'] = 'queued';
            $state['receipt_job_id'] = $job->id;
            return $job;
        } catch (\Throwable $exception) {
            $state['receipt_status'] = 'pending_retry';
            $state['errors']['receipt'] = $exception->getMessage();
            Log::warning('Receipt orchestration remains pending after paid sale.', [
                'sales_order_id' => $sale->id,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    private function ensureKotAndReminder(SalesOrder $sale, array &$state): array
    {
        if (($state['kot_intent'] ?? 'skip') !== 'print') {
            $state['kot_status'] = 'skipped';
            $state['reminder_status'] = 'not_applicable';
            return [[], $this->emptyReminder()];
        }

        try {
            $jobs = $this->existingKotJobs($state);
            if (!$jobs) {
                $jobs = $this->printJobs->queueKot($sale, terminalId: $sale->terminal_id);
            }

            if (!$jobs) {
                $state['kot_status'] = 'not_required';
                $state['reminder_status'] = 'not_applicable';
                return [[], $this->emptyReminder()];
            }

            $batchId = (int) collect($jobs)->pluck('payload.kot_batch_id')->filter()->first();
            $state['kot_status'] = 'queued';
            $state['kot_batch_id'] = $batchId ?: null;
            $state['kot_job_ids'] = collect($jobs)->pluck('id')->map(fn ($id) => (int) $id)->all();

            try {
                $reminder = $this->printJobs->planRemindersForKotJobs($sale, $jobs);
                $state['ask_printer_ids'] = collect($reminder['ask_printers'] ?? [])->pluck('id')->all();
                $state['reminder_status'] = !empty($reminder['ask_printers'])
                    ? 'awaiting_confirmation'
                    : (!empty($reminder['auto_jobs']) ? 'queued' : 'not_applicable');
            } catch (\Throwable $exception) {
                $reminder = $this->emptyReminder();
                $reminder['warning'] = 'KOT was queued, but Reminder is pending retry.';
                $state['reminder_status'] = 'pending_retry';
                $state['errors']['reminder'] = $exception->getMessage();
                Log::warning('Reminder orchestration remains pending after paid sale.', [
                    'sales_order_id' => $sale->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            return [$jobs, $reminder];
        } catch (\Throwable $exception) {
            $state['kot_status'] = 'pending_retry';
            $state['reminder_status'] = 'pending';
            $state['errors']['kot'] = $exception->getMessage();
            Log::warning('KOT orchestration remains pending after paid sale.', [
                'sales_order_id' => $sale->id,
                'error' => $exception->getMessage(),
            ]);
            return [[], $this->emptyReminder()];
        }
    }

    private function existingKotJobs(array $state): array
    {
        $ids = collect($state['kot_job_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
        return $ids->isEmpty()
            ? []
            : PrintJob::with('printer')->whereIn('id', $ids)->where('document_type', 'kot')->get()->all();
    }

    private function response(SalesOrder $sale, array $state, array $kotJobs, ?PrintJob $receiptJob, array $reminder): array
    {
        return [
            'configured' => true,
            'stable' => true,
            'sale_paid' => true,
            'state' => $state,
            'retry_available' => in_array($state['kot_status'] ?? null, ['pending', 'pending_retry'], true)
                || in_array($state['receipt_status'] ?? null, ['pending', 'pending_retry'], true)
                || ($state['reminder_status'] ?? null) === 'pending_retry',
            'kot_jobs' => collect($kotJobs)->map(fn (PrintJob $job) => [
                'job_id' => $job->id,
                'job_no' => $job->job_no,
                'printer_type' => $job->printer?->printer_type ?? 'browser',
                'preview_url' => url('/printing/documents/' . $job->id . '/kot'),
                'fallback' => empty($job->printer_id),
            ])->values()->all(),
            'receipt' => $receiptJob ? [
                'job_id' => $receiptJob->id,
                'job_no' => $receiptJob->job_no,
                'printer_type' => $receiptJob->printer?->printer_type ?? 'browser',
                'preview_url' => url('/printing/documents/' . $receiptJob->id . '/receipt'),
                'fallback' => empty($receiptJob->printer_id),
            ] : null,
            'reminder' => [
                'revision' => $reminder['revision'] ?? null,
                'auto_jobs' => collect($reminder['auto_jobs'] ?? [])->map(fn (PrintJob $job) => [
                    'job_id' => $job->id,
                    'job_no' => $job->job_no,
                    'printer_id' => $job->printer_id,
                ])->values()->all(),
                'ask_printers' => $reminder['ask_printers'] ?? [],
                'confirmation_token' => $reminder['confirmation_token'] ?? null,
                'warning' => $reminder['warning'] ?? null,
            ],
        ];
    }

    private function emptyReminder(): array
    {
        return ['revision' => null, 'auto_jobs' => [], 'ask_printers' => [], 'confirmation_token' => null];
    }
}
