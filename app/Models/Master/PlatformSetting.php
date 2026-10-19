<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONLINE-ORDERING-1 — platform ki aik setting (key → value). Abhi sirf Billing rates.
 *
 * Seedha parhne ke bajaye `App\Services\Saas\BillingRates` se parho: wohi `.env` ka sahara aur
 * aik request me aik hi query ka khayal rakhta hai.
 */
class PlatformSetting extends Model
{
    protected $connection = 'master';

    protected $table = 'platform_settings';

    protected $fillable = ['key', 'value', 'updated_by_user_id'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'updated_by_user_id');
    }
}
