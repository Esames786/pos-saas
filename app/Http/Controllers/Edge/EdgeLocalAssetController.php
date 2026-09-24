<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Support\EdgeRouteManifest;
use App\Support\EdgeRuntime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * W1 (Team 1, owner directive 20 Sep 2026) — the Branch Server's LOCAL static-asset channel.
 *
 * The appliance serves the app with PHP's built-in server behind nginx, router public/index.php, so a request for
 * /assets/* never reaches a file — it is routed into Laravel and 404s (that is the favicon 404 the audit saw on every
 * page). The Online POS loads the locally packaged Bootstrap 5.3.8 / SweetAlert2 / Tabler icons from public/assets
 * (layouts/app.blade.php); the Edge cashier page loads the SAME files through this one route, so it keeps working with
 * NO Internet (audit E-10) and without a CDN.
 *
 * Boundary (fail closed): ONLY a regular file under public/assets, ONLY the static types below, never a dot-file,
 * never a path with '..', a drive letter, a backslash or an absolute path, never a symlink anywhere on the path, and
 * the resolved real path must stay inside public/assets. Everything else is a plain 404 (nothing advertised). The
 * route is unauthenticated on purpose (the login page needs the stylesheet too) and exposes nothing but the Online
 * frontend's own public assets.
 */
class EdgeLocalAssetController extends Controller
{
    public const ROUTE_NAME = 'edge.local.assets';

    /** Extension => Content-Type. Anything else is refused. */
    private const TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
    ];

    /** Segment grammar: starts with a letter/digit (so no '.', '..', '.env', '.htaccess'), then [A-Za-z0-9._-]. */
    private const SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * True when the page may reference the asset route: it is registered AND (on a Branch Server) on the explicit
     * route allowlist (config/edge.php). Until the allowlist carries `edge.local.assets` the page renders with its
     * self-sufficient inline stylesheet and the in-page dialog fallbacks — it never emits a link that would 404.
     */
    public static function available(): bool
    {
        if (! Route::has(self::ROUTE_NAME)) {
            return false;
        }

        return EdgeRuntime::isCloud() || EdgeRouteManifest::isAllowed(self::ROUTE_NAME);
    }

    /** URL of one asset (relative to public/assets), for the Blade partials. */
    public static function url(string $path): string
    {
        return url('/edge/local/assets/' . ltrim($path, '/'));
    }

    public function show(Request $request, string $path): Response
    {
        $file = $this->resolve($path);
        if ($file === null) {
            abort(404);
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $size = (int) filesize($file);
        $mtime = (int) filemtime($file);
        $etag = '"' . sha1($path . '|' . $size . '|' . $mtime) . '"';

        $headers = [
            'Content-Type' => self::TYPES[$ext],
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $inm = (string) $request->headers->get('If-None-Match', '');
        if ($inm !== '' && in_array($etag, array_map('trim', explode(',', $inm)), true)) {
            return response('', 304, array_diff_key($headers, ['Content-Type' => true]));
        }

        $response = new BinaryFileResponse($file, 200, $headers, true, null, false, false);
        // BinaryFileResponse may guess a type — the whitelist decides it, always.
        $response->headers->set('Content-Type', self::TYPES[$ext]);

        return $response;
    }

    /** The absolute path of an allowed asset, or null. Never throws for hostile input. */
    private function resolve(string $path): ?string
    {
        if ($path === '' || strlen($path) > 255 || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':')) {
            return null;
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (! preg_match(self::SEGMENT, $segment)) {
                return null; // empty segment (leading '/', '//'), '.', '..', dot-files, odd characters
            }
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! array_key_exists($ext, self::TYPES)) {
            return null;
        }

        $root = realpath(public_path('assets'));
        if ($root === false) {
            return null;
        }

        // No symlink anywhere on the path (a link inside public/assets could point anywhere).
        $cursor = $root;
        foreach ($segments as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                return null;
            }
        }
        if (! is_file($cursor) || ! is_readable($cursor)) {
            return null;
        }

        $real = realpath($cursor);
        if ($real === false || ! str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
