<?php

/**
 * CP5 (stream B) - php -S router for the DISPOSABLE Cloud inside the sandbox:
 *   php -S 127.0.0.1:9831 -t %CP5_TREE%\public C:\cp5\kit\cp5-router.php
 */
require __DIR__ . '/cp5-env.php';
cp5_apply_env();

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
