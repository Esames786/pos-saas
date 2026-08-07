<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section S + L) — the Branch Server local-runtime readiness report.
 *
 * Distinguishes RUNTIME-FOUNDATION readiness (boundary + local DB + bootstrap binding) from SELLING
 * readiness, which does not exist yet. It NEVER reports ready_to_sell / operational_stock ready:
 * a stale bootstrap config quantity must never become the selling authority (that requires the
 * future activation fence: Cloud baseline @ revision R + activation generation + ack cursor).
 */
class EdgeLocalReadiness
{
    public function __construct(private readonly EdgeBranchContext $context = new EdgeBranchContext())
    {
    }

    /** True if the Edge-local database has been provisioned (edge_local_meta exists). */
    public function localDatabaseReady(): bool
    {
        if (! EdgeRuntime::isBranchServer()) {
            return false;
        }
        try {
            return Schema::connection('tenant')->hasTable('edge_local_meta');
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $dbReady = $this->localDatabaseReady();
        $bound = $dbReady && $this->context->isBound();

        $foundationReady = $dbReady && $bound; // runtime_boundary is always implemented on a branch_server

        return [
            'runtime_mode' => EdgeRuntime::mode(),

            // Foundation checks owned by THIS sprint.
            'runtime_boundary' => 'ready',
            'local_database' => $dbReady ? 'ready' : 'not_ready',
            'bootstrap_binding' => $bound ? 'ready' : 'not_ready',

            // Non-secret facts about the binding (null until bootstrapped).
            'tenant_code' => $this->context->boundTenantCode(),
            'branch_id' => $this->context->boundBranchId(),
            'activation_epoch' => $this->context->activationEpoch(),
            'config_revision' => $this->context->configRevision(),
            'bootstrap_schema' => $this->context->current()?->bootstrap_schema,

            // Config vs operational-stock: config may be imported, but stock is NEVER ready here.
            'config_ready' => $bound,
            'operational_stock' => 'not_ready',

            // Capabilities that do not exist yet — reported honestly, never faked.
            'local_auth' => 'not_implemented',       // EDGE-LOCAL-AUTH-1
            'local_pos' => 'not_implemented',        // EDGE-LOCAL-POS-1
            'local_print' => 'not_implemented',      // EDGE-LOCAL-PRINT-1
            'sync' => 'not_implemented',             // OFFLINE-SYNC-ENGINE-1

            // The runtime FOUNDATION may be ready; the appliance still cannot sell.
            'foundation_ready' => $foundationReady,
            'activation_ready' => false,
            'runtime_state' => $this->safeRuntimeState($dbReady),
        ];
    }

    private function safeRuntimeState(bool $dbReady): string
    {
        if (! $dbReady) {
            return EdgeLocalMeta::STATE_UNINITIALIZED;
        }
        try {
            return EdgeLocalMeta::current()?->runtime_state ?? EdgeLocalMeta::STATE_UNINITIALIZED;
        } catch (Throwable $e) {
            return EdgeLocalMeta::STATE_UNINITIALIZED;
        }
    }
}
