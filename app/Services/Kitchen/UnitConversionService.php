<?php

namespace App\Services\Kitchen;

use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use App\Support\StandardUnits;
use RuntimeException;

/**
 * Converts a quantity between two units: the tenant's own conversion rows first (Kitchen → Unit Conversions), then the
 * conversions every shop agrees on (UB-08, 22 Oct: kg/g, litre/ml, metre/cm via StandardUnits). Anything else — a cup,
 * a packet, a pound — has no path and throws, so a sale that needs it is refused rather than guessed.
 */
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
        })->exists() || StandardUnits::factor($fromUnit, $toUnit) !== null;
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

        // UB-08: 250 G of a KG item is 0.25 KG without anyone typing a row. Recognised by code AND type; a typed
        // base_factor is never trusted (StandardUnits).
        $standard = StandardUnits::factor($fromUnit, $toUnit);
        if ($standard !== null) {
            return $amount * $standard;
        }

        throw new RuntimeException(
            "No unit conversion found from [{$fromUnit->code}] to [{$toUnit->code}]."
        );
    }
}
