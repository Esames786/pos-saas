<?php

namespace App\Services\Saas;

use App\Models\Master\PlatformSetting;

/**
 * ONLINE-ORDERING-1 — Bingoo ke rates ka AIK darwaza.
 *
 * Rate master ki `platform_settings` me hai (malik: ".env me nahi, master me"). Row na ho, ya master
 * parhi na ja sake, to `.env` (config) ka sahara — taake deploy aur pehli bharai ke beech, ya kisi
 * kharabi me, WhatsApp report rukne ke bajaye purane rate par chalti rahe. Report har subah
 * pohanchni chahiye; hisaab ka masla usay nahi rok sakta.
 *
 * Rate har usage row par LIKHA jata hai (WhatsApp: `rate_charged`). Yahan se sirf naye kaam ke liye
 * parha jata hai; guzri rows aur band invoices kabhi yahan se dobara nahi nikalte.
 */
class BillingRates
{
    public const WHATSAPP_RATE = 'whatsapp_rate_pkr';

    public const WHATSAPP_COST = 'whatsapp_cost_pkr';

    public const WEBSITE_COMMISSION = 'website_commission_percent';

    /** Screen par dikhne wali tarteeb, aur har key ka `.env` sahara. */
    public const KEYS = [
        self::WHATSAPP_RATE => 'services.whatsapp.rate_pkr',
        self::WHATSAPP_COST => 'services.whatsapp.cost_pkr',
        self::WEBSITE_COMMISSION => 'services.online_ordering.commission_percent',
    ];

    /** @var array<string, string|null>|null aik request ke andar aik hi query */
    private ?array $loaded = null;

    public function whatsappRatePkr(): float
    {
        return $this->get(self::WHATSAPP_RATE);
    }

    public function whatsappCostPkr(): float
    {
        return $this->get(self::WHATSAPP_COST);
    }

    public function websiteCommissionPercent(): float
    {
        return $this->get(self::WEBSITE_COMMISSION);
    }

    public function get(string $key): float
    {
        $stored = $this->all()[$key] ?? null;

        if ($stored !== null && $stored !== '' && is_numeric($stored)) {
            return (float) $stored;
        }

        return (float) config(self::KEYS[$key] ?? '', 0);
    }

    /**
     * Rates likho. Sirf jaani pehchani keys; baqi chup-chaap chhoR di jati hain.
     *
     * @param  array<string, float|int|string>  $values
     */
    public function put(array $values, ?int $userId = null): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::KEYS)) {
                continue;
            }

            PlatformSetting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) (float) $value, 'updated_by_user_id' => $userId],
            );
        }

        $this->loaded = null;
    }

    /** @return array<string, string|null> */
    private function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        try {
            return $this->loaded = PlatformSetting::query()
                ->whereIn('key', array_keys(self::KEYS))
                ->pluck('value', 'key')
                ->all();
        } catch (\Throwable $e) {
            // Master na mile (ya table abhi bani na ho) to `.env` par chalo. Yaad na rakho, taake
            // agli dafa master theek ho to wahi parha jaye.
            report($e);

            return [];
        }
    }
}
