<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Printer extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id', 'name', 'code', 'printer_type', 'print_role',
        'ip_address', 'port', 'paper_size', 'characters_per_line',
        'is_default', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active'  => 'boolean',
            'port'       => 'integer',
            'characters_per_line' => 'integer',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function printJobs()
    {
        return $this->hasMany(PrintJob::class);
    }

    public function categoryMappings()
    {
        return $this->hasMany(CategoryPrinterMapping::class);
    }
}
