<?php

namespace App\Services\Sales;

use App\Models\Tenant\ServiceChargeSetting;

class ServiceChargeService
{
    public function calculate(int $branchId, string $orderType): array
    {
        $setting = ServiceChargeSetting::where('branch_id', $branchId)
            ->where('is_active', true)
            ->first();

        if (!$setting) {
            return ['service_charge_amount' => 0, 'is_taxable' => false];
        }

        if ($setting->order_types && !in_array($orderType, $setting->order_types, true)) {
            return ['service_charge_amount' => 0, 'is_taxable' => false];
        }

        return [
            'service_charge_amount' => 0,
            'is_taxable'            => (bool) $setting->is_taxable,
            'setting'               => $setting,
        ];
    }
}
