<?php

namespace App\Services\Edge;

use App\Exceptions\EdgeNotBoundException;
use App\Models\Edge\EdgeLocalMeta;
use App\Support\EdgeRuntime;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section Q + fix 4) — the canonical bound-branch accessor for a Branch Server.
 *
 * After a successful bootstrap import the appliance is immutably bound to exactly one
 * tenant / branch / device / activation epoch (edge_local_meta). Every present and future Edge
 * service must derive the operating branch from HERE, never from an arbitrary request branch_id.
 *
 * Two paths, deliberately separated:
 *   - tryCurrent()/isBound()   GRACEFUL probe for health/readiness. Returns null when the appliance is
 *     legitimately NOT INITIALISED (edge-local DB/table absent, or no bootstrapped row). A genuine DB
 *     error (connection failure, corruption) is NOT swallowed — it propagates.
 *   - requireCurrent()         STRICT path for security/operational code. Throws EdgeNotBoundException
 *     when not initialised, and lets real DB errors propagate — so "not bound" is never confused with
 *     "the local database failed". EnsureEdgeBranchBound and future Edge services use this path.
 */
class EdgeBranchContext
{
    /** MySQL: 1049 = unknown database, 1146 = base table/view not found, 1046 = no database selected. */
    private const NOT_INITIALISED_CODES = [1049, 1146, 1046];

    /** GRACEFUL: the binding row, or null if not initialised. Real DB errors propagate. */
    public function tryCurrent(): ?EdgeLocalMeta
    {
        if (! EdgeRuntime::isBranchServer()) {
            return null;
        }

        try {
            $meta = EdgeLocalMeta::current();
        } catch (QueryException $e) {
            if ($this->isNotInitialised($e)) {
                return null; // edge-local DB / edge_local_meta table does not exist yet
            }
            throw $e; // genuine DB/schema failure — do NOT mask as "not bound"
        }

        if ($meta === null || $meta->runtime_state !== EdgeLocalMeta::STATE_BOOTSTRAPPED) {
            return null;
        }

        // Defence-in-depth (fix 5): a corrupt multi-binding state must never be silently accepted.
        $this->assertSingleBinding();

        return $meta;
    }

    /** Backwards-compatible alias for the graceful probe. */
    public function current(): ?EdgeLocalMeta
    {
        return $this->tryCurrent();
    }

    public function isBound(): bool
    {
        return $this->tryCurrent() !== null;
    }

    /** STRICT: the binding, or throw. NOT INITIALISED -> EdgeNotBoundException; DB errors propagate. */
    public function requireCurrent(): EdgeLocalMeta
    {
        $meta = $this->tryCurrent(); // real DB errors propagate through here
        if ($meta === null) {
            throw new EdgeNotBoundException('This Branch Server is not bound to a branch yet (no bootstrap imported).');
        }

        return $meta;
    }

    public function boundBranchId(): ?int
    {
        $m = $this->tryCurrent();

        return $m ? (int) $m->branch_id : null;
    }

    public function boundTenantId(): ?int
    {
        $m = $this->tryCurrent();

        return $m ? (int) $m->tenant_id : null;
    }

    public function boundTenantCode(): ?string
    {
        return $this->tryCurrent()?->tenant_code;
    }

    public function deviceUuid(): ?string
    {
        return $this->tryCurrent()?->device_uuid;
    }

    public function activationEpoch(): ?int
    {
        $m = $this->tryCurrent();

        return $m && $m->activation_epoch !== null ? (int) $m->activation_epoch : null;
    }

    public function configRevision(): ?string
    {
        return $this->tryCurrent()?->source_revision;
    }

    /** STRICT: reject unless $branchId is exactly the bound branch. */
    public function assertBranchMatches(?int $branchId): void
    {
        $bound = $this->requireCurrent();
        if ($branchId === null || (int) $branchId !== (int) $bound->branch_id) {
            throw new RuntimeException("This Branch Server can only operate on branch {$bound->branch_id}, not branch " . ($branchId ?? 'null') . '.');
        }
    }

    /** STRICT: reject unless $tenantId is exactly the bound tenant. */
    public function assertTenantMatches(?int $tenantId): void
    {
        $bound = $this->requireCurrent();
        if ($tenantId === null || (int) $tenantId !== (int) $bound->tenant_id) {
            throw new RuntimeException("This Branch Server is bound to tenant {$bound->tenant_id}, not tenant " . ($tenantId ?? 'null') . '.');
        }
    }

    /** There must be exactly one binding row; a corrupt multi-row state is rejected, not ignored. */
    private function assertSingleBinding(): void
    {
        if (EdgeLocalMeta::query()->count() > 1) {
            throw new RuntimeException('Corrupt Edge binding: more than one edge_local_meta row exists.');
        }
    }

    private function isNotInitialised(QueryException $e): bool
    {
        if (in_array((int) ($e->errorInfo[1] ?? 0), self::NOT_INITIALISED_CODES, true)) {
            return true; // MySQL: unknown database / no such table
        }
        // Driver-agnostic fallback (SQLite/other) — a genuine connection failure won't match these.
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'no such table')
            || str_contains($msg, 'unknown database')
            || str_contains($msg, "doesn't exist");
    }
}
