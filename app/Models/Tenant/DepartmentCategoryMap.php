<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class DepartmentCategoryMap extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['department_id', 'category_id', 'include_children'];

    protected function casts(): array
    {
        return [
            'include_children' => 'boolean',
        ];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
