<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class KitchenProduction extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'production_no',
        'branch_id',
        'recipe_id',
        'quantity_produced',
        'yield_unit_id',
        'production_date',
        'status',
        'notes',
        'produced_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_produced' => 'decimal:4',
            'production_date'   => 'date',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function yieldUnit()
    {
        return $this->belongsTo(Unit::class, 'yield_unit_id');
    }

    public function producedBy()
    {
        return $this->belongsTo(User::class, 'produced_by_user_id');
    }

    public function ingredients()
    {
        return $this->hasMany(KitchenProductionIngredient::class);
    }
}
