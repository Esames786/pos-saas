<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Terminal extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'device_identifier',
        'requires_shift',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'requires_shift' => 'boolean',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function openShift()
    {
        return $this->hasOne(Shift::class)->where('status', 'open');
    }

    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class);
    }
}
