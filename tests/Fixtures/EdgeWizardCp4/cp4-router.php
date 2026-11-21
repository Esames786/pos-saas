<?php

/**
 * CP4 (stream B) — php -S router for the DISPOSABLE integrated Cloud:
 *   php -S 127.0.0.1:9821 -t public tests/Fixtures/EdgeWizardCp4/cp4-router.php    (cwd = the worktree root)
 * Every request gets the rehearsal environment (cp4-env.php) and is served by the REAL Cloud app (public/index.php).
 */
require __DIR__ . '/cp4-env.php';
cp4_apply_env();

$root = dirname(__DIR__, 3);
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
