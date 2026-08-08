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
    protected function bindEdgeLocalMeta(int $branchId, int $activationEpoch = 1, int $tenantId = 42, string $deviceUuid = 'test-device-uuid'): void
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
            'runtime_state' => EdgeLocalMeta::STATE_BOOTSTRAPPED,
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // EdgeBranchContext may memoise the binding — resolve a fresh instance so the new row is picked up.
        app()->forgetInstance(EdgeBranchContext::class);
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
