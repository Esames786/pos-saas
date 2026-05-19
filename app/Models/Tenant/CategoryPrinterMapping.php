<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CategoryPrinterMapping extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id', 'category_id', 'printer_id', 'print_role', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }
}
