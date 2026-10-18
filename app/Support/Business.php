<?php

namespace App\Support;

use App\Models\Master\Tenant;

/**
 * PRIMARY-BUSINESS-1 — the one place that knows what each kind of business is.
 *
 * A tenant's primary business is set in Bingoo Master (never guessed from modules). Screens ask this
 * class — never their own copy — what to show: the product types, the words, the top button.
 * NULL (not set yet) means "behave exactly as before".
 */
final class Business
{
    public const RESTAURANT = 'restaurant';
    public const CATERING   = 'catering';
    public const PHARMACY   = 'pharmacy';
    public const MART       = 'mart';
    public const INDUSTRY   = 'industry';

    /** @return array<string, array{label:string, label_ur:string, image:string, hint:string, needs:array<int,string>, button:array{label:string,url:string,module:string}}> */
    public static function all(): array
    {
        return [
            self::RESTAURANT => [
                'label' => 'Restaurant', 'label_ur' => 'ریسٹورنٹ', 'image' => 'assets/img/business/restaurant.png',
                'hint' => 'Dine-in, takeaway, delivery', 'needs' => ['pos'],
                'button' => ['label' => 'POS', 'url' => '/pos', 'module' => 'pos'],
            ],
            self::CATERING => [
                'label' => 'Catering', 'label_ur' => 'کیٹرنگ', 'image' => 'assets/img/business/catering.png',
                'hint' => 'Events, degs, per head', 'needs' => ['catering'],
                'button' => ['label' => 'Events', 'url' => '/catering/events', 'module' => 'catering'],
            ],
            self::PHARMACY => [
                'label' => 'Medical Store', 'label_ur' => 'میڈیکل اسٹور', 'image' => 'assets/img/business/pharmacy.png',
                'hint' => 'Medicine, batch, expiry', 'needs' => ['pos', 'inventory', 'purchasing'],
                'button' => ['label' => 'Counter', 'url' => '/pos', 'module' => 'pos'],
            ],
            self::MART => [
                'label' => 'Mart / Shop', 'label_ur' => 'مارٹ / دکان', 'image' => 'assets/img/business/mart.png',
                'hint' => 'Barcode, loose items by weight', 'needs' => ['pos'],
                'button' => ['label' => 'Counter', 'url' => '/pos', 'module' => 'pos'],
            ],
            self::INDUSTRY => [
                'label' => 'Industry', 'label_ur' => 'انڈسٹری', 'image' => 'assets/img/business/industry.png',
                'hint' => 'Raw material, BOM, production', 'needs' => ['manufacturing'],
                'button' => ['label' => 'Production', 'url' => '/manufacturing/bom', 'module' => 'manufacturing'],
            ],
        ];
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::all());
    }

    public static function label(?string $key): string
    {
        return self::all()[$key]['label'] ?? 'Not set';
    }

    /** The current request's tenant (or the given one), its primary business, or null when not set. */
    public static function of(?Tenant $tenant = null): ?string
    {
        $tenant ??= app()->bound('tenant') ? app('tenant') : null;
        $key = $tenant?->primary_business;

        return self::exists($key) ? $key : null;
    }

    /**
     * Modules this business needs that the plan does not include — a reason to refuse the change.
     *
     * @param  array<int,string>  $planModules
     * @return array<int,string>
     */
    public static function missingModules(string $key, array $planModules): array
    {
        return array_values(array_diff(self::all()[$key]['needs'] ?? [], $planModules));
    }
}
