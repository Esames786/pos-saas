<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeSyncOutbox;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OFFLINE-SYNC-ENGINE-1E — the controlled operational-baseline CUTOVER authority.
 *
 * A Config Refresh advances the appliance's source_revision. The accepted baseline's revision no longer
 * matches, EdgeOperationalBaselineService::currentAccepted() returns null, and offline selling fences
 * (fail-closed). This service is the ONLY sanctioned path back to selling: it accepts a NEW baseline whose
 * on-hand quantities already ACCOUNT for the prior generation's ingested sales. The blind in-binding
 * "replace now" fence inside accept() stays untouched — this is a deliberate, drained, audited transition.
 *
 * State machine (per branch + activation_epoch), from EDGE_BASELINE_CUTOVER_PROTOCOL.md:
 *   SELLING(N) --config refresh--> CUTOVER_REQUIRED --drain--> DRAINED --Cloud position--> position_ready
 *     --Cloud issues package--> baseline_issued --Edge accepts (this service)--> SELLING(N+1)
 *
 * Non-negotiables enforced here:
 *   - selling never resumes on a baseline that does not account for prior-generation un-ingested sales:
 *     acceptCutover() REFUSES until the outbox is drained (every row acknowledged);
 *   - the Cloud remains the stock authority: the package's quantities are verified by an integrity hash;
 *     a tampered, wrong-branch, wrong-epoch, or wrong-revision package is refused;
 *   - acceptance is ATOMIC: the prior baseline is superseded and the new one accepted inside one tenant
 *     transaction; a failure halfway rolls back entirely — the old baseline stays accepted and selling
 *     stays fenced (no hybrid snapshot);
 *   - accepting a baseline locally posts NO Cloud GL or stock — it only swaps the local selling snapshot.
 */
class EdgeBaselineCutoverService
{
    public const STATE_NOT_BOUND = 'not_bound';
    public const STATE_NO_BASELINE = 'no_baseline';       // never accepted an initial baseline
    public const STATE_SELLING = 'selling';               // accepted baseline matches the current revision
    public const STATE_CUTOVER_REQUIRED = 'cutover_required'; // revision moved; accepted baseline is stale

    public function __construct(
        private readonly EdgeBranchContext $context,
    ) {
    }

    /**
     * The cutover state for the current binding, plus the revisions and the drain summary. Read-only.
     */
    public function status(): array
    {
        $meta = $this->context->tryCurrent();
        if ($meta === null) {
            return ['state' => self::STATE_NOT_BOUND];
        }

        $branchId = (int) $meta->branch_id;
        $deviceUuid = (string) $meta->device_uuid;
        $epoch = (int) $meta->activation_epoch;
        $currentRevision = (string) $meta->source_revision;

        $accepted = $this->acceptedBaseline($branchId, $deviceUuid, $epoch, lock: false);
        $drain = $this->drainSummary($branchId);

        if ($accepted === null) {
            return [
                'state' => self::STATE_NO_BASELINE,
                'current_revision' => $currentRevision,
                'baseline_revision' => null,
                'drain' => $drain,
                'selling_fenced' => true,
            ];
        }

        $selling = (string) $accepted->source_revision === $currentRevision;

        return [
            'state' => $selling ? self::STATE_SELLING : self::STATE_CUTOVER_REQUIRED,
            'current_revision' => $currentRevision,
            'baseline_revision' => (string) $accepted->source_revision,
            'baseline_uuid' => (string) $accepted->baseline_uuid,
            'generation' => (int) $accepted->generation,
            'drain' => $drain,
            // A cutover-required binding cannot sell (currentAccepted() is null); a selling one can.
            'selling_fenced' => ! $selling,
            // Selling may resume (a cutover may be accepted) only once the prior generation is fully drained.
            'cutover_ready' => ! $selling && $drain['drained'],
        ];
    }

    /**
     * The drain rule (§M): every prior-generation outbox envelope must have reached a SAFE-terminal state.
     * V1 fail-closed: the only safe-terminal state is `acknowledged`. Any pending / leased / failed_permanent
     * row blocks the cutover — an un-ingested sale's stock/accounting consequence is unresolved.
     */
    public function drainSummary(int $branchId): array
    {
        $counts = DB::connection('tenant')->table('edge_sync_outbox')
            ->selectRaw('state, COUNT(*) as c')
            ->groupBy('state')
            ->pluck('c', 'state');

        $pending = (int) ($counts[EdgeSyncOutbox::STATE_PENDING] ?? 0);
        $leased = (int) ($counts[EdgeSyncOutbox::STATE_LEASED] ?? 0);
        $failed = (int) ($counts[EdgeSyncOutbox::STATE_FAILED_PERMANENT] ?? 0);
        $acknowledged = (int) ($counts[EdgeSyncOutbox::STATE_ACKNOWLEDGED] ?? 0);
        $blocking = $pending + $leased + $failed;

        return [
            'drained' => $blocking === 0,
            'blocking' => $blocking,
            'pending' => $pending,
            'leased' => $leased,
            'failed_permanent' => $failed,
            'acknowledged' => $acknowledged,
        ];
    }

