<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CategoryTranslation extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['category_id', 'language_code', 'name', 'description'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
