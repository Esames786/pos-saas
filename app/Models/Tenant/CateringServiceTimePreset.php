<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** KASHIF-EVENT-FORM-1 — one bookable sitting (label + time), owner-editable. */
class CateringServiceTimePreset extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['label', 'service_time', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('service_time');
    }

    /** "1:00 PM" — how the operator reads a time, never "13:00:00". */
    public function displayTime(): string
    {
        return \Carbon\Carbon::parse($this->service_time)->format('g:i A');
    }

    public function inputTime(): string
    {
        return \Carbon\Carbon::parse($this->service_time)->format('H:i');
    }
}
