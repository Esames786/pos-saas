<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * OFFLINE-SYNC-ENGINE-1C — the Cloud ingestion registry row for one Edge paid-sale envelope
 * (see the migration). The first ACCEPTED truth for a sale_uuid is authoritative and never overwritten.
 */
class EdgeInboundSaleIngestion extends Model
{
    protected $connection = 'tenant';

    protected $table = 'edge_inbound_sale_ingestions';

    public const STATUS_APPLIED = 'applied';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_EXCEPTION = 'exception';

    protected $fillable = [
        'sale_uuid', 'content_hash', 'envelope_schema_version',
        'tenant_id', 'branch_id', 'device_public_uuid', 'activation_epoch', 'config_revision',
        'ingestion_uuid', 'status', 'failure_code', 'ingested_sales_order_id', 'official_sale_no',
        'ack_payload', 'last_error', 'ingested_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'branch_id' => 'integer',
        'activation_epoch' => 'integer',
        'config_revision' => 'integer',
        'ingested_sales_order_id' => 'integer',
        'ack_payload' => 'array',
        'ingested_at' => 'datetime',
    ];

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }
}
