<?php

namespace App\Services\Edge;

use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * EDGE-LOCAL-POS-1 (I) — the ACCEPTED operational-stock baseline authority.
 *
 * Selling stock on a Branch Server exists ONLY under an accepted baseline bound to the current
 * branch / device / activation_epoch / generation + a content hash. Ordinary bootstrap does NOT create
 * selling authority. Contract (locked):
 *
 *   - no accepted baseline                      → local paid sale REFUSED before any mutation;
 *   - exact same baseline (uuid + hash) retry   → idempotent (returns the existing baseline);
 *   - same baseline uuid, different hash        → CONFLICT (tampered/eq-id different payload);
 *   - wrong branch/device/epoch                 → REFUSED;
 *   - ANY different baseline once one is accepted → REFUSED (replacement fence): a newer Cloud snapshot
 *     must never erase already-consumed local quantity while unsynced operational activity exists —
 *     that would oversell. B1→B2 cutover belongs to the future sync/reconciliation sprint.
 *
 * There is deliberately NO artisan command and NO HTTP route for this service: production has no
 * invocation path. Tests / physical-artifact QA call it directly — a controlled TEST/QA entry that cannot
 * become a hidden production "sell anyway" bypass. It never touches activation_ready.
 */
class EdgeOperationalBaselineService
{
    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    /**
     * Accept the INITIAL operational-stock baseline for the bound appliance.
     * $items: [['product_id' => int, 'product_variant_id' => ?int, 'quantity' => float], ...]
     */
    public function accept(string $baselineUuid, string $contentHash, array $items, ?string $sourceRevision = null, int $generation = 1): object
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Operational stock baselines exist only on a Branch Server.');
        }
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $deviceUuid = (string) $meta->device_uuid;
        $epoch = (int) $meta->activation_epoch;

        return DB::connection('tenant')->transaction(function () use ($baselineUuid, $contentHash, $items, $sourceRevision, $generation, $branchId, $deviceUuid, $epoch) {
            $existing = DB::connection('tenant')->table('edge_operational_stock_baselines')
                ->where('branch_id', $branchId)
                ->where('activation_epoch', $epoch)
                ->where('status', 'accepted')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->baseline_uuid === $baselineUuid && $existing->content_hash === $contentHash) {
                    return $existing; // idempotent same-baseline retry
                }
                if ($existing->baseline_uuid === $baselineUuid) {
                    throw new RuntimeException('Baseline conflict: same baseline identity with a different content hash.');
                }
                // REPLACEMENT FENCE — no reset/refresh while this appliance has an accepted baseline.
                throw new RuntimeException(
                    'Operational stock baseline replacement is refused: an accepted baseline already exists for this '
                    . 'appliance generation. Replacing selling stock while unsynced local activity may exist would '
                    . 'oversell; baseline cutover belongs to the future sync/reconciliation protocol.'
                );
            }

            $baselineId = DB::connection('tenant')->table('edge_operational_stock_baselines')->insertGetId([
                'baseline_uuid' => $baselineUuid,
                'branch_id' => $branchId,
                'device_uuid' => $deviceUuid,
                'activation_epoch' => $epoch,
                'generation' => $generation,
                'source_revision' => $sourceRevision,
                'content_hash' => $contentHash,
                'status' => 'accepted',
                'accepted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $variantId = isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;
                DB::connection('tenant')->table('edge_operational_stock_balances')->insert([
                    'balance_key' => $baselineId . '-' . $productId . '-' . ($variantId ?: 0),
                    'baseline_id' => $baselineId,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'quantity_on_hand' => (float) $item['quantity'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return DB::connection('tenant')->table('edge_operational_stock_baselines')->where('id', $baselineId)->first();
        });
    }

    /** The accepted baseline for the CURRENT binding (branch + activation_epoch), or null. */
    public function currentAccepted(): ?object
    {
        $meta = $this->context->tryCurrent();
        if ($meta === null) {
            return null;
        }

        return DB::connection('tenant')->table('edge_operational_stock_baselines')
            ->where('branch_id', (int) $meta->branch_id)
            ->where('activation_epoch', (int) $meta->activation_epoch)
            ->where('status', 'accepted')
            ->first();
    }

    /** Convenience for tests/QA: mint a baseline uuid + content hash for an item payload. */
    public static function hashItems(array $items): string
    {
        return hash('sha256', json_encode($items));
    }

    public static function newBaselineUuid(): string
    {
        return (string) Str::ulid();
    }
}
