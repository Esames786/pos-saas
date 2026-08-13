<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * CLOUD-BILLING-1A — a manual payment method a tenant can send money to (EasyPaisa /
 * JazzCash / NayaPay / bank ...). Account details are admin-editable DB config; never
 * hardcoded. Only active methods are surfaced to tenants.
 */
class BillingPaymentMethod extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'code',
        'display_name',
        'account_title',
        'account_number',
        'instructions',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Active methods a tenant may pay to, in display order. */
    public function scopeActiveOrdered($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('display_name');
    }
}
