<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class KotBatch extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'event_uuid', 'sales_order_id', 'sequence_no', 'event_type',
        'reprint_of_batch_id', 'copy_no', 'created_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (KotBatch $batch) {
            $batch->event_uuid ??= (string) Str::uuid();
        });

        // EDGE-IDENTITY-1: event_uuid is the batch's canonical cross-system identity (reused, UUID v4).
        // Immutable once assigned — reprints reuse the same batch (never regenerate); this guards tampering.
        static::updating(function (KotBatch $batch) {
            if ($batch->isDirty('event_uuid') && $batch->getOriginal('event_uuid') !== null) {
                throw new \RuntimeException('event_uuid is immutable once assigned and cannot be changed.');
            }
        });
    }

    public function sale()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function lines()
    {
        return $this->hasMany(KotBatchLine::class);
    }
}
