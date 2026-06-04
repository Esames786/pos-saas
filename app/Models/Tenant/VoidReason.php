<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class VoidReason extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['name', 'reason_type', 'requires_manager_approval', 'is_active'];

    protected function casts(): array
    {
        return [
            'requires_manager_approval' => 'boolean',
            'is_active'                 => 'boolean',
        ];
    }
}
