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

    /**
     * A method is "configured" (safe to surface to a tenant / safe to activate) only when the
     * customer-facing money-transfer fields are present. V1 requires an account title AND an
     * account/reference number (display_name is NOT NULL by schema, so it is always present).
     * This is the single source of truth for completeness — used by both the activation guard
     * and the tenant-visible query, so "active" can never mean "shown but unpayable".
     */
    public function scopeConfigured($query)
    {
        return $query
            ->whereNotNull('account_title')->where('account_title', '!=', '')
            ->whereNotNull('account_number')->where('account_number', '!=', '');
    }

    public function isConfigured(): bool
    {
        return filled($this->account_title) && filled($this->account_number);
    }

    /**
     * Has any historical subscription payment ever referenced this method's code?
     * `subscription_payments.payment_method_code` is a soft string reference (no FK), so a hard
     * delete would leave those rows pointing at a code that no longer resolves to a label —
     * deactivate instead of delete once referenced (see PaymentMethodController::destroy).
     */
    public function hasBeenReferenced(): bool
    {
        return SubscriptionPayment::query()
            ->where('payment_method_code', $this->code)
            ->exists();
    }
}
