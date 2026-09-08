<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Models\Tenant\EdgeBranchAuthorityLease;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * OFFLINE EDGE — P0 BRANCH AUTHORITY LEASE, Cloud side.
 *
 * The contract (operating-model lock §5–6):
 *  - While Online the CLOUD holds the branch's mutation lease. The branch's appliance renews it by heartbeat;
 *    every accepted heartbeat extends `expires_at` by the TTL (Cloud clock).
 *  - When the lease can no longer be renewed (heartbeats stop reaching the Cloud), the Cloud STOPS accepting the
 *    branch's transactional mutations at `expires_at` — `blocksCloud()` is what the shared fence asks.
 *  - The appliance may assume local authority only after the lease lapsed on ITS clock by TTL + skew margin and
 *    its readiness gates pass; it then reports `edge_state = local_active` and the holder becomes EDGE.
 *  - A resumed heartbeat never bounces authority: while the holder is EDGE, only an explicit, sync-clean HANDBACK
 *    returns the lease to the Cloud. A stale/replayed heartbeat (sequence not increasing) is refused.
 *  - An operator may RELEASE a dead appliance's lease (Cloud CLI, audited) — the only way past a fence when the
 *    appliance will never heartbeat again. Nothing here is "last write wins".
 */
class EdgeAuthorityLeaseService
{
    public function ttlSeconds(): int
    {
        return max(15, (int) config('edge.authority.ttl_seconds', 120));
    }

    /**
     * Accept (or refuse) one heartbeat from the branch's ACTIVE device and return the lease as the Cloud sees it.
     *
     * @return array{holder:string, edge_state:string, lease_ttl_seconds:int, expires_in_seconds:int, seq:int, server_time:string, fenced:bool}
     */
    public function heartbeat(EdgeDevice $device, int $seq, string $edgeState): array
    {
        if (! in_array($edgeState, [EdgeBranchAuthorityLease::EDGE_STANDBY, EdgeBranchAuthorityLease::EDGE_LOCAL_ACTIVE, EdgeBranchAuthorityLease::EDGE_HANDING_BACK], true)) {
            throw new RuntimeException('EDGE_STATE_INVALID');
        }

        return DB::connection('tenant')->transaction(function () use ($device, $seq, $edgeState) {
            $lease = $this->lockedLease((int) $device->branch_id, (string) $device->public_uuid);

            // A replayed or out-of-order beat can never extend or move authority.
            if ($seq <= (int) $lease->heartbeat_seq && $lease->last_heartbeat_at !== null) {
                throw new RuntimeException('STALE_HEARTBEAT');
            }
            // Another device cannot heartbeat this branch's lease (the active slot is the only device the Cloud pairs).
            if ($lease->device_public_uuid !== (string) $device->public_uuid) {
                $lease->device_public_uuid = (string) $device->public_uuid;
            }

            $now = now();
            $ttl = $this->ttlSeconds();
            $lease->heartbeat_seq = $seq;
            $lease->last_heartbeat_at = $now;
            $lease->lease_ttl_seconds = $ttl;
            $lease->expires_at = $now->copy()->addSeconds($ttl);
            $lease->edge_state = $edgeState;
            $lease->released_at = null;
            $lease->release_reason = null;

            if ($edgeState === EdgeBranchAuthorityLease::EDGE_LOCAL_ACTIVE) {
                // The appliance asserts it took over after a safe lapse: the Cloud is fenced until handback.
                $lease->holder = EdgeBranchAuthorityLease::HOLDER_EDGE;
                $lease->fenced_at = $lease->fenced_at ?? $now;
            } elseif ($lease->holder === EdgeBranchAuthorityLease::HOLDER_EDGE) {
                // Network flap: heartbeats resume but the appliance has not handed back — authority does NOT bounce.
                // (holder stays edge; the appliance learns it from the response and must run the handback protocol.)
            } else {
                // Healthy renewal (or the Cloud re-gaining a lapsed lease the appliance never took over).
                $lease->holder = EdgeBranchAuthorityLease::HOLDER_CLOUD;
                $lease->fenced_at = null;
            }
            $lease->save();

            return $this->view($lease);
        });
    }

