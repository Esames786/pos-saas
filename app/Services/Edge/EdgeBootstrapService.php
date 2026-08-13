<?php

namespace App\Services\Edge;

use App\Exceptions\EdgeBootstrapException;
use App\Models\Master\EdgeBootstrapSnapshot;
use App\Models\Master\EdgeBootstrapSnapshotSection;
use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BRANCH-BOOTSTRAP-SNAPSHOT-1 (+HARDEN-1/2) — build and serve a deterministic, immutable,
 * BRANCH-SCOPED bootstrap snapshot for a paired device.
 *
 * HARDEN-2 closes: (1/2) tenant device-limit + active-subscription re-checked in the ONE
 * contract; (3) source-change detection via a fresh LIVE revision read after the consistent
 * build (claim==txn==live); (4) role revision hashed as model_id:role_id pairs; (5) full
 * reference coherence (variants/barcodes/prices/combos/modifiers constrained to included
 * sets); (6) server verifies stored payload bytes before marking a section delivered; (7)
 * final contract re-check under lock before device-ready / snapshot-publish; (8) nullable
 * locked-model handling (controlled error, never TypeError).
 */
class EdgeBootstrapService
{
    // EDGE-CONFIG-REFRESH-1: bumped v4 -> v5. The wire contract materially changed — the manifest now
    // carries the Cloud-authoritative MONOTONIC config_revision plus config_schema_version (both part
    // of the manifest hash), so ONE package format serves the initial bootstrap AND every subsequent
    // config refresh. No real appliance consumes v4 yet (EDGE_FEATURE_ENABLED=false, none deployed),
    // so this is a clean forward bump, not a compat break.
    public const SCHEMA_VERSION = 'edge-bootstrap-v5';

    // EDGE-CONFIG-REFRESH-1: the CONFIG payload contract (which sections exist, their column sets, and
    // the upsert/tombstone semantics the refresh applier applies). Versioned separately from the wire
    // envelope so the future compatibility contract can classify "package readable but config contract
    // newer than this build understands".
    public const CONFIG_SCHEMA_VERSION = 'edge-config-v1';
    public const TTL_HOURS      = 72;
    private const BUILD_WAIT_MS = 120;
    private const BUILD_WAITS   = 25;

    private const PHASE1_PAYMENT_TYPES  = ['cash'];
    private const OFFLINE_DELIVERY_TYPES = ['own'];

    public function __construct(
        private readonly OfflineEdgeEntitlementService $entitlement,
        private readonly TenancyManager $tenancy,
        private readonly EdgePairingService $pairing,   // device-limit reuse (single source of truth)
    ) {}

    /* ── shared security contract ────────────────────────────────────────── */

