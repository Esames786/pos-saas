<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** PARTNER-SHARE-1 — a kitchen that shares dishes with the restaurant (see share_rates). */
class SharePartner extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['name', 'count_free_lines', 'is_active', 'notes'];

    protected function casts(): array
    {
        return [
            'count_free_lines' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ShareRate::class);
    }
}
