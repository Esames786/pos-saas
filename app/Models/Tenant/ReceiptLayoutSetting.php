<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ReceiptLayoutSetting extends Model
{
    public const CONFIGURABLE_DOCUMENT_TYPES = ['receipt', 'kot', 'reminder'];

    /**
     * Every show/hide switch, in ONE place.
     *
     * The edit modal, its live-preview URL, the save handler and the preview controller each
     * carried their own hardcoded copy of this list. When four new columns were added, the
     * modal's copy was not updated — so those toggles always displayed OFF regardless of what was
     * saved, the live preview rendered them hidden, and (worst) saving ANY other change posted
     * the falsely-unchecked boxes and silently wiped the stored values to false. Everything now
     * derives from this constant, so a new column is either everywhere or a loud test failure.
     */
    public const TOGGLE_FIELDS = [
        'show_logo', 'show_branch_name', 'show_branch_address', 'show_branch_phone',
        'show_tax_number', 'show_cashier_name', 'show_customer_name', 'show_table_info',
        'show_order_no', 'show_order_time', 'show_updated_time', 'show_print_time',
        'show_item_codes', 'show_payment_breakdown', 'show_bingoo_branding',
        'show_delivery_details', 'show_vehicle_number', 'show_order_type',
        'show_column_dividers', 'show_category_header',
    ];

    public const SUPPORTED_DOCUMENT_TYPES = ['receipt', 'kot', 'reminder'];

    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id', 'document_type', 'paper_size', 'logo_path',
        'show_logo', 'show_branch_name', 'show_branch_address',
        'show_branch_phone', 'show_tax_number', 'show_cashier_name',
        'show_customer_name', 'show_table_info', 'show_order_no',
        'show_order_time', 'show_updated_time', 'show_print_time',
        'show_item_codes', 'show_payment_breakdown', 'show_bingoo_branding',
        'show_delivery_details', 'show_vehicle_number', 'show_order_type',
        'show_column_dividers', 'show_category_header',
        'header_text', 'footer_text', 'font_size', 'kot_font_size',
        'item_font_size', 'time_font_size', 'is_active',
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
            'show_order_time'         => 'boolean',
            'show_updated_time'       => 'boolean',
            'show_print_time'         => 'boolean',
            'show_item_codes'         => 'boolean',
            'show_payment_breakdown'  => 'boolean',
            'show_bingoo_branding'    => 'boolean',
            'show_delivery_details'   => 'boolean',
            'show_vehicle_number'     => 'boolean',
            'show_order_type'         => 'boolean',
            'show_column_dividers'    => 'boolean',
            'show_category_header'    => 'boolean',
            'is_active'               => 'boolean',
            'font_size'               => 'integer',
            'kot_font_size'           => 'integer',
            'item_font_size'          => 'integer',
            'time_font_size'          => 'integer',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
