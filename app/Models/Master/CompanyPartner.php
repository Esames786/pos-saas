<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MASTER-HISAB-1 — Bingoo ka ek owner aur nafe me us ka hissa (%).
 */
class CompanyPartner extends Model
{
    protected $connection = 'master';

    protected $table = 'company_partners';

    protected $fillable = ['name', 'share_percent', 'central_user_id', 'is_active'];

    protected $casts = [
        'share_percent' => 'decimal:2',
        'is_active'     => 'boolean',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(CompanyAccount::class, 'partner_id');
    }
}
