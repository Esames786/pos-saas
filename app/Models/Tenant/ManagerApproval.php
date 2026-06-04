<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ManagerApproval extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'approval_no',
        'action_type',
        'reference_type',
        'reference_id',
        'requested_by_user_id',
        'approved_by_user_id',
        'amount',
        'payload',
        'reason',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:2',
            'payload'     => 'json',
            'approved_at' => 'datetime',
        ];
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
