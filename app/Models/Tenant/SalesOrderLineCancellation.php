<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SalesOrderLineCancellation extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'event_uuid', 'sales_order_id', 'sales_order_line_id', 'source_line_uuid', 'source_kot_event_uuid',
        'void_reason_id', 'manager_approval_id', 'kot_batch_id', 'requested_by_user_id',
        'approved_by_user_id', 'approval_mode', 'product_name', 'variant_name',
        'quantity', 'policy_snapshot', 'cancelled_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (SalesOrderLineCancellation $cancellation) {
            $cancellation->event_uuid ??= (string) Str::uuid();
        });

        // EDGE-IDENTITY-1: event_uuid is this cancellation record's canonical cross-system identity
        // (reused, UUID v4). Immutable once assigned.
        static::updating(function (SalesOrderLineCancellation $cancellation) {
            if ($cancellation->isDirty('event_uuid') && $cancellation->getOriginal('event_uuid') !== null) {
                throw new \RuntimeException('event_uuid is immutable once assigned and cannot be changed.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'policy_snapshot' => 'array',
            'cancelled_at' => 'datetime',
        ];
    }

    public function reason()
    {
        return $this->belongsTo(VoidReason::class, 'void_reason_id');
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
