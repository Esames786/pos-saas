<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\HasManufacturingPostingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    use HasManufacturingPostingStatus;

    protected $connection = 'tenant';

    public const STATUSES = [
        'draft', 'planned', 'released', 'in_progress', 'on_hold', 'completed', 'cancelled',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUS_COLORS = [
        'draft'       => 'secondary',
        'planned'     => 'info',
        'released'    => 'primary',
        'in_progress' => 'warning',
        'on_hold'     => 'dark',
        'completed'   => 'success',
        'cancelled'   => 'danger',
    ];

    public const PRIORITY_COLORS = [
        'low'    => 'secondary',
        'normal' => 'info',
        'high'   => 'warning',
        'urgent' => 'danger',
    ];

    protected $fillable = [
        'order_no',
        'manufacturing_customer_id',
        'branch_id',
        'product_id',
        'planned_quantity',
        'produced_quantity',
        'order_date',
        'due_date',
        'status',
        'priority',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'order_date'        => 'date',
            'due_date'          => 'date',
            'planned_quantity'  => 'decimal:4',
            'produced_quantity' => 'decimal:4',
        ];
    }

    public function manufacturingCustomer()
    {
        return $this->belongsTo(ManufacturingCustomer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['completed', 'cancelled'], true);
    }
}
