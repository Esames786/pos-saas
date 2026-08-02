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
