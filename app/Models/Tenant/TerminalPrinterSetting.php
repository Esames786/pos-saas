<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class TerminalPrinterSetting extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'terminal_id', 'receipt_printer_id', 'kot_printer_id',
        'auto_print_receipt', 'auto_print_kot',
    ];

    protected function casts(): array
    {
        return [
            'auto_print_receipt' => 'boolean',
            'auto_print_kot'     => 'boolean',
        ];
    }

    public function terminal()
    {
        return $this->belongsTo(Terminal::class);
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
