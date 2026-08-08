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

    /**
     * Bounded backoff contract (exact): temporary failure N (1..5) schedules retry after
     * BACKOFF_SECONDS[N-1] — every configured slot is reachable; failure #6 is TERMINAL (the shared
     * markFailed runs once and the job waits for an explicit retry).
     */
    public const BACKOFF_SECONDS = [5, 15, 30, 60, 120];

    public const MAX_FAILURES = 6;

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
                // PER-PRINTER FIFO (head-of-line): a NEWER job for the SAME printer must not overtake
                // an OLDER queued job that is merely leased-live or waiting out its retry backoff — a
                // kitchen must never receive the Addition/CANCEL KOT before the original round.
                // A terminal_failed older job does NOT block (it never auto-runs again; an operator
                // must explicitly resolve/retry it, and later jobs may proceed meanwhile).
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('print_jobs as older')
                        ->join('edge_local_print_deliveries as od', 'od.print_job_id', '=', 'older.id')
                        ->whereColumn('older.printer_id', 'print_jobs.printer_id')
                        ->whereColumn('older.id', '<', 'print_jobs.id')
                        ->where('older.print_status', 'queued')
                        ->where(function ($w) {
                            $w->where('od.lease_expires_at', '>', now())
                                ->orWhere('od.next_attempt_at', '>', now());
                        });
                })
                ->orderBy('created_at')
                ->orderBy('id')
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
            // ONE lock order everywhere: print_job FIRST, then its delivery row (matches claimNext).
            $job = PrintJob::where('id', $printJobId)->lockForUpdate()->first();
            $delivery = EdgeLocalPrintDelivery::where('print_job_id', $printJobId)->lockForUpdate()->first();
            if (! $delivery || ! $job || ! $this->tokenIsCurrent($delivery, $leaseToken)) {
                return false; // stale worker — refuse silently, no state mutation
            }
            // state consistency: the lease authorizes completing a queued delivery intent — a job that
            // is already `printed` converges idempotently via markPrinted; anything else refuses.
            if (! in_array($job->print_status, ['queued', 'printed'], true)) {
                return false;
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
            // ONE lock order everywhere: print_job FIRST, then its delivery row (matches claimNext).
            $job = PrintJob::where('id', $printJobId)->lockForUpdate()->first();
            $delivery = EdgeLocalPrintDelivery::where('print_job_id', $printJobId)->lockForUpdate()->first();
            if (! $delivery || ! $job || ! $this->tokenIsCurrent($delivery, $leaseToken)) {
                return false;
            }
            // state consistency: a temporary failure may only be recorded against a QUEUED delivery
            // intent — `printed` (or any other) state must never gain a failure counter or a
            // printed+terminal_failed contradiction.
            if ($job->print_status !== 'queued') {
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

    /**
     * §10 — explicit local retry of a TERMINALLY-failed job: delegates to the ONE shared
     * PrintJobService::requeueFailed (the same operation Cloud PrintJobController::retry uses — no
     * duplicated field contract), then resets the Edge-only delivery metadata. Refuses anything that
     * is not an Edge terminal_failed delivery — the Edge path can never retry a random queued/printed
     * or Cloud-owned job.
     */
    public function retryTerminalFailed(int $printJobId): void
    {
        DB::connection('tenant')->transaction(function () use ($printJobId) {
            // ONE lock order everywhere: print_job FIRST, then its delivery row.
            $job = PrintJob::where('id', $printJobId)->lockForUpdate()->first();
            $delivery = EdgeLocalPrintDelivery::where('print_job_id', $printJobId)->lockForUpdate()->first();
            if (! $job || ! $delivery || $delivery->delivery_state !== EdgeLocalPrintDelivery::STATE_TERMINAL_FAILED) {
                throw new RuntimeException('Only a terminally-failed local delivery can be retried.');
            }
            $this->printJobs->requeueFailed($job); // shared eligibility + field contract (failed|cancelled only)
            $delivery->update([
                'delivery_state' => EdgeLocalPrintDelivery::STATE_WAITING,
                'failure_count' => 0, 'worker_uuid' => null, 'lease_token' => null,
                'claimed_at' => null, 'lease_expires_at' => null, 'next_attempt_at' => null,
            ]);
        });
    }

    /**
     * The one ownership rule: only the CURRENT ACTIVE lease token may complete — state still leased,
     * token equal, AND the lease NOT expired. Expiry itself revokes authority: an expired lease is
     * stale even if no other worker has reclaimed the job yet (the transport may still be in flight
     * on a stalled worker — its outcome must never land after its authority window closed).
     */
    private function tokenIsCurrent(EdgeLocalPrintDelivery $delivery, string $leaseToken): bool
    {
        return $delivery->delivery_state === EdgeLocalPrintDelivery::STATE_LEASED
            && is_string($delivery->lease_token)
            && $delivery->lease_token !== ''
            && hash_equals($delivery->lease_token, $leaseToken)
            && $delivery->lease_expires_at !== null
            && $delivery->lease_expires_at->isFuture();
    }
}
