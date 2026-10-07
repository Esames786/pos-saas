<?php

namespace Tests\MySql;

use App\Support\PublicLocale;
use App\Support\PublicTranslations;
use Illuminate\Support\Facades\Artisan;

/**
 * WEBSITE-I18N-GEO-1 P1 — bingoopos.com in Arabic.
 *
 * Every public page exists twice: /pricing (English, as always) and /ar/pricing (Arabic, right to
 * left). The language comes from the URL, so a search engine indexes each language and the back
 * office's own language switch never leaks onto the website. These go through the REAL routes and
 * middleware, not a view rendered by hand.
 */
class PublicSiteLanguageMySqlTest extends MySqlTenantTestCase
{
    private const PAGES = ['/', '/pricing', '/features', '/demos', '/start-trial', '/contact', '/terms', '/privacy', '/refund-policy', '/support-policy'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.public_site_mode' => 'live']);
    }

    private function central(string $path): string
    {
        return 'http://' . config('tenancy.central_domain') . $path;
    }

    private function arabic(string $page): string
    {
        return $page === '/' ? '/ar' : '/ar' . $page;
    }

    public function test_every_sentence_on_the_public_site_has_an_arabic_translation(): void
    {
        $audit = PublicTranslations::audit('ar');
        $this->assertSame([], $audit['missing'], 'an English sentence would show on the Arabic site');
        $this->assertSame([], $audit['empty']);
        $this->assertGreaterThan(600, count(PublicTranslations::keys()), 'the scan found the site\'s sentences — this check proves something');

        $ar = PublicTranslations::load('ar');
        foreach (PublicTranslations::keys() as $key => $where) {
            $this->assertNotContains(strtolower($key), PublicTranslations::RESERVED, "{$where}: \"{$key}\" is also a translation file name");
            preg_match_all('/:[a-z_]+/', $key, $m);
            foreach ($m[0] as $placeholder) {
                $this->assertStringContainsString($placeholder, $ar[$key], "{$placeholder} lost in the Arabic for \"{$key}\"");
            }
        }
    }

