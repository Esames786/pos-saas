<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class TenantDomain extends Model
{
    protected $connection = 'master';

    /** ONLINE-ORDERING-1 (Phase 5): `app` = POS/admin (*.bingoopos.com); `website` = client ka apna domain, sirf ordering website. */
    public const KIND_APP = 'app';

    public const KIND_WEBSITE = 'website';

    protected $fillable = [
        'tenant_id',
        'domain',
        'kind',
        'is_primary',
        'status',
    ];

    public function isWebsite(): bool
    {
        return $this->kind === self::KIND_WEBSITE;
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
