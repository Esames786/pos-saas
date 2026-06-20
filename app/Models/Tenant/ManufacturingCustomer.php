<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ManufacturingCustomer extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'code',
        'name',
        'company_name',
        'contact_person',
        'email',
        'phone',
        'mobile',
        'tax_number',
        'address',
        'city',
        'country',
        'status',
        'notes',
        'created_by_user_id',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
