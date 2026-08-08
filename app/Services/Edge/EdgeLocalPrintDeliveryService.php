<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalPrintDelivery;
use App\Models\Tenant\PrintJob;
use App\Services\Printing\PrintJobService;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EDGE-LOCAL-PRINT-1 (§6–§10) — the Branch Server's lease-safe local print delivery authority.
 *
 * The frozen POS layer creates the BUSINESS events + logical print_jobs; this service only executes
 * PHYSICAL delivery of the exact stored raw_payload. Contract:
 *
 *  - CLAIM (§6): only on a bound branch_server; only print_status='queued' jobs with a trusted,
 *    active, NETWORK printer (branch-scoped by the same rule Cloud routing uses: printer/job branch
 *    NULL or = bound branch); never a NULL-printer job (§20 — historical browser intents are never
 *    silently rerouted); backoff must have elapsed and no unexpired lease may exist. A claim mints a
 *    cryptographically random LEASE TOKEN — the ONLY completion authority.
 *  - STALE COMPLETION IS IMPOSSIBLE (§7): completeSuccess/completeFailure verify the CURRENT token
 *    under row locks; a stale worker's report mutates NOTHING (a printed job can additionally never
 *    be demoted — shared markFailed guard).
 *  - SUCCESS (§8): the REAL shared PrintJobService::markPrinted runs while the token is current —
 *    socket completion is transport success, NOT proof paper emerged: physical printing stays
 *    AT-LEAST-ONCE; never claim physical exactly-once.
 *  - FAILURE (§9): temporary failures never touch the shared print_status — Edge-only failure_count +
 *    bounded backoff (BACKOFF_SECONDS), print_jobs stays queued until the terminal threshold, then
 *    the shared markFailed runs ONCE (attempts keeps its Cloud semantic: markFailed transitions).
 *  - RETRY (§10): a terminally-failed job is re-queued with the SAME field contract as the Cloud
 *    admin retry (status queued, error/failed_at/claim cleared) + delivery metadata reset to waiting.
 *
 * No master DB, no print_agents rows, no Cloud pairing secrets — the printer destination comes ONLY
 * from the bootstrapped trusted printer config.
 */
class EdgeLocalPrintDeliveryService
{
    public const LEASE_SECONDS = 120;

    /** Bounded, deliberately conservative backoff after the Nth temporary failure. */
    public const BACKOFF_SECONDS = [5, 15, 30, 60, 120];

