<?php

namespace App\Models\Master;

use App\Models\Master\CentralUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TenantBackup extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'tenant_id', 'tenant_code', 'database_name', 'disk', 'path', 'file_name',
        'file_size', 'backup_type', 'status', 'created_by', 'restored_at', 'restored_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'file_size'   => 'integer',
            'restored_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator()
    {
        return $this->belongsTo(CentralUser::class, 'created_by');
    }

    /** True only if the underlying file still exists on its disk. */
    public function fileExists(): bool
    {
        return $this->status === 'completed'
            && $this->path
            && Storage::disk($this->disk)->exists($this->path);
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
