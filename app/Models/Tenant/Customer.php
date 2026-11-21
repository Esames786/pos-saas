<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    /**
     * EDGE-IDENTITY-1: canonical cross-system identity of the customer (immutable; not mass-assignable),
     * so a customer created offline on an Edge appliance preserves its identity into Cloud. Distinct from
     * the optional human `code`. Offline customer-create UI is NOT exposed in this sprint.
     */
    protected string $canonicalIdentityColumn = 'customer_uuid';

    /**
     * EDGE PHASE 4 — `phone_normalized` (App\Support\PhoneNormalizer) is the cross-system MATCH KEY, derived from `phone`
     * on every Eloquent write (Cloud and Branch Server alike) so no code path can store a phone without its match key.
     * It is never mass-assigned: the normaliser is the only writer.
     */
    protected static function booted(): void
    {
        static::saving(function (self $customer) {
            if ($customer->isDirty('phone') || ($customer->phone_normalized === null && $customer->phone !== null)) {
                $customer->phone_normalized = PhoneNormalizer::normalize($customer->phone !== null ? (string) $customer->phone : null);
            }
        });
    }

    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'address',
        'tax_number',
        'date_of_birth',
        'gender',
        'status',
        'opening_balance',
        'current_balance',
        'credit_limit',
        'credit_days',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth'   => 'date',
            'opening_balance' => 'decimal:4',
            'current_balance' => 'decimal:4',
            'credit_limit'    => 'decimal:4',
            'credit_days'     => 'integer',
        ];
    }

    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /** EDGE PHASE 4 — offline-minted customer_uuids the Cloud matched to this customer (never duplicates). */
    public function uuidAliases()
    {
        return $this->hasMany(CustomerUuidAlias::class);
    }

    public function ledgers()
    {
        return $this->hasMany(CustomerLedger::class);
    }

    public function payments()
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
