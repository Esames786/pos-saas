<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CategoryPrinterMapping extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id', 'terminal_id', 'category_id', 'printer_id', 'print_role', 'order_type',
        'reminder_confirm_on_addition', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'reminder_confirm_on_addition' => 'boolean',
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

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }
}
