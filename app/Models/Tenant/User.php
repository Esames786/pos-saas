<?php

namespace App\Models\Tenant;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use Notifiable, HasRoles;

    protected $connection = 'tenant';

    protected $fillable = [
        'name',
        'email',
        'password',
        'employee_code',
        'phone',
        'default_branch_id',
        'default_terminal_id',
        'status',
        'force_password_change',
        'last_login_at',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password'              => 'hashed',
            'email_verified_at'     => 'datetime',
            'last_login_at'         => 'datetime',
            'force_password_change' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function defaultBranch()
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function defaultTerminal()
    {
        return $this->belongsTo(Terminal::class, 'default_terminal_id');
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'branch_user')
            ->withPivot('is_default', 'is_active')
            ->withTimestamps();
    }

    public function terminals()
    {
        return $this->belongsToMany(Terminal::class, 'terminal_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function canAccessBranch(int $branchId): bool
    {
        return $this->branches()->where('branch_id', $branchId)->exists();
    }

    public function canAccessTerminal(int $terminalId): bool
    {
        return $this->terminals()->where('terminal_id', $terminalId)->exists();
    }
}
