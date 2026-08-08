<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EDGE-LOCAL-RUNTIME-1 (Sections 8/9/M/N/H/O/P) — import a Cloud bootstrap package into the Branch
 * Server's local database.
 *
 * Contract (locked):
 *  - schema v4 only;
 *  - re-verify the manifest hash + EVERY section hash (integrity/corruption — NOT cryptographic Cloud
 *    authenticity, which the authenticated download channel provides and a manual file copy does not);
 *  - verify tenant / branch / device / activation_epoch, and immutable binding (a different
 *    tenant/branch/device/epoch may never rebind an already-provisioned appliance);
 *  - required sections present; all branch-scoped rows carry the bound branch_id (cross-branch
 *    contamination => whole import rejected); no secret fields; NO Cloud operational history;
 *  - DDL is done beforehand by edge:local:db-init — this runs ONLY the data import, in ONE InnoDB
 *    transaction, parents before children, preserving Cloud IDs, WITHOUT ever disabling
 *    FOREIGN_KEY_CHECKS; any late failure rolls the whole import back.
 *  - initial bootstrap only: same package retried => idempotent no-op; a different config revision
 *    after a successful bootstrap => controlled REFRESH_NOT_IMPLEMENTED (never DELETE+INSERT).
 */
class EdgeLocalBootstrapImporter
{
    public const CONN = 'tenant';

    /** Sections that carry configuration but are informational here (validated, not table-imported). */
    private const INFORMATIONAL = ['tenant', 'restrictions'];

    /** Cloud operational-history sections that must NEVER appear in a config bootstrap. */
    private const FORBIDDEN_HISTORY = [
        'sales', 'sales_orders', 'sales_order_lines', 'sale_payments', 'payments',
        'journal_entries', 'journal_lines', 'stock_balances', 'stock_ledgers',
        'purchase_orders', 'grns', 'manufacturing_orders', 'shifts', 'cogs', 'fefo_layers',
    ];

    /** Field names that must never be present in any imported row (secret material). */
    private const SECRET_FIELDS = ['password', 'password_hash', 'remember_token', 'pin', 'pin_hash', 'manager_pin', 'device_secret', 'device_secret_hash', 'secret'];

    /**
     * Ordered import plan: [section, table, branchScoped]. Parents BEFORE children so real FKs are
     * satisfied without disabling FK checks. Sections not in this list (and not informational) are a
     * hard error — an unknown section must never be silently dropped.
     */
    private const PLAN = [
        ['branch', 'branches', false],               // the appliance's own branch row (id preserved)
        ['units', 'units', false],
        ['categories', 'categories', false],         // self-referential — topologically ordered on insert
        ['products', 'products', false],
        ['product_variants', 'product_variants', false],
        ['product_barcodes', 'product_barcodes', false],
        ['product_branch_prices', 'product_branch_prices', true],
        ['terminals', 'terminals', true],
        ['modifier_groups', 'modifier_groups', true],
        ['modifiers', 'modifiers', false],
        ['combos', 'combos', true],
        ['combo_components', 'combo_components', false],
        ['payment_methods', 'payment_methods', false],
        ['restaurant_floors', 'restaurant_floors', true],
        ['restaurant_tables', 'restaurant_tables', true],
        ['restaurant_waiters', 'restaurant_waiters', true],
        ['delivery_channels', 'delivery_channels', false],
        ['delivery_riders', 'delivery_riders', true],
        ['printers', 'printers', true],
        ['receipt_layout_settings', 'receipt_layout_settings', true],
        ['category_printer_mappings', 'category_printer_mappings', true],
        ['terminal_printer_settings', 'terminal_printer_settings', false],
        ['service_charge_settings', 'service_charge_settings', true],
        ['void_reasons', 'void_reasons', false],
        ['recipes', 'recipes', false],
        ['recipe_ingredients', 'recipe_ingredients', false],
        ['unit_conversions', 'unit_conversions', false],
        ['roles', 'roles', false],
        ['users', 'users', false],                   // roles/permissions arrays handled specially
    ];

    public function __construct(private readonly EdgeBootstrapService $bootstrap)
    {
    }

