<?php

namespace App\Services\Kitchen;

use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use RuntimeException;

class UnitConversionService
{
    public function canConvert(Unit $fromUnit, Unit $toUnit): bool
    {
        if ($fromUnit->id === $toUnit->id) {
            return true;
        }

        return UnitConversion::where(function ($q) use ($fromUnit, $toUnit) {
            $q->where('from_unit_id', $fromUnit->id)->where('to_unit_id', $toUnit->id);
        })->orWhere(function ($q) use ($fromUnit, $toUnit) {
            $q->where('from_unit_id', $toUnit->id)->where('to_unit_id', $fromUnit->id);
        })->exists();
    }

    public function convert(float $amount, Unit $fromUnit, Unit $toUnit): float
    {
        if ($fromUnit->id === $toUnit->id) {
            return $amount;
        }

        $direct = UnitConversion::where('from_unit_id', $fromUnit->id)
            ->where('to_unit_id', $toUnit->id)
            ->first();

        if ($direct) {
            return $amount * (float) $direct->factor;
        }

        $inverse = UnitConversion::where('from_unit_id', $toUnit->id)
            ->where('to_unit_id', $fromUnit->id)
            ->first();

        if ($inverse && (float) $inverse->factor > 0) {
            return $amount / (float) $inverse->factor;
        }

        throw new RuntimeException(
            "No unit conversion found from [{$fromUnit->code}] to [{$toUnit->code}]."
        );
    }
}
