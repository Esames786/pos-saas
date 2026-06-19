<?php

namespace App\Models\Tenant;

use App\Mail\TenantPasswordResetMail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Traits\HasRoles;
use Throwable;

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

    /**
     * Send the password-reset link on the CURRENT tenant subdomain (PRD-5).
     * The reset request runs on the tenant host, so url() yields the tenant URL.
     * Mail failures are reported but never bubble up (avoids leaking enumeration
     * and never breaks the generic "link sent" response).
     */
    public function sendPasswordResetNotification($token): void
    {
        $email   = $this->getEmailForPasswordReset();
        $resetUrl = url('/reset-password/' . $token) . '?email=' . urlencode($email);

        try {
            Mail::to($email)->send(new TenantPasswordResetMail(
                brand: config('saas.brand_name', 'Bingoo'),
                resetUrl: $resetUrl,
                expireMinutes: (int) config('auth.passwords.tenant_users.expire', 60),
                supportEmail: config('saas.contact.support_email', 'support@bingoopos.com'),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
