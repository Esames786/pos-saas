<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PARTNER-SHARE-1 — one VERSION of what one side owes the other each time one dish sells.
 *
 * A change of rate is a new row with a later `effective_from`; a row is never edited, so a past
 * period keeps reporting at the rate it sold under.
 */
class ShareRate extends Model
{
    public const PAYER_PARTNER = 'partner';   // the partner pays us

    public const PAYER_US = 'us';             // we pay the partner

    protected $connection = 'tenant';

    protected $fillable = [
        'share_partner_id', 'item_key', 'product_id', 'combo_id', 'payer',
        'amount', 'effective_from', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date',
        ];
    }

    /** "p:52" for a product, "c:6" for a deal — the one key the report and the unique index share. */
    public static function itemKey(?int $productId, ?int $comboId): string
    {
        return $comboId ? 'c:' . $comboId : 'p:' . $productId;
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(SharePartner::class, 'share_partner_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }
}
