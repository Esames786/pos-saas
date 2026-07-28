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

    /**
     * HARDEN-1: the Local POS setup journey (pairing/bootstrap/sync) is
     * incomplete, so its UI + request actions are hidden behind a flag that is
     * OFF by default. Existing cloud POS is never affected by this flag.
     */
    public function isEdgeFeatureEnabled(): bool
    {
        return (bool) config('app.edge_feature_enabled', false);
    }

    public function assertEdgeFeatureEnabled(): void
    {
        if (! $this->isEdgeFeatureEnabled()) {
            throw new BranchLocalEdgeException(null, BranchLocalEdgeException::CODE_FEATURE_DISABLED);
        }
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

    /**
     * A Branch Server may only ever touch its hard-bound tenant + branch — both
     * from config/env, never from a request. HARDEN-1: enforce EDGE_TENANT_CODE
     * as well as EDGE_BRANCH_ID.
     */
    public function assertBranchServerBoundToBranch(Branch $branch): void
    {
        if (! $this->isBranchServerInstance()) {
            return;
        }

        $boundTenant = (string) config('app.edge_tenant_code');
        $currentTenant = (string) (app()->bound('tenant') ? app('tenant')->tenant_code : '');

        if ($boundTenant === '' || $currentTenant === '' || $currentTenant !== $boundTenant) {
            throw new BranchLocalEdgeException($branch, BranchLocalEdgeException::CODE_TENANT_NOT_BOUND);
        }

        $boundBranchId = (int) config('app.edge_branch_id');

        if ($boundBranchId <= 0 || (int) $branch->id !== $boundBranchId) {
            throw new BranchLocalEdgeException($branch, BranchLocalEdgeException::CODE_BRANCH_NOT_BOUND);
        }
    }

    /**
     * HARDEN-1: code-enforced lifecycle transition matrix (do not rely on UI).
     *
     *   inactive  → pending
     *   pending   → active | suspended | inactive
     *   active    → closing | suspended
     *   closing   → inactive | suspended
     *   suspended → inactive
     *
     * Everything else (inactive→active, pending→closing, closing→active,
     * suspended→active, unknown status, same-state) is rejected.
     */
    public const TRANSITIONS = [
        Branch::STATUS_INACTIVE  => [Branch::STATUS_PENDING],
        Branch::STATUS_PENDING   => [Branch::STATUS_ACTIVE, Branch::STATUS_SUSPENDED, Branch::STATUS_INACTIVE],
        Branch::STATUS_ACTIVE    => [Branch::STATUS_CLOSING, Branch::STATUS_SUSPENDED],
        Branch::STATUS_CLOSING   => [Branch::STATUS_INACTIVE, Branch::STATUS_SUSPENDED],
        Branch::STATUS_SUSPENDED => [Branch::STATUS_INACTIVE],
    ];

    /** Statuses a branch may legally move to from its current one. */
    public function allowedTransitions(Branch $branch): array
    {
        return self::TRANSITIONS[$branch->local_edge_status] ?? [];
    }

    public function canTransition(Branch $branch, string $toStatus): bool
    {
        return in_array($toStatus, $this->allowedTransitions($branch), true);
    }

    /**
     * Transition a branch through the lifecycle. Rejects invalid transitions
     * with a friendly domain exception — never a raw field jump.
     */
    public function transition(Branch $branch, string $toStatus, ?int $actorId = null, ?string $reason = null): void
    {
        $fromStatus = $branch->local_edge_status;

        if (! $this->canTransition($branch, $toStatus)) {
            throw new BranchLocalEdgeException(
                $branch,
                BranchLocalEdgeException::CODE_INVALID_TRANSITION,
                "Cannot move this branch from '{$fromStatus}' to '{$toStatus}'."
            );
        }

        $branch->local_edge_status        = $toStatus;
        $branch->local_edge_status_reason = $reason;

        $branch->sales_operating_mode = $toStatus === Branch::STATUS_INACTIVE
            ? Branch::MODE_CLOUD
            : Branch::MODE_LOCAL_EDGE;

        if ($toStatus === Branch::STATUS_ACTIVE) {
            $branch->local_edge_activated_at = now();
        }
        if ($toStatus === Branch::STATUS_SUSPENDED) {
            $branch->local_edge_suspended_at = now();
        }

        $branch->save();

        $eventMap = [
            Branch::STATUS_PENDING   => 'requested',
            Branch::STATUS_ACTIVE    => 'activated',
            Branch::STATUS_CLOSING   => 'closing',
            Branch::STATUS_SUSPENDED => 'suspended',
            Branch::STATUS_INACTIVE  => 'returned_to_cloud',
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
