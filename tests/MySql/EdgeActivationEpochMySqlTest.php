<?php

namespace Tests\MySql;

use App\Models\Master\EdgeDevice;
use App\Services\Edge\EdgeActivationEpochService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * EDGE-LOCAL-RUNTIME-1 (fix 3 + 6) — activation-generation allocation is device-authoritative and
 * monotonic: a revoked/replaced device can never bump the epoch after losing authority, the same
 * device retries to the same generation, and a new authoritative device gets a strictly newer one.
 */
class EdgeActivationEpochMySqlTest extends MySqlTenantTestCase
{
    private int $tenantId = 7;
    private int $branchId = 3;

    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('master')->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['edge_branch_activations', 'edge_devices'] as $t) {
            DB::connection('master')->table($t)->delete();
        }
        DB::connection('master')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function device(string $uuid, int $slot = 1, string $status = EdgeDevice::STATUS_PENDING_BOOTSTRAP): EdgeDevice
    {
        return EdgeDevice::create([
            'public_uuid' => $uuid,
            'tenant_id' => $this->tenantId,
            'branch_id' => $this->branchId,
            'installation_uuid' => (string) Str::uuid(),
            'device_secret_hash' => hash('sha256', $uuid),
            'status' => $status,
            'active_slot' => $slot,
        ]);
    }

    private function service(): EdgeActivationEpochService
    {
        return app(EdgeActivationEpochService::class);
    }

    public function test_generation_is_device_authoritative_monotonic_and_idempotent(): void
    {
        $svc = $this->service();
        $a = $this->device('dev-A');

        // First allocation -> generation 1; same device retries -> still 1.
        $this->assertSame(1, $svc->allocateForDevice($a));
        $this->assertSame(1, $svc->allocateForDevice($a->fresh()));
        $this->assertSame(1, $svc->currentGeneration($this->tenantId, $this->branchId));

        // Replace device A with B: revoke A (frees slot), then B becomes the active device.
        $a->update(['status' => EdgeDevice::STATUS_REVOKED, 'active_slot' => null]);
        $b = $this->device('dev-B');

        // A stale request from the replaced device must NOT allocate a newer epoch.
        try {
            $svc->allocateForDevice($a->fresh());
            $this->fail('a revoked/replaced device must not allocate a generation');
        } catch (RuntimeException $e) {
            $this->assertTrue(true);
        }

        // The new authoritative device gets a STRICTLY newer generation.
        $this->assertSame(2, $svc->allocateForDevice($b));
        $this->assertSame(2, $svc->currentGeneration($this->tenantId, $this->branchId));

        // Exactly two generation rows, distinct + monotonic (no gap/dup).
        $gens = DB::connection('master')->table('edge_branch_activations')
            ->where('tenant_id', $this->tenantId)->where('branch_id', $this->branchId)
            ->orderBy('generation')->pluck('generation')->all();
        $this->assertSame([1, 2], array_map('intval', $gens));
    }

    public function test_old_epoch_is_stale_after_replacement(): void
    {
        $svc = $this->service();
        $a = $this->device('dev-A');
        $epochA = $svc->allocateForDevice($a); // 1

        $a->update(['status' => EdgeDevice::STATUS_REVOKED, 'active_slot' => null]);
        $b = $this->device('dev-B');
        $epochB = $svc->allocateForDevice($b); // 2

        // A snapshot minted under epoch A is no longer the current generation -> stale.
        $this->assertNotSame($epochA, $svc->currentGeneration($this->tenantId, $this->branchId));
        $this->assertSame($epochB, $svc->currentGeneration($this->tenantId, $this->branchId));
    }
}
