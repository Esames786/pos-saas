<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'key',
        'name',
        'category',
        'description',
        'route_module_keys',
        'sort_order',
        'is_core',
        'is_active',
    ];

    protected $casts = [
        'route_module_keys' => 'array',
        'sort_order'        => 'integer',
        'is_core'           => 'boolean',
        'is_active'         => 'boolean',
    ];

    public function planModules(): HasMany
    {
        return $this->hasMany(PlanModule::class);
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_modules')
            ->withPivot(['is_enabled', 'limits'])
            ->withTimestamps();
    }
}
