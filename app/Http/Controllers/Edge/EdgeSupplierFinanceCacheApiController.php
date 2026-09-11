<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Services\Edge\EdgeSupplierFinanceProjectionService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OFFLINE EDGE — F2: device-authenticated, READ-ONLY. The warm supplier-finance projection the standby appliance pulls
 * when the heartbeat advertises a moved `supplier_finance_watermark`. Cloud-only controller (excluded from the artifact).
 */
class EdgeSupplierFinanceCacheApiController extends Controller
{
    public function __construct(private readonly EdgeSupplierFinanceProjectionService $projection, private readonly TenancyManager $tenancy)
    {
    }

    public function package(Request $request): JsonResponse
    {
        $device = $request->attributes->get('edgeDevice');
        $tenant = Tenant::find($device->tenant_id);
        if (! $tenant) {
            return response()->json(['status' => 'refused', 'failure_code' => 'WRONG_TENANT'], 403);
        }
        $this->tenancy->activate($tenant);
        try {
            $branch = Branch::on('tenant')->find((int) $device->branch_id);
            if (! $branch) {
                return response()->json(['status' => 'refused', 'failure_code' => 'BRANCH_UNKNOWN'], 409);
            }
            $package = $this->projection->package($branch);
        } finally {
            $this->tenancy->deactivate();
        }

        return response()->json(['status' => 'ok', 'package' => $package]);
    }
}
