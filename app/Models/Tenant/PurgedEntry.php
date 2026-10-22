<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * VOID-PURGE-1: the hidden copy of an entry the Owner removed permanently — every deleted row of both halves, who, when
 * and why. Shown only on the Owner's "Deleted entries" log; never deleted by any code path.
 */
class PurgedEntry extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'entity_type', 'entity_id', 'document_no', 'document_date', 'amount', 'reason', 'snapshot',
        'purged_by_user_id', 'purged_at', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'snapshot'      => 'array',
            'document_date' => 'date',
            'purged_at'     => 'datetime',
            'amount'        => 'float',
        ];
    }

    public function purgedBy()
    {
        return $this->belongsTo(User::class, 'purged_by_user_id');
    }
}
