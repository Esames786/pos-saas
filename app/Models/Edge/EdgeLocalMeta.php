<?php

namespace App\Models\Edge;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE-LOCAL-RUNTIME-1 — the appliance's single immutable binding + runtime-state row, stored in the
 * Edge-local database (the `tenant` connection on a branch_server runtime). There is exactly one row
 * (singleton_guard). Written only by the guarded local db-init / bootstrap-import path; read by the
 * runtime readiness surface and EdgeBranchContext.
 */
class EdgeLocalMeta extends Model
{
    protected $connection = 'tenant';

    protected $table = 'edge_local_meta';

    /** Runtime states (NOT active/selling/synced — those capabilities do not exist yet). */
    public const STATE_UNINITIALIZED = 'uninitialized';
    public const STATE_IMPORTING = 'importing';
    public const STATE_BOOTSTRAPPED = 'bootstrapped';
    public const STATE_ERROR = 'error';

    /** The fixed singleton key. */
    public const SINGLETON = 1;

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'branch_id' => 'integer',
        'activation_epoch' => 'integer',
        'imported_at' => 'datetime',
    ];

    /** The single binding row, or null if the appliance has not been initialised/imported yet. */
    public static function current(): ?self
    {
        return static::query()->where('singleton_guard', self::SINGLETON)->first();
    }

    public function isBootstrapped(): bool
    {
        return $this->runtime_state === self::STATE_BOOTSTRAPPED;
    }
}
