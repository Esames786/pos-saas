<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** OFFLINE EDGE — F3: Cloud registry row of one Edge-originated purchase-return event (Cloud-only class). */
class EdgeInboundPurchaseReturnIngestion extends Model
{
    public const STATUS_APPLIED = 'applied';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_EXCEPTION = 'exception';

    protected $connection = 'tenant';
    protected $table = 'edge_inbound_purchase_return_ingestions';
    protected $guarded = [];

    protected $casts = [
        'ack_payload' => 'array',
        'ingested_at' => 'datetime',
        'tenant_id' => 'integer',
        'branch_id' => 'integer',
        'activation_epoch' => 'integer',
        'config_revision' => 'integer',
        'supplier_id' => 'integer',
        'goods_receipt_id' => 'integer',
        'purchase_return_id' => 'integer',
    ];

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }
}
