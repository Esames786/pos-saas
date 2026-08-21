<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * KASHIF-CATERING-INSTRUCTIONS-1 — one entry of the managed kitchen-instruction
 * vocabulary (Mirch Kam, Chawal Dana Dana, …). Master data: recorded by the
 * Owner, multi-selected per estimate line, printed on the kitchen sheet.
 *
 * Deactivation hides an entry from new selection; it never deletes it, because
 * lines that already carry it still mean what they meant.
 */
class CateringInstruction extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'label',
        'label_ur',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }
}
