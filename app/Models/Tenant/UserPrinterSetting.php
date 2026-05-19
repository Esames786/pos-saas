<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class UserPrinterSetting extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'user_id', 'receipt_printer_id', 'kot_printer_id',
        'remember_last_kot_printers',
    ];

    protected function casts(): array
    {
        return [
            'remember_last_kot_printers' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function receiptPrinter()
    {
        return $this->belongsTo(Printer::class, 'receipt_printer_id');
    }

    public function kotPrinter()
    {
        return $this->belongsTo(Printer::class, 'kot_printer_id');
    }
}
