<?php

namespace Tests\Feature\Edge;

use App\Models\Master\EdgeBootstrapSnapshot;
use Tests\TestCase;

/**
 * P4 — regression guard for a Cloud bug the clean-machine install proof caught: the bootstrap snapshot row must carry
 * the monotonic config_revision (EDGE-CONFIG-REFRESH-1 v5). It was allocated but never mass-assignable, so every REAL
 * snapshot minted through POST /api/edge/bootstrap/snapshots had config_revision NULL and the appliance importer refused
 * the package with CONFIG_REVISION_MISSING — a first-boot blocker no in-process fixture ever hit.
 */
class EdgeBootstrapSnapshotContractTest extends TestCase
{
    public function test_the_snapshot_row_carries_its_activation_epoch_and_config_revision(): void
    {
        $snapshot = new EdgeBootstrapSnapshot();
        foreach (['activation_epoch', 'config_revision', 'source_revision', 'schema_version', 'manifest_hash'] as $field) {
            $this->assertTrue($snapshot->isFillable($field), "EdgeBootstrapSnapshot must mass-assign {$field}");
        }
        $snapshot->fill(['config_revision' => '7', 'activation_epoch' => '2']);
        $this->assertSame(7, $snapshot->config_revision, 'config_revision is an integer attribute');
        $this->assertSame(2, $snapshot->activation_epoch);
    }
}
