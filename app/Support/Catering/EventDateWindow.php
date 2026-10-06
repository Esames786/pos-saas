<?php

namespace App\Support\Catering;

use Carbon\Carbon;

/**
 * CATERING-BALANCES-DATE-FILTER-1 — "kis din se kis din tak", EK jagah.
 *
 * Ye qaida pehle `CateringEventController` ke andar private tha. Jab Customer
 * Balances par wohi From/To maanga gaya to use naql karne ka aasan raasta
 * saamne tha — aur wohi ghalti hai jo is module me pehle do baar ho chuki hai
 * (ek hi sawal ke chaar jawab chaar jagah). Is liye wo qaida yahan utha liya
 * gaya aur dono screenein ab isi se poochhti hain.
 *
 * Do baatein jaan-boojh kar aisi hain:
 *
 *  • **Jo samajh na aaye wo KHALI hai, aaj nahi.** URL me kuch bhi likha ja
 *    sakta hai; na-samajh aane wali tareekh ko "aaj" maan lena filter ko chup
 *    chaap ek aisi muddat bana deta jo kisi ne maangi hi nahi thi.
 *
 *  • **Shakl ki poori jaanch.** `createFromFormat` "2026-13-45" jaise matn ko
 *    bhi nigal kar aage ki tareekh bana deta hai, is liye natije ko wapas
 *    likh kar asal matn se milaya jata hai.
 */
final class EventDateWindow
{
    /** URL ki ek tareekh — ya `null`, agar wo tareekh hai hi nahi. */
    public static function parse(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date->toDateString() : null;
    }

    /**
     * Dono sire — aur agar ulte likhe gaye hon to seedhe kar ke.
     *
     * Ulta likhna (From 30 Oct, To 1 Oct) hamesha ungli ki ghalti hoti hai, aur
     * us ka seedha natija EK KHALI FEHRIST hai: "koi graahak nahi mila". Wo
     * jawab jhoota nahi magar gumraah karta hai — parhne wala samajhta hai ke
     * is muddat me kaam hi nahi hua. Is liye sire badal diye jate hain.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function window(mixed $from, mixed $to): array
    {
        $from = self::parse($from);
        $to = self::parse($to);

        if ($from !== null && $to !== null && $from > $to) {
            return [$to, $from];
        }

        return [$from, $to];
    }
}
