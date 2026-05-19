<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['code', 'name', 'unit_type', 'base_factor', 'is_base', 'is_active'];

    protected function casts(): array
    {
        return [
            'base_factor' => 'decimal:6',
            'is_base'     => 'boolean',
            'is_active'   => 'boolean',
        ];
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
