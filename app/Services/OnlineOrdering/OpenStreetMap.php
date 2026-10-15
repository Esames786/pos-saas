<?php

namespace App\Services\OnlineOrdering;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * ONLINE-ORDERING-1 — OpenStreetMap (Nominatim): coordinates → jagah ka naam, aur naam → coordinates.
 *
 * Mufat service ke qaaide: apni pehchan (User-Agent) bhejni hai, aur bohot zyada requests nahi (≈1/second).
 * Is liye browser seedha nahi bulata — hamara server bulata hai, aur jawab cache hota hai (aas paas ke
 * customer, aik hi jawab). Na chale to null: website phir bhi chalti hai, customer khud area chun leta hai.
 */
class OpenStreetMap
{
    private const BASE = 'https://nominatim.openstreetmap.org';

    private const COUNTRY_CODES = ['pakistan' => 'pk', 'saudi arabia' => 'sa', 'united arab emirates' => 'ae', 'qatar' => 'qa'];

    /** "Pakistan" → "pk" (search sirf usi mulk me). */
    public static function countryCode(?string $country): ?string
    {
        return self::COUNTRY_CODES[mb_strtolower((string) $country)] ?? null;
    }

    /** @return array<string, mixed>|null Nominatim ka reverse jawab (address details samet). */
    public function reverse(float $lat, float $lng): ?array
    {
        // ~100 m ka khana: aik gali ke customer aik hi jawab, aur service par bojh nahi.
        $key = sprintf('osm:rev:%.3f:%.3f', $lat, $lng);
        try {
            if (is_array($hit = Cache::get($key))) {
                return $hit;
            }
        } catch (\Throwable) {
            // cache na chale to seedha poochh lo
        }
        $res = $this->get('/reverse', ['format' => 'jsonv2', 'lat' => $lat, 'lon' => $lng, 'zoom' => 18, 'addressdetails' => 1]);
        if ($res !== null) {
            try {
                Cache::put($key, $res, now()->addDays(30));   // nakami cache nahi — agli dafa phir koshish
            } catch (\Throwable) {
            }
        }

        return $res;
    }

    /** @return array{lat: float, lng: float, label: string}|null */
    public function search(string $query, ?string $countryCode = null): ?array
    {
        $rows = $this->get('/search', array_filter(['format' => 'jsonv2', 'q' => $query, 'limit' => 1, 'countrycodes' => $countryCode]));
        $hit = is_array($rows) ? ($rows[0] ?? null) : null;

        return $hit ? ['lat' => (float) $hit['lat'], 'lng' => (float) $hit['lon'], 'label' => (string) ($hit['display_name'] ?? '')] : null;
    }

    private function get(string $path, array $query): ?array
    {
        try {
            $res = Http::withHeaders(['User-Agent' => 'BingooPOS-OnlineOrdering/1.0 (+https://bingoopos.com)', 'Accept-Language' => 'en'])
                ->timeout(6)->get(self::BASE.$path, $query);

            return $res->successful() && is_array($res->json()) ? $res->json() : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
