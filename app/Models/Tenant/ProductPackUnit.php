<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * PHARMACY-PACKS-1 — one way a product is packed (Strip = 10 tablets, Box = 100 tablets).
 * `factor` is in the product's own unit; the row with factor 1 is that unit itself.
 * `sale_price` NULL = factor × the product's selling price.
 * UNITS-PACKS batch (23 Oct): a kg, litre or metre item's pack may hold a part (Bag = 2.5 kg), so `factor` is a
 * decimal; compare it with self::same(), never with ===.
 */
class ProductPackUnit extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'name', 'factor', 'sale_price', 'barcode', 'can_sell', 'can_buy', 'sort_order'];

    /** Two pack sizes are the same size (a decimal column read back as float). */
    public static function same(float $a, float $b): bool
    {
        return abs($a - $b) < 1e-9;
    }

    protected function casts(): array
    {
        return [
            'factor'     => 'float',
            'sale_price' => 'decimal:2',
            'can_sell'   => 'boolean',
            'can_buy'    => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function isBase(): bool
    {
        return self::same($this->factor, 1.0);
    }

    /** The price of one of this pack: its own, else size × the product's price. */
    public function priceFor(Product $product): float
    {
        return $this->sale_price !== null
            ? (float) $this->sale_price
            : round($this->factor * (float) $product->default_selling_price, 2);
    }
}
