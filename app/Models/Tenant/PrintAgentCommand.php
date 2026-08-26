<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-shot control command the operator raised from the Printers screen (Test / Reboot). The print
 * agent claims queued rows for its branch, acts on the LAN printer, and posts the outcome back.
 */
class PrintAgentCommand extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'printer_id', 'branch_id', 'type', 'status', 'result', 'latency_ms',
        'requested_by_user_id', 'claimed_by_agent_id', 'claimed_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'latency_ms'   => 'integer',
            'claimed_at'   => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /** How long a claimed-but-unreported command may stay `running` before it is presumed dead. */
    public const LEASE_SECONDS = 90;

    /**
     * Fail any command an agent claimed but never reported on — a crashed agent or a lost result POST
     * must not leave a row stuck in `running` forever (the Printers screen would wait on it and the
     * next Test/Reboot for that printer would look like it never ran). Idempotent; safe to call often.
     */
    public static function expireStale(int $leaseSeconds = self::LEASE_SECONDS): int
    {
        return static::query()
            ->where('status', 'running')
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<', now()->subSeconds($leaseSeconds))
            ->update([
                'status'       => 'failed',
                'result'       => 'Timed out — the print agent did not report a result.',
                'completed_at' => now(),
            ]);
    }
}
