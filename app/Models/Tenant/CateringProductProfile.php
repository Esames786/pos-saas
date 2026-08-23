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

    /**
     * PRICING METHOD — how the dish is quoted commercially.
     *
     * Not to be confused with the costing source below. They answer different
     * questions and changing one never changes the other; the screen labels them
     * "Pricing Method" and "Costing Source" so nobody has to remember which
     * "mode" was meant.
     */
    public const PRICING_PER_PAX = 'per_pax';

    public const PRICING_FIXED = 'fixed';

    public const PRICING_MODES = [self::PRICING_PER_PAX, self::PRICING_FIXED];

    /**
     * COSTING SOURCE — which authority decides what this dish costs.
     *
     * Exactly one is active. The other's configuration may still be stored, and
     * is dormant rather than deleted: a client who supplies recipes next month
     * must be able to switch back and find their recipe intact.
     */
    public const COSTING_RECIPE = 'recipe';

    public const COSTING_BLOCKS = 'blocks';

    public const COSTING_MODES = [self::COSTING_RECIPE, self::COSTING_BLOCKS];

    /** Defaults to recipe, which is what every dish did before blocks existed. */
    public function costingMode(): string
    {
        return in_array($this->costing_mode, self::COSTING_MODES, true)
            ? $this->costing_mode
            : self::COSTING_RECIPE;
    }

    public function usesBlocks(): bool
    {
        return $this->costingMode() === self::COSTING_BLOCKS;
    }

    /** The blocks stored against this product — active authority or not. */
    public function costBlocks()
    {
        return $this->hasMany(CateringProductCostBlock::class, 'product_id', 'product_id');
    }

    protected $fillable = [
        'product_id',
        'catering_enabled',
        'allow_party_supply',
        'is_complimentary',
        'default_quote_unit_id',
        'pricing_mode',
        'default_catering_rate',
        'production_station',
        'minimum_qty',
        'production_label',
        'production_label_ur',
        'instructions',
        'costing_mode',
    ];

    protected function casts(): array
    {
        return [
            'catering_enabled' => 'boolean',
            'allow_party_supply' => 'boolean',
            'is_complimentary' => 'boolean',
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
