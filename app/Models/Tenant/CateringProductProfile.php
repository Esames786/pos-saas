<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;

/**
 * CATERING-SLICE-1: 1:1 catering extension of a Product. The shared Product
 * authority carries no catering columns; deleting the product cascades the
 * profile. Config-replicated for future Edge (profile_uuid canonical).
 */
class CateringProductProfile extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    protected string $canonicalIdentityColumn = 'profile_uuid';

    public const PRICING_PER_PAX = 'per_pax';

    public const PRICING_FIXED = 'fixed';

    public const PRICING_MODES = [self::PRICING_PER_PAX, self::PRICING_FIXED];

    protected $fillable = [
        'product_id',
        'catering_enabled',
        'default_quote_unit_id',
        'pricing_mode',
        'default_catering_rate',
        'production_station',
        'minimum_qty',
        'production_label',
        'production_label_ur',
        'instructions',
    ];

    protected function casts(): array
    {
        return [
            'catering_enabled' => 'boolean',
            'default_catering_rate' => 'decimal:2',
            'minimum_qty' => 'decimal:3',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function defaultQuoteUnit()
    {
        return $this->belongsTo(Unit::class, 'default_quote_unit_id');
    }
}
