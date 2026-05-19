<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ProductTranslation extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'language_code', 'name', 'description'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
