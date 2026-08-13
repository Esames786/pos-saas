<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SupplierTranslation extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'supplier_id',
        'language_code',
        'name',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
