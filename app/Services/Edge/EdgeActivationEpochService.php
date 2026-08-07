<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeBranchActivation;
use App\Models\Master\EdgeDevice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section I) — allocates and reads the Cloud-authoritative branch activation
 * generation ("epoch"). This is the foundation for stale-device fencing: when the authoritative
 * appliance for a branch changes, a newer generation is minted; the old generation stays visible so
 * a replaced box can eventually be rejected. Local Mode activation is NOT implemented here.
 *
 * Guarantees:
 *  - monotonic per (tenant, branch): generation 1, 2, 3, …;
 *  - IDEMPOTENT for the same device — re-allocating for the current authoritative device returns the
 *    same generation (a bootstrap retry never bumps the epoch);
 *  - a NEW authoritative device gets a newer generation;
 *  - concurrency-safe: allocation locks the branch's latest row and retries on the unique index, so
 *    two racing allocations for different devices yield two distinct monotonic generations.
 */
class EdgeActivationEpochService
{
    private const MASTER = 'master';
    private const MAX_RETRIES = 8;

    /** The current (highest) activation generation for a branch, or 0 if none allocated yet. */
    public function currentGeneration(int $tenantId, int $branchId): int
    {
        return (int) EdgeBranchActivation::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->max('generation');
    }

    /** The activation row for the current generation, or null. */
    public function currentActivation(int $tenantId, int $branchId): ?EdgeBranchActivation
    {
        return EdgeBranchActivation::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->orderByDesc('generation')
            ->first();
    }

    /**
     * Allocate (or reuse) the activation generation for a device that is becoming — or already is —
     * the authoritative appliance for its branch. Idempotent per device; row-locked; retries on the
     * unique constraint so concurrent different-device allocations stay monotonic and distinct.
     */
    public function allocateForDevice(EdgeDevice $device): int
    {
        $tenantId = (int) $device->tenant_id;
        $branchId = (int) $device->branch_id;
        $deviceUuid = (string) $device->public_uuid;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                return DB::connection(self::MASTER)->transaction(function () use ($tenantId, $branchId, $deviceUuid, $device) {
                    // NEVER trust the passed-in (possibly stale) device object. Re-read + lock the row
                    // and revalidate INSIDE the transaction, so a revoked/replaced device cannot bump
                    // the generation after it has lost authority.
                    $locked = EdgeDevice::whereKey($device->id)->lockForUpdate()->first();
                    if (! $locked
                        || $locked->isRevoked()
                        || $locked->active_slot !== EdgeDevice::ACTIVE_SLOT
                        || (int) $locked->tenant_id !== $tenantId
                        || (int) $locked->branch_id !== $branchId
                        || (string) $locked->public_uuid !== $deviceUuid) {
                        throw new \RuntimeException('Cannot allocate an activation generation for a revoked/replaced device.');
                    }
                    // Must still be THE current active device for this tenant+branch (a newer device
                    // replacing it must own the newer generation instead).
                    $current = EdgeDevice::active()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->first();
                    if (! $current || (int) $current->id !== (int) $locked->id) {
                        throw new \RuntimeException('This device is no longer the active appliance for its branch.');
                    }

                    // Lock the branch's latest generation row (if any) to serialise concurrent
                    // allocations once a branch has at least one generation.
                    $latest = EdgeBranchActivation::query()
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branchId)
                        ->orderByDesc('generation')
                        ->lockForUpdate()
                        ->first();

                    // Same authoritative device -> reuse (a bootstrap retry must not bump the epoch).
                    if ($latest !== null && (string) $latest->device_public_uuid === $deviceUuid) {
                        return (int) $latest->generation;
                    }

                    $next = ($latest !== null ? (int) $latest->generation : 0) + 1;

                    EdgeBranchActivation::query()->create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'generation' => $next,
                        'edge_device_id' => $device->id,
                        'device_public_uuid' => $deviceUuid,
                        'reason' => $next === 1 ? EdgeBranchActivation::REASON_INITIAL : EdgeBranchActivation::REASON_DEVICE_REPLACED,
                    ]);

                    return $next;
                });
            } catch (QueryException $e) {
                // A racing allocation took our generation number first — re-read and retry.
                if ($this->isUniqueViolation($e) && $attempt < self::MAX_RETRIES) {
                    continue;
                }
                throw $e;
            }
        }

        // Unreachable in practice (loop returns or rethrows), but keep the contract explicit.
        throw new \RuntimeException('Could not allocate an activation generation after retries.');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 // MySQL duplicate entry
            || str_contains($e->getMessage(), 'Integrity constraint violation');
    }
}
