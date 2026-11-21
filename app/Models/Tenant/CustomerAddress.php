<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    /**
     * EDGE PHASE 4 — canonical cross-system identity of an address (ULID, generated on create, immutable, never
     * mass-assignable) so an address typed at a Branch Server is created exactly once on the Cloud and matched by
     * identity on refresh (numeric ids differ between the two databases).
     */
    protected string $canonicalIdentityColumn = 'address_uuid';

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
