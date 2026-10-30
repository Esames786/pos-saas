<?php

namespace App\Support;

/**
 * CONTACT-FORM-1 — the business's WhatsApp / phone numbers, from one setting.
 *
 * `SAAS_CONTACT_PHONE` me ek ya kai number, comma se alag, poore international shakl me bina '+'
 * ke: `923328252838,923331279246`. Wahi shakl `wa.me` ka link maangta hai, aur parhne wali shakl
 * (+92 332 825 2838) yahan se khud banti hai — do jagah do shaklein rakhna hi ghalti ki jaR hai.
 *
 * Khaali ya abhi tak placeholder (`+92 XXX XXXXXXX`) ho to fehrist KHAALI aati hai, aur safha
 * number ka khana dikhata hi nahi. 25 Oct tak live safhe par "Phone is a placeholder — replace
 * before production" har visitor parh raha tha; jhoota number chhapne se behtar hai kuch na chhapna.
 */
class ContactNumbers
{
    /**
     * @return array<int, array{raw: string, display: string, wa: string}>
     */
    public static function all(): array
    {
        $raw = (string) config('saas.contact.phone', '');

        $numbers = [];
        foreach (preg_split('/[,;]+/', $raw) ?: [] as $entry) {
            // Sirf hindsay — spaces, dashes, brackets aur '+' sab hata do.
            $digits = preg_replace('/\D+/', '', (string) $entry) ?? '';

            // Placeholder me koi hindsa hota hi nahi ("+92 XXX XXXXXXX" se sirf "92" bachta hai),
            // aur koi asli number 8 hindson se chhota nahi hota.
            if (strlen($digits) < 8) {
                continue;
            }

            $numbers[$digits] = [
                'raw'     => $digits,
                'display' => self::display($digits),
                'wa'      => 'https://wa.me/' . $digits,
            ];
        }

        return array_values($numbers);   // ek hi number do baar likha ho to ek hi baar dikhe
    }

    public static function any(): bool
    {
        return self::all() !== [];
    }

    /** The first number — for places that show only one (the confirmation email). */
    public static function first(): ?array
    {
        return self::all()[0] ?? null;
    }

    /** Pakistani mobile numbers read as +92 332 825 2838; anything else is left alone. */
    private static function display(string $digits): string
    {
        if (preg_match('/^92(\d{3})(\d{3})(\d{4})$/', $digits, $m)) {
            return '+92 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
        }

        return '+' . $digits;
    }
}