    /**
     * Accept an issued baseline package through the controlled cutover. The ONLY sanctioned baseline
     * replacement. Returns the new accepted baseline row.
     *
     * $package (immutable, issued by the Cloud after computing the authoritative post-drain position):
     *   [ 'baseline_uuid' => string, 'branch_id' => int, 'activation_epoch' => int,
     *     'source_revision' => string, 'content_hash' => string,
     *     'items' => [['product_id'=>int,'product_variant_id'=>?int,'quantity'=>float], ...],
     *     'cloud_position' => ['as_of' => ?string, 'hash' => ?string] ]
     *
     * Fails closed on: not a branch server; not bound; not CUTOVER_REQUIRED (no stale baseline); wrong
     * branch / epoch / revision; tampered integrity hash; an un-drained outbox; same baseline uuid with a
     * different payload (conflict). Idempotent for the exact same package once accepted (replay).
     */
    public function acceptCutover(array $package, string $performedBy, ?string $reason = null): object
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('CUTOVER_NOT_BRANCH_SERVER: baseline cutover exists only on a Branch Server.');
        }
        $startedAt = now();
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $deviceUuid = (string) $meta->device_uuid;
        $epoch = (int) $meta->activation_epoch;
        $currentRevision = (string) $meta->source_revision;

        // ── package binding + integrity (authority is the binding + the computed hash, not caller trust) ──
        if ((int) ($package['branch_id'] ?? 0) !== $branchId) {
            throw new RuntimeException('CUTOVER_WRONG_BRANCH: the baseline package is not for this branch.');
        }
        if ((int) ($package['activation_epoch'] ?? 0) !== $epoch) {
            throw new RuntimeException('CUTOVER_WRONG_EPOCH: the baseline package is not for this activation epoch.');
        }
        $packageRevision = (string) ($package['source_revision'] ?? '');
        if ($packageRevision === '' || $packageRevision !== $currentRevision) {
            throw new RuntimeException('CUTOVER_REVISION_MISMATCH: the baseline package revision must equal the current binding revision (the new watermark).');
        }
        $baselineUuid = (string) ($package['baseline_uuid'] ?? '');
        if ($baselineUuid === '') {
            throw new RuntimeException('CUTOVER_PACKAGE_INVALID: a baseline_uuid is required.');
        }
        $items = $package['items'] ?? [];
        $computedHash = EdgeOperationalBaselineService::canonicalHash($items); // validates + canonicalizes
        $declaredHash = (string) ($package['content_hash'] ?? '');
        if ($declaredHash === '' || ! hash_equals($computedHash, $declaredHash)) {
            throw new RuntimeException('CUTOVER_INTEGRITY: the baseline package content hash does not match its items (tampered or corrupt).');
        }
        $canonical = EdgeOperationalBaselineService::canonicalizeItems($items);

        // ── drain (§M): refuse until every prior-generation sale is safely terminal ──
        $drain = $this->drainSummary($branchId);
        if (! $drain['drained']) {
            throw new RuntimeException(
                'CUTOVER_NOT_DRAINED: ' . $drain['blocking'] . ' prior-generation sale(s) are not yet acknowledged '
                . '(pending=' . $drain['pending'] . ', leased=' . $drain['leased'] . ', failed_permanent=' . $drain['failed_permanent'] . '). '
                . 'Resolve them before cutover — selling must never resume on a baseline that does not account for un-ingested sales.'
            );
        }

        $cloudPosition = $package['cloud_position'] ?? [];

        return DB::connection('tenant')->transaction(function () use (
            $branchId, $deviceUuid, $epoch, $currentRevision, $baselineUuid, $computedHash, $canonical,
            $drain, $cloudPosition, $performedBy, $reason, $startedAt
        ) {
            $accepted = $this->acceptedBaseline($branchId, $deviceUuid, $epoch, lock: true);

            // Idempotency / conflict against whatever is currently accepted for the binding.
            if ($accepted !== null) {
                if ((string) $accepted->source_revision === $currentRevision) {
                    // Already at the new watermark. Same package -> idempotent replay; anything else -> refuse.
                    if ((string) $accepted->baseline_uuid === $baselineUuid && hash_equals((string) $accepted->content_hash, $computedHash)) {
                        return $accepted;
                    }
                    if ((string) $accepted->baseline_uuid === $baselineUuid) {
                        throw new RuntimeException('CUTOVER_CONFLICT: the same baseline identity was already accepted with a different payload.');
                    }
                    throw new RuntimeException('CUTOVER_ALREADY_DONE: a different baseline is already accepted at the current revision; no cutover is pending.');
                }
                // else: a STALE accepted baseline (prior revision) — the expected cutover case.
            } else {
                // No accepted baseline for the binding: this is not a cutover situation (initial acceptance
                // is EdgeOperationalBaselineService::accept). Refuse rather than invent selling authority.
                throw new RuntimeException('CUTOVER_NO_STALE_BASELINE: there is no stale accepted baseline to cut over from; use initial baseline acceptance.');
            }

            // ── supersede the stale baseline: free the single-accepted unique key, clear its selling balances ──
            $oldId = (int) $accepted->id;
            DB::connection('tenant')->table('edge_operational_stock_baselines')->where('id', $oldId)->update([
                'status' => 'superseded',
                'active_binding_key' => null, // frees eosb_active_binding_unique for the new accepted row
                'superseded_at' => now(),
                'updated_at' => now(),
            ]);
            // Clear the prior selling quantities (balances). Movements are append-only history (FK RESTRICT) and stay.
            DB::connection('tenant')->table('edge_operational_stock_balances')->where('baseline_id', $oldId)->delete();

            // ── accept the new baseline at the new watermark ──
            $newGeneration = (int) $accepted->generation + 1;
            $newBaselineId = DB::connection('tenant')->table('edge_operational_stock_baselines')->insertGetId([
                'baseline_uuid' => $baselineUuid,
                'branch_id' => $branchId,
                'device_uuid' => $deviceUuid,
                'activation_epoch' => $epoch,
                'generation' => $newGeneration,
                'source_revision' => $currentRevision,
                'content_hash' => $computedHash,
                'status' => 'accepted',
                'active_binding_key' => EdgeOperationalBaselineService::bindingKey($branchId, $deviceUuid, $epoch),
                'accepted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($canonical as $item) {
                DB::connection('tenant')->table('edge_operational_stock_balances')->insert([
                    'balance_key' => $newBaselineId . '-' . $item['product_id'] . '-' . ($item['product_variant_id'] ?: 0),
                    'baseline_id' => $newBaselineId,
                    'branch_id' => $branchId,
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity_on_hand' => $item['quantity'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // ── immutable audit row ──
            DB::connection('tenant')->table('edge_baseline_cutovers')->insert([
                'cutover_uuid' => (string) Str::ulid(),
                'branch_id' => $branchId,
                'device_uuid' => $deviceUuid,
                'activation_epoch' => $epoch,
                'old_baseline_id' => $oldId,
                'old_baseline_uuid' => (string) $accepted->baseline_uuid,
                'old_source_revision' => (string) $accepted->source_revision,
                'old_generation' => (int) $accepted->generation,
                'new_baseline_id' => $newBaselineId,
                'new_baseline_uuid' => $baselineUuid,
                'new_source_revision' => $currentRevision,
                'new_generation' => $newGeneration,
                'new_content_hash' => $computedHash,
                'cloud_position_as_of' => $cloudPosition['as_of'] ?? null,
                'cloud_position_hash' => $cloudPosition['hash'] ?? null,
                'drain_evidence' => json_encode($drain),
                'performed_by' => mb_substr($performedBy, 0, 191),
                'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
                'started_at' => $startedAt,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::connection('tenant')->table('edge_operational_stock_baselines')->where('id', $newBaselineId)->first();
        });
    }

    /**
     * Build an immutable baseline package (§O) from the authoritative post-drain quantities. On the Cloud
     * side those quantities come from official inventory after the prior generation is ingested; this helper
     * only stamps the canonical integrity hash so issuance and acceptance agree byte-for-byte. It never
     * reads Edge provisional balances.
     */
    public static function buildPackage(int $branchId, int $activationEpoch, string $sourceRevision, array $items, array $cloudPosition = []): array
    {
        return [
            'baseline_uuid' => (string) Str::ulid(),
            'branch_id' => $branchId,
            'activation_epoch' => $activationEpoch,
            'source_revision' => $sourceRevision,
            'content_hash' => EdgeOperationalBaselineService::canonicalHash($items),
            'items' => $items,
            'cloud_position' => $cloudPosition,
        ];
    }

    /** Resolve the accepted baseline for the binding; >1 accepted row is corruption and FAILS CLOSED. */
    private function acceptedBaseline(int $branchId, string $deviceUuid, int $epoch, bool $lock): ?object
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
            throw new RuntimeException('CUTOVER_AUTHORITY_CORRUPTION: multiple accepted baselines for one binding.');
        }

        return $rows->first();
    }
}
