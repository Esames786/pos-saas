<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PromotionTarget extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['promotion_id', 'target_type', 'target_id'];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }
}
