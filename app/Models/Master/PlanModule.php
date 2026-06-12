<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanModule extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'plan_id',
        'module_id',
        'is_enabled',
        'limits',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'limits'     => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
