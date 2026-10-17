<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE PHASE 4 — an offline-minted `customer_uuid` that the Cloud MATCHED to an existing customer (by normalized phone)
 * instead of creating a duplicate. `alias_uuid` is the primary key; `customer_uuid` is the canonical identity it maps to.
 * Written only by the Cloud sale ingestion (create-or-match); shipped to the appliance as a bootstrap/refresh section so
 * the applier can merge its local row into the canonical one.
 */
class CustomerUuidAlias extends Model
{
    protected $connection = 'tenant';

    protected $table = 'customer_uuid_aliases';

    protected $primaryKey = 'alias_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public const SOURCE_PHONE_MATCH = 'phone_match';

    protected $fillable = ['alias_uuid', 'customer_id', 'customer_uuid', 'source_device_uuid', 'source'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
