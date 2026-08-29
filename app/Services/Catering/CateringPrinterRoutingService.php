<?php

namespace App\Services\Catering;

use App\Models\Tenant\CategoryPrinterMapping;
use App\Models\Tenant\CateringPrinterMapping;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-SLICE-3: catering printer routing authority (spec §15).
 * Reads POS KOT mappings only for the one-way copy convenience; the POS
 * table (category_printer_mappings) is NEVER written by catering code.
 */
class CateringPrinterRoutingService
{
    /** One-way copy: active POS KOT mappings → catering mappings. Returns rows copied. */
    public function copyFromPosKotMappings(): int
    {
        $posMappings = CategoryPrinterMapping::query()
            ->whereIn('print_role', ['kot', 'both'])
            ->where('is_active', true)
            ->get();

        $copied = 0;
        DB::connection('tenant')->transaction(function () use ($posMappings, &$copied) {
            foreach ($posMappings as $posMapping) {
                $exists = CateringPrinterMapping::query()
                    ->when($posMapping->branch_id === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $posMapping->branch_id))
                    ->when($posMapping->category_id === null, fn ($q) => $q->whereNull('category_id'), fn ($q) => $q->where('category_id', $posMapping->category_id))
                    ->whereNull('production_station')
                    ->where('printer_id', $posMapping->printer_id)
                    ->exists();

                if (! $exists) {
                    CateringPrinterMapping::create([
                        'branch_id' => $posMapping->branch_id,
                        'category_id' => $posMapping->category_id,
                        'production_station' => null,
                        'printer_id' => $posMapping->printer_id,
                        'is_active' => true,
                    ]);
                    $copied++;
                }
            }
        });

        return $copied;
    }
}
