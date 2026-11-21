<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Models\Tenant\EdgeBranchAuthorityLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Exceptions\EdgeStaleHeartbeatException;
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
            $current = (int) $lease->heartbeat_seq;
            if ($lease->last_heartbeat_at !== null && $seq < $current) {
                throw new EdgeStaleHeartbeatException($current);
            }
            if ($lease->last_heartbeat_at !== null && $seq === $current) {
                // P5C HOME LAB (19 Sep 2026) — LOST ACK: the appliance sent THIS beat, the Cloud applied it, the answer never
                // arrived (single-threaded lab Cloud under load; a WAN blip does the same), so the appliance re-sends the same
                // sequence forever and every refusal counted as a lost Cloud — a healthy Cloud reported as CONNECTION_LOST and
                // the supervisor invited to a needless takeover. Re-acknowledge the SAME beat idempotently: nothing extended
                // (timestamps untouched), nothing moved (holder/state untouched) — the appliance just learns the ack it missed
                // and advances. A LOWER sequence is still refused above.
                return $this->view($lease);
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
     * W-F VERSION REPORTING — the build facts an appliance may report on a heartbeat, with their bounds. Anything else in
     * the block is ignored (a newer appliance may add keys; this Cloud records what it understands).
     */
    public const BUILD_STRING_FIELDS = [
        'edge_app_version' => 64,
        'git_commit' => 64,
        'artifact_version' => 64,
        'bootstrap_schema' => 64,
        'config_schema' => 64,
        'edge_schema_version' => 190,
        'applied_edge_schema_version' => 190,
    ];

    /** List members of the build block: [max items, max length per item]. */
    public const BUILD_LIST_FIELDS = [
        'envelope_versions' => [20, 64],
        'capabilities' => [100, 100],
    ];

    /**
     * The canonical (allowlisted, ordered) form of an ALREADY VALIDATED build block — the thing that is hashed and stored.
     */
    public function normalizeBuild(array $build): array
    {
        $out = [];
        foreach (self::BUILD_STRING_FIELDS as $key => $max) {
            $v = $build[$key] ?? null;
            $out[$key] = is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        }
        foreach (self::BUILD_LIST_FIELDS as $key => [$maxItems, $maxLen]) {
            $list = [];
            foreach (array_values(is_array($build[$key] ?? null) ? $build[$key] : []) as $item) {
                if (is_scalar($item) && trim((string) $item) !== '') {
                    $list[] = mb_substr(trim((string) $item), 0, $maxLen);
                }
            }
            $out[$key] = array_slice($list, 0, $maxItems);
        }

        return $out;
    }

    /**
     * W-F — record the build an appliance reported on an ACCEPTED heartbeat, on the MASTER device row, only when it changed
     * (sha256 of the canonical block differs from `build_reported_hash`). Informational and idempotent:
     *  - it runs AFTER (and outside) the tenant lease transaction and never touches the lease, the fence or edge_state;
     *  - the write is conditional in SQL too (a concurrent identical beat updates nothing);
     *  - it can never fail a heartbeat — every failure is swallowed and logged.
     *
     * @return bool true when the device row was written
     */
    public function recordBuildReport(EdgeDevice $device, ?array $build): bool
    {
        if ($build === null) {
            return false; // an old appliance (or one whose facts were unavailable) — nothing to record
        }
        try {
            $build = $this->normalizeBuild($build);
            $hash = EdgeCanonicalJson::hash($build);
            $known = (string) ($device->getAttribute('build_reported_hash') ?? '');
            if ($known !== '' && hash_equals($known, $hash)) {
                return false;
            }
            $now = now();
            $manifest = $build + [
                // Aliases in the compatibility-report vocabulary so EdgeCompatibilityService::classify() reads either source.
                'bootstrap_schema_version' => $build['bootstrap_schema'],
                'config_schema_version' => $build['config_schema'],
                'reported_via' => 'heartbeat',
            ];
            $update = [
                'compatibility_manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'compatibility_reported_at' => $now,
                'build_reported_hash' => $hash,
                'build_reported_at' => $now,
                'updated_at' => $now,
            ];
            if ($build['edge_app_version'] !== null) {
                $update['app_version'] = mb_substr($build['edge_app_version'], 0, 64);
            }
            if ($build['bootstrap_schema'] !== null) {
                $update['schema_version'] = mb_substr($build['bootstrap_schema'], 0, 64);
            }
            $written = DB::connection($device->getConnectionName() ?: 'master')->table('edge_devices')
                ->where('id', (int) $device->getKey())
                ->where(fn ($q) => $q->whereNull('build_reported_hash')->orWhere('build_reported_hash', '!=', $hash))
                ->update($update);
            $device->setAttribute('build_reported_hash', $hash);

            return $written > 0;
        } catch (\Throwable $e) {
            Log::warning('[edge-authority] build report not recorded (heartbeat unaffected)', [
                'device' => (string) $device->public_uuid, 'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return false;
        }
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