    /** Activate the tenant (caller MUST deactivate in finally) and run the full contract. */
    public function assertContract(EdgeDevice $device): array
    {
        $tenant = Tenant::find($device->tenant_id);
        if (! $tenant) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::NOT_ALLOWED);
        }
        $this->tenancy->activate($tenant);
        return $this->checkContract($device, $tenant);
    }

    /**
     * Pure contract checks — assumes the tenant is ALREADY active (reusable under a lock).
     * @return array{0: Tenant, 1: Branch}
     */
    public function checkContract(EdgeDevice $device, Tenant $tenant): array
    {
        if ($device->isRevoked() || $device->active_slot !== EdgeDevice::ACTIVE_SLOT) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::DEVICE_REVOKED);
        }
        if (! in_array($device->status, [EdgeDevice::STATUS_PENDING_BOOTSTRAP, EdgeDevice::STATUS_READY], true)) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::NOT_ALLOWED);
        }

        // Still THE current active device for its tenant+branch.
        $current = EdgeDevice::active()->where('tenant_id', $device->tenant_id)->where('branch_id', $device->branch_id)->first();
        if (! $current || (int) $current->id !== (int) $device->id) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::DEVICE_REVOKED);
        }

        // Active subscription + offline_edge entitlement + rollout flag.
        if (! $this->entitlement->featureIsEnabled() || ! $this->entitlement->tenantHasOfflineEdgeAccess()) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::NOT_ALLOWED);
        }

        // HARDEN-2: tenant-wide licensed device limit (e.g. plan reduced 5→1 after pairing).
        if ($this->pairing->activeDeviceCount($tenant->id) > $this->pairing->deviceLimit($tenant)) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::DEVICE_LIMIT_REACHED);
        }

        $branch = Branch::find($device->branch_id);
        if (! $branch) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::NOT_ALLOWED);
        }
        // EDGE-CONFIG-REFRESH-1: an INITIAL bootstrap (device pending_bootstrap) still requires the
        // branch to be PENDING. A READY device is an already-bootstrapped appliance pulling a config
        // REFRESH — allowed while the branch stays in the Local-POS lifecycle (pending/active/closing),
        // never for a suspended/inactive branch.
        $allowedBranchStatuses = $device->status === EdgeDevice::STATUS_READY
            ? [Branch::STATUS_PENDING, Branch::STATUS_ACTIVE, Branch::STATUS_CLOSING]
            : [Branch::STATUS_PENDING];
        if (! in_array($branch->local_edge_status, $allowedBranchStatuses, true)) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::BRANCH_NOT_PENDING);
        }

        return [$tenant, $branch];
    }

    /** HARDEN-2: nullable — a missing/deleted locked row is a controlled DEVICE_REVOKED, never a TypeError. */
    private function revalidateLocked(?EdgeDevice $locked, EdgeDevice $expected): void
    {
        if (! $locked
            || $locked->isRevoked()
            || $locked->active_slot !== EdgeDevice::ACTIVE_SLOT
            || (int) $locked->tenant_id !== (int) $expected->tenant_id
            || (int) $locked->branch_id !== (int) $expected->branch_id
            || ! in_array($locked->status, [EdgeDevice::STATUS_PENDING_BOOTSTRAP, EdgeDevice::STATUS_READY], true)) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::DEVICE_REVOKED);
        }
    }

    /* ── create / reuse ──────────────────────────────────────────────────── */

    /** @return array{snapshot: EdgeBootstrapSnapshot, built: bool} */
    public function createOrReuse(EdgeDevice $device): array
    {
        try {
            [$tenant, $branch] = $this->assertContract($device);
            $claimRevision = $this->sourceRevision($branch);

            // EDGE-LOCAL-RUNTIME-1 (Section I): allocate (idempotently) the Cloud-authoritative
            // activation generation for THIS device BEFORE opening the snapshot transaction (the
            // allocator has its own locked+retry txn). The snapshot then carries the epoch so the
            // importer can store it and ack can fence a stale generation.
            $activationEpoch = app(EdgeActivationEpochService::class)->allocateForDevice($device);

            // EDGE-CONFIG-REFRESH-1: allocate (idempotently) the monotonic config revision for THIS
            // watermark before claiming — the snapshot/manifest carries it so the appliance can order
            // refreshes. Same watermark => same revision (a rebuild never mints a new revision).
            $configRevision = app(EdgeConfigRevisionService::class)
                ->allocateForWatermark((int) $tenant->id, (int) $branch->id, $claimRevision);

            $claim = DB::connection('master')->transaction(function () use ($device, $tenant, $branch, $claimRevision, $activationEpoch, $configRevision) {
                $locked = EdgeDevice::whereKey($device->id)->lockForUpdate()->first();
                $this->revalidateLocked($locked, $device);

                $existing = EdgeBootstrapSnapshot::where('edge_device_id', $device->id)
                    ->where('source_revision', $claimRevision)
                    // fix 6: only reuse a snapshot that belongs to the CURRENT activation generation —
                    // a snapshot minted under an older epoch must never be reused/served.
                    ->where('activation_epoch', $activationEpoch)
                    // EDGE-CONFIG-REFRESH-1: and to the CURRENT config revision — if the watermark
                    // reverted to an earlier value (edit then undo), the same content now carries a
                    // NEWER revision, and a snapshot minted under the older revision number must not
                    // be reused (the appliance would refuse it as stale).
                    ->where('config_revision', $configRevision)
                    ->whereIn('status', [
                        EdgeBootstrapSnapshot::STATUS_READY, EdgeBootstrapSnapshot::STATUS_DOWNLOADED,
                        EdgeBootstrapSnapshot::STATUS_ACKNOWLEDGED, EdgeBootstrapSnapshot::STATUS_BUILDING,
                    ])->orderByDesc('id')->first();

                if ($existing && ($existing->isReusable() || $existing->status === EdgeBootstrapSnapshot::STATUS_BUILDING)) {
                    return ['snapshot' => $existing, 'build' => false];
                }

                $snapshot = EdgeBootstrapSnapshot::create([
                    'public_uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
                    'edge_device_id' => $device->id, 'schema_version' => self::SCHEMA_VERSION,
                    'status' => EdgeBootstrapSnapshot::STATUS_BUILDING, 'source_revision' => $claimRevision,
                    'activation_epoch' => $activationEpoch, 'config_revision' => $configRevision,
                ]);
                return ['snapshot' => $snapshot, 'build' => true];
            });

            /** @var EdgeBootstrapSnapshot $snapshot */
            $snapshot = $claim['snapshot'];
            $built = false;

            if ($claim['build']) {
                $this->build($snapshot, $tenant, $branch, $device, $claimRevision);
                $built = $snapshot->fresh()->status === EdgeBootstrapSnapshot::STATUS_READY;
            } elseif ($snapshot->status === EdgeBootstrapSnapshot::STATUS_BUILDING) {
                $this->waitForBuild($snapshot);
            }

            return ['snapshot' => $snapshot->fresh(), 'built' => $built];
        } finally {
            $this->tenancy->deactivate();
        }
    }

    private function waitForBuild(EdgeBootstrapSnapshot $snapshot): void
    {
        for ($i = 0; $i < self::BUILD_WAITS; $i++) {
            if (($snapshot->fresh()->status ?? null) !== EdgeBootstrapSnapshot::STATUS_BUILDING) {
                return;
            }
            usleep(self::BUILD_WAIT_MS * 1000);
        }
    }

    private function build(EdgeBootstrapSnapshot $snapshot, Tenant $tenant, Branch $branch, EdgeDevice $device, string $claimRevision): void
    {
        try {
            // Consistent read boundary (MySQL REPEATABLE READ): sections + the txn revision are
            // one coherent point in time.
            [$sections, $txnRevision] = DB::connection('tenant')->transaction(function () use ($tenant, $branch) {
                $s = $this->buildSections($tenant, $branch);
                return [$s, $this->sourceRevision($branch)];
            });

            // HARDEN-2: read a FRESH LIVE revision AFTER the txn closes (own connection, no RR
            // view). Publish only if claim == txn == live — otherwise the source moved during
            // the build and we must not publish a stale snapshot.
            DB::purge('tenant');
            DB::reconnect('tenant');
            $liveRevision = $this->sourceRevision($branch);

            if (! hash_equals($claimRevision, $txnRevision) || ! hash_equals($txnRevision, $liveRevision)) {
                $snapshot->update([
                    'status' => EdgeBootstrapSnapshot::STATUS_FAILED,
                    'failure_code' => EdgeBootstrapException::SOURCE_CHANGED,
                    'last_error' => 'source revision changed during build',
                ]);
                EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->delete();
                throw EdgeBootstrapException::of(EdgeBootstrapException::SOURCE_CHANGED);
            }

            // HARDEN-2: final contract re-check immediately before publishing ready.
            $this->checkContract($device->fresh(), $tenant);

            $summary = [];
            foreach ($sections as $name => $rows) {
                $json = $this->canonicalJson($rows);
                $hash = hash('sha256', $json);
                EdgeBootstrapSnapshotSection::create([
                    'snapshot_id' => $snapshot->id, 'name' => $name, 'content_hash' => $hash,
                    'row_count' => is_array($rows) ? count($rows) : 0, 'payload_gz' => base64_encode(gzencode($json, 6)),
                ]);
                $summary[$name] = ['hash' => $hash, 'count' => is_array($rows) ? count($rows) : 0];
            }

            $snapshot->update([
                'status' => EdgeBootstrapSnapshot::STATUS_READY, 'manifest_hash' => $this->manifestHash($snapshot, $summary),
                'section_summary' => $summary, 'generated_at' => now(), 'expires_at' => now()->addHours(self::TTL_HOURS),
                'failure_code' => null, 'last_error' => null,
            ]);
        } catch (EdgeBootstrapException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $snapshot->update([
                'status' => EdgeBootstrapSnapshot::STATUS_FAILED, 'failure_code' => EdgeBootstrapException::BUILD_FAILED,
                'last_error' => substr($e->getMessage(), 0, 500),
            ]);
            EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->delete();
            Log::error('[edge-bootstrap-audit] build_failed', ['snapshot' => $snapshot->public_uuid, 'error' => substr($e->getMessage(), 0, 190)]);
            throw EdgeBootstrapException::of(EdgeBootstrapException::BUILD_FAILED);
        }
    }

    /* ── manifest / section / acknowledge ────────────────────────────────── */

    public function manifest(EdgeBootstrapSnapshot $snapshot): array
    {
        $this->assertServable($snapshot);
        $tenant = Tenant::find($snapshot->tenant_id);
        return [
            'schema_version' => $snapshot->schema_version, 'snapshot_uuid' => $snapshot->public_uuid,
            'tenant_code' => $tenant?->tenant_code, 'tenant_id' => (int) $snapshot->tenant_id,
            'branch_id' => $snapshot->branch_id, 'status' => $snapshot->status,
            'device_public_uuid' => EdgeDevice::whereKey($snapshot->edge_device_id)->value('public_uuid'),
            'activation_epoch' => $snapshot->activation_epoch !== null ? (int) $snapshot->activation_epoch : null,
            'config_revision' => $snapshot->config_revision !== null ? (int) $snapshot->config_revision : null,
            'config_schema_version' => self::CONFIG_SCHEMA_VERSION,
            'source_revision' => $snapshot->source_revision,
            'manifest_hash' => $snapshot->manifest_hash, 'generated_at' => optional($snapshot->generated_at)->toIso8601String(),
            'expires_at' => optional($snapshot->expires_at)->toIso8601String(), 'sections' => $snapshot->section_summary ?? [],
        ];
    }

    /**
     * Serialise a READY snapshot into the self-contained package the local importer consumes:
     * {manifest, sections:{name:[rows]}}. This is the exact data the authenticated Cloud download API
     * serves (manifest + section payloads) — no miniature alternate format.
     *
     * @return array{manifest: array<string,mixed>, sections: array<string, array<int,array<string,mixed>>>}
     */
    public function exportPackage(EdgeBootstrapSnapshot $snapshot): array
    {
        $manifest = $this->manifest($snapshot);
        $sections = [];
        foreach (EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->orderBy('name')->get() as $section) {
            $sections[$section->name] = json_decode($section->json(), true) ?: [];
        }

        return ['manifest' => $manifest, 'sections' => $sections];
    }

    public function sectionPayload(EdgeBootstrapSnapshot $snapshot, string $name): array
    {
        $this->assertServable($snapshot);
        $section = EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->where('name', $name)->first();
        if (! $section) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_NOT_FOUND);
        }

        // HARDEN-2: VERIFY the stored bytes before recording delivery. A corrupted at-rest
        // payload fails here and is NEVER marked delivered — so acknowledgment stays impossible
        // even if a dishonest client echoes the manifest hash.
        $json = $section->json();
        $liveHash = hash('sha256', $json);
        if (! hash_equals((string) $section->content_hash, $liveHash)) {
            Log::error('[edge-bootstrap-audit] section_corrupted', ['snapshot' => $snapshot->public_uuid, 'section' => $name]);
            throw EdgeBootstrapException::of(EdgeBootstrapException::SECTION_CORRUPTED);
        }

        // Atomic receipt: never lose an increment; delivered_hash is the VERIFIED byte hash.
        DB::connection('master')->table('edge_bootstrap_snapshot_sections')->where('id', $section->id)->update([
            'downloaded_at' => DB::raw('COALESCE(downloaded_at, ' . (DB::connection('master')->getDriverName() === 'sqlite' ? "datetime('now')" : 'NOW()') . ')'),
            'delivered_hash' => $liveHash,
            'attempts' => DB::raw('attempts + 1'),
            'updated_at' => now(),
        ]);

        if ($snapshot->status === EdgeBootstrapSnapshot::STATUS_READY && $this->allSectionsDelivered($snapshot)) {
            $snapshot->forceFill(['downloaded_at' => now(), 'status' => EdgeBootstrapSnapshot::STATUS_DOWNLOADED])->save();
        }

        return ['json' => $json, 'hash' => $liveHash, 'count' => $section->row_count];
    }

    private function allSectionsDelivered(EdgeBootstrapSnapshot $snapshot): bool
    {
        $total = EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->count();
        $done  = EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->whereNotNull('downloaded_at')->count();
        return $total > 0 && $total === $done;
    }

    public function acknowledge(EdgeDevice $device, EdgeBootstrapSnapshot $snapshot, string $schemaVersion, string $manifestHash, array $sectionHashes): array
    {
        try {
            if ((int) $snapshot->edge_device_id !== (int) $device->id) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_NOT_FOUND);
            }

            [$tenant, ] = $this->assertContract($device);   // full contract (subscription/limit/flag/branch)

            if ($schemaVersion !== $snapshot->schema_version || $schemaVersion !== self::SCHEMA_VERSION) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::SCHEMA_UNSUPPORTED);
            }
            if ($snapshot->status === EdgeBootstrapSnapshot::STATUS_FAILED || $snapshot->status === EdgeBootstrapSnapshot::STATUS_BUILDING) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_NOT_FOUND);
            }
            if ($snapshot->isExpired()) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_EXPIRED);
            }
            if (! $snapshot->manifest_hash || ! hash_equals((string) $snapshot->manifest_hash, $manifestHash)) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::HASH_MISMATCH);
            }

            $this->assertCompleteDelivery($snapshot, $sectionHashes);

            DB::connection('master')->transaction(function () use ($device, $snapshot, $tenant) {
                $locked = EdgeDevice::whereKey($device->id)->lockForUpdate()->first();
                $this->revalidateLocked($locked, $device);
                $lockedSnap = EdgeBootstrapSnapshot::whereKey($snapshot->id)->lockForUpdate()->first();
                if (! $lockedSnap || $lockedSnap->isExpired()) {
                    throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_EXPIRED);
                }

                // HARDEN-2: FINAL contract re-check under the lock, immediately before writing
                // ready — catches entitlement/subscription/flag/branch/limit changes that landed
                // after the initial check.
                $this->checkContract($locked, $tenant);

                // EDGE-LOCAL-RUNTIME-1 (Section I): fence a stale activation generation. If a newer
                // generation was allocated for this branch (an appliance replacement) after this
                // snapshot was built, its epoch is no longer current and must not be acknowledged.
                if ($lockedSnap->activation_epoch !== null) {
                    $current = app(EdgeActivationEpochService::class)->currentGeneration((int) $lockedSnap->tenant_id, (int) $lockedSnap->branch_id);
                    if ((int) $lockedSnap->activation_epoch !== $current) {
                        throw EdgeBootstrapException::of(EdgeBootstrapException::STALE_ACTIVATION);
                    }
                }

                if (! $lockedSnap->acknowledged_at) {
                    $lockedSnap->forceFill(['status' => EdgeBootstrapSnapshot::STATUS_ACKNOWLEDGED, 'acknowledged_at' => now()])->save();
                }
                if ($locked->status === EdgeDevice::STATUS_PENDING_BOOTSTRAP) {
                    $locked->forceFill(['status' => EdgeDevice::STATUS_READY])->save();
                }
            });

            $this->audit('acknowledged', $snapshot);
            return ['snapshot_uuid' => $snapshot->public_uuid, 'device_status' => EdgeDevice::whereKey($device->id)->value('status'), 'acknowledged' => true];
        } finally {
            $this->tenancy->deactivate();
        }
    }

    private function assertCompleteDelivery(EdgeBootstrapSnapshot $snapshot, array $sectionHashes): void
    {
        $manifest = $snapshot->section_summary ?? [];
        $manifestNames = array_keys($manifest);
        $suppliedNames = array_keys($sectionHashes);
        sort($manifestNames); sort($suppliedNames);
        if ($manifestNames !== $suppliedNames) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::INCOMPLETE);
        }
        foreach ($manifest as $name => $meta) {
            if (! hash_equals((string) $meta['hash'], strtolower((string) $sectionHashes[$name]))) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::SECTION_HASH_MISMATCH);
            }
        }
        // Every section must have been VERIFIED-delivered (downloaded_at set AND delivered_hash
        // equals the manifest hash — a corrupted section could not have satisfied this).
        $sections = EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->get(['name', 'downloaded_at', 'delivered_hash', 'content_hash']);
        $deliveredOk = $sections->filter(fn ($s) => $s->downloaded_at !== null
            && hash_equals((string) $s->delivered_hash, (string) $s->content_hash))->pluck('name')->all();
        sort($deliveredOk);
        if ($deliveredOk !== $manifestNames) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::INCOMPLETE);
        }
    }

    private function assertServable(EdgeBootstrapSnapshot $snapshot): void
    {
        if ($snapshot->schema_version !== self::SCHEMA_VERSION) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SCHEMA_UNSUPPORTED);
        }
        if ($snapshot->status === EdgeBootstrapSnapshot::STATUS_FAILED) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::BUILD_FAILED);
        }
        if ($snapshot->status === EdgeBootstrapSnapshot::STATUS_BUILDING) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::BUILD_IN_PROGRESS);
        }
        if ($snapshot->isExpired()) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_EXPIRED);
        }
    }

    /* ── source watermark ────────────────────────────────────────────────── */

    // Protected (not private) for the same reason as buildSections: the watermark behaviour is
    // pinned by executable tests (EdgeConfigWatermarkMySqlTest) through a test-only subclass.
    protected function sourceRevision(Branch $branch): string
    {
        $conn = DB::connection('tenant');
        $b = (int) $branch->id;
        $wm = [];
        $add = function (string $table, ?string $branchCol = null) use ($conn, &$wm, $b) {
            $q = $conn->table($table);
            if ($branchCol) { $q->where($branchCol, $b); }
            $wm[] = $table . '=' . (string) $q->max('updated_at') . '|' . $q->count();
        };
        $termIds = $conn->table('terminals')->where('branch_id', $b)->pluck('id')->all();
        $assignedUserIds = $conn->table('branch_user')->where('branch_id', $b)->where('is_active', 1)->pluck('user_id');
        $userIds = $conn->table('users')
            ->where(fn ($query) => $query->where('default_branch_id', $b)->orWhereIn('id', $assignedUserIds))
            ->pluck('id')->all();

        $add('branches', 'id'); $add('terminals', 'branch_id'); $add('categories'); $add('units');
        $add('products'); $add('product_variants'); $add('product_barcodes'); $add('product_branch_prices', 'branch_id');
        $add('modifier_groups', 'branch_id'); $add('modifiers'); $add('combos', 'branch_id'); $add('combo_components');
        $add('payment_methods'); $add('restaurant_floors', 'branch_id'); $add('restaurant_tables', 'branch_id');
        $add('restaurant_waiters', 'branch_id'); $add('delivery_channels'); $add('delivery_riders', 'branch_id');
        $add('printers', 'branch_id'); $add('receipt_layout_settings', 'branch_id'); $add('category_printer_mappings', 'branch_id');
        $add('service_charge_settings', 'branch_id'); $add('void_reasons'); $add('roles'); $add('permissions');
        $add('branch_user', 'branch_id');
        // EDGE-LOCAL-RUNTIME-1 (K): recipe config is now part of the snapshot, so a recipe edit must
        // change the revision and trigger a fresh bootstrap.
        $add('recipes'); $add('recipe_ingredients'); $add('unit_conversions');

        $users = $conn->table('users')->whereIn('id', $userIds ?: [0]);
        $wm[] = 'users=' . (string) $users->max('updated_at') . '|' . $users->count();

        $tps = $conn->table('terminal_printer_settings')->whereIn('terminal_id', $termIds ?: [0]);
        $wm[] = 'terminal_printer_settings=' . (string) $tps->max('updated_at') . '|' . $tps->count();

        // HARDEN-2: hash model_id:role_id PAIRS (not role_id alone) so a role swap between two
        // branch users — same role multiset — still changes the revision.
        $pairs = $conn->table('model_has_roles')->where('model_type', \App\Models\Tenant\User::class)
            ->whereIn('model_id', $userIds ?: [0])->orderBy('model_id')->orderBy('role_id')->get(['model_id', 'role_id']);
        $wm[] = 'model_has_roles=' . implode(',', $pairs->map(fn ($r) => $r->model_id . ':' . $r->role_id)->all());
        $roleIds = $pairs->pluck('role_id')->unique()->all();
        $rolePermissions = $conn->table('role_has_permissions')->whereIn('role_id', $roleIds ?: [0])
            ->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id']);
        $wm[] = 'role_has_permissions=' . implode(',', $rolePermissions->map(fn ($r) => $r->role_id . ':' . $r->permission_id)->all());
        $directPermissions = $conn->table('model_has_permissions')->where('model_type', \App\Models\Tenant\User::class)
            ->whereIn('model_id', $userIds ?: [0])->orderBy('model_id')->orderBy('permission_id')->get(['model_id', 'permission_id']);
        $wm[] = 'model_has_permissions=' . implode(',', $directPermissions->map(fn ($r) => $r->model_id . ':' . $r->permission_id)->all());

        return hash('sha256', implode("\n", $wm));
    }

    /* ── deterministic branch-scoped sections (allowlists + coherence) ────── */

    protected function buildSections(Tenant $tenant, Branch $branch): array
    {
        $conn = DB::connection('tenant');
        $b = (int) $branch->id;
        $rows = fn ($q, array $cols) => collect($q->orderBy('id')->get($cols))->map(fn ($r) => (array) $r)->all();

        $terminalIds = $conn->table('terminals')->where('branch_id', $b)->pluck('id')->all();
        $groupIds    = $conn->table('modifier_groups')->where('branch_id', $b)->pluck('id')->all();

        // Included product set + included ACTIVE variant set (everything else is constrained to these).
        $productIds = $conn->table('products')->where('is_sellable', 1)->where('is_pos_visible', 1)->where('status', 'active')->pluck('id')->all();
        $pid = $productIds ?: [0];
        $variantRowsRaw = $conn->table('product_variants')->where('is_active', 1)->whereIn('product_id', $pid)->orderBy('id')->get(['id', 'product_id', 'sku', 'name', 'barcode', 'selling_price', 'is_default', 'is_active']);
        $variantIds = $variantRowsRaw->pluck('id')->all();
        $vid = $variantIds ?: [0];
        $variantOk = fn ($v) => $v === null || in_array((int) $v, $variantIds, true);

        // Coherent combos: a combo ships only if EVERY component references an included product
        // AND (null variant OR included variant). Incoherent combos are dropped whole.
        $branchComboIds = $conn->table('combos')->where('branch_id', $b)->pluck('id')->all();
        $compRows = $conn->table('combo_components')->whereIn('combo_id', $branchComboIds ?: [0])->orderBy('id')->get(['id', 'combo_id', 'product_id', 'product_variant_id', 'quantity', 'sort_order']);
        $incoherentCombo = [];
        foreach ($compRows as $c) {
            if (! in_array((int) $c->product_id, $productIds, true) || ! $variantOk($c->product_variant_id)) {
                $incoherentCombo[$c->combo_id] = true;
            }
        }
        $coherentComboIds = array_values(array_filter($branchComboIds, fn ($id) => empty($incoherentCombo[$id])));
        $cid = $coherentComboIds ?: [0];

        // EDGE-LOCAL-RUNTIME-1 (Section K): recipe configuration for INCLUDED sellable products. This
        // sprint does NOT execute recipe stock — it only makes the config bootstrap CAPABLE of carrying
        // phase-1b recipe config. recipe_ingredients reference raw-material products/variants that are
        // not in the sellable set, so we EXPAND the shipped product/variant/unit coverage to keep the
        // import FK-coherent (parents before children); never a stock baseline.
        $recipeRows = $conn->table('recipes')->where('is_active', 1)->whereIn('product_id', $pid)->orderBy('id')
            ->get(['id', 'product_id', 'name', 'yield_quantity', 'yield_unit_id', 'is_active', 'notes']);
        $recipeIds = $recipeRows->pluck('id')->all();
        $ingredientRows = $conn->table('recipe_ingredients')->whereIn('recipe_id', $recipeIds ?: [0])->orderBy('id')
            ->get(['id', 'recipe_id', 'product_id', 'product_variant_id', 'quantity', 'unit_id', 'cost_override', 'sort_order']);
        $ingredientProductIds = $ingredientRows->pluck('product_id')->filter()->map(fn ($x) => (int) $x)->unique()->values()->all();
        $ingredientVariantIds = $ingredientRows->pluck('product_variant_id')->filter()->map(fn ($x) => (int) $x)->unique()->values()->all();

        // Full product/variant coverage the appliance must know about = sellable + recipe raw materials.
        $allProductIds = array_values(array_unique(array_merge($productIds, $ingredientProductIds)));
        $apid = $allProductIds ?: [0];
        $ingredientVariantRows = $ingredientVariantIds
            ? $conn->table('product_variants')->whereIn('id', $ingredientVariantIds)->orderBy('id')
                ->get(['id', 'product_id', 'sku', 'name', 'barcode', 'selling_price', 'is_default', 'is_active'])
            : collect();
        $allVariantRows = collect($variantRowsRaw)->concat($ingredientVariantRows)->unique('id')->values();

        // unit_conversions are tenant-global; ship all, and make sure every unit a recipe/ingredient/
        // conversion references is in the units section (active units may not cover raw-material units).
        $unitConversionRows = $conn->table('unit_conversions')->orderBy('id')->get(['id', 'from_unit_id', 'to_unit_id', 'factor']);
        $referencedUnitIds = collect()
            ->concat($recipeRows->pluck('yield_unit_id'))
            ->concat($ingredientRows->pluck('unit_id'))
            ->concat($unitConversionRows->pluck('from_unit_id'))
            ->concat($unitConversionRows->pluck('to_unit_id'))
            ->filter()->map(fn ($x) => (int) $x)->unique()->values()->all();

        return [
            'tenant' => [['tenant_code' => $tenant->tenant_code, 'business_name' => $tenant->business_name, 'currency_code' => $tenant->currency_code]],
            'branch' => [[
                'id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name, 'business_type' => $branch->business_type,
                'allow_negative_stock' => (bool) $branch->allow_negative_stock,
                'held_kot_cancellation_approval_mode' => $branch->held_kot_cancellation_approval_mode,
                'held_kot_line_cancellation_approval_mode' => $branch->held_kot_line_cancellation_approval_mode,
                'timezone' => $branch->timezone,
                'tax_registration_no' => $branch->tax_registration_no, 'is_tax_enabled' => (bool) $branch->is_tax_enabled,
                'show_tax_number_on_invoice' => (bool) $branch->show_tax_number_on_invoice, 'receipt_footer' => $branch->receipt_footer,
                'address' => $branch->address, 'phone' => $branch->phone, 'email' => $branch->email, 'status' => $branch->status,
            ]],
            'terminals' => $rows($conn->table('terminals')->where('branch_id', $b)->where('status', 'active'),
                ['id', 'branch_id', 'code', 'name', 'device_identifier', 'requires_shift', 'status']),
            'categories' => $rows($conn->table('categories')->where('is_active', 1), ['id', 'parent_id', 'code', 'name', 'slug', 'sort_order', 'is_active']),
            'units' => $rows($conn->table('units')->where(fn ($q) => $q->where('is_active', 1)->orWhereIn('id', $referencedUnitIds ?: [0])),
                ['id', 'code', 'name', 'unit_type', 'base_factor', 'is_base', 'is_active']),
            // Products = sellable set PLUS recipe raw-material products (bare config rows), so
            // recipe_ingredients import FK-coherently. Sellable-only sections (variants/barcodes/prices/
            // modifiers/combos) below stay keyed on the sellable set and are unchanged.
            'products' => $rows($conn->table('products')->whereIn('id', $apid),
                ['id', 'category_id', 'unit_id', 'sku', 'name', 'slug', 'product_type', 'item_kind', 'is_pos_visible',
                 'is_stock_tracked', 'has_variants', 'default_selling_price', 'is_taxable', 'tax_rate_percent', 'image_path', 'status']),
            'product_variants' => $allVariantRows->map(fn ($r) => (array) $r)->all(),
            'product_barcodes' => $rows($conn->table('product_barcodes')->whereIn('product_id', $pid)
                ->where(fn ($q) => $q->whereNull('product_variant_id')->orWhereIn('product_variant_id', $vid)),
                ['id', 'product_id', 'product_variant_id', 'barcode', 'barcode_type', 'is_primary']),
            'product_branch_prices' => $rows($conn->table('product_branch_prices')->where('branch_id', $b)->whereIn('product_id', $pid)
                ->where(fn ($q) => $q->whereNull('product_variant_id')->orWhereIn('product_variant_id', $vid)),
                ['id', 'branch_id', 'product_id', 'product_variant_id', 'selling_price', 'minimum_selling_price', 'is_available']),
            'modifier_groups' => $rows($conn->table('modifier_groups')->where('branch_id', $b),
                ['id', 'branch_id', 'name', 'min_select', 'max_select', 'is_required', 'sort_order', 'status']),
            // Modifiers only ship when their linked product (if any) is included.
            'modifiers' => $rows($conn->table('modifiers')->whereIn('modifier_group_id', $groupIds ?: [0])
                ->where(fn ($q) => $q->whereNull('linked_product_id')->orWhereIn('linked_product_id', $pid)),
                ['id', 'modifier_group_id', 'name', 'price_delta', 'linked_product_id', 'consume_stock', 'linked_quantity', 'linked_unit_id', 'is_default', 'sort_order', 'status']),
            'combos' => $rows($conn->table('combos')->where('branch_id', $b)->whereIn('id', $cid),
                ['id', 'branch_id', 'code', 'name', 'price', 'sort_order', 'status', 'description']),
            'combo_components' => $rows($conn->table('combo_components')->whereIn('combo_id', $cid),
                ['id', 'combo_id', 'product_id', 'product_variant_id', 'quantity', 'sort_order']),
            'payment_methods' => $rows($conn->table('payment_methods')->where('is_active', 1)->whereIn('method_type', self::PHASE1_PAYMENT_TYPES),
                ['id', 'code', 'name', 'method_type', 'requires_reference', 'is_cash_drawer', 'is_active']),
            'restaurant_floors' => $rows($conn->table('restaurant_floors')->where('branch_id', $b), ['id', 'branch_id', 'name', 'code', 'status', 'sort_order']),
            'restaurant_tables' => $rows($conn->table('restaurant_tables')->where('branch_id', $b), ['id', 'branch_id', 'restaurant_floor_id', 'table_no', 'name', 'capacity', 'status', 'sort_order']),
            'restaurant_waiters' => $rows($conn->table('restaurant_waiters')->where('branch_id', $b), ['id', 'branch_id', 'name', 'code', 'phone', 'status']),
            'delivery_channels' => $rows($conn->table('delivery_channels')->where('is_active', 1)->whereIn('type', self::OFFLINE_DELIVERY_TYPES),
                ['id', 'name', 'type', 'commission_percent', 'is_active', 'sort_order']),
            'delivery_riders' => $rows($conn->table('delivery_riders')->where('branch_id', $b), ['id', 'branch_id', 'name', 'phone', 'status']),
            // EDGE-LOCAL-PRINT-1 (§16) ROUTING PARITY: Cloud PrintRoutingService selects printers with
            // branch_id = branch OR branch_id IS NULL (global defaults). The export must preserve that
            // SAME effective set — a branch-only filter silently dropped global printers, making Edge
            // routing fall back to browser where Cloud would have picked a real device.
            'printers' => $rows($conn->table('printers')->where(fn ($q) => $q->where('branch_id', $b)->orWhereNull('branch_id')),
                ['id', 'branch_id', 'name', 'code', 'printer_type', 'print_role', 'supports_reminder', 'ip_address', 'port', 'paper_size', 'characters_per_line', 'is_default', 'is_active', 'agent_enabled']),
            'receipt_layout_settings' => $rows($conn->table('receipt_layout_settings')->where('branch_id', $b),
                ['id', 'branch_id', 'document_type', 'paper_size', 'show_logo', 'show_branch_name', 'show_branch_address', 'show_branch_phone', 'show_tax_number',
                 'show_cashier_name', 'show_customer_name', 'show_table_info', 'show_order_no', 'show_order_time', 'show_updated_time', 'show_print_time', 'show_item_codes', 'show_payment_breakdown', 'header_text', 'footer_text', 'font_size', 'kot_font_size', 'is_active']),
            // same §16 parity rule: routing accepts branch-or-GLOBAL mappings.
            'category_printer_mappings' => $rows($conn->table('category_printer_mappings')->where(fn ($q) => $q->where('branch_id', $b)->orWhereNull('branch_id')), ['id', 'branch_id', 'category_id', 'printer_id', 'print_role', 'order_type', 'reminder_confirm_on_addition', 'is_active']),
            'terminal_printer_settings' => $rows($conn->table('terminal_printer_settings')->whereIn('terminal_id', $terminalIds ?: [0]), ['id', 'terminal_id', 'receipt_printer_id', 'kot_printer_id', 'auto_print_receipt', 'auto_print_kot']),
            'service_charge_settings' => $rows($conn->table('service_charge_settings')->where('branch_id', $b), ['id', 'branch_id', 'charge_type', 'charge_value', 'order_types', 'is_taxable', 'is_active']),
            'void_reasons' => $rows($conn->table('void_reasons')->where('is_active', 1), ['id', 'name', 'reason_type', 'requires_manager_approval', 'is_active']),
            // EDGE-LOCAL-RUNTIME-1 (Section K) — recipe CONFIG (no stock execution this sprint).
            'recipes' => $recipeRows->map(fn ($r) => (array) $r)->all(),
            'recipe_ingredients' => $ingredientRows->map(fn ($r) => (array) $r)->all(),
            'unit_conversions' => $unitConversionRows->map(fn ($r) => (array) $r)->all(),
            'users' => $this->userSection($conn, $b),
            'roles' => $rows($conn->table('roles')->where('guard_name', 'tenant'), ['id', 'name', 'guard_name']),
            'restrictions' => [[
                'phase' => 'offline-v1', 'blocked_payment_types' => ['card', 'wallet', 'bank_transfer', 'cheque', 'customer_credit'],
                'allowed_payment_types' => self::PHASE1_PAYMENT_TYPES, 'blocked_delivery_types' => ['aggregator'],
                'allowed_delivery_types' => self::OFFLINE_DELIVERY_TYPES,
                'held_kot_cancellation' => $branch->held_kot_cancellation_approval_mode === Branch::KOT_CANCELLATION_AUTO_APPROVE
                    ? 'auto_approve'
                    : 'blocked_requires_online_manager',
                'blocked_capabilities' => ['card_gateway', 'external_aggregator_api', 'customer_credit_ledger', 'returns_against_cloud', 'purchasing', 'stock_operations', 'manufacturing', 'cloud_manager_approval'],
            ]],
        ];
    }

    private function userSection($conn, int $branchId): array
    {
        $assignedUserIds = $conn->table('branch_user')->where('branch_id', $branchId)->where('is_active', 1)->pluck('user_id');
        $users = $conn->table('users')
            ->where(fn ($query) => $query->where('default_branch_id', $branchId)->orWhereIn('id', $assignedUserIds))
            ->orderBy('id')
            ->get(['id', 'employee_code', 'name', 'default_branch_id', 'default_terminal_id', 'allowed_order_types', 'default_order_type', 'status', 'locale']);
        $userIds = $users->pluck('id')->all();
        $roleMap = [];
        $roleIdsByUser = [];
        $permissionMap = [];
        if ($userIds) {
            foreach ($conn->table('model_has_roles as mhr')->join('roles as r', 'r.id', '=', 'mhr.role_id')
                ->where('mhr.model_type', \App\Models\Tenant\User::class)->whereIn('mhr.model_id', $userIds)
                ->orderBy('r.name')->get(['mhr.model_id as uid', 'mhr.role_id', 'r.name as role']) as $a) {
                $roleMap[$a->uid][] = $a->role;
                $roleIdsByUser[$a->uid][] = (int) $a->role_id;
            }

            $allRoleIds = collect($roleIdsByUser)->flatten()->unique()->all();
            $permissionsByRole = [];
            foreach ($conn->table('role_has_permissions as rhp')->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
                ->whereIn('rhp.role_id', $allRoleIds ?: [0])->orderBy('p.name')
                ->get(['rhp.role_id', 'p.name']) as $permission) {
                $permissionsByRole[$permission->role_id][] = $permission->name;
            }
            foreach ($roleIdsByUser as $userId => $roleIds) {
                foreach ($roleIds as $roleId) {
                    $permissionMap[$userId] = array_merge($permissionMap[$userId] ?? [], $permissionsByRole[$roleId] ?? []);
                }
            }
            foreach ($conn->table('model_has_permissions as mhp')->join('permissions as p', 'p.id', '=', 'mhp.permission_id')
                ->where('mhp.model_type', \App\Models\Tenant\User::class)->whereIn('mhp.model_id', $userIds)
                ->orderBy('p.name')->get(['mhp.model_id as uid', 'p.name']) as $permission) {
                $permissionMap[$permission->uid][] = $permission->name;
            }
        }
        return collect($users)->map(fn ($u) => [
            'id' => $u->id, 'employee_code' => $u->employee_code, 'name' => $u->name, 'default_branch_id' => $u->default_branch_id,
            'default_terminal_id' => $u->default_terminal_id, 'status' => $u->status, 'locale' => $u->locale,
            'allowed_order_types' => json_decode($u->allowed_order_types ?: '[]', true) ?: array_keys(\App\Models\Tenant\User::ORDER_TYPES),
            'default_order_type' => $u->default_order_type,
            'roles' => $roleMap[$u->id] ?? [],
            'permissions' => collect($permissionMap[$u->id] ?? [])->unique()->sort()->values()->all(),
        ])->all();
    }

    /* ── determinism helpers ─────────────────────────────────────────────── */

    public function canonicalJson(mixed $data): string
    {
        return json_encode($this->canonicalize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalize(mixed $v): mixed
    {
        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map(fn ($x) => $this->canonicalize($x), $v);
            }
            ksort($v);
            return array_map(fn ($x) => $this->canonicalize($x), $v);
        }
        return $v;
    }

    private function manifestHash(EdgeBootstrapSnapshot $snapshot, array $summary): string
    {
        return $this->computeManifestHash(
            (string) $snapshot->schema_version,
            (string) $snapshot->public_uuid,
            (int) $snapshot->tenant_id,
            (int) $snapshot->branch_id,
            EdgeDevice::whereKey($snapshot->edge_device_id)->value('public_uuid'),
            $snapshot->activation_epoch !== null ? (int) $snapshot->activation_epoch : null,
            $snapshot->config_revision !== null ? (int) $snapshot->config_revision : null,
            self::CONFIG_SCHEMA_VERSION,
            $summary,
        );
    }

    /**
     * The ONE manifest-hash formula, used both when building a snapshot and by the local importer to
     * re-verify a package self-consistently. Any change here is a wire-contract change.
     * EDGE-CONFIG-REFRESH-1 (v5): config_revision + config_schema_version are INSIDE the hash so a
     * revision number can never be tampered with independently of the manifest.
     */
    public function computeManifestHash(
        string $schemaVersion,
        string $snapshotUuid,
        int $tenantId,
        int $branchId,
        ?string $deviceUuid,
        ?int $activationEpoch,
        ?int $configRevision,
        string $configSchemaVersion,
        array $sectionSummary,
    ): string {
        return hash('sha256', $this->canonicalJson([
            'schema_version' => $schemaVersion, 'snapshot_uuid' => $snapshotUuid,
            'tenant_id' => $tenantId, 'branch_id' => $branchId,
            'device_public_uuid' => $deviceUuid,
            'activation_epoch' => $activationEpoch,
            'config_revision' => $configRevision,
            'config_schema_version' => $configSchemaVersion,
            'sections' => $sectionSummary,
        ]));
    }

    public function audit(string $event, EdgeBootstrapSnapshot $snapshot): void
    {
        Log::info("[edge-bootstrap-audit] {$event}", ['snapshot' => $snapshot->public_uuid, 'tenant_id' => $snapshot->tenant_id, 'branch_id' => $snapshot->branch_id]);
    }
}
