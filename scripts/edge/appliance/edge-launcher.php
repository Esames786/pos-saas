#!/usr/bin/env php
<?php
/**
 * Bingoo Edge — Branch Server appliance LAUNCHER (installed as <InstallRoot>\artisan by Install-EdgeAppliance.ps1).
 *
 * Every supervised Scheduled Task and every operator command runs `php <InstallRoot>\artisan <edge:command>`. This
 * file resolves the CURRENT runtime version (the atomic `current` pointer the signed updater switches), exports the
 * appliance configuration directory (BINGOO_EDGE_ENV_DIR → <DataRoot>\config\appliance.env) and hands off to that
 * version's own artisan. No secret is read or printed here; no business logic lives here.
 *
 * appliance.json (non-secret, written by the installer next to this file):
 *   { "data_root": "C:\\ProgramData\\BingooEdge", "runtime_root": "C:\\Program Files\\Bingoo Edge\\runtime",
 *     "php": "C:\\Program Files\\Bingoo Edge\\php\\php.exe", "gateway": "C:\\Program Files\\Bingoo Edge\\gateway\\nginx.exe" }
 */
$installRoot = __DIR__;
$layoutFile = $installRoot . DIRECTORY_SEPARATOR . 'appliance.json';
$layout = is_file($layoutFile) ? (json_decode((string) file_get_contents($layoutFile), true) ?: []) : [];
$dataRoot = rtrim((string) ($layout['data_root'] ?? ($installRoot . DIRECTORY_SEPARATOR . 'data')), '/\\');
$runtimeRoot = rtrim((string) ($layout['runtime_root'] ?? ($installRoot . DIRECTORY_SEPARATOR . 'runtime')), '/\\');

$pointer = $runtimeRoot . DIRECTORY_SEPARATOR . 'current';
$version = is_file($pointer) ? trim((string) file_get_contents($pointer)) : '';
if ($version === '' || ! preg_match('/^[A-Za-z0-9._+-]+$/', $version)) {
    fwrite(STDERR, "Bingoo Edge: no active runtime version (missing or invalid {$pointer}). Run the installer or Update-EdgeAppliance.ps1.\n");
    exit(70);
}
$versionDir = $runtimeRoot . DIRECTORY_SEPARATOR . 'versions' . DIRECTORY_SEPARATOR . $version;
$artisan = $versionDir . DIRECTORY_SEPARATOR . 'artisan';
if (! is_file($artisan)) {
    fwrite(STDERR, "Bingoo Edge: active runtime version [{$version}] has no artisan at {$artisan}.\n");
    exit(71);
}
$configDir = $dataRoot . DIRECTORY_SEPARATOR . 'config';
if (! is_file($configDir . DIRECTORY_SEPARATOR . 'appliance.env')) {
    fwrite(STDERR, "Bingoo Edge: appliance configuration not found at {$configDir}\\appliance.env.\n");
    exit(72);
}
putenv('BINGOO_EDGE_ENV_DIR=' . $configDir);
$_ENV['BINGOO_EDGE_ENV_DIR'] = $configDir;
$_SERVER['BINGOO_EDGE_ENV_DIR'] = $configDir;
putenv('BINGOO_EDGE_INSTALL_ROOT=' . $installRoot);
$_SERVER['BINGOO_EDGE_INSTALL_ROOT'] = $installRoot;
chdir($versionDir);
// argv[0] must look like the versioned artisan for Symfony Console; everything else passes through untouched.
$_SERVER['argv'][0] = $artisan;
require $artisan;
