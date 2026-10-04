<?php

namespace Tests\MySql\Support;

use App\Models\Edge\EdgeLocalMeta;
use App\Services\Edge\EdgeBranchContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * EDGE-LOCAL-POS-1 — real Edge-local runtime harness for MySQL tests. Runs the REAL edge migrations onto the
 * tenant test connection (as `edge:local:db-init` does on a branch_server) and seeds a genuine
 * `edge_local_meta` binding, so `EdgeBranchContext::requireCurrent()` resolves the bound tenant/branch/device/
 * activation_epoch — never mocked, never passed manually into the service.
 */
trait EdgeLocalRuntimeFixture
{
    private static bool $edgeSchemaReady = false;

    /** Put the runtime into branch_server mode (EdgeBranchContext only binds on a branch_server). */
    protected function asBranchServerRuntime(): void
    {
        config(['app.role' => 'branch_server']);
    }

    protected function resetRuntimeRole(): void
    {
        config(['app.role' => null]);
    }

    /** Run the edge migrations onto the tenant test DB (idempotent, once per process). */
    protected function ensureEdgeSchema(): void
    {
        if (self::$edgeSchemaReady) {
            return;
        }
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        self::$edgeSchemaReady = true;
    }

    /** Seed (or replace) the singleton edge_local_meta binding for the given branch/epoch, and clear any cache. */
    protected function bindEdgeLocalMeta(int $branchId, int $activationEpoch = 1, int $tenantId = 42, string $deviceUuid = 'test-device-uuid', int $configRevision = 1): void
    {
        DB::connection('tenant')->table('edge_local_meta')->truncate();
        DB::connection('tenant')->table('edge_local_meta')->insert([
            'singleton_guard' => 1,
            'tenant_code' => 'edgepos',
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'device_uuid' => $deviceUuid,
            'activation_epoch' => $activationEpoch,
            'source_revision' => 'test-rev-1',
            // OFFLINE-SYNC-ENGINE-1B: the envelope builder freezes this per sale — a real binding always has it.
            'last_applied_config_revision' => $configRevision,
            'config_schema_version' => 'edge-config-v1',
            'runtime_state' => EdgeLocalMeta::STATE_BOOTSTRAPPED,
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // EdgeBranchContext may memoise the binding — resolve a fresh instance so the new row is picked up.
        app()->forgetInstance(EdgeBranchContext::class);
    }

    /**
     * Seed a genuine ACTIVE Edge local credential (what enrollment produces) so the slice-1.1
     * session-freshness guard (EnsureEdgeAuthenticated) accepts the user's session. Returns the row id.
     */
    /**
     * W-E (owner decision A6) — the canonical `Cashier (Counter)` template, PosPermissionCatalog::cashier(): every
     * permission the POS cashier workflows actually check at runtime (Edge endpoints enforce the SAME names as the
     * Online routes — W0b), which is also what TenantProvisioner gives a NEW tenant's cashier role. It replaces the
     * former route-derived list of 10 (the LAB drift). Every seeded cashier holds the whole set — a test that models a
     * restricted operator revokes the one it studies (revokeEdgePermission). NOTE: the set includes
     * tenant.pos.void-kot-item, which is the REQUESTER's void permission only — since Phase 3 (approver eligibility) a
     * seeded cashier is NOT an offline approver; a test models the approving manager with markPosApprover().
     *
     * @return list<string>
     */
    protected function onlinePosParityPermissions(): array
    {
        return \App\Support\Pos\PosPermissionCatalog::cashier();
    }

    protected function seedEdgeCredential(int $userId, int $branchId, int $activationEpoch = 1, string $password = 'CashierPass1'): int
    {
        // Every seeded cashier holds the synced Online cashier permission set (the effective per-user set the Cloud
        // exports) — a test that models a restricted operator revokes the permission it studies.
        foreach ($this->onlinePosParityPermissions() as $permission) {
            $this->grantEdgePermission($userId, $permission);
        }

        return (int) DB::connection('tenant')->table('edge_local_user_credentials')->insertGetId([
            'user_id' => $userId, 'branch_id' => $branchId, 'activation_epoch' => $activationEpoch,
            'credential_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'credential_type' => 'password', 'credential_version' => 1, 'status' => 'active',
            'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Phase 3 (approver eligibility): mark a bootstrapped user as an eligible offline approver — what the Cloud exports
     * as users[].may_approve_pos (active manager PIN AND active user, edge-bootstrap-v8) and the importer/applier store
     * in the Edge-only users.may_approve_pos column. EdgeLocalAuthService::verifyManager requires it; the approver must
     * ALSO hold the permission the approved action needs (seedEdgeCredential's template covers the cashier actions).
     */
    protected function markPosApprover(int $userId, bool $eligible = true): void
    {
        DB::connection('tenant')->table('users')->where('id', $userId)->update(['may_approve_pos' => $eligible ? 1 : 0]);
    }

    /** Grant a synced (spatie, tenant guard) permission directly to a user — idempotent across tests. */
    protected function grantEdgePermission(int $userId, string $permission): void
    {
        $conn = DB::connection('tenant');
        $permId = (int) ($conn->table('permissions')->where('name', $permission)->where('guard_name', 'tenant')->value('id')
            ?: $conn->table('permissions')->insertGetId(['name' => $permission, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]));
        $conn->table('model_has_permissions')->insertOrIgnore([
            'permission_id' => $permId, 'model_type' => \App\Models\Tenant\User::class, 'model_id' => $userId,
        ]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        // The acting user model may already have lazily loaded its permissions (every gated endpoint calls can());
        // a real request re-resolves the user from the session, so mirror that here by dropping the stale relations.
        $acting = auth('tenant')->user();
        if ($acting && (int) $acting->getKey() === $userId) {
            $acting->unsetRelation('permissions');
            $acting->unsetRelation('roles');
        }
    }

    /** Remove a DIRECT (spatie, tenant guard) permission from a user — the counterpart of grantEdgePermission. */
    protected function revokeEdgePermission(int $userId, string $permission): void
    {
        $conn = DB::connection('tenant');
        $permId = (int) $conn->table('permissions')->where('name', $permission)->where('guard_name', 'tenant')->value('id');
        $conn->table('model_has_permissions')->where('permission_id', $permId)
            ->where('model_type', \App\Models\Tenant\User::class)->where('model_id', $userId)->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $acting = auth('tenant')->user();
        if ($acting && (int) $acting->getKey() === $userId) {
            $acting->unsetRelation('permissions');
            $acting->unsetRelation('roles');
        }
    }

    /** Accept a TEST operational-stock baseline for the current binding. $items: [[product_id, variant, qty]]. */
    protected function acceptTestBaseline(array $items): object
    {
        $svc = app(\App\Services\Edge\EdgeOperationalBaselineService::class);

        return $svc->accept(
            \App\Services\Edge\EdgeOperationalBaselineService::newBaselineUuid(),
            \App\Services\Edge\EdgeOperationalBaselineService::hashItems($items),
            $items,
            'test-rev-1'
        );
    }

    /** Edge operational on-hand quantity for a product under the given baseline. */
    protected function edgeOnHand(int $baselineId, int $productId, ?int $variantId = null): float
    {
        return (float) \Illuminate\Support\Facades\DB::connection('tenant')
            ->table('edge_operational_stock_balances')
            ->where('balance_key', $baselineId . '-' . $productId . '-' . ($variantId ?: 0))
            ->value('quantity_on_hand');
    }
}
