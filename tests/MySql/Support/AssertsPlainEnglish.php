<?php

namespace Tests\MySql\Support;

/**
 * Review, 21 Oct: the purchasing interface reads in clear English. A rendered page (layout, labels, hints, messages and
 * the strings its scripts show) must carry no Roman Urdu. Urdu script is not checked here: Urdu product names and chosen
 * Urdu prints are data, and the small Urdu helper lines are an open owner decision.
 */
trait AssertsPlainEnglish
{
    /** Words that only Roman Urdu uses — whole words, any case. */
    private static array $romanUrdu = ['hai', 'hain', 'nahi', 'nahin', 'karo', 'karein', 'kijiye', 'kiya', 'mein', 'yeh', 'aur',
        'sab', 'maal', 'wapsi', 'wapas', 'ghata', 'ghatao', 'gaya', 'gayi', 'hatay', 'jayega', 'jayegi', 'jaye', 'uska', 'uski',
        'unka', 'pehle', 'chuka', 'chuki', 'raha', 'rahi', 'rahe', 'kaisa', 'kaise', 'kyun', 'abhi', 'lekin', 'sirf', 'hoga',
        'hogi', 'karna', 'wala', 'wali', 'liye', 'naam', 'saath', 'bilkul', 'zaroor', 'dobara', 'kitna', 'kitni', 'chahiye',
        'banao', 'likha', 'lazmi', 'khaata', 'khaate', 'hissa', 'chunein', 'chunain', 'chuna', 'nikla',
        'batana', 'zaroori', 'kis', 'hua', 'hota', 'jata', 'karta', 'karti', 'apna', 'apni', 'kijiye', 'dein', 'se', 'ka', 'ki', 'ke', 'ko', 'ye', 'wo', 'jo', 'bhi', 'tha', 'thi'];

    protected function assertNoRomanUrdu(string $html, string $where): void
    {
        $text = preg_replace(['/<style\b[^>]*>.*?<\/style>/is', '/<!--.*?-->/s'], ' ', $html);
        // a script's own comments are not shown to anyone; its strings are, so they stay in
        $text = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/is',
            fn ($m) => ' ' . preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m', '~\s//\s.*$~m'], ' ', $m[1]) . ' ', (string) $text);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $found = [];
        foreach (self::$romanUrdu as $word) {
            if (preg_match_all('/(?<![\p{L}\p{N}_\-])' . $word . '(?![\p{L}\p{N}_\-])/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$hit, $at]) {
                    $found[] = '"' . $hit . '" in …' . trim(mb_strcut($text, max(0, $at - 40), 90)) . '…';
                }
            }
        }
        $this->assertSame([], $found, $where . ': Roman Urdu on the page');
    }
}
