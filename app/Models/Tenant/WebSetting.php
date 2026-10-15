<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONLINE-ORDERING-1 — website ki settings, aik row.
 */
class WebSetting extends Model
{
    protected $connection = 'tenant';

    protected $table = 'web_settings';

    public const DAYS = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    ];

    protected $fillable = [
        'is_enabled', 'branch_mode', 'default_branch_id', 'default_eta_minutes', 'pickup_eta_minutes',
        'hours', 'closed_message', 'auto_cancel_minutes',
        'wa_notify_received', 'wa_notify_confirmed', 'wa_notify_cancelled', 'contact_phone',
        // Website ki shakl (logo, banners, footer, cart ka "Popular with your order").
        'logo_path', 'banners', 'whatsapp_number', 'contact_email', 'contact_address', 'facebook_url', 'instagram_url',
        'about_text', 'upsell_category_id', 'popular_auto',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'default_eta_minutes' => 'integer',
            'pickup_eta_minutes' => 'integer',
            'hours' => 'array',
            'auto_cancel_minutes' => 'integer',
            'wa_notify_received' => 'boolean',
            'wa_notify_confirmed' => 'boolean',
            'wa_notify_cancelled' => 'boolean',
            'banners' => 'array',
            'upsell_category_id' => 'integer',
            'popular_auto' => 'boolean',
        ];
    }

    /**
     * Aik hi row. Har default yahan saaf likha hai: firstOrCreate DB ke defaults wapas nahi parhta,
     * to yahan na likha boolean nayi row par null reh jata.
     */
    public static function tenantDefault(): self
    {
        return static::query()->orderBy('id')->firstOrCreate([], [
            'is_enabled' => false,
            'branch_mode' => 'area',
            'default_eta_minutes' => 45,
            'pickup_eta_minutes' => 25,
            'hours' => self::defaultHours(),
            'wa_notify_received' => true,
            'wa_notify_confirmed' => true,
            'wa_notify_cancelled' => true,
            'popular_auto' => true,
        ]);
    }

    /** @return array<string, array{open: string, close: string, closed: bool}> */
    public static function defaultHours(): array
    {
        return array_map(fn () => ['open' => '12:00', 'close' => '23:59', 'closed' => false], self::DAYS);
    }

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }
}
