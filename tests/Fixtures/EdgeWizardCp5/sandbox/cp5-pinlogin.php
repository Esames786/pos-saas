<?php

/**
 * CP5 (stream B) - one Edge PIN login, run INSIDE the sandbox with any php (curl + openssl):
 *   php cp5-pinlogin.php <baseUrl> <employee_code> <pinFile> [<caFile>]
 *     baseUrl  http://127.0.0.1:8090        the real loopback web backend (task), X-Forwarded-Proto https like the gateway
 *              https://bingoo-edge.local:8443 / https://<lan ip>:8443   through the real gateway with FULL TLS verification
 *                                            (peer + host name/IP against the branch CA file given as <caFile>)
 * The PIN is read from a file (never argv, never printed). Prints one JSON line: {ok, login_status, location, status_after, tls}.
 */
[$self, $base, $code, $pinFile] = array_pad($argv, 4, null);
$caFile = $argv[4] ?? null;
if (! $base || ! $code || ! $pinFile || ! is_file($pinFile)) {
    fwrite(STDERR, "usage: cp5-pinlogin.php <baseUrl> <employee_code> <pinFile> [<caFile>]\n");
    exit(2);
}
$pin = trim((string) file_get_contents($pinFile));
$base = rtrim($base, '/');
$https = str_starts_with($base, 'https://');
$jar = [];

$http = function (string $method, string $path, ?array $form) use ($base, $https, $caFile, &$jar): array {
    $ch = curl_init($base . $path);
    $headers = ['Accept: text/html,application/json'];
    if (! $https) {
        $headers[] = 'X-Forwarded-Proto: https';
        $headers[] = 'X-Forwarded-Port: 443';
    }
    if ($jar !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => $k . '=' . $v, array_keys($jar), $jar));
    }
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers];
    if ($https) {
        // FULL verification: the branch CA only (not the system bundle), peer + host name / IP SAN.
        $opts += [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        if ($caFile) {
            $opts[CURLOPT_CAINFO] = $caFile;
        }
        // CP5_RESOLVE="bingoo-edge.local:8443:<lan ip>": the by-NAME check without a hosts line (SNI + name verification intact)
        $resolve = getenv('CP5_RESOLVE');
        if ($resolve) {
            $opts[CURLOPT_RESOLVE] = [$resolve];
        }
    }
    curl_setopt_array($ch, $opts);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form ?? []));
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $verify = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
    curl_close($ch);
    if ($raw === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $err, 'ssl_verify_result' => $verify];
    }
    $head = substr((string) $raw, 0, $hsize);
    $headers = [];
    foreach (preg_split('/\r?\n/', $head) as $line) {
        if (preg_match('/^set-cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
            $jar[trim($m[1])] = $m[2];
        } elseif (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $m)) {
            $headers[strtolower($m[1])] = trim($m[2]);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => substr((string) $raw, $hsize), 'error' => null, 'ssl_verify_result' => $verify];
};

$r = $http('GET', '/edge/local/login', null);
$tls = $https ? ['verified' => $r['error'] === null && (int) $r['ssl_verify_result'] === 0, 'error' => $r['error']] : null;
if (! preg_match('/name="_token"\s+value="([^"]+)"/', $r['body'], $m)) {
    echo json_encode(['ok' => false, 'why' => 'no CSRF token on the login page', 'status' => $r['status'], 'tls' => $tls]) . "\n";
    exit(1);
}
$p = $http('POST', '/edge/local/login', ['_token' => $m[1], 'employee_code' => $code, 'credential' => $pin]);
$location = $p['headers']['location'] ?? '';
$s = $http('GET', '/edge/local/status', null);
$ok = $p['status'] === 302 && ! str_contains($location, '/login') && $s['status'] === 200;
echo json_encode(['ok' => $ok, 'login_status' => $p['status'], 'location' => preg_replace('#^https?://[^/]+#', '', $location), 'status_after' => $s['status'], 'tls' => $tls]) . "\n";
exit($ok ? 0 : 1);
