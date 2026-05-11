<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class RouteCatalog extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'route_name',
        'uri',
        'method',
        'module_key',
        'action_key',
        'is_published',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
