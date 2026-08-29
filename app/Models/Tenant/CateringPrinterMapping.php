<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * CATERING-SLICE-3: independent Catering routing authority (spec §15).
 * Mirrors category_printer_mappings' shape; POS KOT mappings are read-only
 * source material for the one-way "Copy from POS" convenience and are never
 * modified by catering code.
 */
class CateringPrinterMapping extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'category_id',
        'production_station',
        'printer_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }
}
