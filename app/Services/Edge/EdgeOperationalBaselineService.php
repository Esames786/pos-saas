<?php

namespace App\Services\Edge;

use App\Support\EdgeRuntime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * EDGE-LOCAL-POS-1 (I, hardened) — the ACCEPTED operational-stock baseline AUTHORITY.
 *
 * Selling stock on a Branch Server exists ONLY under an accepted baseline bound to the FULL current
 * binding: branch + device + activation_epoch (a baseline accepted for device A never authorizes device B,
 * even when branch+epoch match). Contract (locked):
 *
 *   - no accepted baseline for the CURRENT binding      → local paid sale REFUSED before any mutation;
 *   - exact same baseline (uuid + canonical hash) retry → idempotent;
 *   - same baseline uuid, different canonical payload   → CONFLICT;
 *   - wrong branch/device/epoch                         → no authority;
 *   - >1 accepted baseline for one binding              → controlled CORRUPTION failure (never pick one);
 *   - ANY different baseline once one is accepted       → REFUSED (replacement fence — a newer snapshot must
 *     never erase already-consumed local quantity; B1→B2 cutover belongs to future sync/reconciliation).
 *
 * AUTHORITY IS COMPUTED, NOT CALLER-SUPPLIED:
 *   - the content hash is canonicalized + SHA-256'd INTERNALLY from the actual items (sorted, normalized to
 *     the persistence precision, duplicates rejected); a caller-supplied expected hash must MATCH the
 *     computed hash or acceptance refuses — the DB stores the computed hash;
 *   - the baseline source_revision must equal the currently imported edge_local_meta.source_revision;
 *   - generation is fixed internally at 1 for the INITIAL baseline (no independent generation authority
 *     exists yet; advancement belongs to the future sync/reconciliation protocol).
 *
 * DB-level invariant (migration 2026_08_08_000004): a UNIQUE `active_binding_key` (fixed-size hash of
 * branch|device|epoch, populated only on accepted rows) guarantees at most ONE accepted baseline per binding
 * even when two first-acceptance transactions race from a zero-row state — the loser gets a controlled
 * conflict, never split authority.
 *
 * There is deliberately NO artisan command and NO HTTP route for this service: production has no invocation
 * path. Tests / physical-artifact QA call it directly. It never touches activation_ready.
 */
