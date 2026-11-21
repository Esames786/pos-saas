<?php

namespace App\Services\Edge;

use Closure;

/**
 * OFFLINE-SYNC-ENGINE-1C — the narrow, process-local authority scope under which Cloud sync ingestion
 * may post OFFICIAL stock for a branch that has been handed to its Branch Server.
 *
 * The split-brain fence (BranchOperatingModeService::assertOfficialStockMutationAllowed) blocks official
 * stock mutation on a Cloud instance for any branch in Local Mode (active/closing/suspended) — precisely
 * the branches whose offline sales Cloud ingestion must post. Ingestion enters THIS scope (per branch)
 * around its authoritative posting; the fence grants official-stock authority ONLY while the scope is
 * active for that exact branch. It is:
 *   - NOT settable from a request (no HTTP/config/env input toggles it — only EdgeInboundSaleIngestionService
 *     opens it, in code, around one ingest transaction);
 *   - scoped to ONE branch id at a time and always popped in a finally, so a thrown ingest never leaks it;
 *   - irrelevant to the SALE-mutation fence — ordinary Cloud POS mutation for a handed branch stays fully
 *     blocked; ingestion never goes through POS controllers.
 */
final class EdgeIngestionAuthority
{
    /** @var array<int,int> branch_id => active depth (re-entrant per branch) */
    private static array $active = [];

    /** Run $fn with official-stock authority granted for exactly $branchId; always released. */
    public static function run(int $branchId, Closure $fn): mixed
    {
        self::$active[$branchId] = (self::$active[$branchId] ?? 0) + 1;
        try {
            return $fn();
        } finally {
            if (($self = (self::$active[$branchId] ?? 0)) <= 1) {
                unset(self::$active[$branchId]);
            } else {
                self::$active[$branchId] = $self - 1;
            }
        }
    }

    /** Is the ingestion authority currently active for this branch (on this process)? */
    public static function isActiveFor(int $branchId): bool
    {
        return ! empty(self::$active[$branchId]);
    }
}
