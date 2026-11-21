<?php

namespace App\Support;

/**
 * PHASE 4 (offline add-customer) — THE ONE phone normaliser shared by the Cloud and every Branch Server.
 *
 * `customers.phone_normalized` is the cross-system MATCH KEY for "is this the same customer?" (the Cloud's deterministic
 * create-or-match on an offline-created customer, the appliance's local duplicate lookup, Online's quick-store reuse).
 * Both sides MUST compute it identically or an offline customer could be duplicated on the Cloud — so the rule lives here
 * once and is deliberately small, total and stable:
 *
 *   1. keep digits only ("+92 (300) 123-4567" → "923001234567");
 *   2. Pakistani international spellings collapse to the national one:
 *        0092XXXXXXXXXX → 0XXXXXXXXXX,   92XXXXXXXXXX (12 digits) → 0XXXXXXXXXX,
 *        3XXXXXXXXX (10 digits, the leading 0 dropped) → 03XXXXXXXXX;
 *   3. anything else (landlines, foreign numbers, short codes) stays as its digits;
 *   4. no digits → null.
 *
 * It is NOT the stored spelling of `customers.phone` (Online keeps CustomerDirectory::normalizePhone = digits only for
 * that; this normaliser only feeds the match column). Bounded to 32 characters (the column width).
 */
final class PhoneNormalizer
{
    public const MAX_LENGTH = 32;

    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0092') && strlen($digits) === 14) {
            $digits = '0' . substr($digits, 4);
        } elseif (str_starts_with($digits, '92') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (str_starts_with($digits, '3') && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }

        return substr($digits, 0, self::MAX_LENGTH);
    }

    /** True when two spellings name the same phone under this rule (null/empty never matches anything). */
    public static function same(?string $a, ?string $b): bool
    {
        $na = self::normalize($a);

        return $na !== null && $na === self::normalize($b);
    }
}
