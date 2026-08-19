<?php

namespace App\Services\Printing;

use App\Models\Tenant\PrintJob;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * PRINT-JOB-NUMBER-1 — create a PrintJob under a guaranteed-unique job_no.
 *
 * Every print job, from every path (POS receipt/KOT, reminders, catering
 * documents and production tickets, a Report Center send-to-network, the agent
 * test print), is numbered here and nowhere else. The print_jobs.job_no UNIQUE
 * index is the final authority: a candidate that loses a race is rejected by the
 * database and this regenerates and retries, so two requests in the same second
 * — or two separate FPM workers at once — cannot both keep a number. That is
 * why the fix lives around the write and not merely in a wider random suffix:
 * process-local cleverness cannot see another process's row, the constraint can.
 *
 * Only a job_no collision is retried. A logical_key collision is the domain's
 * idempotency signal (the same document is already queued) and is re-thrown
 * unchanged for the caller to resolve; no other exception is swallowed.
 */
class PrintJobFactory
{
    /**
     * Eight strong candidates. With ~4.3 billion values per second a single
     * collision is already vanishingly unlikely; eight in a row would mean the
     * generator itself is broken, and then failing loudly is the right outcome.
     */
    private const MAX_ATTEMPTS = 8;

    public function __construct(private readonly PrintJobNumber $numbers) {}

    /**
     * @param  array<string, mixed>  $attributes  Everything but job_no — this is its sole author.
     */
    public function create(array $attributes, string $prefix = 'PJ'): PrintJob
    {
        unset($attributes['job_no']);

        for ($attempt = 1; ; $attempt++) {
            $attributes['job_no'] = $this->numbers->generate($prefix);

            try {
                return PrintJob::create($attributes);
            } catch (QueryException $e) {
                if (! $this->isJobNoCollision($e)) {
                    // logical_key idempotency, or a genuine error — not ours to retry.
                    throw $e;
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException(
                        'Could not allocate a unique print_jobs.job_no after '.self::MAX_ATTEMPTS.' attempts.',
                        0,
                        $e
                    );
                }
                // otherwise: regenerate a fresh candidate and try again.
            }
        }
    }

    /**
     * A uniqueness violation on the job_no index specifically — MySQL's
     * 'print_jobs_job_no_unique' or SQLite's 'print_jobs.job_no' — never the
     * logical_key index, whose collision means something different.
     */
    private function isJobNoCollision(QueryException $e): bool
    {
        $message = (string) $e->getMessage();

        $isUniqueViolation = ((int) ($e->errorInfo[1] ?? 0) === 1062)   // MySQL duplicate key
            || str_contains($message, 'UNIQUE constraint failed');       // SQLite

        return $isUniqueViolation
            && str_contains($message, 'job_no')
            && ! str_contains($message, 'logical_key');
    }
}
