<?php

/**
 * CP5 scenario s (stream D) - php -S router for the Online POS the CASHIER uses in scenario s:
 *
 *   php -S 127.0.0.1:9841 -t %CP5_TREE%\public C:\cp5\kit\cp5-selfservice-router.php      (behind the scenario's nginx TLS front)
 *
 * The SAME disposable Cloud (same code tree, databases, keys, storage) as stream B's cp5-router.php, with two differences a real
 * browser session needs:
 *   - FILE sessions (B's Cloud uses the array driver: fine for the device API, but a login could never survive a request);
 *   - HTTPS exactly as php-fpm behind a TLS terminator sees it: the scenario's nginx (127.0.0.1:9443, a sandbox-only test CA,
 *     FULL verification in the browser) adds X-CP5-TLS: 1, and only a loopback peer may set it. The Online POS URL the
 *     counter setup embeds is then https://<tenant>.cp5.test:9443/pos (the renderer accepts https only).
 */
require __DIR__ . '/cp5-env.php';
cp5_apply_env();

foreach (['SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => 'true'] as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}
$tlsFront = ($_SERVER['HTTP_X_CP5_TLS'] ?? '') === '1' && in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
unset($_SERVER['HTTP_X_CP5_TLS']);
if ($tlsFront) {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = (string) ((int) (getenv('CP5_SS_TLS_PORT') ?: 9443));
}

$root = CP5_TREE;
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$static = $root . '/public' . $path;
if ($path !== '/' && is_file($static) && ! str_ends_with($static, '.php')) {
    return false;
}
$_SERVER['SCRIPT_FILENAME'] = $root . '/public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir($root . '/public');
require $root . '/public/index.php';

return true;
