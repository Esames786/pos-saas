<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;

class ManagerApproval extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    /**
     * EDGE-IDENTITY-1: canonical cross-system identity of the approval (immutable; not mass-assignable).
     * Distinct from `approval_no`, the locally-generated human display label. Re-authing the manager does
     * not create a new approval row, so this identity is stable for the approval's lifetime.
     */
    protected string $canonicalIdentityColumn = 'approval_uuid';

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
        'consumed_at',
        'consumed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:2',
            'payload'     => 'json',
            'approved_at' => 'datetime',
            'consumed_at' => 'datetime',
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
