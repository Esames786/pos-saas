<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MASTER-HISAB-1 — ek khata: company ka paisa kahan para hai. Owner ka apna account (partner_id) ya
 * company ka apna (partner_id NULL).
 */
class CompanyAccount extends Model
{
    protected $connection = 'master';

    protected $table = 'company_accounts';

    protected $fillable = ['name', 'partner_id', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(CompanyPartner::class, 'partner_id');
    }
}
