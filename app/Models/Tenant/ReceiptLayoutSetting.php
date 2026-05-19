<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ReceiptLayoutSetting extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id', 'document_type', 'paper_size', 'logo_path',
        'show_logo', 'show_branch_name', 'show_branch_address',
        'show_branch_phone', 'show_tax_number', 'show_cashier_name',
        'show_customer_name', 'show_table_info', 'show_order_no',
        'show_item_codes', 'show_payment_breakdown',
        'header_text', 'footer_text', 'font_size', 'kot_font_size', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'show_logo'               => 'boolean',
            'show_branch_name'        => 'boolean',
            'show_branch_address'     => 'boolean',
            'show_branch_phone'       => 'boolean',
            'show_tax_number'         => 'boolean',
            'show_cashier_name'       => 'boolean',
            'show_customer_name'      => 'boolean',
            'show_table_info'         => 'boolean',
            'show_order_no'           => 'boolean',
            'show_item_codes'         => 'boolean',
            'show_payment_breakdown'  => 'boolean',
            'is_active'               => 'boolean',
            'font_size'               => 'integer',
            'kot_font_size'           => 'integer',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
