<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * KASHIF-EVENT-HISTORY-1 — one remembered state of one booking.
 *
 * Append-only: nothing in the application updates or deletes a revision.
 * A revert reads one and writes a NEW one; the trail only ever grows.
 */
class CateringEventRevision extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'catering_event_id',
        'changed_by_user_id',
        'action',
        'change_summary',
        'snapshot',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    public function event()
    {
        return $this->belongsTo(CateringEvent::class, 'catering_event_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
