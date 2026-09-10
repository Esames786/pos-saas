<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;

/**
 * OFFLINE EDGE — Q: WARM STANDBY FRESHNESS, Cloud side.
 *
 * What the Cloud tells the appliance on every accepted heartbeat so a standby can PROVE it is current:
 *   - cloud_config_revision / cloud_config_watermark — the branch's monotonic config revision (allocated for the
 *     current content watermark; idempotent) — the appliance pulls a refresh when it is behind;
 *   - stock_watermark / stock_as_of — a content hash of the branch's authoritative sellable position — the appliance
 *     pulls a fresh baseline when its accepted baseline does not equal it.
 * Pure reads on the Cloud's own truth (config tables, official stock_balances); never Edge-provisional data.
 * The tenant must be active (the heartbeat controller activates it).
 */
class EdgeStandbyAdvertiser
{
    public function __construct(
        private readonly EdgeBootstrapService $bootstrap,
        private readonly EdgeBaselineIssuanceService $baselines,
        private readonly EdgeReturnableSaleProjectionService $returnable,
    ) {
    }

    /** @return array{cloud_config_revision:?int, cloud_config_watermark:?string, stock_watermark:?string, stock_as_of:?string, returnable_watermark:?string, returnable_as_of:?string} */
    public function forDevice(EdgeDevice $device, Tenant $tenant): array
    {
        $branch = Branch::on('tenant')->find((int) $device->branch_id);
        if (! $branch) {
            return ['cloud_config_revision' => null, 'cloud_config_watermark' => null, 'stock_watermark' => null, 'stock_as_of' => null, 'returnable_watermark' => null, 'returnable_as_of' => null];
        }
        $config = $this->bootstrap->currentConfigRevision($tenant, $branch);
        $stock = $this->baselines->stockWatermark((int) $branch->id);
        $returnable = $this->returnable->watermark((int) $branch->id);

        return [
            'cloud_config_revision' => (int) $config['revision'],
            'cloud_config_watermark' => (string) $config['watermark'],
            'stock_watermark' => (string) $stock['stock_watermark'],
            'stock_as_of' => (string) $stock['as_of'],
            // F1 — the returnable-sale position (sales, returned quantities, posted returns) the standby must mirror.
            'returnable_watermark' => (string) $returnable['watermark'],
            'returnable_as_of' => (string) $returnable['as_of'],
        ];
    }
}
