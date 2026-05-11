<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class TenantDomain extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'tenant_id',
        'domain',
        'is_primary',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
