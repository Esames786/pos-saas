<?php

namespace App\Exceptions;

use App\Models\Tenant\Branch;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * BRANCH-OPERATING-MODE-1: thrown when a sale-side mutation is attempted on the
 * wrong instance for a Local POS branch — either the cloud instance mutating an
 * active Local POS branch, or a Branch Server mutating a branch it is not bound
 * to. Self-renders a friendly 409 (JSON or redirect-back) — never a 500.
 *
 * EDGE 0.9 (owner, 21 Nov): the Cloud also fences a paired branch by itself when its Branch Server stops heartbeating
 * (lease holder still CLOUD, lease expired — BranchOperatingModeService::leaseBlocksCloud). That refusal is now PRESENTED
 * as its own code, BRANCH_SERVER_UNREACHABLE, with an actionable message for the cashier. Presentation only: the throw
 * sites, the fencing decision and the HTTP status (409) are unchanged; any doubt keeps the generic CODE_ACTIVE wording.
 */
class BranchLocalEdgeException extends RuntimeException
{
    public const CODE_ACTIVE            = 'BRANCH_LOCAL_EDGE_ACTIVE';
    public const CODE_NOT_BOUND         = 'BRANCH_SERVER_NOT_BOUND';
    public const CODE_TENANT_NOT_BOUND  = 'BRANCH_SERVER_TENANT_NOT_BOUND';
    public const CODE_BRANCH_NOT_BOUND  = 'BRANCH_SERVER_BRANCH_NOT_BOUND';
    public const CODE_INVALID_TRANSITION = 'BRANCH_LOCAL_EDGE_INVALID_TRANSITION';
    public const CODE_FEATURE_DISABLED  = 'BRANCH_LOCAL_EDGE_FEATURE_DISABLED';
    // EDGE-SPLITBRAIN-STOCK-1: a Branch Server tried to post OFFICIAL stock (FEFO/costing/valuation).
    // Stricter than a sale: official stock is Cloud authority even for the server's own bound branch —
    // the server tracks provisional quantity only, and Cloud-side sync ingestion posts the official row.
    public const CODE_BRANCH_SERVER_OFFICIAL_STOCK = 'BRANCH_SERVER_OFFICIAL_STOCK';
    // EDGE 0.9: the Cloud paused this branch because its Branch Server stopped heartbeating (lease holder cloud, expired).
    public const CODE_BRANCH_SERVER_UNREACHABLE = 'BRANCH_SERVER_UNREACHABLE';

    public const UNREACHABLE_MESSAGE = 'Branch Server not reachable — Online POS paused for safety. Switch on the Branch Server PC (and its internet), or use BINGOO Offline on the shop network.';

    public readonly string $reasonCode;

    /** EDGE 0.9 — when the branch's Branch Server last heartbeated (only for CODE_BRANCH_SERVER_UNREACHABLE). */
    public readonly ?string $lastHeartbeatAt;

    public function __construct(
        public readonly ?Branch $branch = null,
        string $reasonCode = self::CODE_ACTIVE,
        ?string $overrideMessage = null,
    ) {
        $lastHeartbeat = null;
        if ($reasonCode === self::CODE_ACTIVE && $overrideMessage === null && $branch !== null) {
            $lastHeartbeat = self::unreachableSince($branch);
            if ($lastHeartbeat !== null) {
                $reasonCode = self::CODE_BRANCH_SERVER_UNREACHABLE;
            }
        }
        $this->reasonCode = $reasonCode;
        $this->lastHeartbeatAt = $lastHeartbeat;
        parent::__construct($overrideMessage ?? $this->defaultMessage());
    }

    /**
     * The lease-expired case, read-only: Cloud runtime, the branch NOT handed to its Branch Server by the manual mode
     * (inactive / pending), and a lease row that is not released, held by the CLOUD, has heartbeated before and is now
     * expired. Returns the last heartbeat time (ISO-8601) or null. Never throws (falls back to the generic wording).
     */
    private static function unreachableSince(Branch $branch): ?string
    {
        try {
            if (\App\Support\EdgeRuntime::isBranchServer()) {
                return null;
            }
            if (in_array($branch->local_edge_status, [Branch::STATUS_ACTIVE, Branch::STATUS_CLOSING, Branch::STATUS_SUSPENDED], true)) {
                return null;
            }
            $lease = \App\Models\Tenant\EdgeBranchAuthorityLease::on('tenant')->where('branch_id', (int) $branch->id)->first();
            if (! $lease || $lease->isReleased() || $lease->holder !== \App\Models\Tenant\EdgeBranchAuthorityLease::HOLDER_CLOUD
                || $lease->last_heartbeat_at === null || ! $lease->isExpired()) {
                return null;
            }

            return $lease->last_heartbeat_at->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function friendlyMessage(): string
    {
        return $this->getMessage();
    }

    private function defaultMessage(): string
    {
        return match ($this->reasonCode) {
            self::CODE_NOT_BOUND,
            self::CODE_TENANT_NOT_BOUND,
            self::CODE_BRANCH_NOT_BOUND   => 'This Branch Server is not configured for this branch.',
            self::CODE_INVALID_TRANSITION => 'This Local POS mode change is not allowed.',
            self::CODE_FEATURE_DISABLED   => 'Local POS setup is not enabled for this installation.',
            self::CODE_BRANCH_SERVER_OFFICIAL_STOCK => 'Official stock valuation is managed by the cloud. The Branch Server records provisional stock only; it will be reconciled on sync.',
            self::CODE_BRANCH_SERVER_UNREACHABLE => self::UNREACHABLE_MESSAGE,
            default                       => 'This branch is operating through Bingoo Local POS. Create and manage sales from the Branch Server.',
        };
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            $body = [
                'message' => $this->friendlyMessage(),
                'code'    => $this->reasonCode,
            ];
            if ($this->reasonCode === self::CODE_BRANCH_SERVER_UNREACHABLE) {
                $body['branch_server_last_seen_at'] = $this->lastHeartbeatAt;
            }

            return response()->json($body, 409);
        }

        return back()->withErrors(['branch_local_edge' => $this->friendlyMessage()]);
    }
}
