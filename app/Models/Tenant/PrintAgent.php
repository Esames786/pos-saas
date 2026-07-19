<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PrintAgent extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'name', 'agent_code', 'branch_id', 'terminal_id',
        'token_hash', 'device_name', 'device_os', 'local_ip',
        'is_active', 'last_seen_at', 'last_error',
        'pairing_code_hash', 'pairing_expires_at', 'pairing_attempts',
        'paired_at', 'paired_device_name', 'paired_device_platform', 'paired_device_ip',
    ];

    protected function casts(): array
    {
        return [
            'is_active'          => 'boolean',
            'last_seen_at'       => 'datetime',
            'pairing_expires_at' => 'datetime',
            'paired_at'          => 'datetime',
        ];
    }

    /** Waiting for the installed agent to enter the pairing code. */
    public function isWaitingToPair(): bool
    {
        return $this->pairing_code_hash !== null
            && $this->pairing_expires_at?->isFuture() === true;
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function terminal()
    {
        return $this->belongsTo(Terminal::class);
    }

    public function claimedJobs()
    {
        return $this->hasMany(PrintJob::class, 'claimed_by_agent_id');
    }
}