    /**
     * @param  array{manifest: array<string,mixed>, sections: array<string, array<int, array<string,mixed>>>}  $package
     */
    public function import(array $package, ?int $userId = null): EdgeLocalMeta
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Bootstrap import only runs on a Branch Server.');
        }
        EdgeLocalDatabase::assertSafeTarget();

        $manifest = $package['manifest'] ?? [];
        $sections = $package['sections'] ?? [];

        $this->assertSchema($manifest);
        $this->assertNoForbiddenHistory($sections);
        $this->verifyIntegrity($manifest, $sections);
        $this->assertRequiredSections($sections);

        $tenantId = (int) ($manifest['tenant_id'] ?? 0);
        $branchId = (int) ($manifest['branch_id'] ?? 0);
        $deviceUuid = (string) ($manifest['device_public_uuid'] ?? '');
        $epoch = $manifest['activation_epoch'] !== null ? (int) $manifest['activation_epoch'] : null;
        $tenantCode = (string) ($manifest['tenant_code'] ?? '');
        $snapshotUuid = (string) ($manifest['snapshot_uuid'] ?? '');
        $manifestHash = (string) ($manifest['manifest_hash'] ?? '');
        $sourceRevision = (string) ($manifest['source_revision'] ?? ($sections['__source_revision'] ?? ''));

        $this->assertBranchScoping($sections, $branchId);
        $this->assertNoSecretFields($sections);

        // Idempotency + immutability + refresh boundary (N/H).
        $existing = EdgeLocalMeta::current();
        if ($existing && $existing->runtime_state === EdgeLocalMeta::STATE_BOOTSTRAPPED) {
            $this->assertSameBinding($existing, $tenantId, $branchId, $deviceUuid, $epoch);
            if ((string) $existing->bootstrap_snapshot_uuid === $snapshotUuid
                && (string) $existing->manifest_hash === $manifestHash) {
                return $existing; // idempotent: same package already imported
            }
            // Same appliance, different config — refresh is a later sprint (never blind DELETE+INSERT).
            throw new RuntimeException('REFRESH_NOT_IMPLEMENTED: this appliance is already bootstrapped; config refresh is not implemented yet.');
        }

        return DB::connection(self::CONN)->transaction(function () use (
            $sections, $tenantId, $branchId, $deviceUuid, $epoch, $tenantCode, $snapshotUuid, $manifestHash, $sourceRevision, $manifest
        ) {
            $meta = $this->markImporting($tenantCode, $tenantId, $branchId, $deviceUuid, $epoch, $snapshotUuid, $manifestHash, $sourceRevision, $manifest);

            // Initial bootstrap: db-init ran the real tenant migrations, some of which SEED default
            // config rows (e.g. a default delivery channel / payment method). Clear the config tables
            // (children first) so the package's authoritative rows import without a PK collision. This
            // is DML inside the one transaction (no TRUNCATE/DDL, no FOREIGN_KEY_CHECKS toggling) and
            // applies to the fresh appliance only — it is NOT the blocked config-REFRESH path.
            $this->clearExistingConfig();

            foreach (self::PLAN as [$section, $table, $branchScoped]) {
                $rows = $sections[$section] ?? [];
                if ($rows === []) {
                    continue;
                }
                $section === 'categories'
                    ? $this->insertCategories($rows)
                    : ($section === 'users' ? $this->insertUsers($rows) : $this->insertRows($table, $rows));
            }

            $meta->update(['runtime_state' => EdgeLocalMeta::STATE_BOOTSTRAPPED, 'imported_at' => now(), 'last_error' => null]);

            return $meta->fresh();
        });
    }

    // ── validation ─────────────────────────────────────────────────────────

    private function assertSchema(array $manifest): void
    {
        $schema = (string) ($manifest['schema_version'] ?? '');
        if ($schema !== EdgeBootstrapService::SCHEMA_VERSION) {
            throw new RuntimeException("SCHEMA_UNSUPPORTED: package schema [{$schema}] is not " . EdgeBootstrapService::SCHEMA_VERSION . '.');
        }
    }

    private function assertNoForbiddenHistory(array $sections): void
    {
        foreach (array_keys($sections) as $name) {
            if (in_array($name, self::FORBIDDEN_HISTORY, true)) {
                throw new RuntimeException("Bootstrap must not carry Cloud operational history (section [{$name}]).");
            }
        }
    }

    /** Re-verify the manifest hash (self-consistency) and every section content hash (corruption). */
    private function verifyIntegrity(array $manifest, array $sections): void
    {
        $summary = $manifest['sections'] ?? [];

        foreach ($summary as $name => $meta) {
            $rows = $sections[$name] ?? null;
            if ($rows === null) {
                throw new RuntimeException("Missing section payload for [{$name}].");
            }
            $liveHash = hash('sha256', $this->bootstrap->canonicalJson($rows));
            if (! hash_equals((string) ($meta['hash'] ?? ''), $liveHash)) {
                throw new RuntimeException("SECTION_HASH_MISMATCH: section [{$name}] failed integrity verification.");
            }
            if ((int) ($meta['count'] ?? -1) !== count($rows)) {
                throw new RuntimeException("Section [{$name}] row count does not match the manifest.");
            }
        }

        $recomputed = $this->bootstrap->computeManifestHash(
            (string) $manifest['schema_version'],
            (string) $manifest['snapshot_uuid'],
            (int) $manifest['tenant_id'],
            (int) $manifest['branch_id'],
            $manifest['device_public_uuid'] ?? null,
            $manifest['activation_epoch'] !== null ? (int) $manifest['activation_epoch'] : null,
            $summary,
        );
        if (! hash_equals((string) ($manifest['manifest_hash'] ?? ''), $recomputed)) {
            throw new RuntimeException('HASH_MISMATCH: the manifest hash is not self-consistent.');
        }
    }

    private function assertRequiredSections(array $sections): void
    {
        foreach (['tenant', 'branch'] as $required) {
            if (empty($sections[$required])) {
                throw new RuntimeException("Required bootstrap section [{$required}] is missing.");
            }
        }
    }

    private function assertBranchScoping(array $sections, int $branchId): void
    {
        // The branch section itself must be exactly the bound branch.
        $branchRow = $sections['branch'][0] ?? null;
        if (! $branchRow || (int) ($branchRow['id'] ?? 0) !== $branchId) {
            throw new RuntimeException('Branch section does not match the manifest branch.');
        }
        foreach (self::PLAN as [$section, , $branchScoped]) {
            if (! $branchScoped) {
                continue;
            }
            foreach ($sections[$section] ?? [] as $row) {
                // A NULL branch_id row is GLOBAL/shared (e.g. a tenant-wide default printer or category
                // mapping the Cloud routing may select for this branch — §16 parity), not another
                // branch's data. Only a row bound to a DIFFERENT branch is a cross-branch violation.
                if (array_key_exists('branch_id', $row) && $row['branch_id'] !== null && (int) $row['branch_id'] !== $branchId) {
                    throw new RuntimeException("CROSS_BRANCH: section [{$section}] contains a row for branch " . $row['branch_id'] . " (bound branch is {$branchId}).");
                }
            }
        }
    }

    private function assertNoSecretFields(array $sections): void
    {
        foreach ($sections as $name => $rows) {
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach (self::SECRET_FIELDS as $secret) {
                    if (array_key_exists($secret, $row)) {
                        throw new RuntimeException("Bootstrap section [{$name}] contains a forbidden secret field [{$secret}].");
                    }
                }
            }
        }
    }

    private function assertSameBinding(EdgeLocalMeta $existing, int $tenantId, int $branchId, string $deviceUuid, ?int $epoch): void
    {
        $mismatch = (int) $existing->tenant_id !== $tenantId
            || (int) $existing->branch_id !== $branchId
            || (string) $existing->device_uuid !== $deviceUuid
            || (int) $existing->activation_epoch !== (int) $epoch;
        if ($mismatch) {
            throw new RuntimeException('BINDING_IMMUTABLE: a different tenant/branch/device/epoch cannot rebind this appliance.');
        }
    }

    // ── insertion ────────────────────────────────────────────────────────────

    private function markImporting(string $tenantCode, int $tenantId, int $branchId, string $deviceUuid, ?int $epoch, string $snapshotUuid, string $manifestHash, string $sourceRevision, array $manifest): EdgeLocalMeta
    {
        return EdgeLocalMeta::create([
            'tenant_code' => $tenantCode,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'device_uuid' => $deviceUuid,
            'activation_epoch' => $epoch,
            'bootstrap_snapshot_uuid' => $snapshotUuid,
            'bootstrap_schema' => (string) $manifest['schema_version'],
            'source_revision' => $sourceRevision,
            'manifest_hash' => $manifestHash,
            'runtime_state' => EdgeLocalMeta::STATE_IMPORTING,
        ]);
    }

    /** Clear migration-seeded config so the package's authoritative rows import cleanly (children first). */
    private function clearExistingConfig(): void
    {
        $conn = DB::connection(self::CONN);
        // Spatie graph (children first) — reconstructed on import (EDGE-LOCAL-AUTH-1).
        $conn->table('model_has_permissions')->where('model_type', \App\Models\Tenant\User::class)->delete();
        $conn->table('model_has_roles')->where('model_type', \App\Models\Tenant\User::class)->delete();
        $conn->table('role_has_permissions')->delete();
        $conn->table('permissions')->where('guard_name', 'tenant')->delete();
        foreach (array_reverse(self::PLAN) as [$section, $table, $branchScoped]) {
            $conn->table($table)->delete();
        }
    }

    private function insertRows(string $table, array $rows): void
    {
        $conn = DB::connection(self::CONN);
        foreach (array_chunk($rows, 200) as $chunk) {
            $conn->table($table)->insert($chunk);
        }
    }

    /** Categories self-reference parent_id — insert roots first, then each depth, so the FK holds. */
    private function insertCategories(array $rows): void
    {
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $inserted = [];
        $conn = DB::connection(self::CONN);

        $remaining = $rows;
        while ($remaining !== []) {
            $progress = false;
            $next = [];
            foreach ($remaining as $r) {
                $parent = $r['parent_id'] ?? null;
                if ($parent === null || isset($inserted[(int) $parent]) || ! isset($byId[(int) $parent])) {
                    $conn->table('categories')->insert($r);
                    $inserted[(int) $r['id']] = true;
                    $progress = true;
                } else {
                    $next[] = $r;
                }
            }
            if (! $progress) {
                throw new RuntimeException('Category hierarchy could not be ordered (cycle or missing parent).');
            }
            $remaining = $next;
        }
    }

    /**
     * Users carry denormalised roles/permissions arrays (no secrets — password/remember_token/PIN are
     * never exported). Import the user rows (WITHOUT a Cloud password — the column is nullable on the
     * Edge-local DB) and reconstruct the Spatie permission graph so $user->can()/hasRole() resolve
     * LOCALLY exactly as the auth gate needs (EDGE-LOCAL-AUTH-1):
     *   - model_has_roles from role names (for hasRole());
     *   - the user's EFFECTIVE permissions granted DIRECTLY (model_has_permissions) — identical
     *     effective can() behaviour without needing to export role_has_permissions (documented
     *     denormalisation; role->permission is empty locally, but the user-level gate is exact).
     */
    private function insertUsers(array $rows): void
    {
        $conn = DB::connection(self::CONN);
        $roleIdByName = $conn->table('roles')->where('guard_name', 'tenant')->pluck('id', 'name');

        // Upsert every distinct permission the bootstrap grants, then build name->id.
        $permNames = collect($rows)->flatMap(fn ($r) => $r['permissions'] ?? [])->filter()->unique()->values();
        foreach ($permNames as $name) {
            $conn->table('permissions')->insert(['name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        }
        $permIdByName = $conn->table('permissions')->where('guard_name', 'tenant')->pluck('id', 'name');

        foreach ($rows as $row) {
            $roles = $row['roles'] ?? [];
            $permissions = $row['permissions'] ?? [];
            unset($row['roles'], $row['permissions']);
            // Never a Cloud password on the appliance.
            unset($row['password'], $row['remember_token']);
            // allowed_order_types is a JSON column in the schema.
            if (isset($row['allowed_order_types']) && is_array($row['allowed_order_types'])) {
                $row['allowed_order_types'] = json_encode($row['allowed_order_types']);
            }
            $conn->table('users')->insert($row);

            foreach ($roles as $roleName) {
                if ($roleId = $roleIdByName[$roleName] ?? null) {
                    $conn->table('model_has_roles')->insert([
                        'role_id' => $roleId,
                        'model_type' => \App\Models\Tenant\User::class,
                        'model_id' => $row['id'],
                    ]);
                }
            }
            foreach ($permissions as $permName) {
                if ($permId = $permIdByName[$permName] ?? null) {
                    $conn->table('model_has_permissions')->insert([
                        'permission_id' => $permId,
                        'model_type' => \App\Models\Tenant\User::class,
                        'model_id' => $row['id'],
                    ]);
                }
            }
        }
    }
}
