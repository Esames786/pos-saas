<?php

namespace App\Services\OnlineOrdering;

/**
 * "24.9521, 67.1412" ya Google Maps ka link (…@24.95,67.14… / ?q=24.95,67.14 / !3d24.95!4d67.14) → [lat, lng].
 * Chhota share link (maps.app.goo.gl, share.google) me nuqta nahi hota — us ke liye [null, null].
 */
final class MapPoint
{
    /** @return array{0: ?float, 1: ?float} */
    public static function parse(?string $raw): array
    {
        $raw = (string) $raw;
        if (preg_match('/!3d(-?\d{1,2}\.\d{3,})!4d(-?\d{1,3}\.\d{3,})/', $raw, $m)
            || preg_match('/(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/', $raw, $m)) {
            $lat = (float) $m[1];
            $lng = (float) $m[2];
            if (abs($lat) <= 90 && abs($lng) <= 180) {
                return [$lat, $lng];
            }
        }

        return [null, null];
    }
}
