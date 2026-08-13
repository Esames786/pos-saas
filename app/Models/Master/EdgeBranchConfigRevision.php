<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE-CONFIG-REFRESH-1 — one row per allocated (tenant, branch, revision). Append-only; the
 * monotonic config revision authority for Edge config refresh (see EdgeConfigRevisionService).
 */
class EdgeBranchConfigRevision extends Model
{
    protected $connection = 'master';

    protected $fillable = ['tenant_id', 'branch_id', 'revision', 'source_revision'];

    protected $casts = [
        'tenant_id' => 'integer',
        'branch_id' => 'integer',
        'revision' => 'integer',
    ];
}
