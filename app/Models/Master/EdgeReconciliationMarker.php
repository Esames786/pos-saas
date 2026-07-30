<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * BRANCH-DEVICE-PAIRING-HARDEN-2 — durable "branch reconciliation still pending" marker
 * for when a lifecycle transition failed after a committed master security mutation.
 */
class EdgeReconciliationMarker extends Model
{
    protected $connection = 'master';

    public const STATUS_PENDING  = 'pending';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'tenant_id', 'branch_id', 'operation', 'status', 'attempts', 'last_error', 'resolved_at',
    ];

    protected $casts = [
        'attempts'    => 'integer',
        'resolved_at' => 'datetime',
    ];
}
