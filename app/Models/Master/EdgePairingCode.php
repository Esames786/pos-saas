<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * BRANCH-DEVICE-PAIRING-1 — a short-lived six-digit pairing code bound to ONE
 * tenant+branch. Master-DB row. The six digits are NEVER stored — only
 * code_hash = hmac_sha256(public_uuid|code, app key). One live code per branch via
 * the unique (tenant_id, branch_id, active_slot=1) index.
 */
class EdgePairingCode extends Model
{
    protected $connection = 'master';

    public const ACTIVE_SLOT = 1;

    protected $fillable = [
        'public_uuid', 'tenant_id', 'branch_id', 'code_hash', 'attempts', 'max_attempts',
        'expires_at', 'used_at', 'paired_device_id', 'active_slot', 'created_by_user_id', 'cancelled_at',
    ];

    protected $casts = [
        'attempts'     => 'integer',
        'max_attempts' => 'integer',
        'active_slot'  => 'integer',
        'expires_at'   => 'datetime',
        'used_at'      => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $hidden = ['code_hash'];

    public function isLive(): bool
    {
        return $this->active_slot === self::ACTIVE_SLOT
            && $this->used_at === null
            && $this->cancelled_at === null
            && $this->expires_at
            && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return ! $this->expires_at || $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }
}
