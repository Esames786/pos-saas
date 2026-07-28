<?php

namespace App\Services\Edge;

use App\Exceptions\BranchLocalEdgeException;
use App\Models\Tenant\Branch;
use Illuminate\Support\Facades\Log;

/**
 * BRANCH-OPERATING-MODE-1 — single source of truth for Branch Edge decisions.
 *
 * Split-brain guard: a Local POS branch's sales must run on exactly ONE
 * authority. On the cloud instance, sale-side mutations are blocked once the
 * branch is local_edge_active (or closing). On a Branch Server instance, only
 * the hard-bound branch may be mutated. 'pending' setup never blocks cloud sales.
 *
 * Role + edge binding come ONLY from config (env), never from request data.
 */
class BranchOperatingModeService
{
    public function isCloudInstance(): bool
    {
        return config('app.role', 'cloud') !== 'branch_server';
    }

    public function isBranchServerInstance(): bool
    {
        return config('app.role', 'cloud') === 'branch_server';
    }

    /** Branch has handed sale authority to its Branch Server (live authority). */
    public function isLocalEdgeActive(Branch $branch): bool
    {
        return $branch->isLocalEdgeActive();
    }

    /**
     * Cloud sale mutation is blocked while the branch has committed to a Branch
     * Server: active/closing (server is live) AND suspended (emergency hold — the
     * server may hold un-synced sales, so the cloud must not create conflicting
     * ones; the authorized path back is an explicit Return to Cloud). Only
     * inactive (cloud) and pending (setup) keep cloud sales working.
     */
    public function cloudSaleMutationBlocked(Branch $branch): bool
    {
        return $branch->sales_operating_mode === 'local_edge'
            && in_array($branch->local_edge_status, ['active', 'closing', 'suspended'], true);
    }

    /** Cloud instance should refuse to mutate this branch's sales. */
    public function shouldBlockCloudSaleMutation(Branch $branch): bool
    {
        return $this->isCloudInstance() && $this->cloudSaleMutationBlocked($branch);
    }

    /**
     * The one call sale-mutating controllers make. Cloud: block committed Local
     * POS branches (active/closing/suspended). Branch Server: allow only the
     * configured branch. Everything else (cloud/inactive/pending) passes → zero
     * behavior change for normal branches.
     */
    public function assertSaleMutationAllowed(Branch $branch): void
    {
        if ($this->isBranchServerInstance()) {
            $this->assertBranchServerBoundToBranch($branch);
            return;
        }

        if ($this->cloudSaleMutationBlocked($branch)) {
            throw new BranchLocalEdgeException($branch, BranchLocalEdgeException::CODE_ACTIVE);
        }
    }

    /** A Branch Server may only ever touch its hard-bound branch. */
    public function assertBranchServerBoundToBranch(Branch $branch): void
    {
        if (! $this->isBranchServerInstance()) {
            return;
        }

        $boundBranchId = (int) config('app.edge_branch_id');

        if ($boundBranchId <= 0 || (int) $branch->id !== $boundBranchId) {
            throw new BranchLocalEdgeException($branch, BranchLocalEdgeException::CODE_NOT_BOUND);
        }
    }

    /**
     * Transition a branch through the lifecycle with a structured audit line.
     * Activation is intentionally NOT reachable from the normal admin UI in this
     * sprint — it requires future Branch Server pairing/bootstrap readiness — so
     * the UI may only request setup (→ pending) or return to cloud.
     *
     * Allowed transitions:
     *   cloud/inactive        → pending      (request setup)
     *   pending               → active       (pairing-ready; service/QA only)
     *   active                → closing      (controlled exit)
     *   closing               → cloud/inactive (reconciled)
     *   active|pending        → suspended    (emergency)
     *   suspended             → cloud/inactive
     */
    public function transition(Branch $branch, string $toStatus, ?int $actorId = null, ?string $reason = null): void
    {
        $fromStatus = $branch->local_edge_status;

        $branch->local_edge_status       = $toStatus;
        $branch->local_edge_status_reason = $reason;

        if ($toStatus === 'inactive') {
            $branch->sales_operating_mode = 'cloud';
        } else {
            $branch->sales_operating_mode = 'local_edge';
        }

        if ($toStatus === 'active') {
            $branch->local_edge_activated_at = now();
        }
        if ($toStatus === 'suspended') {
            $branch->local_edge_suspended_at = now();
        }

        $branch->save();

        $eventMap = [
            'pending'   => 'requested',
            'active'    => 'activated',
            'closing'   => 'closing',
            'suspended' => 'suspended',
            'inactive'  => 'returned_to_cloud',
        ];

        Log::info('[branch-edge-audit] branch.local_edge.' . ($eventMap[$toStatus] ?? $toStatus), [
            'branch_id'     => $branch->id,
            'from_status'   => $fromStatus,
            'to_status'     => $toStatus,
            'actor_id'      => $actorId ?? auth('tenant')->id(),
            'reason'        => $reason,
            'instance_role' => config('app.role', 'cloud'),
        ]);
    }
}
