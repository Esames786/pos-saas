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

        $result = DB::connection(self::CONN)->transaction(function () use ($manifest, $sections, $revision) {
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
            foreach (EdgeLocalBootstrapImporter::PLAN as [$section, $table, $branchScoped]) {
                $rows = $sections[$section] ?? [];
                $stats[$section] = match ($section) {
                    'branch' => $this->applyBranch($rows),
                    'categories' => $this->upsertCategories($rows),
                    'users' => $this->upsertUsersAndPermissionGraph($rows),
                    'restaurant_tables' => $this->upsertSection($section, $table, $rows, mergeTableStatus: true),
                    default => $this->upsertSection($section, $table, $rows),
                };
            }

            $meta->update([
                'last_applied_config_revision' => $revision,
                'config_schema_version' => (string) ($manifest['config_schema_version'] ?? ''),
                'last_refresh_snapshot_uuid' => (string) ($manifest['snapshot_uuid'] ?? ''),
                'last_refreshed_at' => now(),
                'source_revision' => (string) ($manifest['source_revision'] ?? ''),
                'manifest_hash' => $manifestHash,
                'last_error' => null,
            ]);

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
