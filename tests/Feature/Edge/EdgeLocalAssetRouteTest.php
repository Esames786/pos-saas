<?php

namespace Tests\Feature\Edge;

use App\Http\Controllers\Edge\EdgeLocalAssetController;
use App\Support\EdgeRouteManifest;
use Tests\TestCase;

/**
 * W1 (Team 1) — GET /edge/local/assets/{path}: the Branch Server's local static-asset channel (audit E-10 + the
 * favicon/asset 404 on every page). Booted AS a Branch Server (like EdgeBranchServerRegistrationTest), over the real
 * HTTP kernel → router → middleware → EdgeLocalAssetController, with NO authenticated user (the login page needs
 * the stylesheet too).
 *
 * Proves: the packaged Online assets stream with the whitelisted type + cache headers + an ETag (304 on revalidation);
 * a missing file, every traversal / dot-file / absolute / drive / backslash form and every non-whitelisted type under
 * public/assets are a plain 404; no session is started for an asset request.
 */
class EdgeLocalAssetRouteTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        // The branch_server route boundary is default-DENY by route NAME (config/edge.php `route_allowlist`). The W1 block
        // there lists `edge.local.assets`; should a config override ever drop it, the entry is re-added for the request under test
        // so the controller + route are still proven — test_the_asset_route_is_on_the_branch_server_allowlist checks the SHIPPED list.
        foreach ([EdgeLocalAssetController::ROUTE_NAME, EdgeLocalAssetController::STORAGE_ROUTE_NAME] as $name) {
            if (! EdgeRouteManifest::isAllowed($name)) {
                config(['edge.route_allowlist' => array_merge((array) config('edge.route_allowlist'), [$name])]);
            }
        }
    }

    /** @var string[] paths created under storage/app/public by a test (removed in tearDown) */
    private array $storageLitter = [];

    protected function tearDown(): void
    {
        // Never recursive (a junction/link must be removed as a link, never walked into): unlink a file/link, else rmdir an
        // empty directory or a junction (PHP's is_dir() is unreliable for a junction on Windows, so both are tried).
        clearstatcache();
        foreach (array_reverse($this->storageLitter) as $p) {
            @unlink($p) || @rmdir($p);
        }
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    public function test_packaged_bootstrap_css_streams_with_type_cache_headers_and_etag(): void
    {
        $res = $this->get('/edge/local/assets/css/bootstrap.min.css');
        $res->assertOk();
        $this->assertStringStartsWith('text/css', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('public', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=86400', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $etag = (string) $res->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{40}"$/', $etag);
        $this->assertStringContainsString('Bootstrap', file_get_contents($res->baseResponse->getFile()->getPathname()));
        $this->assertSame(filesize(public_path('assets/css/bootstrap.min.css')), $res->baseResponse->getFile()->getSize());

        // Revalidation → 304, no body.
        $this->get('/edge/local/assets/css/bootstrap.min.css', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_every_asset_the_edge_pages_reference_is_served_with_its_whitelisted_type(): void
    {
        foreach ([
            'js/bootstrap.bundle.min.js' => 'application/javascript',
            'plugins/sweetalert/sweetalert2.all.min.js' => 'application/javascript',
            'plugins/tabler-icons/tabler-icons.min.css' => 'text/css',
            'plugins/tabler-icons/fonts/tabler-icons8aff.woff2' => 'font/woff2',
            'plugins/tabler-icons/fonts/tabler-iconsd41d.woff' => 'font/woff',
            'plugins/tabler-icons/fonts/tabler-icons8aff.ttf' => 'font/ttf',
            'img/apple-touch-icon.png' => 'image/png',
        ] as $path => $type) {
            $this->assertFileExists(public_path('assets/' . $path), "fixture asset {$path} is packaged");
            $res = $this->get('/edge/local/assets/' . $path);
            $res->assertOk();
            $this->assertStringStartsWith($type, (string) $res->headers->get('Content-Type'), $path);
        }
    }

    public function test_a_missing_file_is_404(): void
    {
        $this->get('/edge/local/assets/css/does-not-exist.css')->assertNotFound();
        $this->get('/edge/local/assets/js/nope.js')->assertNotFound();
    }

    public function test_traversal_dotfiles_absolute_paths_and_foreign_types_never_escape_public_assets(): void
    {
        foreach ([
            '../index.php',                    // public/index.php — outside assets, and not a whitelisted type
            '../../.env',                      // the app secrets
            '..%2F..%2F.env',                  // encoded traversal
            '%2e%2e/%2e%2e/.env',
            'css/../../index.php',
            'css/../../../composer.json',
            'css/%2e%2e/%2e%2e/%2e%2e/.env',
            '../../routes/edge_runtime.php',
            '..%5C..%5C.env',                  // backslash form (Windows), encoded — a raw backslash is refused by the HTTP layer itself
            'C:/Windows/win.ini',              // drive-absolute
            '/etc/passwd',                     // absolute (leading slash → empty segment)
            'css//bootstrap.min.css',          // empty segment
            './css/bootstrap.min.css',         // dot segment
            '.htaccess',                       // dot-file
            'css/.hidden.css',
            'css/owl.video.play.html',         // a real file under public/assets, but not a whitelisted type
            'css/bootstrap.min.css.map',       // not whitelisted
            'plugins/boxicons/fonts/boxicons.eot',
            'css/bootstrap.min.css%00.png',    // NUL smuggling
        ] as $path) {
            $status = $this->get('/edge/local/assets/' . $path)->getStatusCode();
            $this->assertContains($status, [403, 404], "'{$path}' must be refused, got {$status}");
        }
    }

    public function test_the_asset_route_needs_no_login_and_starts_no_session(): void
    {
        $this->assertGuest('tenant');
        $res = $this->get('/edge/local/assets/css/bootstrap.min.css')->assertOk();
        foreach ($res->headers->getCookies() as $cookie) {
            $this->assertStringNotContainsStringIgnoringCase('session', $cookie->getName(), 'an asset request must not start a session');
        }
    }

    public function test_the_asset_route_is_on_the_branch_server_allowlist(): void
    {
        // Reads the SHIPPED config (not the per-test override above).
        $shipped = (array) (require base_path('config/edge.php'))['route_allowlist'];
        $this->assertContains(EdgeLocalAssetController::ROUTE_NAME, $shipped,
            'config/edge.php route_allowlist (W1 block) must list `edge.local.assets` — otherwise the appliance 404s the route and the '
            . 'Edge pages fall back to their inline stylesheet + in-page dialogs.');
        $this->assertTrue(EdgeLocalAssetController::available());
    }

    // ═══ W-C (Edge next release §1.2/§4) — the FULL Online layout asset list + self-hosted Nunito + the storage root ═══

    private const EXPECTED_TYPE = [
        'css' => 'text/css', 'js' => 'application/javascript', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf',
        'png' => 'image/png', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
    ];

    /** The layouts/app.blade.php list the shared layout (layouts.pos, W-A) loads in the same order, plus the local font. */
    private const ONLINE_LAYOUT_ASSETS = [
        'js/theme-script.js', 'img/favicon.png', 'img/apple-touch-icon.png',
        'css/bootstrap.min.css', 'css/bootstrap-datetimepicker.min.css', 'css/animate.css',
        'plugins/select2/css/select2.min.css', 'plugins/daterangepicker/daterangepicker.css', 'plugins/tabler-icons/tabler-icons.min.css',
        'plugins/fontawesome/css/fontawesome.min.css', 'plugins/fontawesome/css/all.min.css',
        'css/fonts-local.css', 'css/style.css', 'css/a11y-custom.css',
        'js/jquery-3.7.1.min.js', 'js/feather.min.js', 'js/jquery.slimscroll.min.js', 'js/bootstrap.bundle.min.js', 'js/moment.min.js',
        'plugins/daterangepicker/daterangepicker.js', 'plugins/select2/js/select2.min.js', 'plugins/sweetalert/sweetalert2.all.min.js', 'js/script.js',
        'fonts/nunito/nunito-latin-wght-v32.woff2',
    ];

    private function assertServedAs(string $url, string $ext): \Illuminate\Testing\TestResponse
    {
        $res = $this->get($url);
        $this->assertSame(200, $res->getStatusCode(), "{$url} must be served");
        $this->assertStringStartsWith(self::EXPECTED_TYPE[$ext], (string) $res->headers->get('Content-Type'), $url);

        return $res;
    }

    private function body(\Illuminate\Testing\TestResponse $res): string
    {
        return (string) file_get_contents($res->baseResponse->getFile()->getPathname());
    }

    public function test_the_full_online_layout_asset_list_and_the_local_font_are_served_with_their_types(): void
    {
        // Whatever layouts/app.blade.php loads today is served too (the list above is the floor, the layout the truth).
        // …and so is whatever the shared layout (layouts/pos, W-A) loads through $posRuntime->asset('assets/…').
        $blade = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));
        if (is_file(resource_path('views/layouts/pos.blade.php'))) {
            $blade .= (string) file_get_contents(resource_path('views/layouts/pos.blade.php'));
        }
        preg_match_all("/asset\\('assets\\/([^']+)'\\)/", $blade, $m);
        $paths = array_values(array_unique(array_merge(self::ONLINE_LAYOUT_ASSETS, $m[1])));
        $this->assertGreaterThanOrEqual(24, count($paths));

        foreach ($paths as $path) {
            $this->assertFileExists(public_path('assets/' . $path), "{$path} is packaged under public/assets");
            $this->assertServedAs('/edge/local/assets/' . $path, strtolower(pathinfo($path, PATHINFO_EXTENSION)));
        }
    }

    public function test_style_css_carries_no_remote_import_and_fonts_local_resolves_through_the_route(): void
    {
        $style = $this->body($this->assertServedAs('/edge/local/assets/css/style.css', 'css'));
        $this->assertStringNotContainsString('@import', $style, 'owner decision A3: no @import in style.css');
        $this->assertStringNotContainsString('fonts.googleapis', $style);
        $this->assertStringNotContainsString('fonts.gstatic', $style);
        $this->assertDoesNotMatchRegularExpression('/url\(\s*[\'"]?(?:https?:)?\/\//i', $style, 'no remote url() in style.css');
        $this->assertStringContainsString('font-family: "Nunito", sans-serif', $style, 'style.css still asks for Nunito by name');

        $fonts = $this->body($this->assertServedAs('/edge/local/assets/css/fonts-local.css', 'css'));
        $this->assertStringNotContainsString('@import', $fonts);
        $this->assertDoesNotMatchRegularExpression('/(?:https?:)?\/\//i', preg_replace('/\/\*[\s\S]*?\*\//', '', $fonts), 'no remote reference in fonts-local.css rules');
        preg_match_all('/@font-face\s*\{([^}]*)\}/', $fonts, $faces);
        $weights = [];
        foreach ($faces[1] as $face) {
            $this->assertMatchesRegularExpression('/font-family:\s*"Nunito";/', $face, 'the family name must be exactly "Nunito"');
            $this->assertStringContainsString('font-display: swap', $face);
            $this->assertMatchesRegularExpression('/unicode-range:\s*U\+0000-00FF/', $face, 'latin subset');
            preg_match('/font-weight:\s*(\d+)/', $face, $w);
            $weights[] = (int) $w[1];
            preg_match('/url\("([^"]+)"\)\s*format\("woff2"\)/', $face, $u);
            $this->assertNotEmpty($u, 'each face is a local woff2');
            // Resolve exactly as the browser does: relative to /edge/local/assets/css/.
            $resolved = '/edge/local/assets/' . preg_replace('#^\.\./#', '', $u[1]);
            $font = $this->assertServedAs($resolved, 'woff2');
            $this->assertSame('wOF2', substr($this->body($font), 0, 4), "{$resolved} is a WOFF2 file");
        }
        sort($weights);
        $this->assertSame([300, 400, 500, 600, 700], $weights);
    }

    private function storageFile(string $rel, string $bytes): string
    {
        $root = storage_path('app/public');
        $this->assertDirectoryExists($root, 'storage/app/public is a runtime dir of the appliance');
        $cursor = $root;
        foreach (array_slice(explode('/', $rel), 0, -1) as $dir) {
            $cursor .= DIRECTORY_SEPARATOR . $dir;
            if (! is_dir($cursor)) {
                mkdir($cursor);
                $this->storageLitter[] = $cursor;
            }
        }
        $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        file_put_contents($abs, $bytes);
        $this->storageLitter[] = $abs;

        return $abs;
    }

    public function test_the_storage_route_serves_product_images_with_their_types_and_a_sandbox_csp(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $dir = 'edge-wc-test-' . bin2hex(random_bytes(4));
        $this->storageFile("{$dir}/products/photo.png", $png);
        $this->storageFile("{$dir}/products/photo.jpg", "\xFF\xD8\xFF\xE0jpeg");
        $this->storageFile("{$dir}/products/photo.JPEG", "\xFF\xD8\xFF\xE0jpeg");
        $this->storageFile("{$dir}/products/photo.webp", 'RIFF....WEBPVP8 ');
        $this->storageFile("{$dir}/products/logo.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $res = $this->assertServedAs("/edge/local/storage/{$dir}/products/photo.png", 'png');
        $this->assertSame($png, $this->body($res));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $res->headers->get('Content-Security-Policy'));
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{40}"$/', (string) $res->headers->get('ETag'));
        $this->get("/edge/local/storage/{$dir}/products/photo.png", ['If-None-Match' => $res->headers->get('ETag')])->assertStatus(304);
        foreach ($res->headers->getCookies() as $cookie) {
            $this->assertStringNotContainsStringIgnoringCase('session', $cookie->getName(), 'a storage request must not start a session');
        }
        $this->assertServedAs("/edge/local/storage/{$dir}/products/photo.jpg", 'jpg');
        $this->assertServedAs("/edge/local/storage/{$dir}/products/photo.JPEG", 'jpeg');
        $this->assertServedAs("/edge/local/storage/{$dir}/products/photo.webp", 'webp');
        $svg = $this->assertServedAs("/edge/local/storage/{$dir}/products/logo.svg", 'svg');
        $this->assertStringContainsString("default-src 'none'", (string) $svg->headers->get('Content-Security-Policy'), 'an uploaded SVG can never run script');

        $this->assertSame(url("/edge/local/storage/{$dir}/products/photo.png"), EdgeLocalAssetController::storageUrl("{$dir}/products/photo.png"));
        $this->assertSame(url("/edge/local/storage/{$dir}/products/photo.png"), EdgeLocalAssetController::storageUrl("/{$dir}/products/photo.png"));
    }

    public function test_the_storage_route_refuses_traversal_dotfiles_and_unknown_types(): void
    {
        $dir = 'edge-wc-test-' . bin2hex(random_bytes(4));
        $this->storageFile("{$dir}/notes.txt", 'x');
        $this->storageFile("{$dir}/page.html", '<html>');
        $this->storageFile("{$dir}/shell.php", '<?php echo 1;');
        $this->storageFile("{$dir}/theme.css", 'body{}');
        $this->storageFile("{$dir}/icon.ico", 'x');
        $this->storageFile("{$dir}/.hidden.png", 'x');

        foreach ([
            '.gitignore',                                  // the real dot-file in storage/app/public
            "{$dir}/.hidden.png",                          // dot-file with an image extension
            "{$dir}/notes.txt", "{$dir}/page.html", "{$dir}/shell.php", "{$dir}/theme.css", "{$dir}/icon.ico", // not image types of this root
            '../../.env', '..%2F..%2F.env', '%2e%2e/%2e%2e/.env', "{$dir}/../../../.env",
            '../private/.gitignore', '../../app/Http/Kernel.php', '../../logs/laravel.log',
            '..%5C..%5C.env', 'C:/Windows/win.ini', '/etc/passwd', "{$dir}//x.png", "./{$dir}/x.png",
            "{$dir}/missing.png",                          // absent
            "{$dir}/x.png%00.txt",
            '../../../public/assets/img/apple-touch-icon.png', // a real png — but outside this root
        ] as $path) {
            $status = $this->get('/edge/local/storage/' . $path)->getStatusCode();
            $this->assertContains($status, [403, 404], "'{$path}' must be refused by the storage route, got {$status}");
        }
    }

    public function test_the_storage_route_never_follows_a_symlink(): void
    {
        $dir = 'edge-wc-test-' . bin2hex(random_bytes(4));
        $this->storageFile("{$dir}/keep.png", 'x'); // creates the directory
        $link = storage_path("app/public/{$dir}/linked.png");
        $linkDir = storage_path("app/public/{$dir}/linkeddir");
        $made = @symlink(public_path('assets/img/apple-touch-icon.png'), $link);
        $madeDir = @symlink(public_path('assets/img'), $linkDir);
        if ($made) {
            $this->storageLitter[] = $link;
        }
        if ($madeDir) {
            $this->storageLitter[] = $linkDir;
        }
        // Windows: a directory JUNCTION needs no privilege and is invisible to is_link() — the walk must still refuse it,
        // both one pointing OUTSIDE the root and one pointing to another directory INSIDE the root.
        $junctions = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $this->storageFile("{$dir}/real/inner.png", 'x');
            foreach (['jout' => public_path('assets/img'), 'jin' => storage_path("app/public/{$dir}/real")] as $name => $target) {
                $j = storage_path("app/public/{$dir}/{$name}");
                exec('cmd /c mklink /J ' . escapeshellarg(str_replace('/', '\\', $j)) . ' ' . escapeshellarg(str_replace('/', '\\', $target)) . ' 2>&1', $o, $code);
                if ($code === 0 && is_dir($j)) {
                    $this->storageLitter[] = $j;
                    $junctions[$name] = $j;
                }
            }
        }
        if (! $made && ! $madeDir && $junctions === []) {
            $this->markTestSkipped('this host can create neither symlinks nor junctions');
        }
        if ($made) {
            $this->assertTrue(is_link($link));
            $this->get("/edge/local/storage/{$dir}/linked.png")->assertNotFound();
        }
        if ($madeDir) {
            $this->get("/edge/local/storage/{$dir}/linkeddir/apple-touch-icon.png")->assertNotFound();
        }
        if (isset($junctions['jout'])) {
            $this->assertFileExists($junctions['jout'] . '/apple-touch-icon.png', 'the junction resolves on disk');
            $this->get("/edge/local/storage/{$dir}/jout/apple-touch-icon.png")->assertNotFound();
        }
        if (isset($junctions['jin'])) {
            $this->assertFileExists($junctions['jin'] . '/inner.png', 'the junction resolves on disk');
            $this->get("/edge/local/storage/{$dir}/jin/inner.png")->assertNotFound();
            $this->assertServedAs("/edge/local/storage/{$dir}/real/inner.png", 'png');
        }
        $this->assertServedAs("/edge/local/storage/{$dir}/keep.png", 'png'); // the real file beside the links still serves
    }

    public function test_the_storage_route_is_on_the_branch_server_allowlist(): void
    {
        $shipped = (array) (require base_path('config/edge.php'))['route_allowlist'];
        $this->assertContains(EdgeLocalAssetController::STORAGE_ROUTE_NAME, $shipped, 'config/edge.php route_allowlist must list `edge.local.storage`');
        $this->assertTrue(EdgeLocalAssetController::storageAvailable());
        $this->assertSame('edge/local/storage/{path}', \Illuminate\Support\Facades\Route::getRoutes()->getByName(EdgeLocalAssetController::STORAGE_ROUTE_NAME)?->uri());
    }
}
