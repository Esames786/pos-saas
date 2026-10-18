<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * EDGE-CONFIG-REFRESH-1 — apply a NEWER Cloud configuration revision to an ALREADY-BOOTSTRAPPED
 * Branch Server WITHOUT replacing its operational database.
 *
 * LOCKED CONTRACT (proven by ConfigRefreshFkSpikeTest): never full DELETE + INSERT. Per section:
 *   - existing stable ID  -> UPDATE the Cloud-authoritative config fields;
 *   - new stable ID       -> INSERT;
 *   - missing from the authoritative revision -> TOMBSTONE (the same is_active/status flag the local
 *     POS already filters on), or DELETE only for pure composition tables that nothing references
 *     (verified against the real FK graph: product_barcodes, product_branch_prices, combo_components,
 *     recipe_ingredients, terminal_printer_settings, unit_conversions have NO inbound FKs and are not
 *     resolved by historical rows — history snapshots names/prices on the sale line itself).
 *
 * Operational state is never touched: sales, held sales, open shifts, table sessions, KOT/print
 * history, operational stock, and local credentials all survive; historical FKs keep resolving
 * because tombstoned rows keep their IDs.
 *
 * restaurant_tables.status is SPECIAL: it carries live occupancy (available/occupied/bill_requested/
 * cleaning/reserved) that is LOCALLY owned after bootstrap. Refresh merges it: Cloud may disable a
 * table ('inactive') or re-enable a tombstoned one; otherwise the local occupancy wins.
 *
 * Revision rules (Cloud-authoritative monotonic revision, verified inside the manifest hash):
 *   - same revision + same manifest hash -> safe no-op (already applied);
 *   - same revision + different hash     -> refuse (REVISION_CONFLICT — one revision, one content);
 *   - older revision                     -> refuse (OLD_REVISION — config never rolls back);
 *   - newer revision (any gap)           -> apply. Gaps are explicitly safe because every revision
 *     carries the COMPLETE supported configuration set (no delta chaining); the jump is logged.
 *
 * Concurrency/atomicity: ONE database transaction whose first act is locking the edge_local_meta
 * singleton row (SELECT ... FOR UPDATE) — the single refresh authority. Two concurrent refreshes
 * serialise; the loser re-reads the applied revision under the lock and no-ops/refuses. Any failure
 * rolls the WHOLE apply back and last_applied_config_revision does NOT advance.
 *
 * KNOWN LIMITATION (fails SAFE, not silent): if the Cloud deletes a config row and later re-mints its
 * unique business key (e.g. a terminal code) under a NEW id, the local tombstoned row still holds the
 * key and the insert refuses on the unique index — the refresh rolls back whole and can be retried
 * after the Cloud renames. We deliberately do NOT rename tombstoned rows to dodge this: their
 * identity (code/sku/barcode) is exactly what historical records must keep resolving.
 */
class EdgeLocalConfigRefreshApplier
{
    public const CONN = 'tenant';

    public const OUTCOME_APPLIED = 'applied';
    public const OUTCOME_ALREADY_APPLIED = 'already_applied';

    /**
     * Per-section tombstone strategy for rows missing from the authoritative revision.
     *   ['flag', column, off-value] — deactivate via the flag the POS already filters on;
     *   ['delete']                  — pure composition rows with no inbound FKs (see class doc);
     *   ['none']                    — never tombstoned (the bound branch row).
     * Sections absent here (tenant, restrictions) are informational and not table-backed.
     * users/roles are handled by the dedicated permission-graph path but their tombstone/delete
     * strategy is declared here so the contract is explicit in one place.
     */
    private const TOMBSTONE = [
        'branch' => ['none'],
        'units' => ['flag', 'is_active', 0],
        'categories' => ['flag', 'is_active', 0],
        'products' => ['flag', 'status', 'inactive'],
        'product_variants' => ['flag', 'is_active', 0],
        'product_barcodes' => ['delete'],
        'product_branch_prices' => ['delete'],
        'terminals' => ['flag', 'status', 'inactive'],
        'modifier_groups' => ['flag', 'status', 'inactive'],
        'modifiers' => ['flag', 'status', 'inactive'],
        'product_modifier_group' => ['delete'],          // W6 v7: pure composition (no inbound FKs)
        // W6 v7: denominations are referenced by local cash_count_lines (FK cascade) — never deleted, only deactivated.
        'currencies' => ['flag', 'is_active', 0],
        'currency_denominations' => ['flag', 'is_active', 0],
        'combos' => ['flag', 'status', 'inactive'],
        'combo_components' => ['delete'],
        'payment_methods' => ['flag', 'is_active', 0],
        'restaurant_floors' => ['flag', 'status', 'inactive'],
        'restaurant_tables' => ['flag', 'status', 'inactive'],
        'restaurant_waiters' => ['flag', 'status', 'inactive'],
        'delivery_channels' => ['flag', 'is_active', 0],
        'delivery_riders' => ['flag', 'status', 'inactive'],
        'printers' => ['flag', 'is_active', 0],
        'receipt_layout_settings' => ['flag', 'is_active', 0],
        'category_printer_mappings' => ['flag', 'is_active', 0],
        'terminal_printer_settings' => ['delete'],
        'service_charge_settings' => ['flag', 'is_active', 0],
        'void_reasons' => ['flag', 'is_active', 0],
        'promotions' => ['flag', 'status', 'inactive'],
        'promotion_targets' => ['delete'],
        // PHASE 4: customers / addresses are matched by IDENTITY (customer_uuid / address_uuid) in their own engines
        // (upsertCustomers / upsertCustomerAddresses); the strategies below apply to CLOUD-origin rows only — a row born on
        // this appliance (edge_origin = 'local') that the Cloud has not sent back is NEVER tombstoned.
        'customers' => ['flag', 'status', 'inactive'],   // sales/reservations keep resolving; a retired customer is never offered
        'customer_addresses' => ['delete'],
        'customer_uuid_aliases' => ['delete'],
        'recipes' => ['flag', 'is_active', 0],
        'recipe_ingredients' => ['delete'],
        'unit_conversions' => ['delete'],
        'roles' => ['delete'],                       // graph is rebuilt; nothing else references roles
        'users' => ['flag', 'status', 'inactive'],   // login gate checks status; history keeps resolving
    ];

