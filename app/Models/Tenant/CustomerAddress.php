<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['customer_id', 'label', 'address', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
