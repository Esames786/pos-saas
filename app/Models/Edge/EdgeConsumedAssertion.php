<?php

namespace App\Models\Edge;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE-LOCAL-AUTH-1 — one-time enrollment-assertion replay store. A jti may be consumed exactly once
 * (the UNIQUE index + a transaction make a concurrent double-submit enroll only once).
 */
class EdgeConsumedAssertion extends Model
{
    protected $connection = 'tenant';

    protected $table = 'edge_consumed_assertions';

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'activation_epoch' => 'integer',
        'consumed_at' => 'datetime',
    ];
}
