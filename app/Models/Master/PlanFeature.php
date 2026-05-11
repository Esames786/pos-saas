<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class PlanFeature extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'plan_id',
        'feature_key',
        'feature_value',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
