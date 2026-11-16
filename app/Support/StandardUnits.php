<?php

namespace App\Support;

use App\Models\Tenant\Unit;

/**
 * UB-08 (unified-business roadmap, W2) — the conversions every shop agrees on: 1 kg = 1000 g, 1 litre = 1000 ml,
 * 1 metre = 100 cm. Nothing else.
 *
 * A unit is recognised by its CODE and must have the matching type (a "L" kept as a count unit is not a litre).
 * Its saved `base_factor` is NOT trusted: tenants typed those freely (a test unit "TEST" ×12 exists), and the roadmap
 * forbids treating a factor as true just because the type matches. Counted things (piece, box, carton, strip) never
 * convert here — how many pieces a carton holds belongs to one product: its packs.
 *
 * Read-only helper; no caller's result changes because this class exists.
 */
final class StandardUnits
{
    /** family (= units.unit_type) => code => size in the family's smallest unit */
    private const FAMILIES = [
        'weight' => ['MG' => 0.001, 'G' => 1, 'GM' => 1, 'GMS' => 1, 'GRAM' => 1, 'GRAMS' => 1, 'KG' => 1000, 'KGS' => 1000],
        'volume' => ['ML' => 1, 'L' => 1000, 'LTR' => 1000, 'LITRE' => 1000, 'LITER' => 1000],
        'length' => ['MM' => 1, 'CM' => 10, 'M' => 1000, 'MTR' => 1000, 'METRE' => 1000, 'METER' => 1000],
    ];

    /** The family a unit belongs to, or null when it is not a recognised standard unit. */
    public static function family(?Unit $unit): ?string
    {
        if (! $unit) {
            return null;
        }
        $type = (string) $unit->unit_type;

        return isset(self::FAMILIES[$type][self::code($unit)]) ? $type : null;
    }

    /**
     * How many $to are in one $from (kg → g = 1000), or null when the pair is not a recognised standard pair.
     * The same unit is always 1.
     */
    public static function factor(?Unit $from, ?Unit $to): ?float
    {
        if (! $from || ! $to) {
            return null;
        }
        if ((int) $from->id === (int) $to->id && $from->id !== null) {
            return 1.0;
        }
        $family = self::family($from);
        if ($family === null || $family !== self::family($to)) {
            return null;
        }

        return self::FAMILIES[$family][self::code($from)] / self::FAMILIES[$family][self::code($to)];
    }

    /** UNITS-PACKS F2: the units a cashier may type a quantity in, per family, smallest first: [code, label]. */
    private const ENTRY = [
        'weight' => [['G', 'g'], ['KG', 'kg']],
        'volume' => [['ML', 'ml'], ['L', 'litre']],
        'length' => [['CM', 'cm'], ['M', 'm']],
    ];

    /**
     * UNITS-PACKS F2 — what the counter may offer for an item kept in $stock: "g" and "kg" for a kg item, each with how
     * many of the stock unit one of it is (g → 0.001 for kg). Empty when the stock unit is not a standard unit.
     *
     * @return list<array{code:string, label:string, to_stock:float}>
     */
    public static function entryUnits(?Unit $stock): array
    {
        $family = self::family($stock);
        if ($family === null) {
            return [];
        }
        $out = [];
        foreach (self::ENTRY[$family] as [$code, $label]) {
            $out[] = ['code' => $code, 'label' => $label, 'to_stock' => self::FAMILIES[$family][$code] / self::FAMILIES[$family][self::code($stock)]];
        }

        return $out;
    }

    /** UNITS-PACKS F4: the word the counter shows for an entry unit ("g" for G), else the code in small letters. */
    public static function entryLabel(string $code): string
    {
        $code = strtoupper(trim($code));
        foreach (self::ENTRY as $units) {
            foreach ($units as [$c, $label]) {
                if ($c === $code) {
                    return $label;
                }
            }
        }

        return strtolower($code);
    }

    /** How many of the stock unit one $code is (G for a kg item = 0.001), or null when $code is not in its family. */
    public static function codeFactor(string $code, ?Unit $stock): ?float
    {
        $family = self::family($stock);
        $code = strtoupper(trim($code));
        if ($family === null || ! isset(self::FAMILIES[$family][$code])) {
            return null;
        }

        return self::FAMILIES[$family][$code] / self::FAMILIES[$family][self::code($stock)];
    }

    /** Report wording: kg, litre and metre, the size each family's quantities are added up in. */
    public const REPORT_UNITS = ['weight' => 'kg', 'volume' => 'L', 'length' => 'm'];

    /**
     * UNITS-PACKS F4 (mixed units): $qty of $unit in its family's report unit — 500 g = 0.5 kg — as [family, qty], or
     * null for a unit that is not a recognised standard one (pieces, boxes, a tenant's own unit).
     */
    public static function inReportUnit(?Unit $unit, float $qty): ?array
    {
        $family = self::family($unit);
        if ($family === null) {
            return null;
        }

        return [$family, $qty * self::FAMILIES[$family][self::code($unit)] / 1000];
    }

    /** A unit that holds part quantities (1.250 kg) — the type, as every screen already decides it. */
    public static function allowsParts(?Unit $unit): bool
    {
        return $unit !== null && in_array($unit->unit_type, ['weight', 'volume', 'length'], true);
    }

    private static function code(Unit $unit): string
    {
        return strtoupper(trim((string) $unit->code));
    }
}
