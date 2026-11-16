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
        // SEO aur website ke safhe (Privacy Policy, FAQs) — har restaurant ke apne.
        'seo_title', 'seo_description', 'seo_keywords', 'privacy_policy', 'faqs',
        // DELIVERY-RADIUS-1: area (har area ki fee) | distance (branch se doori ke bands).
        'delivery_fee_mode',
        // WEB-PICKUP-SWITCH-1: website par Pick-Up on/off.
        'pickup_enabled',
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
            'faqs' => 'array',
            'pickup_enabled' => 'boolean',
        ];
    }

    /** Website par Pick-Up chalta hai? Khaali (nayi row jo DB ka default wapas nahi parhti) = haan. */
    public function pickupOn(): bool
    {
        return $this->pickup_enabled !== false;
    }

    /** @return list<array{category: string, q: string, a: string}> */
    public function faqList(): array
    {
        return array_values(array_filter(array_map(fn ($f) => is_array($f) && trim((string) ($f['q'] ?? '')) !== '' ? [
            'category' => trim((string) ($f['category'] ?? '')) ?: 'General',
            'q' => trim((string) $f['q']),
            'a' => trim((string) ($f['a'] ?? '')),
        ] : null, (array) ($this->faqs ?? []))));
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