    /**
     * Apply a VALIDATED package (schema/integrity/binding/scoping already verified by the importer) to
     * a bootstrapped appliance. Returns the outcome and the refreshed meta.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<string, array<int, array<string,mixed>>>  $sections
     * @return array{outcome: string, meta: EdgeLocalMeta}
     */
    /** TEST-ONLY seam (see apply): a production no-op; a subclass may pause here to prove serialization. */
    protected function beforeConfigCommit(): void
    {
    }

    public function apply(array $manifest, array $sections): array
    {
        $revision = (int) ($manifest['config_revision'] ?? 0);
        if ($revision < 1) {
            throw new RuntimeException('CONFIG_REVISION_MISSING: a refresh package must carry a positive config_revision.');
        }
        // EDGE-BOOTSTRAP-SCHEMA-FOLLOWS-REFRESH-1: the applier is reached through the importer (which asserted the
        // package schema) AND directly by the standby freshness worker (which did not). Assert it here as well:
        // a package of another bootstrap generation is refused whole, never partially applied.
        $schema = (string) ($manifest['schema_version'] ?? '');
        if ($schema !== EdgeBootstrapService::SCHEMA_VERSION) {
            throw new RuntimeException("SCHEMA_UNSUPPORTED: package schema [{$schema}] is not " . EdgeBootstrapService::SCHEMA_VERSION . '.');
        }

        $result = DB::connection(self::CONN)->transaction(function () use ($manifest, $sections, $revision, $schema) {
            // THE refresh authority: the singleton binding row. Locking it first serialises every
            // concurrent refresh attempt; all decisions below happen under this lock.
            $meta = EdgeLocalMeta::query()->where('singleton_guard', EdgeLocalMeta::SINGLETON)->lockForUpdate()->first();
            if (! $meta || $meta->runtime_state !== EdgeLocalMeta::STATE_BOOTSTRAPPED) {
                throw new RuntimeException('REFRESH_NOT_BOOTSTRAPPED: config refresh requires a bootstrapped appliance.');
            }

            // Defence-in-depth: the importer verified binding before delegating, but re-verify under
            // the lock so a racing package for another tenant/branch/device/epoch can never slip in.
            if ((int) $meta->tenant_id !== (int) $manifest['tenant_id']
                || (int) $meta->branch_id !== (int) $manifest['branch_id']
                || (string) $meta->device_uuid !== (string) $manifest['device_public_uuid']
                || (int) $meta->activation_epoch !== (int) $manifest['activation_epoch']) {
                throw new RuntimeException('BINDING_IMMUTABLE: a different tenant/branch/device/epoch cannot refresh this appliance.');
            }

            $applied = $meta->last_applied_config_revision !== null ? (int) $meta->last_applied_config_revision : null;
            $manifestHash = (string) ($manifest['manifest_hash'] ?? '');

            if ($applied !== null && $revision < $applied) {
                throw new RuntimeException("OLD_REVISION: refusing config revision {$revision}; this appliance already applied revision {$applied}.");
            }
            if ($applied !== null && $revision === $applied) {
                if (hash_equals((string) $meta->manifest_hash, $manifestHash)) {
                    return ['outcome' => self::OUTCOME_ALREADY_APPLIED, 'meta' => $meta];
                }
                throw new RuntimeException("REVISION_CONFLICT: config revision {$revision} was already applied with different content; one revision must have exactly one content.");
            }
            if ($applied !== null && $revision > $applied + 1) {
                // Explicit gap handling: SAFE because every revision is the complete supported set.
                Log::info('[edge-config-refresh] revision_gap', ['from' => $applied, 'to' => $revision]);
            }

            $stats = [];
            $this->customerIdMap = [];
            foreach (EdgeLocalBootstrapImporter::PLAN as [$section, $table, $branchScoped]) {
                $rows = $sections[$section] ?? [];
                $stats[$section] = match ($section) {
                    'branch' => $this->applyBranch($rows),
                    'categories' => $this->upsertCategories($rows),
                    'users' => $this->upsertUsersAndPermissionGraph($rows),
                    'restaurant_tables' => $this->upsertSection($section, $table, $rows, mergeTableStatus: true),
                    // PHASE 4: identity-keyed customer book (see the three engines).
                    'customers' => $this->upsertCustomers($rows),
                    'customer_addresses' => $this->upsertCustomerAddresses($rows),
                    'customer_uuid_aliases' => $this->applyCustomerAliases($rows),
                    default => $this->upsertSection($section, $table, $rows),
                };
            }

            $meta->update([
                'last_applied_config_revision' => $revision,
                // After a software update the appliance still records the PREVIOUS build's bootstrap generation
                // (a 0.6.0 install carries edge-bootstrap-v6). The first revision applied by the new build is the
                // moment the local configuration conforms to the new generation, so record it here — otherwise the
                // SCHEMA_COMPATIBLE readiness gate could never recover after an update (found in the 0.7.0 dry-run).
                'bootstrap_schema' => $schema,
                'config_schema_version' => (string) ($manifest['config_schema_version'] ?? ''),
                'last_refresh_snapshot_uuid' => (string) ($manifest['snapshot_uuid'] ?? ''),
                'last_refreshed_at' => now(),
                'source_revision' => (string) ($manifest['source_revision'] ?? ''),
                'manifest_hash' => $manifestHash,
                'last_error' => null,
            ] + EdgeLocalBootstrapImporter::tenantMetaFields($sections));

            Log::info('[edge-config-refresh] applied', [
                'revision' => $revision, 'from_revision' => $applied,
                'snapshot' => $manifest['snapshot_uuid'] ?? null, 'stats' => $stats,
            ]);

            // TEST-ONLY seam: fires INSIDE the transaction, holding the meta X-lock + every config-row
            // X-lock, with the revision already bumped but UNCOMMITTED. Production body is an unconditional
            // no-op; only a test subclass overrides it (to pause on a barrier and prove a concurrent sale
            // genuinely serializes on these real locks). It cannot alter production behaviour.
            $this->beforeConfigCommit();

            return ['outcome' => self::OUTCOME_APPLIED, 'meta' => $meta->fresh()];
        });

        // Post-commit: the permission catalog/grants may have changed — a running process must not
        // keep authorizing from Spatie's cached pre-refresh permission set (revocation takes effect
        // immediately). After the commit only, so a rolled-back refresh never flushes anything.
        if ($result['outcome'] === self::OUTCOME_APPLIED) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $result;
    }

