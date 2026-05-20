<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PrintJob extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'job_no', 'branch_id', 'terminal_id', 'printer_id',
        'claimed_by_agent_id', 'claimed_at',
        'document_type', 'print_status', 'reference_type', 'reference_id',
        'reference_no', 'payload', 'raw_payload', 'attempts',
        'printed_at', 'failed_at', 'error_message', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'payload'    => 'array',
            'printed_at' => 'datetime',
            'failed_at'  => 'datetime',
            'claimed_at' => 'datetime',
            'attempts'   => 'integer',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function claimedByAgent()
    {
        return $this->belongsTo(PrintAgent::class, 'claimed_by_agent_id');
    }
}
