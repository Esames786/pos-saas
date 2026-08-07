<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Support\EdgeRuntime;
use RuntimeException;
use Throwable;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section Q) — the canonical bound-branch accessor for a Branch Server.
 *
 * After a successful bootstrap import the appliance is immutably bound to exactly one
 * tenant / branch / device / activation epoch (edge_local_meta). Every present and future Edge
 * service must derive the operating branch from HERE, never from an arbitrary request branch_id. A
 * request/service that names a different branch or tenant is rejected — a Branch Server can only see
 * its own branch.
 *
 * Resilient by design: on an uninitialised appliance (edge_local_meta absent / local DB not
 * provisioned) current() returns null instead of throwing, so /edge/local/health and readiness still
 * answer.
 */
class EdgeBranchContext
{
    /** The immutable binding row, or null if not yet bootstrapped/initialised. */
    public function current(): ?EdgeLocalMeta
    {
        if (! EdgeRuntime::isBranchServer()) {
            return null;
        }

        try {
            $meta = EdgeLocalMeta::current();
        } catch (Throwable $e) {
            // Local DB not provisioned yet (no edge_local_meta table / no connection).
            return null;
        }

        if ($meta === null || $meta->runtime_state !== EdgeLocalMeta::STATE_BOOTSTRAPPED) {
            return null;
        }

        return $meta;
    }

    public function isBound(): bool
    {
        return $this->current() !== null;
    }

    public function boundBranchId(): ?int
    {
        $m = $this->current();

        return $m ? (int) $m->branch_id : null;
    }

    public function boundTenantId(): ?int
    {
        $m = $this->current();

        return $m ? (int) $m->tenant_id : null;
    }

    public function boundTenantCode(): ?string
    {
        return $this->current()?->tenant_code;
    }

    public function deviceUuid(): ?string
    {
        return $this->current()?->device_uuid;
    }

    public function activationEpoch(): ?int
    {
        $m = $this->current();

        return $m && $m->activation_epoch !== null ? (int) $m->activation_epoch : null;
    }

    public function configRevision(): ?string
    {
        return $this->current()?->source_revision;
    }

    /** Reject unless $branchId is exactly the bound branch. */
    public function assertBranchMatches(?int $branchId): void
    {
        $bound = $this->boundBranchId();
        if ($bound === null) {
            throw new RuntimeException('This Branch Server is not bound to a branch yet.');
        }
        if ($branchId === null || (int) $branchId !== $bound) {
            throw new RuntimeException("This Branch Server can only operate on branch {$bound}, not branch " . ($branchId ?? 'null') . '.');
        }
    }

    /** Reject unless $tenantId is exactly the bound tenant. */
    public function assertTenantMatches(?int $tenantId): void
    {
        $bound = $this->boundTenantId();
        if ($bound === null) {
            throw new RuntimeException('This Branch Server is not bound to a tenant yet.');
        }
        if ($tenantId === null || (int) $tenantId !== $bound) {
            throw new RuntimeException("This Branch Server is bound to tenant {$bound}, not tenant " . ($tenantId ?? 'null') . '.');
        }
    }
}