class EdgeOperationalBaselineService
{
    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    /**
     * Accept the INITIAL operational-stock baseline for the bound appliance.
     * $items: [['product_id' => int, 'product_variant_id' => ?int, 'quantity' => float], ...]
     * $expectedHash: optional import/manifest hash — must equal the internally computed canonical hash.
     */
    public function accept(string $baselineUuid, ?string $expectedHash, array $items, ?string $sourceRevision = null): object
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Operational stock baselines exist only on a Branch Server.');
        }
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $deviceUuid = (string) $meta->device_uuid;
        $epoch = (int) $meta->activation_epoch;

        // (#5) the baseline must come from the revision this appliance actually imported — and the revision
        // is NOT optional authority: a bootstrapped binding always carries source_revision (the importer's
        // config watermark), so an omitted/empty baseline revision is REFUSED, never treated as a bypass.
        $bindingRevision = (string) $meta->source_revision;
        if ($bindingRevision === '') {
            throw new RuntimeException('This binding has no imported source revision — baseline acceptance refused.');
        }
        if ($sourceRevision === null || $sourceRevision === '') {
            throw new RuntimeException('Baseline source revision is required and must match the imported binding revision.');
        }
        if ($bindingRevision !== $sourceRevision) {
            throw new RuntimeException('Baseline source revision does not match the imported binding revision — refused.');
        }

        // (#4) canonical, internally computed content hash — caller metadata is never authority.
        $canonical = self::canonicalizeItems($items);
        $computedHash = hash('sha256', json_encode($canonical));
        if ($expectedHash !== null && ! hash_equals($computedHash, $expectedHash)) {
            throw new RuntimeException('Baseline conflict: supplied content hash does not match the canonical payload.');
        }

        $attempt = function () use ($baselineUuid, $computedHash, $canonical, $sourceRevision, $branchId, $deviceUuid, $epoch) {
            return DB::connection('tenant')->transaction(function () use ($baselineUuid, $computedHash, $canonical, $sourceRevision, $branchId, $deviceUuid, $epoch) {
                $existing = $this->acceptedForBinding($branchId, $deviceUuid, $epoch, lock: true);

                if ($existing) {
                    if ($existing->baseline_uuid === $baselineUuid && $existing->content_hash === $computedHash) {
                        return $existing; // idempotent same-baseline retry
                    }
                    if ($existing->baseline_uuid === $baselineUuid) {
                        throw new RuntimeException('Baseline conflict: same baseline identity with a different canonical payload.');
                    }
                    // REPLACEMENT FENCE — no reset/refresh while this appliance has an accepted baseline.
                    throw new RuntimeException(
                        'Operational stock baseline replacement is refused: an accepted baseline already exists for this '
                        . 'appliance binding. Replacing selling stock while unsynced local activity may exist would '
                        . 'oversell; baseline cutover belongs to the future sync/reconciliation protocol.'
                    );
                }

                $baselineId = DB::connection('tenant')->table('edge_operational_stock_baselines')->insertGetId([
                    'baseline_uuid' => $baselineUuid,
                    'branch_id' => $branchId,
                    'device_uuid' => $deviceUuid,
                    'activation_epoch' => $epoch,
                    'generation' => 1,                       // (#6) fixed internally for the INITIAL baseline
                    'source_revision' => $sourceRevision,
                    'content_hash' => $computedHash,
                    'status' => 'accepted',
                    'active_binding_key' => self::bindingKey($branchId, $deviceUuid, $epoch), // (#7) DB uniqueness
                    'accepted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($canonical as $item) {
                    DB::connection('tenant')->table('edge_operational_stock_balances')->insert([
                        'balance_key' => $baselineId . '-' . $item['product_id'] . '-' . ($item['product_variant_id'] ?: 0),
                        'baseline_id' => $baselineId,
                        'branch_id' => $branchId,
                        'product_id' => $item['product_id'],
                        'product_variant_id' => $item['product_variant_id'],
                        'quantity_on_hand' => $item['quantity'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return DB::connection('tenant')->table('edge_operational_stock_baselines')->where('id', $baselineId)->first();
            });
        };

        try {
            try {
                return $attempt();
            } catch (\Illuminate\Database\QueryException $e) {
                // Two first-acceptance transactions racing from a zero-row state can DEADLOCK on the gap
                // locks (MySQL 1213: "try restarting transaction"). Retry ONCE: the retry observes the
                // winner's committed row and produces the correct controlled outcome (idempotent return,
                // hash conflict, or the replacement fence). Anything else propagates.
                if (($e->errorInfo[1] ?? null) === 1213) {
                    return $attempt();
                }
                throw $e;
            }
        } catch (UniqueConstraintViolationException $e) {
            // (#7) ONLY a collision on the active-binding unique key is the first-acceptance race. Any other
            // unique index (baseline_uuid, balance_key, …) is an unrelated real failure that must propagate —
            // the same lesson the client_uuid classifier taught: classify by the violated KEY NAME, never by
            // whole-message text (the SQL in the message contains unrelated column names).
            if (! $this->isActiveBindingCollision($e->getMessage())) {
                throw $e;
            }
            throw new RuntimeException('Baseline acceptance conflict: another baseline was accepted concurrently for this binding.');
        }
    }

    /** True ONLY when the violated MySQL unique key is the single-accepted-baseline binding invariant. */
    private function isActiveBindingCollision(string $message): bool
    {
        return preg_match("/for key '[^']*eosb_active_binding_unique'/i", $message) === 1;
    }

    /**
     * The accepted baseline that AUTHORIZES selling for the CURRENT full binding, or null.
     *
     * (#5-D) Deliberately revision-strict: if the binding's imported source_revision has moved past the
     * baseline's revision (a future config refresh), the baseline no longer authorizes selling — fail
     * CLOSED until the future reconciliation/cutover protocol re-establishes stock authority. (The
     * replacement FENCE inside accept() stays revision-agnostic on purpose: an existing accepted baseline
     * still blocks any new baseline, so a revision change cannot be used to sneak a replacement in.)
     */
    public function currentAccepted(): ?object
    {
        $meta = $this->context->tryCurrent();
        if ($meta === null) {
            return null;
        }

        $baseline = $this->acceptedForBinding((int) $meta->branch_id, (string) $meta->device_uuid, (int) $meta->activation_epoch, lock: false);
        if ($baseline !== null && (string) $baseline->source_revision !== (string) $meta->source_revision) {
            return null; // authority lapsed with the revision — no selling until a future cutover protocol
        }

        return $baseline;
    }

    /** (#2/#3) resolve by the FULL binding; >1 accepted row is corruption and FAILS CLOSED (never first()). */
    private function acceptedForBinding(int $branchId, string $deviceUuid, int $epoch, bool $lock): ?object
    {
        $query = DB::connection('tenant')->table('edge_operational_stock_baselines')
            ->where('branch_id', $branchId)
            ->where('device_uuid', $deviceUuid)
            ->where('activation_epoch', $epoch)
            ->where('status', 'accepted');
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->get();

        if ($rows->count() > 1) {
            throw new RuntimeException(
                'Operational stock authority corruption: multiple accepted baselines exist for one appliance binding. '
                . 'Refusing to select one arbitrarily — this requires manual/support intervention.'
            );
        }

        return $rows->first();
    }

    /**
     * (#4) Canonical baseline payload: validated rows, quantities normalized to the persistence precision
     * (decimal 14,3), duplicates rejected, deterministically sorted — so semantically identical payloads hash
     * identically regardless of input order, and a tampered/reused stale hash cannot pass.
     */
    public static function canonicalizeItems(array $items): array
    {
        $canonical = [];
        $seen = [];
        foreach ($items as $i => $item) {
            $productId = $item['product_id'] ?? null;
            if (! is_numeric($productId) || (int) $productId <= 0) {
                throw new RuntimeException("Baseline item #{$i} has an invalid product_id.");
            }
            $variantRaw = $item['product_variant_id'] ?? null;
            $variantId = ($variantRaw === null || $variantRaw === '' || (int) $variantRaw === 0) ? null : (int) $variantRaw;
            $qty = $item['quantity'] ?? null;
            if (! is_numeric($qty) || ! is_finite((float) $qty)) {
                throw new RuntimeException("Baseline item #{$i} has an invalid quantity.");
            }
            $key = (int) $productId . ':' . ($variantId ?: 0);
            if (isset($seen[$key])) {
                throw new RuntimeException("Baseline contains duplicate rows for product/variant [{$key}].");
            }
            $seen[$key] = true;
            $canonical[] = [
                'product_id' => (int) $productId,
                'product_variant_id' => $variantId,
                'quantity' => number_format((float) $qty, 3, '.', ''), // persistence precision decimal(14,3)
            ];
        }
        usort($canonical, fn ($a, $b) => [$a['product_id'], $a['product_variant_id'] ?? 0] <=> [$b['product_id'], $b['product_variant_id'] ?? 0]);

        return $canonical;
    }

    /** The canonical content hash for an item payload (what accept() computes and stores). */
    public static function canonicalHash(array $items): string
    {
        return hash('sha256', json_encode(self::canonicalizeItems($items)));
    }

    /** Fixed-size deterministic binding key for the DB single-accepted-baseline unique index. */
    public static function bindingKey(int $branchId, string $deviceUuid, int $epoch): string
    {
        return hash('sha1', $branchId . '|' . $deviceUuid . '|' . $epoch);
    }

    /** @deprecated tests should use canonicalHash(); kept as an alias. */
    public static function hashItems(array $items): string
    {
        return self::canonicalHash($items);
    }

    public static function newBaselineUuid(): string
    {
        return (string) Str::ulid();
    }
}
