<?php

/**
 * CP5 (stream B) - read-only SQL probe INSIDE the sandbox (helpers for scenarios g, h, s):
 *   php cp5-sql.php edge  "<SELECT ...>"   the appliance DB (127.0.0.1:3306 bingoo_edge_local, root password read from the file
 *                                          named by CP5_EDGE_ROOT_PASSFILE - never printed)
 *   php cp5-sql.php cloud "<SELECT ...>"   the sandbox Cloud tenant DB (127.0.0.1:CP5_CLOUD_DB_PORT pos_tenant_cp5edgewiz)
 *   php cp5-sql.php cloud-master "<SELECT ...>"
 * Prints {"now": <UTC>, "rows": [...]} as one JSON line. Only SELECT / SHOW statements are accepted.
 */
[$self, $target, $sql] = array_pad($argv, 3, null);
if (! $target || ! $sql || ! preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) {
    fwrite(STDERR, "usage: cp5-sql.php edge|cloud|cloud-master \"SELECT ...\"\n");
    exit(2);
}
$cloudPort = getenv('CP5_CLOUD_DB_PORT') ?: '3407';
[$port, $db, $user, $pw] = match ($target) {
    'edge' => ['3306', getenv('CP5_EDGE_DB') ?: 'bingoo_edge_local', 'root', trim((string) @file_get_contents((string) getenv('CP5_EDGE_ROOT_PASSFILE')))],
    'cloud' => [$cloudPort, 'pos_tenant_cp5edgewiz', 'root', ''],
    'cloud-master' => [$cloudPort, 'e09b_cp5_master_edge', 'root', ''],
    default => [null, null, null, null],
};
if ($port === null) {
    fwrite(STDERR, "unknown target {$target}\n");
    exit(2);
}
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port={$port};dbname={$db};charset=utf8mb4", $user, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['now' => gmdate('Y-m-d H:i:s'), 'rows' => $rows], JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    echo json_encode(['now' => gmdate('Y-m-d H:i:s'), 'error' => mb_substr($e->getMessage(), 0, 300)]) . "\n";
    exit(1);
}
