<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * TENANT-AUTO-BACKUP-1 — a tenant's automatic backup schedule (master DB). `times` is a JSON list of
 * up to three "HH:MM" strings interpreted in `timezone` (default Pakistan time); the dispatcher
 * snapshots the tenant at each and keeps `retention_days` of scheduled backups.
 */
class TenantBackupSetting extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'tenant_id', 'is_enabled', 'times', 'timezone', 'retention_days',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled'     => 'boolean',
            'times'          => 'array',
            'retention_days' => 'integer',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Normalised, de-duplicated, sorted list of valid "HH:MM" slots (max 3). */
    public function slotList(): array
    {
        $out = [];
        foreach ((array) ($this->times ?? []) as $t) {
            $t = trim((string) $t);
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $t)) {
                $out[$t] = true;
            }
        }

        $slots = array_keys($out);
        sort($slots);

        return array_slice($slots, 0, 3);
    }
}