    /**
     * The appliance hands authority back — only when it certifies its sync is clean (nothing pending, nothing
     * permanently failed) and it currently holds the lease. The Cloud then becomes the writer again.
     */
    public function handback(EdgeDevice $device, int $outboxPending, int $failedPermanent): array
    {
        if ($outboxPending !== 0 || $failedPermanent !== 0) {
            throw new RuntimeException('HANDBACK_NOT_CLEAN');
        }

        return DB::connection('tenant')->transaction(function () use ($device) {
            $lease = $this->lockedLease((int) $device->branch_id, (string) $device->public_uuid);
            if ($lease->holder !== EdgeBranchAuthorityLease::HOLDER_EDGE) {
                throw new RuntimeException('HANDBACK_NOT_HOLDER');
            }
            $now = now();
            $lease->holder = EdgeBranchAuthorityLease::HOLDER_CLOUD;
            $lease->edge_state = EdgeBranchAuthorityLease::EDGE_STANDBY;
            $lease->fenced_at = null;
            $lease->last_heartbeat_at = $now;
            $lease->expires_at = $now->copy()->addSeconds($this->ttlSeconds());
            $lease->save();

            return $this->view($lease);
        });
    }

    /** Operator release of a dead appliance's lease (Cloud CLI, audited). The Cloud becomes the writer again. */
    public function release(int $branchId, string $reason, ?string $by = null): ?array
    {
        return DB::connection('tenant')->transaction(function () use ($branchId, $reason, $by) {
            $lease = EdgeBranchAuthorityLease::on('tenant')->where('branch_id', $branchId)->lockForUpdate()->first();
            if (! $lease) {
                return null;
            }
            $lease->holder = EdgeBranchAuthorityLease::HOLDER_CLOUD;
            $lease->edge_state = EdgeBranchAuthorityLease::EDGE_STANDBY;
            $lease->fenced_at = null;
            $lease->released_at = now();
            $lease->release_reason = mb_substr(trim($reason) . ($by ? " (by {$by})" : ''), 0, 255);
            $lease->expires_at = null; // no live lease until the appliance heartbeats again
            $lease->save();

            return $this->view($lease);
        });
    }

    /**
     * THE fence question on the Cloud: must this branch's transactional mutations be refused because a paired
     * appliance holds — or may be about to hold — the branch's authority?
     *   holder = edge            → yes (the appliance is the writer until handback)
     *   lease lapsed (expired)   → yes (the Cloud can no longer prove it is the sole writer)
     *   released / no lease row  → no  (no appliance in the picture, or an operator released a dead one)
     */
    public function blocksCloud(Branch $branch): bool
    {
        $lease = EdgeBranchAuthorityLease::on('tenant')->where('branch_id', (int) $branch->id)->first();
        if (! $lease || $lease->isReleased()) {
            return false;
        }
        if ($lease->holder === EdgeBranchAuthorityLease::HOLDER_EDGE) {
            return true;
        }
        if ($lease->last_heartbeat_at === null) {
            return false; // never heartbeated: no lease was ever granted, nothing to lose
        }
        if ($lease->isExpired()) {
            if ($lease->fenced_at === null) {
                $lease->forceFill(['fenced_at' => now()])->save(); // audit the first refusal
            }

            return true;
        }

        return false;
    }

    public function status(Branch $branch): ?array
    {
        $lease = EdgeBranchAuthorityLease::on('tenant')->where('branch_id', (int) $branch->id)->first();

        return $lease ? $this->view($lease) : null;
    }

    private function lockedLease(int $branchId, string $deviceUuid): EdgeBranchAuthorityLease
    {
        $lease = EdgeBranchAuthorityLease::on('tenant')->where('branch_id', $branchId)->lockForUpdate()->first();
        if (! $lease) {
            $lease = new EdgeBranchAuthorityLease([
                'branch_id' => $branchId, 'device_public_uuid' => $deviceUuid,
                'holder' => EdgeBranchAuthorityLease::HOLDER_CLOUD, 'edge_state' => EdgeBranchAuthorityLease::EDGE_STANDBY,
                'heartbeat_seq' => 0, 'lease_ttl_seconds' => $this->ttlSeconds(),
            ]);
            $lease->save();
            $lease = EdgeBranchAuthorityLease::on('tenant')->where('branch_id', $branchId)->lockForUpdate()->first();
        }

        return $lease;
    }

    private function view(EdgeBranchAuthorityLease $lease): array
    {
        $now = now();

        return [
            'branch_id' => (int) $lease->branch_id,
            'holder' => (string) $lease->holder,
            'edge_state' => (string) $lease->edge_state,
            'lease_ttl_seconds' => (int) $lease->lease_ttl_seconds,
            'expires_in_seconds' => $lease->expires_at ? max(0, $lease->expires_at->getTimestamp() - $now->getTimestamp()) : 0,
            'seq' => (int) $lease->heartbeat_seq,
            'server_time' => $now->toIso8601String(),
            'fenced' => $lease->holder === EdgeBranchAuthorityLease::HOLDER_EDGE || ($lease->last_heartbeat_at !== null && $lease->isExpired() && ! $lease->isReleased()),
            'released' => $lease->isReleased(),
        ];
    }
}
