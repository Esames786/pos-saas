<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section I) — one row per activation generation of a branch appliance. Stored
 * in the master DB, append-only (allocated via EdgeActivationEpochService under a row lock).
 */
class EdgeBranchActivation extends Model
{
    protected $connection = 'master';

    protected $table = 'edge_branch_activations';

    public const REASON_INITIAL = 'initial';
    public const REASON_DEVICE_REPLACED = 'device_replaced';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'branch_id' => 'integer',
        'generation' => 'integer',
        'edge_device_id' => 'integer',
    ];
}