    /** The Nth failure is terminal: shared markFailed runs once and the job waits for an explicit retry. */
    public const MAX_FAILURES = 5;

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly PrintJobService $printJobs,
    ) {
    }

    /**
     * Claim the next deliverable job for this worker.
     * @return array{job_id: int, lease_token: string, raw_payload: string, ip: string, port: int}|null
     */
    public function claimNext(string $workerUuid): ?array
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Local print delivery only runs on a Branch Server.');
        }
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;

        return DB::connection('tenant')->transaction(function () use ($workerUuid, $branchId) {
            $job = PrintJob::with('printer')
                ->where('print_status', 'queued')
                ->whereNotNull('printer_id') // §20: NULL-printer intents are diagnostics, never claims
                ->whereHas('printer', function ($q) use ($branchId) {
                    $q->where('is_active', true)
                        ->where('printer_type', 'network')
                        ->whereNotNull('ip_address')
                        ->where(fn ($b) => $b->whereNull('branch_id')->orWhere('branch_id', $branchId));
                })
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('edge_local_print_deliveries as d')
                        ->whereColumn('d.print_job_id', 'print_jobs.id')
                        ->where(function ($w) {
                            $w->where('d.lease_expires_at', '>', now())
                                ->orWhere('d.next_attempt_at', '>', now())
                                ->orWhereIn('d.delivery_state', [EdgeLocalPrintDelivery::STATE_DELIVERED, EdgeLocalPrintDelivery::STATE_TERMINAL_FAILED]);
                        });
                })
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $job) {
                return null;
            }

            // per-job transport row, locked — re-verified under the lock (belt to the query above).
            $delivery = EdgeLocalPrintDelivery::where('print_job_id', $job->id)->lockForUpdate()->first()
                ?? EdgeLocalPrintDelivery::create(['print_job_id' => $job->id, 'delivery_state' => EdgeLocalPrintDelivery::STATE_WAITING]);
            $leaseLive = $delivery->lease_expires_at !== null && $delivery->lease_expires_at->isFuture();
            $backoffPending = $delivery->next_attempt_at !== null && $delivery->next_attempt_at->isFuture();
            if ($leaseLive || $backoffPending
                || in_array($delivery->delivery_state, [EdgeLocalPrintDelivery::STATE_DELIVERED, EdgeLocalPrintDelivery::STATE_TERMINAL_FAILED], true)) {
                return null;
            }

            $token = bin2hex(random_bytes(32)); // 64 chars — the completion authority
            $delivery->update([
                'delivery_state' => EdgeLocalPrintDelivery::STATE_LEASED,
                'worker_uuid' => $workerUuid,
                'lease_token' => $token,
                'claimed_at' => now(),
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
            ]);

            return [
                'job_id' => (int) $job->id,
                'lease_token' => $token,
                'raw_payload' => (string) $job->raw_payload, // EXACT stored bytes — never rebuilt (§2)
                'ip' => (string) $job->printer->ip_address,
                'port' => (int) ($job->printer->port ?: 9100),
            ];
        });
    }

    /** TRUE = this (current) lease completed the job. FALSE = stale token: NOTHING was mutated. */
    public function completeSuccess(int $printJobId, string $leaseToken): bool
    {
        return DB::connection('tenant')->transaction(function () use ($printJobId, $leaseToken) {
            $delivery = EdgeLocalPrintDelivery::where('print_job_id', $printJobId)->lockForUpdate()->first();
            $job = PrintJob::where('id', $printJobId)->lockForUpdate()->first();
            if (! $delivery || ! $job || ! $this->tokenIsCurrent($delivery, $leaseToken)) {
                return false; // stale worker — refuse silently, no state mutation
            }

            $this->printJobs->markPrinted($job); // the REAL shared completion (idempotent)

            $delivery->update([
                'delivery_state' => EdgeLocalPrintDelivery::STATE_DELIVERED,
                'lease_token' => null,
                'lease_expires_at' => null,
                'next_attempt_at' => null,
                'last_error' => null,
                'last_attempt_at' => now(),
            ]);

            return true;
        });
    }

    /** TRUE = failure recorded by the current lease. FALSE = stale token: NOTHING was mutated. */
    public function completeFailure(int $printJobId, string $leaseToken, string $error): bool
    {
        return DB::connection('tenant')->transaction(function () use ($printJobId, $leaseToken, $error) {
            $delivery = EdgeLocalPrintDelivery::where('print_job_id', $printJobId)->lockForUpdate()->first();
            $job = PrintJob::where('id', $printJobId)->lockForUpdate()->first();
            if (! $delivery || ! $job || ! $this->tokenIsCurrent($delivery, $leaseToken)) {
                return false;
            }

            $failures = (int) $delivery->failure_count + 1;
            $error = mb_substr($error, 0, 500);

            if ($failures >= self::MAX_FAILURES) {
                // terminal: the shared markFailed runs ONCE, while the token is still the current one.
                $this->printJobs->markFailed($job, $error);
                $delivery->update([
                    'delivery_state' => EdgeLocalPrintDelivery::STATE_TERMINAL_FAILED,
                    'failure_count' => $failures,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'next_attempt_at' => null,
                    'last_error' => $error,
                    'last_attempt_at' => now(),
                ]);

                return true;
            }

            // temporary: Edge-only bookkeeping; the shared print_status STAYS queued.
            $backoff = self::BACKOFF_SECONDS[min($failures, count(self::BACKOFF_SECONDS)) - 1];
            $delivery->update([
                'delivery_state' => EdgeLocalPrintDelivery::STATE_RETRY_WAIT,
                'failure_count' => $failures,
                'lease_token' => null,
                'lease_expires_at' => null,
                'next_attempt_at' => now()->addSeconds($backoff),
                'last_error' => $error,
                'last_attempt_at' => now(),
            ]);

            return true;
        });
    }

    /** §10 — explicit local retry of a terminally-failed job (same field contract as the Cloud admin retry). */
    public function retryTerminalFailed(int $printJobId): void
    {
        DB::connection('tenant')->transaction(function () use ($printJobId) {
            $delivery = EdgeLocalPrintDelivery::where('print_job_id', $printJobId)->lockForUpdate()->first();
            $job = PrintJob::where('id', $printJobId)->lockForUpdate()->first();
            if (! $job || $job->print_status !== 'failed') {
                throw new RuntimeException('Only a failed print job can be retried.');
            }
            $job->update([
                'print_status' => 'queued', 'error_message' => null, 'failed_at' => null,
                'claimed_by_agent_id' => null, 'claimed_at' => null,
            ]);
            $delivery?->update([
                'delivery_state' => EdgeLocalPrintDelivery::STATE_WAITING,
                'failure_count' => 0, 'worker_uuid' => null, 'lease_token' => null,
                'claimed_at' => null, 'lease_expires_at' => null, 'next_attempt_at' => null,
            ]);
        });
    }

    /** The one ownership rule: only the CURRENT active lease token may complete (state must still be leased). */
    private function tokenIsCurrent(EdgeLocalPrintDelivery $delivery, string $leaseToken): bool
    {
        return $delivery->delivery_state === EdgeLocalPrintDelivery::STATE_LEASED
            && is_string($delivery->lease_token)
            && $delivery->lease_token !== ''
            && hash_equals($delivery->lease_token, $leaseToken);
    }
}