    public function test_arabic_pages_are_arabic_right_to_left_and_name_their_english_twin(): void
    {
        foreach (self::PAGES as $page) {
            $html = $this->get($this->central($this->arabic($page)))->assertOk()->getContent();

            $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html, $page);
            $this->assertStringContainsString('assets/css/bootstrap.rtl.min.css', $html, $page);
            $this->assertStringContainsString('hreflang="en" href="' . $this->central($page === '/' ? '' : $page), $html, "{$page}: hreflang to English");
            $this->assertStringContainsString('hreflang="x-default"', $html, $page);
            $this->assertStringContainsString('id="lang-switch-en"', $html, "{$page}: a way back to English");
            $this->assertSame([], $this->englishLeftOn($html), "{$page}: English left on the Arabic page");
        }
    }

    public function test_english_pages_keep_their_urls_and_stay_left_to_right(): void
    {
        foreach (self::PAGES as $page) {
            $html = $this->get($this->central($page))->assertOk()->getContent();
            $this->assertStringContainsString('<html lang="en" dir="ltr">', $html, $page);
            $this->assertStringContainsString('assets/css/bootstrap.min.css', $html, $page);
            $this->assertStringContainsString('id="lang-switch-ar"', $html, "{$page}: a way to Arabic");
        }
        $this->assertStringContainsString('href="' . $this->central('/features') . '"', $this->get($this->central('/pricing'))->getContent());
    }

    public function test_links_and_the_signup_form_stay_in_arabic(): void
    {
        // Every link from an Arabic page to another page of the site stays Arabic — the nav, the
        // footer, the cards. Only a link marked as another language (the switch, a legal page's "English
        // version") and the back-office login lead out of it.
        foreach (self::PAGES as $page) {
            $html = $this->get($this->central($this->arabic($page)))->getContent();
            preg_match_all('#<a\b[^>]*\bhref="' . preg_quote($this->central(''), '#') . '(/[^"]*)?"[^>]*>#', $html, $links, PREG_SET_ORDER);
            $this->assertNotEmpty($links, "{$page}: no internal links found — this check would prove nothing");
            foreach ($links as $link) {
                $path = $link[1] ?? '/';
                if (str_contains($link[0], 'lang-switch-') || str_contains($link[0], 'hreflang=') || str_starts_with($path, '/login')) {
                    continue;
                }
                $this->assertMatchesRegularExpression('#^/ar(/|$|\?|\#)#', $path, "{$page}: link to the English page {$path}");
            }
        }

        $pricing = $this->get($this->central('/ar/pricing'))->getContent();
        $this->assertStringContainsString('href="' . $this->central('/ar/features') . '"', $pricing, 'navigation stays Arabic');
        $this->assertStringContainsString('href="' . $this->central('/ar/start-trial'), $pricing, 'trial links stay Arabic');

        $checkout = $this->get($this->central('/ar/start-trial?plan=x&billing=yearly'))->getContent();
        $this->assertStringContainsString('action="' . $this->central('/ar/start-trial') . '"', $checkout, 'the form posts back in Arabic');
        $this->assertMatchesRegularExpression('/<div class="input-group" dir="ltr">/', $checkout, 'a web address reads left to right in Arabic too');
        // The query survives a switch (Laravel normalises its order, so match the parts).
        $this->assertMatchesRegularExpression('#lang-switch-en" href="' . preg_quote($this->central('/start-trial?'), '#') . '[^"]*billing=yearly#', $checkout,
            'switching language keeps the chosen cycle');
        $this->assertMatchesRegularExpression('#lang-switch-en" href="[^"]*plan=x#', $checkout, 'and the chosen plan');
    }

    public function test_signup_errors_come_back_in_arabic(): void
    {
        $this->from($this->central('/ar/start-trial'))->post($this->central('/ar/start-trial'), ['billing_period' => 'yearly'])
            ->assertRedirect($this->central('/ar/start-trial'));

        $message = session('errors')->first('business_name');
        $this->assertStringContainsString('اسم النشاط التجاري', $message);
        $this->assertStringContainsString('مطلوب', $message);
    }

    public function test_the_back_office_language_never_leaks_onto_the_website(): void
    {
        // An owner who uses the POS in Arabic still gets the English page at the English address.
        $html = $this->withSession(['locale' => 'ar'])->get($this->central('/pricing'))->getContent();
        $this->assertStringContainsString('<html lang="en" dir="ltr">', $html);
    }

    public function test_the_sitemap_lists_every_page_in_both_languages(): void
    {
        $xml = $this->get($this->central('/sitemap.xml'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertSame(count(self::PAGES) * 2, substr_count($xml, '<loc>'));
        $this->assertStringContainsString('<loc>' . $this->central('/ar/pricing') . '</loc>', $xml);
        $this->assertStringContainsString('hreflang="x-default" href="' . $this->central('/pricing') . '"', $xml);
    }

    public function test_language_urls(): void
    {
        $this->assertSame('/pricing', PublicLocale::strip('ar/pricing'));
        $this->assertSame('/', PublicLocale::strip('ar'));
        $this->assertSame('/arabic-food', PublicLocale::strip('arabic-food'), 'only a whole path segment is a language');
        $this->assertSame(url('/ar/start-trial?plan=x'), PublicLocale::url('/start-trial?plan=x', 'ar'));
        $this->assertSame(url('/'), PublicLocale::url('/', 'en'));

        config(['saas.public_locales_enabled' => ['en']]);
        $this->assertSame([], PublicLocale::prefixed(), 'turning Arabic off removes its prefix');
        $this->assertSame(url('/pricing'), PublicLocale::url('/pricing', 'ar'), 'a disabled language falls back to English');
    }

    public function test_a_reviewer_sheet_goes_out_and_comes_back(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'lang') . '.csv';
        $target = PublicTranslations::path('zz');
        @unlink($target);
        try {
            $this->assertSame(0, Artisan::call('lang:export', ['locale' => 'zz', 'path' => $csv]));
            $rows = array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES));
            $this->assertGreaterThan(600, count($rows));

            // The reviewer fills one row and leaves the rest empty.
            $out = fopen($csv, 'w');
            fputcsv($out, ['key (English)', 'where', 'zz', 'notes']);
            fputcsv($out, ['Pricing', 'x', 'PRICES', '']);
            fputcsv($out, ['Not a sentence the site uses', 'x', 'IGNORED', '']);
            fclose($out);

            Artisan::call('lang:import', ['locale' => 'zz', 'path' => $csv, '--dry-run' => true]);
            $this->assertFileDoesNotExist($target, '--dry-run writes nothing');

            Artisan::call('lang:import', ['locale' => 'zz', 'path' => $csv]);
            $this->assertSame(['Pricing' => 'PRICES'], PublicTranslations::load('zz'), 'only sentences the site uses are taken');
        } finally {
            @unlink($csv);
            @unlink($target);
        }
    }

    public function test_every_script_on_the_arabic_pages_parses(): void
    {
        $node = collect([getenv('NODE_BINARY') ?: null, ...(glob('D:/laragon2/bin/nodejs/*/node.exe') ?: [])])
            ->filter(fn ($p) => $p && is_file($p))->first()
            ?? strtok(trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null')), "\r\n");
        if (! $node || ! is_file($node)) {
            $this->markTestSkipped('node not found (set NODE_BINARY)');
        }
        foreach (['/ar/pricing', '/ar/start-trial', '/ar/demos'] as $page) {
            preg_match_all('#<script([^>]*)>(.*?)</script>#s', $this->get($this->central($page))->getContent(), $m, PREG_SET_ORDER);
            foreach ($m as $i => $s) {
                if (str_contains($s[1], 'src=')) {
                    continue;
                }
                $file = tempnam(sys_get_temp_dir(), 'ar') . '.js';
                file_put_contents($file, $s[2]);
                exec(escapeshellarg($node) . ' --check ' . escapeshellarg($file) . ' 2>&1', $out, $code);
                @unlink($file);
                $this->assertSame(0, $code, "{$page} script #{$i}: " . implode("\n", $out ?? []));
                $out = [];
            }
        }
    }

    /** Runs of three or more English words in the visible text (brand and system names excepted). */
    private function englishLeftOn(string $html): array
    {
        $body = preg_replace(['#<script.*?</script>#s', '#<style.*?</style>#s', '#<head.*?</head>#s', '#<code[^>]*>.*?</code>#s', '#<bdi[^>]*>.*?</bdi>#s'], '', $html);
        $left = [];
        foreach (preg_split('/<[^>]+>/', $body) as $text) {
            $text = trim(html_entity_decode($text));
            if (preg_match('/[A-Za-z]{2,}(?:\s+[A-Za-z&\/\-]{2,}){2,}/', $text) && ! preg_match('/BINGOO POS|Bingoo POS|@|https?:/', $text)) {
                $left[] = $text;
            }
        }

        return $left;
    }
}
