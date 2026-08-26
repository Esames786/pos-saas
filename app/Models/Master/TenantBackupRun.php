<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * TENANT-AUTO-BACKUP-1 — one claim/history row per (tenant, local slot date, slot time). The unique
 * (tenant_id, slot_date, slot_time) index makes each scheduled slot fire exactly once per day.
 */
class TenantBackupRun extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'tenant_id', 'slot_date', 'slot_time', 'tenant_backup_id', 'status', 'error',
    ];

    protected function casts(): array
    {
        return [
            'slot_date' => 'date',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function backup()
    {
        return $this->belongsTo(TenantBackup::class, 'tenant_backup_id');
    }
}
