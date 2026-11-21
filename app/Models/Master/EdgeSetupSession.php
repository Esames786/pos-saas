<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE 0.9 (K2) — a Branch Server setup session: a short-lived, single-use setup code the owner created on the Cloud
 * Offline Edge page for ONE tenant+branch, and — once the setup wizard redeemed it — the opaque setup token that
 * authorises the bulk enrollment-assertion call (K3). Neither the code nor the token is ever stored in clear.
 */
class EdgeSetupSession extends Model
{
    protected $connection = 'master';

    public const ACTIVE_SLOT = 1;

    public const MODE_INSTALL = 'install';
    public const MODE_UPDATE  = 'update';

    protected $fillable = [
        'public_uuid', 'tenant_id', 'branch_id', 'mode', 'code_hash', 'active_slot', 'code_expires_at', 'attempts',
        'max_attempts', 'created_by_user_id', 'redeemed_at', 'redeemed_installation_uuid', 'redeemed_device_name',
        'setup_token_hash', 'token_expires_at', 'pairing_code_uuid', 'assertions_issued', 'last_used_at', 'cancelled_at',
    ];

    protected $casts = [
        'tenant_id'          => 'integer',
        'branch_id'          => 'integer',
        'active_slot'        => 'integer',
        'attempts'           => 'integer',
        'max_attempts'       => 'integer',
        'created_by_user_id' => 'integer',
        'assertions_issued'  => 'integer',
        'code_expires_at'    => 'datetime',
        'redeemed_at'        => 'datetime',
        'token_expires_at'   => 'datetime',
        'last_used_at'       => 'datetime',
        'cancelled_at'       => 'datetime',
    ];

    protected $hidden = ['code_hash', 'setup_token_hash'];

    public function isCodeExpired(): bool
    {
        return $this->code_expires_at !== null && $this->code_expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** A live (still redeemable) code: in the active slot, not expired, not cancelled, not redeemed. */
    public function isLive(): bool
    {
        return $this->active_slot === self::ACTIVE_SLOT && ! $this->isCancelled() && ! $this->isRedeemed() && ! $this->isCodeExpired();
    }

    public function tokenIsUsable(): bool
    {
        return $this->isRedeemed() && ! $this->isCancelled()
            && $this->token_expires_at !== null && $this->token_expires_at->isFuture();
    }
}
