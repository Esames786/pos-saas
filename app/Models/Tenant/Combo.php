<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Combo extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'price',
        'sort_order',
        'status',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function components()
    {
        return $this->hasMany(ComboComponent::class)->orderBy('sort_order')->orderBy('id');
    }
}
