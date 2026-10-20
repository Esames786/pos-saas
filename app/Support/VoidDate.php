<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * VOID-ON-ORIGINAL-DATE-1 — the one question every money void asks: which day does the undo land on?
 *
 *   'original' — "it was a wrong entry": the reversal is dated on the document's OWN date, so the money
 *                comes back the same day it went out and every balance from that day on is right.
 *                (QuickBooks / Xero call this Void.)
 *   'today'    — "it was right, now it is being undone": the reversal is dated today and earlier days
 *                stay as they were. (They call this Reverse.) This is what every void did until now, so
 *                it stays the default for any caller that does not ask.
 *
 * Only money voids offer the choice. Stock and POS voids stay on today: dating stock back would rewrite
 * average cost and FEFO history and the counted cash of a closed shift.
 */
final class VoidDate
{
    public const TODAY = 'today';

    public const ORIGINAL = 'original';

    public const MODES = [self::ORIGINAL, self::TODAY];

    /** Plain words for the two picture cards (English, then Roman Urdu help). */
    public const LABELS = [
        self::ORIGINAL => [
            'title' => 'Wrong entry — wipe it on its own date',
            'help' => 'Ghalat entry thi: usi din mita do, jaise kabhi hui hi nahi. Us din se aage ke balance theek ho jate hain.',
        ],
        self::TODAY => [
            'title' => 'It was right — reverse it today',
            'help' => 'Entry sahi thi, ab wapas karni hai: aaj ki tareekh par ulta karo. Purane din waise hi rehte hain.',
        ],
    ];

    /** Validation rule for the request field. */
    public static function rule(): array
    {
        return ['nullable', 'in:' . implode(',', self::MODES)];
    }

    /** Normalise what a request sent; anything unknown or missing is the old behaviour. */
    public static function mode(?string $raw): string
    {
        return in_array($raw, self::MODES, true) ? $raw : self::TODAY;
    }

    /**
     * The date the undo is posted on. `$originalDate` is the document's own business date.
     * A wrong entry dated in the future (a post-dated cheque) is still wiped on its own date.
     */
    public static function resolve(string $mode, Carbon|string|null $originalDate): string
    {
        if ($mode !== self::ORIGINAL) {
            return now()->toDateString();
        }
        if ($originalDate === null || $originalDate === '') {
            throw new InvalidArgumentException('This entry has no date of its own to wipe it on — reverse it today instead.');
        }

        return Carbon::parse($originalDate)->toDateString();
    }
}
