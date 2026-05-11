<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'name',
        'business_type',
        'address',
        'status',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'branch_user')->withTimestamps();
    }
}
