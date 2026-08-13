<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** CATERING-SLICE-3: claim-before-send idempotency log for customer emails. */
class CateringEmailLog extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'catering_event_id',
        'email_type',
        'dedupe_key',
        'recipient',
        'sent_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function event()
    {
        return $this->belongsTo(CateringEvent::class, 'catering_event_id');
    }
}
