<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class TenantDatabase extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'tenant_id',
        'db_connection',
        'db_host',
        'db_port',
        'db_database',
        'db_username',
        'db_password',
        'migration_status',
    ];

    protected function casts(): array
    {
        return [
            'db_password' => 'encrypted',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
