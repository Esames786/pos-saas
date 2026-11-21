<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\Packaging\PhpIniValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 (stream E) — the portable php.ini contract (Khatri 0.8.4 finding: the package shipped Laragon's php.ini with
 * laptop-absolute paths). sanitize() turns a build-host ini into the packaged form; validate() refuses anything that still
 * names the build host. Pure string tests on fixture files (tests/Fixtures/edge/PhpIni).
 */
class PhpIniValidatorTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/edge/PhpIni/' . $name);
    }

    /** Every directive line (not a comment) of an ini. */
    private function activeLines(string $ini): array
    {
        return array_values(array_filter(preg_split('/\r\n|\n/', $ini), fn ($l) => trim($l) !== '' && ! in_array(trim($l)[0], [';', '['], true)));
    }

    public function test_a_laragon_like_ini_is_sanitised_into_the_portable_form(): void
    {
        $v = new PhpIniValidator(['C:\\Users\\builder']);
        $src = $this->fixture('laragon-like.ini');
        $before = $v->validate($src);
        $this->assertFalse($before['ok']);

        $out = $v->sanitize($src);
        $ini = str_replace("\r\n", "\n", $out['ini']); // the fixture may be checked out CRLF or LF
        $this->assertMatchesRegularExpression('/^extension_dir = "ext"$/m', $ini);
        $this->assertMatchesRegularExpression('/^include_path = "\."$/m', $ini);
        $this->assertMatchesRegularExpression('/^curl\.cainfo = "__BINGOO_PHP_DIR__\\\\cacert\.txt"$/m', $ini);
        $this->assertMatchesRegularExpression('/^openssl\.cafile = "__BINGOO_PHP_DIR__\\\\cacert\.txt"$/m', $ini, 'a missing openssl.cafile is added');
        foreach (['error_log', 'sendmail_path', 'session.save_path', 'upload_tmp_dir', 'soap.wsdl_cache_dir', 'openssl.capath', 'zend_extension'] as $key) {
            $this->assertMatchesRegularExpression('/^' . preg_quote(PhpIniValidator::COMMENT_TAG, '/') . '\s*' . preg_quote($key, '/') . '\s*=/m', $ini, "{$key} must be commented out");
        }
        // The [PATH=D:/laragon2/...] scope: header and its directives commented (never made global).
        $this->assertStringContainsString(PhpIniValidator::COMMENT_TAG . '[PATH=D:/laragon2/www/legacy]', $ini);
        $this->assertStringContainsString(PhpIniValidator::COMMENT_TAG . 'display_errors = Off', $ini);
        // Harmless non-path values are untouched.
        $this->assertMatchesRegularExpression('/^session\.cookie_path = \/$/m', $ini);
        $this->assertMatchesRegularExpression('/^date\.timezone = Asia\/Karachi$/m', $ini);
        $this->assertMatchesRegularExpression('/^extension=exif\s+; Must be after mbstring/m', $ini);
        // No ACTIVE line names the build host any more.
        foreach ($this->activeLines($ini) as $line) {
            $this->assertDoesNotMatchRegularExpression('/laragon|[A-Za-z]:[\\\\\/]|\\\\\\\\|C:\\\\Users/i', $line, 'active line still machine-bound: ' . $line);
        }
        $after = $v->validate($ini);
        $this->assertTrue($after['ok'], implode(' | ', $after['problems']));
        $this->assertSame('ext', $after['extension_dir']);
        $this->assertSame(PhpIniValidator::CA_VALUE, $after['curl_cainfo']);
        // The change log names keys + actions only.
        $actions = array_map(fn ($c) => $c['key'] . ':' . $c['action'], $out['changes']);
        $this->assertContains('extension_dir:set', $actions);
        $this->assertContains('openssl.cafile:added', $actions);
        $this->assertContains('zend_extension:commented_extension_by_path', $actions);
    }

    public function test_sanitize_is_idempotent_keeps_crlf_and_strips_a_bom(): void
    {
        $v = new PhpIniValidator();
        $src = "\xEF\xBB\xBF[PHP]\r\nextension_dir = \"D:\\php\\ext\"\r\nextension=curl\r\n";
        $once = $v->sanitize($src)['ini'];
        $this->assertStringStartsWith('[PHP]', $once, 'the BOM is removed');
        $this->assertStringContainsString("\r\n", $once, 'CRLF line endings are kept');
        $this->assertStringNotContainsString("\n\n\n", str_replace("\r", '', $once));
        $twice = $v->sanitize($once);
        $this->assertSame($once, $twice['ini']);
        $this->assertSame([], $twice['changes'], 'a sanitised ini needs no further change');
    }

    public function test_the_already_portable_ini_validates(): void
    {
        $result = (new PhpIniValidator())->validate($this->fixture('portable-ok.ini'));
        $this->assertTrue($result['ok'], implode(' | ', $result['problems']));
        $this->assertSame(['curl', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo_mysql', 'sodium'], $result['extensions']);
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function badInis(): array
    {
        $ok = "include_path = \".\"\nextension=curl\nextension=fileinfo\nextension=gd\nextension=mbstring\nextension=openssl\nextension=pdo_mysql\nextension=sodium\ncurl.cainfo = \"__BINGOO_PHP_DIR__\\cacert.txt\"\nopenssl.cafile = \"__BINGOO_PHP_DIR__\\cacert.txt\"\n";

        return [
            'absolute extension_dir' => ["extension_dir = \"C:\\php\\ext\"\n" . $ok, 'EXTENSION_DIR_NOT_RELATIVE'],
            'no extension_dir' => [$ok, 'EXTENSION_DIR_MISSING'],
            'parent-relative extension_dir' => ["extension_dir = \"..\\ext\"\n" . $ok, 'EXTENSION_DIR_NOT_RELATIVE'],
            'drive path in any key' => ["extension_dir = \"ext\"\nsys_temp_dir = \"D:\\laptop\\tmp\"\n" . $ok, 'ABSOLUTE_HOST_PATH: sys_temp_dir'],
            'drive path inside include_path' => ["extension_dir = \"ext\"\n" . str_replace('include_path = "."', 'include_path = ".;E:/pear"', $ok), 'ABSOLUTE_HOST_PATH: include_path'],
            'UNC path' => ["extension_dir = \"ext\"\nopcache.file_cache = \"\\\\\\\\nas\\\\cache\"\n" . $ok, 'ABSOLUTE_HOST_PATH: opcache.file_cache'],
            'laragon marker without a drive' => ["extension_dir = \"ext\"\nbrowscap = \"laragon/etc/browscap.ini\"\n" . $ok, 'ABSOLUTE_HOST_PATH: browscap'],
            'posix-absolute on a path key' => ["extension_dir = \"ext\"\nsession.save_path = \"/var/lib/php\"\n" . $ok, 'ABSOLUTE_HOST_PATH: session.save_path'],
            'CA not the placeholder' => ["extension_dir = \"ext\"\n" . str_replace('curl.cainfo = "__BINGOO_PHP_DIR__\\cacert.txt"', 'curl.cainfo = "cacert.pem"', $ok), 'CA_BUNDLE_NOT_PLACEHOLDER: curl.cainfo'],
            'CA pinned to an install root' => ["extension_dir = \"ext\"\n" . str_replace('openssl.cafile = "__BINGOO_PHP_DIR__\\cacert.txt"', 'openssl.cafile = "C:\\Program Files\\Bingoo Edge\\php\\cacert.txt"', $ok), 'ABSOLUTE_HOST_PATH: openssl.cafile'],
            'required extension disabled' => ["extension_dir = \"ext\"\n" . str_replace("extension=sodium\n", ";extension=sodium\n", $ok), 'EXTENSION_NOT_ENABLED: sodium'],
            'extension loaded by path' => ["extension_dir = \"ext\"\nextension=C:\\php\\ext\\php_redis.dll\n" . $ok, 'EXTENSION_BY_PATH'],
            'host-scoped section' => ["extension_dir = \"ext\"\n" . $ok . "[PATH=C:/inetpub/site]\nmemory_limit = 1G\n", 'ABSOLUTE_HOST_PATH: section'],
        ];
    }

    #[DataProvider('badInis')]
    public function test_a_machine_bound_or_incomplete_ini_is_refused(string $ini, string $expected): void
    {
        $result = (new PhpIniValidator())->validate($ini);
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty(array_filter($result['problems'], fn ($p) => str_contains($p, $expected)), "expected [{$expected}] in: " . implode(' | ', $result['problems']));
    }

    public function test_with_a_runtime_dir_every_enabled_extension_needs_its_dll(): void
    {
        $dir = sys_get_temp_dir() . '/edge-phpini-' . bin2hex(random_bytes(4));
        mkdir($dir . '/ext', 0777, true);
        try {
            foreach (PhpIniValidator::REQUIRED_EXTENSIONS as $ext) {
                touch($dir . '/ext/php_' . $ext . '.dll');
            }
            $v = new PhpIniValidator();
            $this->assertTrue($v->validate($this->fixture('portable-ok.ini'), $dir)['ok']);
            unlink($dir . '/ext/php_sodium.dll');
            $result = $v->validate($this->fixture('portable-ok.ini'), $dir);
            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('EXTENSION_DLL_MISSING: sodium', implode(' | ', $result['problems']));
        } finally {
            array_map('unlink', glob($dir . '/ext/*') ?: []);
            @rmdir($dir . '/ext');
            @rmdir($dir);
        }
    }

    public function test_host_path_detection_ignores_urls_and_non_path_slashes(): void
    {
        $v = new PhpIniValidator();
        $this->assertFalse($v->isHostPath('session.cookie_path', '/'));
        $this->assertFalse($v->isHostPath('url_rewriter.hosts', 'https://bingoopos.com'));
        $this->assertFalse($v->isHostPath('curl.cainfo', PhpIniValidator::CA_VALUE));
        $this->assertFalse($v->isHostPath('extension_dir', 'ext'));
        $this->assertTrue($v->isHostPath('anything', 'x;D:\\tools'));
        $this->assertTrue($v->isHostPath('error_log', '/tmp/php.log'));
        $this->assertTrue((new PhpIniValidator(['C:/Users/Dell']))->isHostPath('any', 'c:\\users\\dell\\tmp'));
    }

    /** The REAL build-host runtime (Laragon PHP 8.3.16, when present): its ini is refused as-is and accepted after sanitize(). */
    public function test_the_real_laragon_runtime_ini_is_made_portable(): void
    {
        $dir = 'D:/laragon2/bin/php/php-8.3.16-Win32-vs16-x64';
        if (! is_file($dir . '/php.ini')) {
            $this->markTestSkipped('no Laragon PHP 8.3.16 runtime on this machine');
        }
        $v = new PhpIniValidator([$dir]);
        $src = (string) file_get_contents($dir . '/php.ini');
        $this->assertFalse($v->validate($src, $dir)['ok'], 'the Laragon ini names the laptop and must be refused as-is');
        $after = $v->validate($v->sanitize($src)['ini'], $dir);
        $this->assertTrue($after['ok'], implode(' | ', $after['problems']));
    }
}
