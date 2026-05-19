<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ProductVariantTranslation extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_variant_id', 'language_code', 'name'];

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
