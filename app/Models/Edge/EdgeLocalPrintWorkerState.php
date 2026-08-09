<?php

namespace App\Models\Edge;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE-LOCAL-PRINT-1 Slice 2 — the SINGLE print-worker process-state row (singleton_guard pattern,
 * see the edge migration). Liveness = heartbeat freshness; NEVER a job-lease authority.
 */
class EdgeLocalPrintWorkerState extends Model
{
    public const SINGLETON = 1;
    public const STATE_RUNNING = 'running';
    public const STATE_STOPPED = 'stopped';

    protected $connection = 'tenant';
    protected $table = 'edge_local_print_worker_state';

    protected $fillable = [
        'singleton_guard', 'state', 'worker_uuid', 'runtime_version',
        'started_at', 'heartbeat_at', 'stop_requested_at', 'last_graceful_stop_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'stop_requested_at' => 'datetime',
            'last_graceful_stop_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            $row->singleton_guard = self::SINGLETON; // one row, ever
        });
    }

    public static function current(): ?self
    {
        return self::query()->where('singleton_guard', self::SINGLETON)->first();
    }
}
