<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class DepartmentProductOverride extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['department_id', 'product_id', 'mapping_type'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
