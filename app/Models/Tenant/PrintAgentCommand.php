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
}
