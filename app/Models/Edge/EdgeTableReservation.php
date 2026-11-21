<?php

namespace App\Models\Edge;

use Illuminate\Database\Eloquent\Model;

/**
 * OFFLINE EDGE — a local table reservation (Edge-owned operational state; see the migration).
 * active -> seated (table opened) | active -> cancelled.
 */
class EdgeTableReservation extends Model
{
    protected $connection = 'tenant';

    protected $table = 'edge_local_table_reservations';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SEATED = 'seated';
    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $casts = [
        'reserved_for' => 'datetime',
        'reserved_at' => 'datetime',
        'seated_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];
}
