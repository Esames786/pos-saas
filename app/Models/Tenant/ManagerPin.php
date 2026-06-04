<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ManagerPin extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['user_id', 'pin_hash', 'is_active', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'is_active'     => 'boolean',
            'last_used_at'  => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
