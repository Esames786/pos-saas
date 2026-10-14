<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * CASH-BOOK-VOID-1 — Cash Book se dabaya gaya Void, kaam ke baad wapas Cash Book par.
 *
 * Har void apne safhe par lautata hai (receipt → booking, expense → voucher, journal → reversal).
 * Cash Book ki modal se aaya void `return_to` bhejta hai, taake accountant wahin wapas aaye jahan
 * se chala tha — usi tareekh aur usi drawer par.
 *
 * Sirf apni hi site ka Cash Book ka pata qubool: koi poora URL, `//host` ya doosra raasta nahi
 * (open redirect ka darwaza band).
 */
final class CashBookReturn
{
    private const PREFIX = '/reports/cash-book';

    public static function from(Request $request): ?string
    {
        $to = (string) $request->input('return_to', '');

        if ($to === '' || ! str_starts_with($to, self::PREFIX) || str_contains($to, '://') || str_contains($to, '\\')) {
            return null;
        }

        $rest = substr($to, strlen(self::PREFIX));

        return ($rest === '' || $rest[0] === '?') ? $to : null;
    }
}
