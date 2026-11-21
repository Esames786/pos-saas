<?php

/**
 * CP5 (stream B) - the heartbeat-gap / fencing watch around a REAL auto-update (scenarios c and d), INSIDE the sandbox.
 *   php cp5-lease-watch.php <outPrefix> <stopFile> [maxSeconds]
 * Every second: the Cloud lease row (Cloud DB 127.0.0.1:CP5_CLOUD_DB_PORT, tenant pos_tenant_cp5edgewiz), the appliance's own
 * last acknowledged heartbeat (edge_local_meta, 127.0.0.1:3306, root password read from the file named by
 * CP5_EDGE_ROOT_PASSFILE - never printed) and the runtime pointer. Writes <outPrefix>.jsonl (one line per change) and
 * <outPrefix>.summary.json: the largest gap between two acknowledged heartbeats (Cloud side and appliance side) against the
 * lease TTL, the smallest remaining lease time seen, and whether the Cloud ever fenced the branch or changed the holder.
 */
[$self, $prefix, $stopFile] = array_pad($argv, 3, null);
$max = (int) ($argv[3] ?? 3600);
if (! $prefix || ! $stopFile) {
    fwrite(STDERR, "usage: cp5-lease-watch.php <outPrefix> <stopFile> [maxSeconds]\n");
    exit(2);
}
$cloudPort = getenv('CP5_CLOUD_DB_PORT') ?: '3407';
$pointerFile = getenv('CP5_POINTER_FILE') ?: 'C:/Program Files/Bingoo Edge/runtime/current';
$edgeDb = getenv('CP5_EDGE_DB') ?: 'bingoo_edge_local';
$passFile = getenv('CP5_EDGE_ROOT_PASSFILE') ?: '';

$connect = function (string $port, string $db, string $user, string $pw): ?PDO {
    try {
        return new PDO("mysql:host=127.0.0.1;port={$port};dbname={$db};charset=utf8mb4", $user, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    } catch (Throwable) {
        return null;
    }
};
$cloud = null;
$edge = null;
$edgePw = $passFile !== '' && is_file($passFile) ? trim((string) file_get_contents($passFile)) : '';
$utc = fn (?string $s) => $s ? strtotime($s . ' UTC') : null;

$out = fopen($prefix . '.jsonl', 'a');
$t0 = time();
$last = null;
$cloudBeats = [];
$edgeAcks = [];
$minExpiresIn = null;
$fencedSeen = null;
$holders = [];
$ttl = null;
$pointers = [];
$errors = 0;

while (time() - $t0 < $max && ! is_file($stopFile)) {
    $now = time();
    $row = ['t' => gmdate('H:i:s', $now)];
    try {
        $cloud ??= $connect($cloudPort, 'pos_tenant_cp5edgewiz', 'root', '');
        $l = $cloud?->query('SELECT holder, edge_state, heartbeat_seq, last_heartbeat_at, lease_ttl_seconds, expires_at, fenced_at FROM edge_branch_authority_leases ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($l) {
            $row['cloud'] = ['holder' => $l['holder'], 'edge_state' => $l['edge_state'], 'seq' => (int) $l['heartbeat_seq'], 'last_hb' => $l['last_heartbeat_at'], 'expires' => $l['expires_at'], 'fenced' => $l['fenced_at']];
            $ttl = (int) $l['lease_ttl_seconds'];
            $exp = $utc($l['expires_at']);
            if ($exp !== null) {
                $in = $exp - $now;
                $minExpiresIn = $minExpiresIn === null ? $in : min($minExpiresIn, $in);
            }
            if ($l['last_heartbeat_at'] && end($cloudBeats) !== $l['last_heartbeat_at']) {
                $cloudBeats[] = $l['last_heartbeat_at'];
            }
            if ($l['fenced_at']) {
                $fencedSeen ??= $l['fenced_at'];
            }
            $holders[$l['holder']] = true;
        }
    } catch (Throwable $e) {
        $cloud = null;
        $errors++;
        $row['cloud_error'] = mb_substr($e->getMessage(), 0, 120);
    }
    try {
        $edge ??= $edgePw !== '' ? $connect('3306', $edgeDb, 'root', $edgePw) : null;
        $m = $edge?->query('SELECT * FROM edge_local_meta LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($m) {
            $row['edge'] = ['state' => $m['authority_state'], 'last_ack' => $m['authority_last_ack_at']];
            // scenario g: the connection / auto-takeover facts (columns exist from 0.9 on)
            foreach (['heartbeat_consecutive_failures' => 'failures', 'authority_takeover_at' => 'takeover_at', 'authority_cloud_skew_seconds' => 'skew',
                'auto_takeover_last_decision' => 'at_decision', 'auto_takeover_last_reasons' => 'at_reasons', 'auto_takeover_last_attempt_at' => 'at_attempt',
                'authority_state_reason' => 'reason', 'connection_state' => 'connection'] as $col => $key) {
                if (array_key_exists($col, $m)) {
                    $row['edge'][$key] = is_string($m[$col]) ? mb_substr($m[$col], 0, 160) : $m[$col];
                }
            }
            if ($m['authority_last_ack_at'] && end($edgeAcks) !== $m['authority_last_ack_at']) {
                $edgeAcks[] = $m['authority_last_ack_at'];
            }
        }
    } catch (Throwable $e) {
        $edge = null;
        $errors++;
        $row['edge_error'] = mb_substr($e->getMessage(), 0, 120);
    }
    $ptr = trim((string) @file_get_contents($pointerFile));
    $row['pointer'] = $ptr;
    if (end($pointers) === false || end($pointers)['pointer'] !== $ptr) {
        $pointers[] = ['at' => gmdate('H:i:s', $now), 'pointer' => $ptr];
    }
    $key = json_encode(array_diff_key($row, ['t' => 1]));
    if ($key !== $last) {
        fwrite($out, json_encode($row) . "\n");
        fflush($out);
        $last = $key;
    }
    usleep(1000000);
}
fclose($out);

$gaps = function (array $stamps, int $end) use ($utc): array {
    $max = 0;
    $at = null;
    $prev = null;
    foreach ($stamps as $s) {
        $t = $utc($s);
        if ($prev !== null && $t - $prev > $max) {
            $max = $t - $prev;
            $at = gmdate('H:i:s', $prev) . ' -> ' . gmdate('H:i:s', $t);
        }
        $prev = $t;
    }
    $tail = $prev !== null ? $end - $prev : null;

    return ['beats' => count($stamps), 'max_gap_s' => $max, 'max_gap_between' => $at, 'since_last_beat_at_stop_s' => $tail];
};
$end = time();
$summary = [
    'watched_s' => $end - $t0,
    'lease_ttl_s' => $ttl,
    'cloud_heartbeats' => $gaps($cloudBeats, $end),
    'appliance_acks' => $gaps($edgeAcks, $end),
    'min_lease_remaining_s' => $minExpiresIn,
    'lease_never_lapsed' => $minExpiresIn !== null && $minExpiresIn > 0,
    'fenced_at_seen' => $fencedSeen,
    'holders_seen' => array_keys($holders),
    'pointer_changes' => $pointers,
    'read_errors' => $errors,
];
$summary['verdict'] = ($ttl && $summary['cloud_heartbeats']['max_gap_s'] < $ttl && $summary['lease_never_lapsed'] && $fencedSeen === null && $summary['holders_seen'] === ['cloud']) ? 'PASS' : 'FAIL';
file_put_contents($prefix . '.summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n";
