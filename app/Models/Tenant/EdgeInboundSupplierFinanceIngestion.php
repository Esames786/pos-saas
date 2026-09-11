<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** OFFLINE EDGE — F2: Cloud registry row of one Edge-originated supplier-finance event (Cloud-only class). */
class EdgeInboundSupplierFinanceIngestion extends Model
{
    public const STATUS_APPLIED = 'applied';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_EXCEPTION = 'exception';

    protected $connection = 'tenant';
    protected $table = 'edge_inbound_supplier_finance_ingestions';
    protected $guarded = [];

    protected $casts = [
        'ack_payload' => 'array',
        'ingested_at' => 'datetime',
        'tenant_id' => 'integer',
        'branch_id' => 'integer',
        'activation_epoch' => 'integer',
        'config_revision' => 'integer',
        'supplier_id' => 'integer',
        'supplier_payment_id' => 'integer',
        'journal_entry_id' => 'integer',
    ];

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }
}
