<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\CateringServiceTimePreset;
use Illuminate\Database\Seeder;

/**
 * KASHIF-EVENT-FORM-1 — Pakistani catering sittings, as they are actually booked.
 *
 * Seeded ONCE per label: a rerun never overwrites a time the owner retimed,
 * nor revives a sitting they retired. New labels appear; existing ones are
 * left exactly as the house keeps them.
 */
class CateringServiceTimePresetSeeder extends Seeder
{
    public const PRESETS = [
        ['Sehri', '03:30', 10],
        ['Breakfast', '08:00', 20],
        ['Lunch', '13:00', 30],
        ['Hi-Tea', '17:00', 40],
        ['Iftar', '18:30', 50],
        ['Mehndi', '19:30', 60],
        ['Dinner', '20:00', 70],
        ['Walima Dinner', '21:00', 80],
        ['Late Night', '23:00', 90],
    ];

    public function run(): void
    {
        foreach (self::PRESETS as [$label, $time, $order]) {
            $preset = CateringServiceTimePreset::firstOrNew(['label' => $label]);
            if ($preset->exists) {
                continue; // the owner's own timing wins, always
            }
            $preset->fill([
                'service_time' => $time,
                'sort_order' => $order,
                'is_active' => true,
            ])->save();
        }
    }
}
