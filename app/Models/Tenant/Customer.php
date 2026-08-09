<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
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

    public function ledgers()
    {
        return $this->hasMany(CustomerLedger::class);
    }

    public function payments()
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
