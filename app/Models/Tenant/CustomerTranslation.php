<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CustomerTranslation extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'customer_id',
        'language_code',
        'name',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
