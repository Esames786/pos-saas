<?php

namespace App\Services\OnlineOrdering;

use App\Models\Tenant\WebSetting;

/**
 * ONLINE-ORDERING-1 — website ka logo aur banner slides. Settings screen aur import command dono yahi
 * istemal karte hain. Tasveer hamesha chhoti WebP me (banner 1920 + 960 mobile, logo 320).
 */
class WebsiteBranding
{
    public const MAX_BANNERS = 8;

    public function __construct(private readonly WebImageService $images) {}

    public function setLogo(WebSetting $settings, string $sourcePath, string $tenantCode): void
    {
        $paths = $this->images->storeWidths($sourcePath, $tenantCode, 'logo', [320], 85);
        $this->images->storeSquarePngs($sourcePath, self::iconBase($paths[320]), [192, 512]);
        $old = $settings->logo_path;
        $settings->update(['logo_path' => $paths[320]]);
        $this->images->deleteFiles(self::logoFiles($old));
    }

    public function removeLogo(WebSetting $settings): void
    {
        $this->images->deleteFiles(self::logoFiles($settings->logo_path));
        $settings->update(['logo_path' => null]);
    }

    public function addBanner(WebSetting $settings, string $sourcePath, string $tenantCode): void
    {
        $banners = array_values((array) ($settings->banners ?? []));
        if (count($banners) >= self::MAX_BANNERS) {
            return;
        }
        $paths = $this->images->storeWidths($sourcePath, $tenantCode, 'banner', [1920, 960]);
        $banners[] = ['path' => $paths[1920], 'sm' => $paths[960]];
        $settings->update(['banners' => $banners]);
    }

    /** @param  list<int>  $indexes */
    public function removeBanners(WebSetting $settings, array $indexes): void
    {
        $banners = array_values((array) ($settings->banners ?? []));
        $gone = [];
        foreach ($indexes as $i) {
            if (isset($banners[$i])) {
                $gone[] = $banners[$i]['path'] ?? null;
                $gone[] = $banners[$i]['sm'] ?? null;
                unset($banners[$i]);
            }
        }
        $settings->update(['banners' => array_values($banners)]);
        $this->images->deleteFiles($gone);
    }

    /** Banner ko aage/peeche: $from ki jagah $to. */
    public function moveBanner(WebSetting $settings, int $from, int $to): void
    {
        $banners = array_values((array) ($settings->banners ?? []));
        if (! isset($banners[$from]) || $to < 0 || $to >= count($banners)) {
            return;
        }
        $item = array_splice($banners, $from, 1);
        array_splice($banners, $to, 0, $item);
        $settings->update(['banners' => $banners]);
    }

    /** logo-abc-320.webp → logo-abc (us ke app icons logo-abc-192.png / -512.png). */
    public static function iconBase(string $logoPath): string
    {
        return (string) preg_replace('/-320\.webp$/', '', $logoPath);
    }

    /** @return list<string|null> */
    private static function logoFiles(?string $logoPath): array
    {
        return $logoPath ? [$logoPath, self::iconBase($logoPath).'-192.png', self::iconBase($logoPath).'-512.png'] : [];
    }

    /** Customer ke page ke liye: urls. */
    public static function bannerUrls(WebSetting $settings): array
    {
        return collect((array) ($settings->banners ?? []))
            ->filter(fn ($b) => ! empty($b['path']))
            ->map(fn ($b) => ['src' => asset('storage/'.$b['path']), 'sm' => asset('storage/'.($b['sm'] ?? $b['path']))])
            ->values()->all();
    }
}
