<?php

namespace Tests\Unit;

use App\Models\Tenant\Unit;
use App\Support\StandardUnits;
use PHPUnit\Framework\TestCase;

/**
 * UB-08 — only the conversions every shop agrees on (kg/g, litre/ml, metre/cm), recognised by code AND type; a
 * unit's saved base_factor is never trusted, and counted things never convert.
 */
class StandardUnitsTest extends TestCase
{
    public function test_weight_volume_and_length_convert_both_ways(): void
    {
        $this->assertSame(1000.0, StandardUnits::factor($this->u('KG', 'weight'), $this->u('G', 'weight')));
        $this->assertSame(0.001, StandardUnits::factor($this->u('G', 'weight'), $this->u('KG', 'weight')));
        $this->assertSame(1000.0, StandardUnits::factor($this->u('LTR', 'volume'), $this->u('ml', 'volume')), 'codes in any case');
        $this->assertSame(100.0, StandardUnits::factor($this->u('MTR', 'length'), $this->u('CM', 'length')));
    }

    public function test_anything_else_is_not_a_standard_pair(): void
    {
        $this->assertNull(StandardUnits::factor($this->u('KG', 'weight'), $this->u('L', 'volume')), 'weight is not volume');
        $this->assertNull(StandardUnits::factor($this->u('KG', 'quantity'), $this->u('G', 'weight')), 'a "KG" kept as a count unit is not a kilogram');
        $this->assertNull(StandardUnits::factor($this->u('PCS', 'quantity'), $this->u('DOZ', 'quantity', 12)), 'counted things never convert here');
        $this->assertNull(StandardUnits::factor($this->u('TEST', 'weight', 12), $this->u('G', 'weight')), 'a typed base_factor is not trusted');
        $this->assertNull(StandardUnits::factor(null, $this->u('G', 'weight')));
    }

    public function test_the_same_saved_unit_is_one_and_parts_follow_the_type(): void
    {
        $box = $this->u('BOX', 'quantity');
        $box->id = 7;
        $this->assertSame(1.0, StandardUnits::factor($box, $box));
        $this->assertTrue(StandardUnits::allowsParts($this->u('KG', 'weight')));
        $this->assertFalse(StandardUnits::allowsParts($this->u('EA', 'quantity')));
        $this->assertFalse(StandardUnits::allowsParts(null));
    }

    private function u(string $code, string $type, float $factor = 1): Unit
    {
        return new Unit(['code' => $code, 'name' => $code, 'unit_type' => $type, 'base_factor' => $factor]);
    }
}
