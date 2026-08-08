<?php

namespace App\Models\Edge;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE-LOCAL-PRINT-1 — Edge-ONLY transport metadata for one print_job (see the edge migration for the
 * full contract). print_jobs.print_status stays the authoritative business status; this row owns only
 * lease-token ownership + bounded retry/backoff execution state on the appliance.
 */
class EdgeLocalPrintDelivery extends Model
{
    public const STATE_WAITING = 'waiting';
    public const STATE_LEASED = 'leased';
    public const STATE_RETRY_WAIT = 'retry_wait';
    public const STATE_DELIVERED = 'delivered';
    public const STATE_TERMINAL_FAILED = 'terminal_failed';

    protected $connection = 'tenant';
    protected $table = 'edge_local_print_deliveries';

    protected $fillable = [
        'print_job_id', 'delivery_state', 'worker_uuid', 'lease_token',
        'claimed_at', 'lease_expires_at', 'failure_count',
        'next_attempt_at', 'last_attempt_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }
}
