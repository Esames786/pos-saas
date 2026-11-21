<?php
/**
 * Router for PHP's built-in server (dev Cloud / dev renders ONLY — never used on the appliance, whose gateway is nginx + the
 * hardened asset route). `php -S 127.0.0.1:<port> -t public tools/edge-dev-instance/router.php`: an existing static file
 * under public/ is served as-is (Bootstrap, theme CSS, fonts, images); everything else goes to Laravel's front controller.
 * Without this, `php -S … public/index.php` routes EVERY request (including /assets/*) into Laravel and the theme never loads.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$file = realpath(__DIR__ . '/../../public' . $path);
$root = realpath(__DIR__ . '/../../public');
if ($file !== false && $root !== false && str_starts_with($file, $root) && is_file($file) && $path !== '/index.php') {
    return false; // let the built-in server serve the static file with its own MIME handling
}
require $root . '/index.php';