    // ── section engines ─────────────────────────────────────────────────────

    /** The bound branch row: UPDATE only (never inserted, never tombstoned; id verified upstream). */
    private function applyBranch(array $rows): array
    {
        $row = $rows[0] ?? null;
        if (! $row) {
            throw new RuntimeException('Required refresh section [branch] is missing.');
        }
        $conn = DB::connection(self::CONN);
        if (! $conn->table('branches')->where('id', (int) $row['id'])->exists()) {
            throw new RuntimeException('REFRESH_CORRUPT: the bound branch row is missing from the local database.');
        }
        $conn->table('branches')->where('id', (int) $row['id'])->update($this->mutable($row));

        return ['updated' => 1];
    }

    /**
     * Generic upsert + tombstone for one section: DELETE-missing first (frees unique keys, e.g. a
     * barcode moving to a new row), then UPDATE existing IDs, then INSERT new IDs.
     */
    private function upsertSection(string $section, string $table, array $rows, bool $mergeTableStatus = false): array
    {
        $conn = DB::connection(self::CONN);
        $existing = $conn->table($table)->pluck('id')->map(fn ($v) => (int) $v)->flip()->all();
        $payloadIds = array_map(fn ($r) => (int) $r['id'], $rows);
        $missing = array_values(array_diff(array_keys($existing), $payloadIds));

        $currentStatuses = $mergeTableStatus
            ? $conn->table($table)->pluck('status', 'id')->all()
            : [];

        [$tombstoned, $deleted] = $this->tombstoneMissing($section, $table, $missing);

        $updated = $inserted = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (isset($existing[$id])) {
                $data = $this->mutable($row);
                if ($mergeTableStatus) {
                    $data = $this->mergeOccupancyStatus($data, (string) ($currentStatuses[$id] ?? ''));
                }
                $conn->table($table)->where('id', $id)->update($data);
                $updated++;
            } else {
                $conn->table($table)->insert($row);
                $inserted++;
            }
        }

