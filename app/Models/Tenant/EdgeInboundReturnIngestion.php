<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * OFFLINE EDGE — F1: the Cloud's exactly-once registry row for one Edge-originated RETURN event (see migration).
 */
class EdgeInboundReturnIngestion extends Model
{
    public const STATUS_APPLIED = 'applied';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_EXCEPTION = 'exception';

    protected $connection = 'tenant';

    protected $table = 'edge_inbound_return_ingestions';

    protected $guarded = [];

    protected $casts = [
        'ack_payload' => 'array',
        'approval_audit' => 'array',
        'ingested_at' => 'datetime',
        'tenant_id' => 'integer',
        'branch_id' => 'integer',
        'activation_epoch' => 'integer',
        'config_revision' => 'integer',
        'sales_order_id' => 'integer',
        'sales_return_id' => 'integer',
    ];

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }
}
