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
        if (! EdgeRouteManifest::isAllowed(EdgeLocalAssetController::ROUTE_NAME)) {
            config(['edge.route_allowlist' => array_merge((array) config('edge.route_allowlist'), [EdgeLocalAssetController::ROUTE_NAME])]);
        }
    }

    protected function tearDown(): void
    {
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
}
