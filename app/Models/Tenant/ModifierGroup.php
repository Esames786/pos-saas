<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ModifierGroup extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'name',
        'min_select',
        'max_select',
        'is_required',
        'sort_order',
        'status',
        // ONLINE-ORDERING-1: pos | website | both. 'website' wala group POS tak nahi pohanchta.
        'channel',
    ];

    public const CHANNELS = ['pos' => 'POS only', 'website' => 'Website only', 'both' => 'POS + Website'];

    /** Column na ho (purani DB / Edge) to 'pos' — yani aaj ka rawayya. */
    public function showsOnPos(): bool
    {
        return ($this->channel ?? 'pos') !== 'website';
    }

    public function showsOnWebsite(): bool
    {
        return in_array($this->channel, ['website', 'both'], true);
    }

    public function combos()
    {
        return $this->belongsToMany(Combo::class, 'combo_modifier_group')->withPivot('sort_order')->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'min_select'  => 'integer',
            'max_select'  => 'integer',
            'is_required' => 'boolean',
            'sort_order'  => 'integer',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function modifiers()
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order')->orderBy('name');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_modifier_group')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
