<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** CATERING-SLICE-3: one scheduled reminder slot per (event, offset). */
class CateringEventReminder extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'catering_event_id',
        'reminder_key',
        'due_date',
        'sent_at',
        'sent_to',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function event()
    {
        return $this->belongsTo(CateringEvent::class, 'catering_event_id');
    }
}
