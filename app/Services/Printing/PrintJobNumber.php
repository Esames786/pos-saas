<?php

namespace App\Services\Printing;

/**
 * PRINT-JOB-NUMBER-1 — the one place a print job's job_no is generated.
 *
 * job_no is an OPERATIONAL identifier (support tickets, logs, the agent's
 * manifest), not a customer-facing document number, so — like sale_no, and
 * unlike a catering event_no — it carries a readable timestamp rather than a
 * locked daily sequence. The old form paired that timestamp (to the second)
 * with random_int(100, 999): only 900 distinct values inside any one second, so
 * a burst of KOTs queued on a single Hold collided by the birthday bound and
 * threw a duplicate-key error on print_jobs.job_no.
 *
 * Uniqueness is NOT this class's promise. It produces a strong CANDIDATE;
 * PrintJobFactory writes it under the print_jobs.job_no UNIQUE constraint, which
 * is the real authority, and retries on the rare collision. random_bytes(4)
 * widens the per-second namespace from 900 to ~4.3 billion so a retry almost
 * never happens — but it is the database constraint, not the width, that
 * guarantees no two jobs share a number.
 */
class PrintJobNumber
{
    public function generate(string $prefix = 'PJ'): string
    {
        return $prefix.'-'.now()->format('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));
    }
}
