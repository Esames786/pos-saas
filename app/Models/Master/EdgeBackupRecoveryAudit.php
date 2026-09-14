<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/** P5B §3 — audit row of the Cloud backup recovery authority (issue / retrieve / rotate / refuse). Never key material. */
class EdgeBackupRecoveryAudit extends Model
{
    protected $connection = 'master';

    protected $table = 'edge_backup_recovery_audits';

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'branch_id', 'action', 'outcome', 'key_id', 'actor', 'detail', 'ip', 'created_at'];

    protected $casts = [
        'tenant_id' => 'integer',
        'branch_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
