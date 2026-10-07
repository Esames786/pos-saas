<?php

namespace Tests\MySql;

use App\Support\PublicTranslations;

/**
 * WEBSITE-I18N-GEO-1 P5 — the cookie question.
 *
 * No measurement ID → nothing to ask: no banner, nothing from Google. With an ID, Google's script is
 * not on the page until the visitor says yes; "Necessary only" is as easy as yes; the answer is
 * remembered in a cookie the page itself can read (so it must not be encrypted); and the privacy page
 * says what each cookie is, in English and in Arabic.
 */
class CookieConsentMySqlTest extends MySqlTenantTestCase
{
    private const GOOGLE_SCRIPT = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST1234"';

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.public_site_mode' => 'live', 'saas.analytics.ga4_id' => 'G-TEST1234']);
    }

    private function central(string $path): string
    {
        return 'http://' . config('tenancy.central_domain') . $path;
    }

    private function bannerShown(string $html): bool
    {
        if (! preg_match('/<section id="cookie-consent"[^>]*>/', $html, $m)) {
            return false;
        }

        return ! preg_match('/\shidden[\s>]/', $m[0]);
    }

    public function test_no_measurement_id_means_no_question_and_no_google(): void
    {
        foreach ([null, '', 'UA-12345-1', 'G-<script>'] as $id) {
            config(['saas.analytics.ga4_id' => $id]);
            foreach (['/', '/pricing', '/ar/pricing', '/start-trial'] as $page) {
                $html = $this->get($this->central($page))->assertOk()->getContent();
                $this->assertStringNotContainsString('cookie-consent', $html, "{$page} with " . var_export($id, true));
                $this->assertStringNotContainsString('googletagmanager', $html, $page);
                $this->assertStringNotContainsString('data-cookie-settings', $html, $page);
            }
        }
    }

    public function test_google_waits_for_a_yes(): void
    {
        $html = $this->get($this->central('/pricing'))->assertOk()->getContent();
        $this->assertTrue($this->bannerShown($html), 'a first visit is asked');
        $this->assertStringNotContainsString(self::GOOGLE_SCRIPT, $html, 'nothing from Google before the answer');
        $this->assertStringContainsString('data-consent="0"', $html, 'a way to say no …');
        $this->assertStringContainsString('data-consent="1"', $html, '… beside the way to say yes');
        $this->assertMatchesRegularExpression('/<a [^>]*href="[^"]*\/privacy#cookies"[^>]*data-cookie-settings/', $html, 'the footer can open the question again');

        // The page writes the answer itself — Laravel must hand it over unencrypted, or a yes is never read.
        $yes = $this->withUnencryptedCookie('bingoo_consent', 'v1.a1')->get($this->central('/pricing'))->getContent();
        $this->assertStringContainsString(self::GOOGLE_SCRIPT, $yes, 'after a yes, analytics loads');
        $this->assertFalse($this->bannerShown($yes), 'and nobody is asked twice');

        $no = $this->withUnencryptedCookie('bingoo_consent', 'v1.a0')->get($this->central('/pricing'))->getContent();
        $this->assertStringNotContainsString(self::GOOGLE_SCRIPT, $no, 'a no is a no');
        $this->assertFalse($this->bannerShown($no));

        foreach (['yes', 'v1.a1x', 'v0.a1', '1'] as $junk) {
            $html = $this->withUnencryptedCookie('bingoo_consent', $junk)->get($this->central('/pricing'))->getContent();
            $this->assertTrue($this->bannerShown($html), "\"{$junk}\" is not an answer — ask");
            $this->assertStringNotContainsString(self::GOOGLE_SCRIPT, $html, "\"{$junk}\" is not a yes");
        }
    }

    public function test_the_question_is_asked_in_arabic_on_the_arabic_site(): void
    {
        $ar = PublicTranslations::load('ar');
        $html = $this->get($this->central('/ar/pricing'))->assertOk()->getContent();
        $this->assertTrue($this->bannerShown($html));
        foreach (['Cookies on this site', 'Necessary only', 'Allow analytics', 'Cookie settings'] as $line) {
            $this->assertNotEmpty($ar[$line] ?? null, "\"{$line}\" has no Arabic");
            $this->assertStringContainsString(e($ar[$line]), $html, "\"{$line}\" in Arabic on the page");
        }
        $this->assertStringContainsString('href="http://' . config('tenancy.central_domain') . '/ar/privacy#cookies"', $html, 'the details link stays in Arabic');
    }

    public function test_the_privacy_page_names_every_cookie(): void
    {
        foreach (['/privacy', '/ar/privacy'] as $page) {
            $html = $this->get($this->central($page))->assertOk()->getContent();
            $this->assertStringContainsString('id="cookies"', $html, $page);
            foreach (['bingoo_lang', 'bingoo_market', 'bingoo_consent', '_ga', 'DB-IP'] as $name) {
                $this->assertStringContainsString($name, $html, "{$page}: {$name}");
            }
        }
    }
}
