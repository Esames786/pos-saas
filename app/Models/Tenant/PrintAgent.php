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
    ];

    protected function casts(): array
    {
        return [
            'is_active'    => 'boolean',
            'last_seen_at' => 'datetime',
        ];
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
