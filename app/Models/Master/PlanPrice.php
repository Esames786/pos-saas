<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * WEBSITE-I18N-GEO-1 P2 — a plan's price in one currency. 'bundle' = the plan's price (Pakistan);
 * 'per_branch' = the price of one branch (Saudi Arabia, the UAE, Qatar, the US).
 * Read through PlanPricingService::quote(), never directly by a page.
 */
class PlanPrice extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'plan_id',
        'currency_code',
        'pricing_model',
        'monthly_price',
        'yearly_price',
        'extra_terminal_monthly',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'yearly_price' => 'decimal:2',
            'extra_terminal_monthly' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
