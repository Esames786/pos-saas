<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;

/**
 * Q — WARM STANDBY FRESHNESS (Cloud side): what the Cloud advertises to a standby appliance on every heartbeat ACK —
 * the current config revision/watermark, the stock position watermark, the F1 returnable-sale watermark and the F2
 * supplier-finance watermark. The appliance compares them with what it holds and pulls only what moved.
 */
class EdgeStandbyAdvertiser
{
    public function __construct(
        private readonly EdgeBootstrapService $bootstrap,
        private readonly EdgeBaselineIssuanceService $baselines,
        private readonly EdgeReturnableSaleProjectionService $returnable,
        private readonly EdgeSupplierFinanceProjectionService $supplierFinance,
        private readonly EdgePurchaseReturnProjectionService $purchaseReturns,
    ) {
    }

    public function forDevice(EdgeDevice $device, Tenant $tenant): array
    {
        $branch = Branch::on('tenant')->find((int) $device->branch_id);
        if (! $branch) {
            return [
                'cloud_config_revision' => null, 'cloud_config_watermark' => null, 'stock_watermark' => null, 'stock_as_of' => null,
                'returnable_watermark' => null, 'returnable_as_of' => null, 'supplier_finance_watermark' => null, 'supplier_finance_as_of' => null,
                'purchase_return_watermark' => null, 'purchase_return_as_of' => null,
            ];
        }
        $config = $this->bootstrap->currentConfigRevision($tenant, $branch);
        $stock = $this->baselines->stockWatermark((int) $branch->id);
        $returnable = $this->returnable->watermark((int) $branch->id);
        $finance = $this->supplierFinance->watermark((int) $branch->id);
        $purchase = $this->purchaseReturns->watermark((int) $branch->id);

        return [
            'cloud_config_revision' => (int) $config['revision'],
            'cloud_config_watermark' => (string) $config['watermark'],
            'stock_watermark' => (string) $stock['stock_watermark'],
            'stock_as_of' => (string) $stock['as_of'],
            'returnable_watermark' => (string) $returnable['watermark'],
            'returnable_as_of' => (string) $returnable['as_of'],
            'supplier_finance_watermark' => (string) $finance['watermark'],
            'supplier_finance_as_of' => (string) $finance['as_of'],
            'purchase_return_watermark' => (string) $purchase['watermark'],
            'purchase_return_as_of' => (string) $purchase['as_of'],
        ];
    }
}
