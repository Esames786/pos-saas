<?php

namespace App\Support;

use App\Models\Tenant\Unit;

/**
 * UNITS-PACKS F4 (mixed units, 23 Oct): a total of quantities never adds kg and pieces into one number.
 *
 * A "mix" is the same total split by kind of unit, each part in one size: ['kg' => 12.0, 'pcs' => 24.0].
 *  - kg / g, litre / ml and metre / cm are added up in kg, L and m (StandardUnits), so 500 g + 1 kg = 1.5 kg;
 *  - every counted unit (piece, plate, box, no unit at all) is one part, "pcs", added as today;
 *  - a tenant's own unit that is not a standard one (e.g. a weight called "MAUND") stays a part of its own, by name.
 *
 * Shown: one part → the number (counted: exactly today's figure; kg / L / m: with its unit, "12.5 kg"); two or more
 * parts → the parts in words, "12 kg · 24 pcs". A row built without a mix (old callers, fixtures) shows today's number.
 */
final class QtyMix
{
    public const COUNT = 'pcs';

    /** @var array<int, Unit> */
    private static array $units = [];

    private static ?string $unitsDb = null;

    /** The part a quantity of $unitId belongs to, and the quantity in that part's size. @return array{0:string,1:float} */
    public static function part(?int $unitId, float $qty): array
    {
        $unit = $unitId ? self::unit($unitId) : null;
        if ($unit && ($std = StandardUnits::inReportUnit($unit, $qty))) {
            return [StandardUnits::REPORT_UNITS[$std[0]], $std[1]];
        }
        if ($unit && StandardUnits::allowsParts($unit)) {
            return [(string) ($unit->name ?: $unit->code), $qty];   // a measured unit of the tenant's own
        }

        return [self::COUNT, $qty];
    }

    /** $mix plus $qty of $unitId. */
    public static function add(array $mix, ?int $unitId, float $qty, float $sign = 1.0): array
    {
        [$key, $q] = self::part($unitId, $qty);
        $mix[$key] = ($mix[$key] ?? 0.0) + $sign * $q;

        return $mix;
    }

    /** $a + $sign × $b, part by part. */
    public static function merge(array $a, array $b, float $sign = 1.0): array
    {
        foreach ($b as $key => $q) {
            $a[$key] = ($a[$key] ?? 0.0) + $sign * (float) $q;
        }

        return $a;
    }

    /** The parts of a list of rows' mixes under $key, added up (rows without one count as today's number in pcs). */
    public static function sum(iterable $rows, string $mixKey, string $qtyKey): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $out = isset($row[$mixKey]) && is_array($row[$mixKey])
                ? self::merge($out, $row[$mixKey])
                : self::merge($out, [self::COUNT => (float) ($row[$qtyKey] ?? 0)]);
        }

        return $out;
    }

    /** Parts that are really there (a part that came to zero does not make a total "mixed"). */
    public static function parts(?array $mix): array
    {
        return array_filter($mix ?? [], fn ($q) => abs((float) $q) > 0.0005);
    }

    public static function isMixed(?array $mix): bool
    {
        return count(self::parts($mix)) > 1;
    }

    /**
     * What a quantity cell shows. $fmt formats a number the way the screen already does.
     * $compact (thermal): a mixed total is the word "mixed", for a narrow column; words() goes on a line of its own.
     */
    public static function label(float $qty, ?array $mix, callable $fmt, bool $compact = false): string
    {
        $parts = self::parts($mix);
        if ($mix === null || $parts === []) {
            return $fmt($qty);
        }
        if (count($parts) === 1) {
            $key = array_key_first($parts);

            return $key === self::COUNT ? $fmt($qty) : $fmt($parts[$key]) . ' ' . $key;
        }

        return $compact ? 'mixed' : self::words($mix, $fmt);
    }

    /**
     * A spreadsheet cell: stays a NUMBER whenever the total has one kind of unit (counted: today's figure; kg / L / m:
     * the total in that unit), and is the words only for a mixed total.
     */
    public static function cell(float $qty, ?array $mix): float|string
    {
        $parts = self::parts($mix);
        if ($mix === null || $parts === []) {
            return $qty;
        }
        if (count($parts) === 1) {
            $key = array_key_first($parts);

            return $key === self::COUNT ? $qty : round((float) $parts[$key], 3);
        }

        return self::words($mix, fn ($q) => rtrim(rtrim(number_format((float) $q, 3, '.', ''), '0'), '.'));
    }

    /** A quantity travelling with its mix, for a printer that takes figures one by one (the ESC/POS report). */
    public static function tag(float|int|string|null $qty, ?array $mix): array
    {
        return ['qty' => (float) $qty, 'mix' => $mix];
    }

    /** "12 kg · 24 pcs" — measured parts first, then counted. */
    public static function words(?array $mix, ?callable $fmt = null): string
    {
        $fmt ??= fn ($q) => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
        $parts = self::parts($mix);
        uksort($parts, fn ($a, $b) => ($a === self::COUNT) <=> ($b === self::COUNT) ?: strcmp($a, $b));

        return implode(' · ', array_map(fn ($k) => $fmt($parts[$k]) . ' ' . $k, array_keys($parts)));
    }

    private static function unit(int $id): ?Unit
    {
        $db = (string) config('database.connections.tenant.database');
        if (self::$unitsDb !== $db) {
            self::$units = Unit::on('tenant')->get()->keyBy('id')->all();
            self::$unitsDb = $db;
        }

        return self::$units[$id] ?? null;
    }

    /** Tests: forget the unit list (a test changes units mid-run). */
    public static function forget(): void
    {
        self::$units = [];
        self::$unitsDb = null;
    }
}
