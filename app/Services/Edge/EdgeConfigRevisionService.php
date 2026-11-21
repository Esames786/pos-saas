<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeBranchConfigRevision;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * EDGE-CONFIG-REFRESH-1 — allocates and reads the Cloud-authoritative MONOTONIC config revision for a
 * branch. The bootstrap source watermark (a content hash) detects change but carries no order; this
 * service turns each new watermark into revision N+1 so an appliance can:
 *
 *   - refuse an OLDER revision (a stale package can never roll config back);
 *   - treat the SAME revision as an idempotent replay;
 *   - apply a NEWER revision (gaps are safe — every revision carries the complete supported
 *     configuration set, so revision N+k does not depend on N+1..N+k-1).
 *
 * Guarantees (mirrors EdgeActivationEpochService):
 *   - monotonic per (tenant, branch): 1, 2, 3, …;
 *   - IDEMPOTENT per watermark — re-allocating while the branch config is unchanged returns the same
 *     revision (a snapshot rebuild/retry never mints a new revision);
 *   - concurrency-safe: allocation locks the branch's latest row and retries on the unique index.
 */
class EdgeConfigRevisionService
{
    private const MASTER = 'master';
    private const MAX_RETRIES = 8;

    /** The current (highest) config revision for a branch, or 0 if none allocated yet. */
    public function currentRevision(int $tenantId, int $branchId): int
    {
        return (int) EdgeBranchConfigRevision::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->max('revision');
    }

    /**
     * Allocate (or reuse) the monotonic revision for the given config watermark. Same watermark as
     * the latest allocation -> same revision; a new watermark -> revision + 1.
     */
    public function allocateForWatermark(int $tenantId, int $branchId, string $sourceRevision): int
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                return DB::connection(self::MASTER)->transaction(function () use ($tenantId, $branchId, $sourceRevision) {
                    // Lock the branch's latest revision row (if any) to serialise concurrent
                    // allocations once a branch has at least one revision.
                    $latest = EdgeBranchConfigRevision::query()
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branchId)
                        ->orderByDesc('revision')
                        ->lockForUpdate()
                        ->first();

                    // Unchanged config -> reuse (a snapshot rebuild must not mint a new revision).
                    if ($latest !== null && hash_equals((string) $latest->source_revision, $sourceRevision)) {
                        return (int) $latest->revision;
                    }

                    $next = ($latest !== null ? (int) $latest->revision : 0) + 1;

                    EdgeBranchConfigRevision::query()->create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'revision' => $next,
                        'source_revision' => $sourceRevision,
                    ]);

                    return $next;
                });
            } catch (QueryException $e) {
                // A racing allocation took our revision number first — re-read and retry.
                if ($this->isUniqueViolation($e) && $attempt < self::MAX_RETRIES) {
                    continue;
                }
                throw $e;
            }
        }

        throw new \RuntimeException('Could not allocate a config revision after retries.');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 // MySQL duplicate entry
            || str_contains($e->getMessage(), 'Integrity constraint violation');
    }
}
