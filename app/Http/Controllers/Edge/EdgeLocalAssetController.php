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
 *
 * W-C (Edge next release, §4.3) — a SECOND hardened root: `storage/app/public` (the product photos the Online POS loads
 * via asset('storage/…'); the appliance has no public/storage symlink) at GET /edge/local/storage/{path}, route name
 * `edge.local.storage`. Same segment grammar, same no-symlink walk, same real-path containment, read-only — IMAGE types
 * only (png/jpg/jpeg/webp/svg), and every storage response carries a sandboxing CSP so an uploaded SVG can never run
 * script in the appliance origin.
 */
class EdgeLocalAssetController extends Controller
{
    public const ROUTE_NAME = 'edge.local.assets';

    public const STORAGE_ROUTE_NAME = 'edge.local.storage';

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

    /** storage/app/public — IMAGES only (product photos). Anything else is refused. */
    private const STORAGE_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    /** Uploaded (user) content is never scriptable in the appliance origin, even when opened directly. */
    private const USER_CONTENT_CSP = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox";

    /** Segment grammar: starts with a letter/digit (so no '.', '..', '.env', '.htaccess'), then [A-Za-z0-9._-]. */
    private const SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * True when the page may reference the asset route: it is registered AND (on a Branch Server) on the explicit
     * route allowlist (config/edge.php). Until the allowlist carries `edge.local.assets` the page renders with its
     * self-sufficient inline stylesheet and the in-page dialog fallbacks — it never emits a link that would 404.
     */
    public static function available(): bool
    {
        return self::routeUsable(self::ROUTE_NAME);
    }

    /** The same rule for the storage (product image) root — `edge.local.storage`. */
    public static function storageAvailable(): bool
    {
        return self::routeUsable(self::STORAGE_ROUTE_NAME);
    }

    /** URL of one asset (relative to public/assets), for the Blade partials. */
    public static function url(string $path): string
    {
        return url('/edge/local/assets/' . ltrim($path, '/'));
    }

    /** URL of one public-disk file (relative to storage/app/public), e.g. a product image path as stored. */
    public static function storageUrl(string $path): string
    {
        return url('/edge/local/storage/' . ltrim($path, '/'));
    }

    public function show(Request $request, string $path): Response
    {
        return $this->serve($request, $path, public_path('assets'), self::TYPES, false);
    }

    public function storage(Request $request, string $path): Response
    {
        return $this->serve($request, $path, storage_path('app/public'), self::STORAGE_TYPES, true);
    }

    private static function routeUsable(string $name): bool
    {
        if (! Route::has($name)) {
            return false;
        }

        return EdgeRuntime::isCloud() || EdgeRouteManifest::isAllowed($name);
    }

    /**
     * On Windows `is_link()` is FALSE for a directory junction (mklink /J needs no privilege), while readlink() resolves
     * it to its target; for an ordinary file or directory readlink() returns the path itself. A readlink() answer that
     * differs from the path (case-insensitive, as NTFS is) therefore marks a junction/link.
     */
    private static function isWindowsReparsePoint(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! file_exists($path)) {
            return false;
        }
        $target = @readlink($path);
        if ($target === false) {
            return false;
        }
        $norm = static fn (string $p): string => rtrim(str_replace('/', '\\', $p), '\\');

        return strcasecmp($norm($target), $norm($path)) !== 0;
    }

    /** @param array<string,string> $types */
    private function serve(Request $request, string $path, string $rootDir, array $types, bool $userContent): Response
    {
        $file = $this->resolve($path, $rootDir, $types);
        if ($file === null) {
            abort(404);
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $size = (int) filesize($file);
        $mtime = (int) filemtime($file);
        $etag = '"' . sha1($path . '|' . $size . '|' . $mtime) . '"';

        $headers = [
            'Content-Type' => $types[$ext],
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($userContent) {
            $headers['Content-Security-Policy'] = self::USER_CONTENT_CSP;
        }

        $inm = (string) $request->headers->get('If-None-Match', '');
        if ($inm !== '' && in_array($etag, array_map('trim', explode(',', $inm)), true)) {
            return response('', 304, array_diff_key($headers, ['Content-Type' => true]));
        }

        $response = new BinaryFileResponse($file, 200, $headers, true, null, false, false);
        // BinaryFileResponse may guess a type — the whitelist decides it, always.
        $response->headers->set('Content-Type', $types[$ext]);

        return $response;
    }

    /**
     * The absolute path of an allowed file under $rootDir, or null. Never throws for hostile input.
     *
     * @param array<string,string> $types
     */
    private function resolve(string $path, string $rootDir, array $types): ?string
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
        if (! array_key_exists($ext, $types)) {
            return null;
        }

        $root = realpath($rootDir); // the root is app configuration (may itself live on a linked data volume); below it, no link

        if ($root === false || ! is_dir($root)) {
            return null;
        }

        // No symlink anywhere on the path (a link inside the root could point anywhere).
        $cursor = $root;
        foreach ($segments as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor) || self::isWindowsReparsePoint($cursor)) {
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
