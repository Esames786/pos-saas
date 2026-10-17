<?php

namespace App\Services\OnlineOrdering;

use App\Models\Master\GeoArea;
use App\Models\Master\TenantDomain;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\WebSetting;
use Illuminate\Http\Request;

/**
 * ONLINE-ORDERING-1 — website ki SEO, har restaurant ki apni (Website Settings → "SEO & pages").
 *
 * Google sirf restaurant ke APNE domain (kashiffood.com) ko index kare. Wohi website *.bingoopos.com par bhi
 * khulti hai (POS ka subdomain /order, UAT kashiffoodweb.bingoopos.com) — wahan noindex, aur canonical apne
 * domain ki taraf, warna Google do jagah aik hi safha dekhta hai.
 */
class WebsiteSeo
{
    /** Ye request restaurant ke apne domain par hai (bingoopos ka subdomain nahi)? */
    public static function onOwnDomain(Request $request): bool
    {
        return $request->attributes->get('tenant_domain_kind') === TenantDomain::KIND_WEBSITE
            && ! str_ends_with($request->getHost(), '.'.config('tenancy.tenant_base_domain'));
    }

    /** Restaurant ka apna website domain, agar laga ho. */
    public static function ownDomain(): ?string
    {
        if (! app()->bound('tenant')) {
            return null;
        }
        $base = '.'.config('tenancy.tenant_base_domain');

        return TenantDomain::where('tenant_id', app('tenant')->id)->where('kind', TenantDomain::KIND_WEBSITE)
            ->where('status', 'active')->orderBy('id')->pluck('domain')
            ->first(fn ($d) => ! str_ends_with((string) $d, $base));
    }

    /** Website ke safhe ka raasta: apne domain par "/faqs", baqi jagah "/order/faqs". */
    public static function path(Request $request, string $page = ''): string
    {
        $base = self::onOwnDomain($request) ? '' : '/order';

        return ($base.($page !== '' ? '/'.ltrim($page, '/') : '')) ?: '/';
    }

    /** Google ko batane wala asal pata: apna domain ho to wahan, warna yahi. */
    public static function canonical(Request $request, string $page = ''): string
    {
        $own = self::ownDomain();
        if ($own) {
            return 'https://'.$own.($page !== '' ? '/'.ltrim($page, '/') : '/');
        }

        return url(self::path($request, $page));
    }

    /** @return array{title: string, description: string, keywords: ?string} */
    public static function meta(WebSetting $s, string $business): array
    {
        return [
            'title' => trim((string) $s->seo_title) ?: $business.' — Order Online',
            'description' => trim((string) $s->seo_description) ?: 'Order online from '.$business.'. Delivery and pick-up.',
            'keywords' => trim((string) $s->seo_keywords) ?: null,
        ];
    }

    /** Google ke liye restaurant ki pehchan (schema.org Restaurant): naam, pata, phone, waqt, menu, social. */
    public static function restaurantLd(WebSetting $s, string $business, string $url, ?string $logo, array $banners): array
    {
        $zone = DeliveryZone::where('is_active', true)->orderBy('id')->first(['city', 'geo_area_id']);
        $country = $zone?->geo_area_id ? GeoArea::whereKey($zone->geo_area_id)->value('country') : null;
        $days = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
        $hours = [];
        foreach ((array) ($s->hours ?: WebSetting::defaultHours()) as $d => $h) {
            if (isset($days[$d]) && empty($h['closed']) && ! empty($h['open']) && ! empty($h['close'])) {
                $hours[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days[$d], 'opens' => $h['open'], 'closes' => $h['close']];
            }
        }

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Restaurant',
            'name' => $business,
            'url' => $url,
            'logo' => $logo,
            'image' => array_values(array_filter(array_merge([$logo], array_map(fn ($b) => $b['src'] ?? null, array_slice($banners, 0, 3))))) ?: null,
            'description' => trim((string) $s->about_text) ?: self::meta($s, $business)['description'],
            'telephone' => $s->contact_phone,
            'email' => $s->contact_email,
            'address' => $s->contact_address ? array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $s->contact_address,
                'addressLocality' => $zone?->city,
                'addressCountry' => $country ? (strtoupper((string) OpenStreetMap::countryCode($country)) ?: null) : null,
            ]) : null,
            'openingHoursSpecification' => $hours ?: null,
            'hasMenu' => $url,
            'acceptsReservations' => false,
            'sameAs' => array_values(array_filter([$s->facebook_url, $s->instagram_url])) ?: null,
            'potentialAction' => ['@type' => 'OrderAction', 'target' => $url],
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
