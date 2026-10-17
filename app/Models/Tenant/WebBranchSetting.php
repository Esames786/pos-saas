<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DELIVERY-RADIUS-1 — website ke liye branch ki jagah: map par nuqta (delivery ki doori yahan se) aur pick-up
 * card ka "Get directions" link. `branches` (POS / Edge) ko nahi chhuta.
 */
class WebBranchSetting extends Model
{
    protected $connection = 'tenant';

    protected $table = 'web_branch_settings';

    protected $fillable = ['branch_id', 'lat', 'lng', 'map_url'];

    protected function casts(): array
    {
        return ['branch_id' => 'integer', 'lat' => 'float', 'lng' => 'float'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function hasPoint(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }
}