        return array_filter(['updated' => $updated, 'inserted' => $inserted, 'tombstoned' => $tombstoned, 'deleted' => $deleted]);
    }

    /**
     * restaurant_tables.status merge — occupancy is LOCALLY owned once the appliance operates:
     *   - Cloud says 'inactive'          -> adopt (Cloud disabled the table);
     *   - local row is tombstoned/'inactive' -> adopt the payload status (Cloud re-enabled it);
     *   - otherwise                      -> keep the local occupancy (drop status from the update).
     */
    private function mergeOccupancyStatus(array $data, string $currentStatus): array
    {
        $payloadStatus = (string) ($data['status'] ?? '');
        if ($payloadStatus === 'inactive' || $currentStatus === 'inactive') {
            return $data;
        }
        unset($data['status']);

        return $data;
    }

    /** Categories self-reference parent_id — order upserts so a NEW parent lands before its children. */
    private function upsertCategories(array $rows): array
    {
        $conn = DB::connection(self::CONN);
        $existing = $conn->table('categories')->pluck('id')->map(fn ($v) => (int) $v)->flip()->all();
        $payloadIds = array_map(fn ($r) => (int) $r['id'], $rows);
        $missing = array_values(array_diff(array_keys($existing), $payloadIds));
        [$tombstoned, ] = $this->tombstoneMissing('categories', 'categories', $missing);

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }

        $updated = $inserted = 0;
        $done = [];
        $remaining = $rows;
        while ($remaining !== []) {
            $progress = false;
            $next = [];
            foreach ($remaining as $r) {
                $id = (int) $r['id'];
                $parent = $r['parent_id'] ?? null;
                // A parent satisfies the FK if it is null, already in the DB, already processed this
                // run, or not part of the payload at all (then the insert fails loudly, as on import).
                $parentOk = $parent === null
                    || isset($existing[(int) $parent])
                    || isset($done[(int) $parent])
                    || ! isset($byId[(int) $parent]);
                if (! $parentOk) {
                    $next[] = $r;
                    continue;
                }
                if (isset($existing[$id])) {
                    $conn->table('categories')->where('id', $id)->update($this->mutable($r));
                    $updated++;
                } else {
                    $conn->table('categories')->insert($r);
                    $inserted++;
                }
                $done[$id] = true;
                $progress = true;
            }
            if (! $progress) {
                throw new RuntimeException('Category hierarchy could not be ordered (cycle or missing parent).');
            }
            $remaining = $next;
        }

        return array_filter(['updated' => $updated, 'inserted' => $inserted, 'tombstoned' => $tombstoned]);
    }

    /**
     * Users + the derived Spatie permission graph (EDGE-LOCAL-AUTH-1 denormalisation): upsert user
     * rows (never a Cloud password — local credentials live in edge_local_user_credentials and are
     * untouched), tombstone users missing from the revision (status 'inactive' — the login gate
     * refuses them), then REBUILD model_has_roles/model_has_permissions from the payload arrays. The
     * graph is fully derived config, referenced by nothing else, so rebuild ≠ the forbidden
     * DELETE+INSERT of referenced config.
     *
     * SECURITY CLOSURE (see docs/design/EDGE_OFFLINE_PERMISSION_AUTHORITY.md): the ONE effective
     * offline authorization authority is the per-user model_has_permissions exported by the Cloud.
     * Roles remain identity/group metadata (model_has_roles, for hasRole()); role_has_permissions is
     * cleared on EVERY refresh exactly as the initial import clears it — a stale role->permission row
     * must never re-grant, via Spatie's role->permission path, a permission the Cloud revoked.
     */
    private function upsertUsersAndPermissionGraph(array $rows): array
    {
        $conn = DB::connection(self::CONN);

        $existing = $conn->table('users')->pluck('id')->map(fn ($v) => (int) $v)->flip()->all();
        $payloadIds = array_map(fn ($r) => (int) $r['id'], $rows);
        $missing = array_values(array_diff(array_keys($existing), $payloadIds));
        [$tombstoned, ] = $this->tombstoneMissing('users', 'users', $missing);

        // Derived graph: rebuild wholesale from the authoritative payload. role_has_permissions must
        // be EMPTY on the appliance — never a second, independently-stale authority.
        $conn->table('role_has_permissions')->delete();
        $conn->table('model_has_permissions')->where('model_type', \App\Models\Tenant\User::class)->delete();
        $conn->table('model_has_roles')->where('model_type', \App\Models\Tenant\User::class)->delete();

        // Ensure every granted permission exists; prune tenant-guard permissions no longer granted
        // (they are referenced only by the graph we just rebuilt, so pruning is FK-safe).
        $granted = collect($rows)->flatMap(fn ($r) => $r['permissions'] ?? [])->filter()->unique()->values();
        $have = $conn->table('permissions')->where('guard_name', 'tenant')->pluck('name');
        foreach ($granted->diff($have) as $name) {
            $conn->table('permissions')->insert(['name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        }
        $conn->table('permissions')->where('guard_name', 'tenant')->whereNotIn('name', $granted->all())->delete();

        $roleIdByName = $conn->table('roles')->where('guard_name', 'tenant')->pluck('id', 'name');
        $permIdByName = $conn->table('permissions')->where('guard_name', 'tenant')->pluck('id', 'name');

        $updated = $inserted = 0;
        foreach ($rows as $row) {
            $roles = $row['roles'] ?? [];
            $permissions = $row['permissions'] ?? [];
            unset($row['roles'], $row['permissions'], $row['password'], $row['remember_token']);
            // Phase 3 (approver eligibility): Cloud-authoritative per revision — a PIN removed/disabled on the Cloud
            // revokes offline approval rights with the next applied revision (mutable() carries it to the UPDATE).
            $row['may_approve_pos'] = ! empty($row['may_approve_pos']) ? 1 : 0;
            if (isset($row['allowed_order_types']) && is_array($row['allowed_order_types'])) {
                $row['allowed_order_types'] = json_encode($row['allowed_order_types']);
            }

            $id = (int) $row['id'];
            if (isset($existing[$id])) {
                $conn->table('users')->where('id', $id)->update($this->mutable($row));
                $updated++;
            } else {
                $conn->table('users')->insert($row);
                $inserted++;
            }

            foreach ($roles as $roleName) {
                if ($roleId = $roleIdByName[$roleName] ?? null) {
                    $conn->table('model_has_roles')->insert([
                        'role_id' => $roleId, 'model_type' => \App\Models\Tenant\User::class, 'model_id' => $id,
                    ]);
                }
            }
            foreach ($permissions as $permName) {
                if ($permId = $permIdByName[$permName] ?? null) {
                    $conn->table('model_has_permissions')->insert([
                        'permission_id' => $permId, 'model_type' => \App\Models\Tenant\User::class, 'model_id' => $id,
                    ]);
                }
            }
        }

        return array_filter(['updated' => $updated, 'inserted' => $inserted, 'tombstoned' => $tombstoned]);
    }

    // ── PHASE 4 — the customer book, keyed by IDENTITY (docs/status/edge-phase4-offline-add-customer.md §4) ─────────────

    /** Cloud customer id → LOCAL customer id for the revision being applied (built by upsertCustomers, read by the addresses). @var array<int,int> */
    private array $customerIdMap = [];

    public const ORIGIN_LOCAL = 'local';
    public const ORIGIN_CLOUD = 'cloud';

    /**
     * THE REFRESH RULE for customers (owner: "refresh matches customer by UUID, not numeric id; never deactivate/overwrite
     * an offline-created customer merely because its numeric id differs"):
     *   1. a payload row whose customer_uuid exists locally (under ANY local id) UPDATES that local row in place — the local id
     *      is KEPT (ids are local-only; every cross-system reference travels by uuid), `edge_origin` becomes 'cloud'
     *      (the Cloud now owns the row); the Cloud id → local id mapping feeds the addresses pass;
     *   2. a payload row whose uuid is unknown locally INSERTS with its Cloud id (a locally-born row can never hold that id:
     *      local ids live in EdgeLocalCustomerService::LOCAL_ID_FLOOR …); an id held by a DIFFERENT identity is a refusal
     *      (the whole refresh rolls back — never a silent overwrite of one customer by another);
     *   3. a local row the payload does not carry: edge_origin 'cloud' → status 'inactive' (the Cloud retired it; history
     *      keeps resolving), edge_origin 'local' → UNTOUCHED (still active, still attachable; its sale carries it to the Cloud).
     *
     * IDENTITY OWNERSHIP (F1 fix, docs/status/edge-phase4-fix-address-identity-skew.md — owner: "after a Cloud upgrade the
     * appliance proceeds without manual data editing"): CLOUD-OWNED ROWS TAKE THE CLOUD'S IDENTITY. When the payload id is
     * held locally by a row that is NOT local-origin (edge_origin ≠ 'local': the bootstrap/refresh wrote it) its uuid is
     * ADOPTED from the Cloud in place — whether the local uuid is NULL (an appliance that took the identity column through
     * schema-upgrade leaves it NULL, see migration 2026_10_06_000001) or a stale/locally-minted value (an appliance upgraded
     * before that fix). The same applies to addresses (upsertCustomerAddresses). REFRESH_CONFLICT remains ONLY for a
     * LOCAL-ORIGIN row (edge_origin = 'local') that carries a different uuid under the Cloud's id — a real identity clash
     * (impossible with the reserved local id range, modelled deliberately) — and it still rolls the whole refresh back.
     * A customer adoption that changes a non-null uuid also repoints the soft uuid references (reservations) so nothing on
     * the appliance keeps the retired value.
     */
    private function upsertCustomers(array $rows): array
    {
        $conn = DB::connection(self::CONN);
        $local = $conn->table('customers')->get(['id', 'customer_uuid', 'edge_origin', 'status']);
        $byUuid = [];
        $byId = [];
        foreach ($local as $row) {
            $byId[(int) $row->id] = $row;
            if ($row->customer_uuid !== null && $row->customer_uuid !== '') {
                $byUuid[(string) $row->customer_uuid] = $row;
            }
        }

        $updated = $inserted = 0;
        $seen = [];
        foreach ($rows as $row) {
            $cloudId = (int) $row['id'];
            $uuid = (string) ($row['customer_uuid'] ?? '');
            $data = $this->mutable($row) + ['edge_origin' => self::ORIGIN_CLOUD];
            if ($uuid !== '' && isset($byUuid[$uuid])) {
                $localId = (int) $byUuid[$uuid]->id;
                $conn->table('customers')->where('id', $localId)->update($data);
                $this->customerIdMap[$cloudId] = $localId;
                $seen[$localId] = true;
                $updated++;
                continue;
            }
            if (isset($byId[$cloudId])) {
                $held = $byId[$cloudId];
                $heldUuid = (string) ($held->customer_uuid ?? '');
                if ($heldUuid !== '' && $heldUuid !== $uuid) {
                    if ((string) ($held->edge_origin ?? self::ORIGIN_CLOUD) === self::ORIGIN_LOCAL) {
                        throw new RuntimeException("REFRESH_CONFLICT: customer id {$cloudId} is held locally by a different customer identity; refusing to overwrite one customer with another.");
                    }
                    // a Cloud-owned row with a stale identity takes the Cloud's (class doc: identity ownership)
                    $this->repointCustomerUuid($heldUuid, $uuid);
                    Log::info('[edge-config-refresh] cloud-owned customer adopted the Cloud identity', ['id' => $cloudId, 'from' => $heldUuid, 'to' => $uuid]);
                }
                // a Cloud-owned row without an identity (NULL after schema-upgrade, or pre-G3) adopts the Cloud's
                $conn->table('customers')->where('id', $cloudId)->update($data);
                $this->customerIdMap[$cloudId] = $cloudId;
                $seen[$cloudId] = true;
                $updated++;
                continue;
            }
            $conn->table('customers')->insert($row + ['edge_origin' => self::ORIGIN_CLOUD]);
            $this->customerIdMap[$cloudId] = $cloudId;
            $seen[$cloudId] = true;
            $inserted++;
        }

        $tombstoned = $keptLocal = 0;
        foreach ($local as $row) {
            if (isset($seen[(int) $row->id])) {
                continue;
            }
            if ((string) ($row->edge_origin ?? self::ORIGIN_CLOUD) === self::ORIGIN_LOCAL) {
                $keptLocal++;   // born here, not seen back from the Cloud yet — never deactivated, never overwritten
                continue;
            }
            if ((string) $row->status !== 'inactive') {
                $tombstoned += $conn->table('customers')->where('id', (int) $row->id)->update(['status' => 'inactive']);
            }
        }

        return array_filter(['updated' => $updated, 'inserted' => $inserted, 'tombstoned' => $tombstoned, 'kept_local' => $keptLocal]);
    }

    /**
     * Addresses follow the same rule keyed by address_uuid; `customer_id` is MAPPED to the local customer id (the Cloud's
     * id for the parent may differ locally — rule 1 above). A local-origin address the payload does not carry is kept,
     * unless the Cloud now carries an address of the same customer with the SAME text (Online's storeAddressOnce reused
     * it on ingestion) — then the local copy is merged away (deleted) so the book does not show the address twice.
     *
     * IDENTITY OWNERSHIP (see upsertCustomers): a Cloud-origin local row under the Cloud's id ADOPTS the Cloud's address_uuid
     * in place — NULL (schema-upgraded appliance) or stale (an appliance whose Phase 4 migration minted one per row before the
     * F1 fix) alike; no second row, no deletion, no conflict. REFRESH_CONFLICT only for a LOCAL-ORIGIN row holding a
     * different uuid under that id. Nothing else on the appliance can hold a Cloud-origin address's stale uuid: the sale
     * envelope ships address_uuid only for LOCAL-origin addresses (EdgeSaleEnvelopeBuilder::customerIdentity), which are
     * minted once at creation and matched back by uuid (rule 1), never adopted — so the in-place update is complete.
     */
    private function upsertCustomerAddresses(array $rows): array
    {
        $conn = DB::connection(self::CONN);
        $local = $conn->table('customer_addresses')->get(['id', 'address_uuid', 'customer_id', 'address', 'edge_origin']);
        $byUuid = [];
        $byId = [];
        foreach ($local as $row) {
            $byId[(int) $row->id] = $row;
            if ($row->address_uuid !== null && $row->address_uuid !== '') {
                $byUuid[(string) $row->address_uuid] = $row;
            }
        }
        $fold = fn (string $text): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));

        $updated = $inserted = 0;
        $seen = [];
        $cloudTexts = [];   // local customer id → [folded address texts the Cloud carries]
        foreach ($rows as $row) {
            $cloudId = (int) $row['id'];
            $uuid = (string) ($row['address_uuid'] ?? '');
            $cloudCustomerId = (int) ($row['customer_id'] ?? 0);
            $localCustomerId = $this->customerIdMap[$cloudCustomerId] ?? null;
            if ($localCustomerId === null) {
                throw new RuntimeException("REFRESH_CORRUPT: address {$cloudId} references customer {$cloudCustomerId}, which this revision does not carry.");
            }
            $data = $this->mutable($row);
            $data['customer_id'] = $localCustomerId;
            $data['edge_origin'] = self::ORIGIN_CLOUD;
            $cloudTexts[$localCustomerId][] = $fold((string) ($row['address'] ?? ''));

            if ($uuid !== '' && isset($byUuid[$uuid])) {
                $localId = (int) $byUuid[$uuid]->id;
                $conn->table('customer_addresses')->where('id', $localId)->update($data);
                $seen[$localId] = true;
                $updated++;
                continue;
            }
            if (isset($byId[$cloudId])) {
                $held = $byId[$cloudId];
                $heldUuid = (string) ($held->address_uuid ?? '');
                if ($heldUuid !== '' && $heldUuid !== $uuid) {
                    if ((string) ($held->edge_origin ?? self::ORIGIN_CLOUD) === self::ORIGIN_LOCAL) {
                        throw new RuntimeException("REFRESH_CONFLICT: address id {$cloudId} is held locally by a different address identity.");
                    }
                    // a Cloud-owned row with a stale identity takes the Cloud's (class doc: identity ownership)
                    Log::info('[edge-config-refresh] cloud-owned address adopted the Cloud identity', ['id' => $cloudId, 'from' => $heldUuid, 'to' => $uuid]);
                }
                // NULL (schema-upgraded appliance) or already the Cloud's: update in place, the Cloud uuid lands with $data
                $conn->table('customer_addresses')->where('id', $cloudId)->update($data);
                $seen[$cloudId] = true;
                $updated++;
                continue;
            }
            $conn->table('customer_addresses')->insert($data + ['id' => $cloudId]);
            $seen[$cloudId] = true;
            $inserted++;
        }

        $deleted = $merged = $keptLocal = 0;
        foreach ($local as $row) {
            if (isset($seen[(int) $row->id])) {
                continue;
            }
            if ((string) ($row->edge_origin ?? self::ORIGIN_CLOUD) === self::ORIGIN_LOCAL) {
                if (in_array($fold((string) $row->address), $cloudTexts[(int) $row->customer_id] ?? [], true)) {
                    $merged += $conn->table('customer_addresses')->where('id', (int) $row->id)->delete();
                } else {
                    $keptLocal++;
                }
                continue;
            }
            $deleted += $conn->table('customer_addresses')->where('id', (int) $row->id)->delete();
        }

        return array_filter(['updated' => $updated, 'inserted' => $inserted, 'deleted' => $deleted, 'merged' => $merged, 'kept_local' => $keptLocal]);
    }

    /**
     * THE ALIAS RULE: the Cloud matched an offline-minted customer_uuid (alias) to an EXISTING customer by phone instead of
     * creating it. On the appliance that offline customer still exists as its own local row (edge_origin 'local'). The
     * alias book is upserted locally (deleted when the Cloud drops it) and each alias whose local row and canonical row
     * BOTH exist here is MERGED: every reference to the local row (FK children discovered from the schema + the soft
     * references: addresses, reservations by id AND by uuid, table sessions, reserved tables) is repointed to the
     * canonical row, same-text addresses are deduplicated, then the local row is deleted. A canonical customer this
     * appliance does not hold (inactive on the Cloud) leaves the local row untouched — history is never orphaned.
     */
    private function applyCustomerAliases(array $rows): array
    {
        $conn = DB::connection(self::CONN);
        $existing = $conn->table('customer_uuid_aliases')->pluck('alias_uuid')->flip()->all();
        $payloadUuids = [];
        $updated = $inserted = 0;
        foreach ($rows as $row) {
            $alias = (string) ($row['alias_uuid'] ?? '');
            if ($alias === '') {
                throw new RuntimeException('REFRESH_CORRUPT: an alias row without alias_uuid.');
            }
            $payloadUuids[$alias] = true;
            $data = $row;
            unset($data['alias_uuid']);
            // the Cloud's customer_id → this appliance's id for the same identity (rule 1 of upsertCustomers); unmapped = kept as is
            $data['customer_id'] = $this->customerIdMap[(int) ($row['customer_id'] ?? 0)] ?? (int) ($row['customer_id'] ?? 0);
            if (isset($existing[$alias])) {
                $conn->table('customer_uuid_aliases')->where('alias_uuid', $alias)->update($data + ['updated_at' => now()]);
                $updated++;
            } else {
                $conn->table('customer_uuid_aliases')->insert($data + ['alias_uuid' => $alias, 'created_at' => now(), 'updated_at' => now()]);
                $inserted++;
            }
        }
        $missing = array_values(array_diff(array_keys($existing), array_keys($payloadUuids)));
        $deleted = 0;
        foreach (array_chunk($missing, 500) as $chunk) {
            $deleted += $conn->table('customer_uuid_aliases')->whereIn('alias_uuid', $chunk)->delete();
        }

        $merged = 0;
        foreach ($rows as $row) {
            $aliasUuid = (string) $row['alias_uuid'];
            $canonicalUuid = (string) ($row['customer_uuid'] ?? '');
            $localRow = $conn->table('customers')->where('customer_uuid', $aliasUuid)->first(['id']);
            if (! $localRow) {
                continue;   // already merged, or the alias was minted from another appliance's offline customer
            }
            $canonical = $canonicalUuid !== '' ? $conn->table('customers')->where('customer_uuid', $canonicalUuid)->first(['id']) : null;
            if (! $canonical) {
                Log::info('[edge-config-refresh] alias canonical customer not on this appliance; local row kept', ['alias_uuid' => $aliasUuid]);
                continue;
            }
            if ((int) $canonical->id === (int) $localRow->id) {
                continue;
            }
            $this->mergeLocalCustomer((int) $localRow->id, (int) $canonical->id, $aliasUuid, $canonicalUuid);
            $merged++;
        }

        return array_filter(['updated' => $updated, 'inserted' => $inserted, 'deleted' => $deleted, 'merged' => $merged]);
    }

    /** Soft (no-FK) references to a customer on the appliance: table → [id columns, uuid columns]. Guarded by hasColumn at run time. */
    private const CUSTOMER_SOFT_REFERENCES = [
        'customer_addresses' => [['customer_id'], []],
        'edge_local_table_reservations' => [['customer_id'], ['customer_uuid']],
        'restaurant_table_sessions' => [['customer_id'], []],
        'restaurant_tables' => [['reserved_customer_id'], []],
    ];

    /**
     * A Cloud-owned customer row adopted a different customer_uuid (identity ownership): move the soft uuid references
     * (reservations carry customer_uuid for the Cloud hand-back) from the retired value to the Cloud's. Numeric references
     * (sales, sessions, tables) are untouched — the row keeps its id. Outbox envelopes are not rewritten: an envelope only
     * ever carried a Cloud-owned customer's uuid as the Cloud itself exported it, so a retired value cannot be in one.
     */
    private function repointCustomerUuid(string $fromUuid, string $toUuid): void
    {
        $conn = DB::connection(self::CONN);
        $schema = \Illuminate\Support\Facades\Schema::connection(self::CONN);
        foreach (self::CUSTOMER_SOFT_REFERENCES as $table => [, $uuidColumns]) {
            if ($uuidColumns === [] || ! $schema->hasTable($table)) {
                continue;
            }
            foreach ($uuidColumns as $column) {
                if ($schema->hasColumn($table, $column)) {
                    $conn->table($table)->where($column, $fromUuid)->update([$column => $toUuid]);
                }
            }
        }
    }

    private function mergeLocalCustomer(int $fromId, int $toId, string $fromUuid, string $toUuid): void
    {
        $conn = DB::connection(self::CONN);
        $schema = \Illuminate\Support\Facades\Schema::connection(self::CONN);

        // 1. every FK child of customers.id in THIS database (discovered, not guessed).
        $children = $conn->select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?',
            ['customers', 'id']
        );
        $repointed = [];
        foreach ($children as $child) {
            $conn->table((string) $child->t)->where((string) $child->c, $fromId)->update([(string) $child->c => $toId]);
            $repointed["{$child->t}.{$child->c}"] = true;
        }
        // 2. the soft references (no FK) — by id and by uuid.
        foreach (self::CUSTOMER_SOFT_REFERENCES as $table => [$idColumns, $uuidColumns]) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            foreach ($idColumns as $column) {
                if (! isset($repointed["{$table}.{$column}"]) && $schema->hasColumn($table, $column)) {
                    $conn->table($table)->where($column, $fromId)->update([$column => $toId]);
                }
            }
            foreach ($uuidColumns as $column) {
                if ($schema->hasColumn($table, $column)) {
                    $conn->table($table)->where($column, $fromUuid)->update([$column => $toUuid]);
                }
            }
        }
        // 3. the merged book: a moved local-origin address whose text the canonical customer already has is a duplicate.
        $fold = fn (string $text): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
        $book = $conn->table('customer_addresses')->where('customer_id', $toId)->orderBy('id')->get(['id', 'address', 'edge_origin', 'is_default']);
        $keep = [];
        foreach ($book as $address) {
            $key = $fold((string) $address->address);
            if (isset($keep[$key])) {
                $loser = (string) ($address->edge_origin ?? self::ORIGIN_CLOUD) === self::ORIGIN_LOCAL ? $address : $keep[$key];
                $winner = $loser->id === $address->id ? $keep[$key] : $address;
                $conn->table('customer_addresses')->where('id', (int) $loser->id)->delete();
                $keep[$key] = $winner;
                continue;
            }
            $keep[$key] = $address;
        }
        if ($conn->table('customer_addresses')->where('customer_id', $toId)->where('is_default', 1)->count() > 1) {
            $firstDefault = (int) $conn->table('customer_addresses')->where('customer_id', $toId)->where('is_default', 1)->orderBy('id')->value('id');
            $conn->table('customer_addresses')->where('customer_id', $toId)->where('is_default', 1)->where('id', '!=', $firstDefault)->update(['is_default' => 0]);
        }

        // 4. the local row has no references left — remove it (its identity lives on as the alias).
        $conn->table('customers')->where('id', $fromId)->delete();
        Log::info('[edge-config-refresh] local customer merged into canonical', ['alias_uuid' => $fromUuid, 'customer_uuid' => $toUuid, 'from_id' => $fromId, 'to_id' => $toId]);
    }

    /** Apply the section's tombstone strategy to the IDs missing from the revision. */
    private function tombstoneMissing(string $section, string $table, array $missing): array
    {
        $strategy = self::TOMBSTONE[$section] ?? null;
        if ($strategy === null) {
            throw new RuntimeException("No tombstone strategy declared for section [{$section}] — refusing to guess.");
        }
        if ($missing === [] || $strategy[0] === 'none') {
            return [0, 0];
        }

        $conn = DB::connection(self::CONN);
        if ($strategy[0] === 'delete') {
            $deleted = 0;
            foreach (array_chunk($missing, 500) as $chunk) {
                $deleted += $conn->table($table)->whereIn('id', $chunk)->delete();
            }

            return [0, $deleted];
        }

        [, $column, $off] = $strategy;
        $tombstoned = 0;
        foreach (array_chunk($missing, 500) as $chunk) {
            $tombstoned += $conn->table($table)->whereIn('id', $chunk)
                ->where($column, '!=', $off)
                ->update([$column => $off]);
        }

        return [$tombstoned, 0];
    }

    /** The Cloud-authoritative mutable fields of a payload row: everything it carries except the id. */
    private function mutable(array $row): array
    {
        unset($row['id']);

        return $row;
    }
}
