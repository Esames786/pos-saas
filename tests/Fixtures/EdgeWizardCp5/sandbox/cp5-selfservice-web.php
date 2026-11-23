<?php

/**
 * CP5 scenario s (stream D) - a cookie-session HTTP client for the cashier self-service proof (any php with curl + openssl):
 *
 *   php cp5-selfservice-web.php <mode> <config.json>
 *     download     log in as the cashier, read the Install Offline Counter page, POST "my Edge PIN setup", save the file
 *                  (the FALLBACK when the browser cannot be driven over CDP; same routes, same session rules)
 *     negatives    no permission -> 403, other branch -> refused, assertion for another user -> refused, other tenant host ->
 *                  refused, throttle -> 429
 *     edge         the Branch Server's POST /edge/local/enroll refuses replayed / other-tenant / other-branch / forged assertions,
 *                  and throttles
 *     edge-probe   is the Branch Server's optional POST /edge/local/enroll switched on (422) or off (404)
 *     online-down  the Online POS is unreachable (after the Cloud php was stopped)
 *
 * EVERY https request verifies the peer and the host name against ONE CA file (the sandbox test CA for the Online POS, the branch
 * CA for the Branch Server); CURLOPT_RESOLVE pins names to 127.0.0.1 without touching the hosts file. Passwords, PINs, cookies and
 * assertions are read from files / kept in memory and never printed. Prints ONE JSON document.
 */

[$self, $mode, $cfgFile] = array_pad($argv, 3, null);
$cfg = json_decode((string) @file_get_contents((string) $cfgFile), true);
if (! is_array($cfg)) {
    fwrite(STDERR, "usage: cp5-selfservice-web.php <download|negatives|edge|online-down> <config.json>\n");
    exit(2);
}

/** One HTTP call; $jar = a per-user cookie file (deleted by the caller). */
function cp5_http(array $cfg, string $method, string $url, ?string $jar, $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    // A caller's own Accept REPLACES the default: two Accept headers reach Laravel as "text/html,...", so expectsJson() was
    // false and the controller answered with its HTML redirect instead of the JSON status (CP5 run 7, s8 other_branch 302).
    $ownAccept = (bool) preg_grep('/^\s*accept\s*:/i', $headers);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $ownAccept ? $headers : array_merge(['Accept: text/html,application/json'], $headers),
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CAINFO => $cfg['cafile'], CURLOPT_RESOLVE => $cfg['resolve'] ?? []];
    if ($jar !== null) {
        $opts[CURLOPT_COOKIEJAR] = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : (string) $body;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $verify = (int) curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
    curl_close($ch);
    if ($raw === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $err, 'tls_verified' => false];
    }
    $headersOut = [];
    foreach (preg_split('/\r?\n/', substr((string) $raw, 0, $hsize)) as $line) {
        if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $m)) {
            $headersOut[strtolower($m[1])] = trim($m[2]);
        }
    }

    return ['status' => $status, 'headers' => $headersOut, 'body' => substr((string) $raw, $hsize), 'error' => null, 'tls_verified' => $verify === 0];
}

function cp5_token(string $html): ?string
{
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m) || preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }

    return null;
}

/** Log in on the tenant's Online POS; returns [jar, facts]. */
function cp5_login(array $cfg, string $code): array
{
    $u = $cfg['users'][$code];
    $jar = tempnam($cfg['jar_dir'], 'ss-');
    $page = cp5_http($cfg, 'GET', $cfg['base'] . '/login', $jar);
    $token = cp5_token($page['body']);
    $password = trim((string) file_get_contents($u['pass_file']));
    $r = cp5_http($cfg, 'POST', $cfg['base'] . '/login', $jar, ['_token' => (string) $token, 'email' => $u['email'], 'password' => $password]);
    unset($password);
    $location = (string) ($r['headers']['location'] ?? '');

    return [$jar, ['login_status' => $r['status'], 'tls_verified' => $page['tls_verified'], 'logged_in' => $r['status'] === 302 && ! str_contains($location, '/login'),
        'location' => preg_replace('#^https?://[^/]+#', '', $location)]];
}

function cp5_csrf(array $cfg, string $jar): ?string
{
    foreach (['/pos/offline-counter', '/dashboard', '/pos'] as $p) {
        $t = cp5_token(cp5_http($cfg, 'GET', $cfg['base'] . $p, $jar)['body']);
        if ($t) {
            return $t;
        }
    }

    return null;
}

/**
 * For a user who can open NO page (CP5NP: every page is 403, so no token to read; the login ROTATES the token -
 * session()->regenerate() calls regenerateToken() - so the login form's token is stale): Laravel's own XSRF-TOKEN cookie
 * (set on every response, the 403s included) goes back as X-XSRF-TOKEN, which VerifyCsrfToken decrypts when no _token is
 * posted. Without it the POST got 419 before the controller's permission check (CP5 runs 7 + 8).
 */
function cp5_xsrf_header(string $jar): array
{
    $value = null;
    foreach (@file($jar, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $f = explode("\t", $line);
        if (count($f) >= 7 && $f[5] === 'XSRF-TOKEN') {
            $value = $f[6];
        }
    }

    return $value === null ? [] : ['X-XSRF-TOKEN: ' . urldecode($value)];
}

/** Facts of a counter-setup body WITHOUT keeping the assertion (its user + issuer only). */
function cp5_script_facts(string $body): array
{
    $isScript = str_contains($body, "\$SetupKind       = 'bingoo-counter-wizard-1'");
    $user = $issuer = null;
    if (preg_match("/^\\\$EnrollAssertionB64 = '([^']*)'/m", $body, $m) && $m[1] !== '') {
        $a = json_decode((string) base64_decode($m[1], true), true);
        $user = $a['payload']['user_id'] ?? null;
        $issuer = $a['payload']['issuer_user_id'] ?? null;
    }

    return ['is_counter_setup' => $isScript, 'assertion_user_id' => $user, 'assertion_issuer_user_id' => $issuer];
}

function cp5_page_facts(string $html): array
{
    $text = fn (string $cls) => preg_match('/class="[^"]*\b' . preg_quote($cls, '/') . '\b[^"]*"[^>]*>\s*([^<]*)</', $html, $m) ? trim(html_entity_decode($m[1])) : null;

    return ['key_id' => $text('edge-enrollment-key-id'), 'ca_sha256' => $text('edge-counter-ca'), 'problem' => $text('edge-counter-problem'),
        'has_download' => str_contains($html, '/pos/offline-counter/setup'), 'has_my_pin_download' => str_contains($html, '/pos/offline-counter/my-enrollment')];
}

$result = ['mode' => $mode];
$jars = [];
try {
    switch ($mode) {
        case 'download':
            [$jar, $login] = cp5_login($cfg, $cfg['cashier_code']);
            $jars[] = $jar;
            $page = cp5_http($cfg, 'GET', $cfg['base'] . '/pos/offline-counter', $jar);
            $pos = cp5_http($cfg, 'GET', $cfg['base'] . '/pos', $jar);
            $token = cp5_token($page['body']);
            $dl = cp5_http($cfg, 'POST', $cfg['base'] . '/pos/offline-counter/my-enrollment', $jar, ['_token' => (string) $token]);
            if ($dl['status'] === 200) {
                file_put_contents($cfg['out_file'], $dl['body']);
            }
            $disp = (string) ($dl['headers']['content-disposition'] ?? '');
            $result += ['login' => $login, 'pos_status' => $pos['status'], 'pos_has_entry_button' => str_contains($pos['body'], 'id="pos-offline-counter-btn"'),
                'page_status' => $page['status'], 'page' => cp5_page_facts($page['body']), 'download_status' => $dl['status'],
                'file_name' => preg_match('/filename="([^"]+)"/', $disp, $m) ? $m[1] : null, 'cache_control' => $dl['headers']['cache-control'] ?? null,
                'saved' => $dl['status'] === 200];
            break;

        case 'negatives':
            $n = [];
            // 1. a user WITHOUT tenant.offline-edge.counter-install
            [$jar, $login] = cp5_login($cfg, 'CP5NP');
            $jars[] = $jar;
            $token = cp5_csrf($cfg, $jar);
            $n['no_permission'] = ['login' => $login,
                'page' => cp5_http($cfg, 'GET', $cfg['base'] . '/pos/offline-counter', $jar)['status'],
                'setup' => cp5_http($cfg, 'GET', $cfg['base'] . '/pos/offline-counter/setup', $jar)['status'],
                'csrf_via' => $token !== null ? 'page token' : 'XSRF-TOKEN cookie',
                'my_enrollment' => cp5_http($cfg, 'POST', $cfg['base'] . '/pos/offline-counter/my-enrollment', $jar,
                    $token !== null ? ['_token' => $token] : [], $token !== null ? [] : cp5_xsrf_header($jar))['status']];
            $n['no_permission']['ok'] = $login['logged_in'] && $n['no_permission']['setup'] === 403 && $n['no_permission']['my_enrollment'] === 403 && $n['no_permission']['page'] === 403;

            // 2. a cashier of ANOTHER branch (no Branch Server there): refused with a clear reason, no script
            [$jar, $login] = cp5_login($cfg, 'CP5B2');
            $jars[] = $jar;
            $setup = cp5_http($cfg, 'GET', $cfg['base'] . '/pos/offline-counter/setup', $jar, null, ['Accept: application/json']);
            $page = cp5_http($cfg, 'GET', $cfg['base'] . '/pos/offline-counter', $jar);
            $n['other_branch'] = ['login' => $login, 'setup_status' => $setup['status'], 'setup_message' => (string) (json_decode($setup['body'], true)['message'] ?? ''),
                'setup_is_script' => cp5_script_facts($setup['body'])['is_counter_setup'], 'page_problem' => cp5_page_facts($page['body'])['problem']];
            $n['other_branch']['ok'] = $login['logged_in'] && $setup['status'] === 409 && ! $n['other_branch']['setup_is_script'];

            // 3. an assertion for ANOTHER user: the self-service download ignores any target and the owner tool is forbidden
            [$jar, $login] = cp5_login($cfg, $cfg['cashier_code']);
            $jars[] = $jar;
            $token = cp5_csrf($cfg, $jar) ?? '';
            $t0 = time();
            $mine = cp5_http($cfg, 'POST', $cfg['base'] . '/pos/offline-counter/my-enrollment', $jar,
                ['_token' => $token, 'user_id' => $cfg['owner_id'], 'target_user_id' => $cfg['owner_id'], 'employee_code' => 'CP5OWN']);
            $facts = cp5_script_facts($mine['body']);
            $owner = cp5_http($cfg, 'POST', $cfg['base'] . '/settings/offline-edge/enrollment-assertions', $jar, ['_token' => $token, 'user_id' => $cfg['owner_id']]);
            $n['assertion_for_other_user'] = ['self_service_status' => $mine['status'], 'self_service_assertion_user_id' => $facts['assertion_user_id'],
                'self_service_assertion_issuer_user_id' => $facts['assertion_issuer_user_id'], 'owner_tool_status' => $owner['status']];
            $n['assertion_for_other_user']['ok'] = $mine['status'] === 200 && (int) $facts['assertion_user_id'] === (int) $cfg['cashier_id']
                && (int) $facts['assertion_user_id'] !== (int) $cfg['owner_id'] && in_array($owner['status'], [403, 404], true);

            // 4. another tenant's host: the cashier's session is worthless there (browser rules + a forced cookie)
            $cookie = '';
            foreach (file($jar) ?: [] as $line) {
                $f = explode("\t", trim($line));
                if (count($f) === 7) {
                    $cookie .= ($cookie === '' ? '' : '; ') . $f[5] . '=' . $f[6];
                }
            }
            $other = cp5_http($cfg, 'GET', $cfg['other_tenant_base'] . '/pos/offline-counter/setup', $jar);
            $forced = cp5_http($cfg, 'GET', $cfg['other_tenant_base'] . '/pos/offline-counter/setup', null, null, ['Cookie: ' . $cookie]);
            unset($cookie);
            $n['other_tenant_host'] = ['status' => $other['status'], 'forced_cookie_status' => $forced['status'], 'tls_verified' => $other['tls_verified'],
                'script_served' => cp5_script_facts($other['body'])['is_counter_setup'] || cp5_script_facts($forced['body'])['is_counter_setup']];
            $n['other_tenant_host']['ok'] = ! $n['other_tenant_host']['script_served'] && $other['status'] !== 200 && $forced['status'] !== 200;

            // 5. throttle on "my Edge PIN setup" (throttle:5,1): wait for a fresh minute, then CP5C2 posts until refused
            $wait = max(0, 65 - (time() - $t0));
            sleep($wait);
            [$jar, $login] = cp5_login($cfg, 'CP5C2');
            $jars[] = $jar;
            $token = cp5_csrf($cfg, $jar) ?? '';
            $codes = [];
            for ($i = 0; $i < 8 && ! in_array(429, $codes, true); $i++) {
                $codes[] = cp5_http($cfg, 'POST', $cfg['base'] . '/pos/offline-counter/my-enrollment', $jar, ['_token' => $token])['status'];
            }
            [$jar1] = cp5_login($cfg, $cfg['cashier_code']);
            $jars[] = $jar1;
            $other = cp5_http($cfg, 'POST', $cfg['base'] . '/pos/offline-counter/my-enrollment', $jar1, ['_token' => cp5_csrf($cfg, $jar1) ?? '']);
            $n['throttle'] = ['statuses' => $codes, 'waited_s' => $wait, 'another_user_right_after' => $other['status'],
                'keyed_by' => $other['status'] === 429 ? 'ip' : 'user'];
            $n['throttle']['ok'] = count($codes) === 6 && array_slice($codes, 0, 5) === [200, 200, 200, 200, 200] && $codes[5] === 429;

            $result['negatives'] = $n;
            $result['ok'] = ! in_array(false, array_column($n, 'ok'), true);
            break;

        case 'edge':
            $pin = trim((string) file_get_contents($cfg['dummy_pin_file']));
            $post = function (string $assertionFile) use ($cfg, $pin): array {
                $assertion = json_decode((string) file_get_contents($assertionFile), true);
                $r = cp5_http($cfg, 'POST', $cfg['edge_base'] . '/edge/local/enroll', null,
                    json_encode(['assertion' => $assertion, 'credential' => $pin, 'type' => 'pin'], JSON_UNESCAPED_SLASHES), ['Content-Type: application/json', 'Accept: application/json']);

                return ['status' => $r['status'], 'reason' => mb_substr((string) (json_decode($r['body'], true)['reason'] ?? $r['error'] ?? ''), 0, 160), 'tls_verified' => $r['tls_verified']];
            };
            $e = [];
            foreach (['replay' => 'replay', 'other_tenant' => 'tenant', 'other_branch' => 'branch', 'forged_signature' => 'badsig'] as $name => $file) {
                $e[$name] = $post($cfg['assertions'][$file]);
                $e[$name]['ok'] = $e[$name]['status'] === 422 && $e[$name]['tls_verified'];
            }
            $codes = [];
            for ($i = 0; $i < 8 && ! in_array(429, $codes, true); $i++) {
                $codes[] = $post($cfg['assertions']['badsig'])['status'];
            }
            $e['throttle'] = ['statuses' => $codes, 'ok' => in_array(429, $codes, true)];
            unset($pin);
            $result['edge'] = $e;
            $result['ok'] = ! in_array(false, array_column($e, 'ok'), true);
            break;

        case 'edge-probe':
            // Is the Branch Server's optional self-enrolment endpoint on? 404 = off; 422 = on (an empty body is refused).
            $r = cp5_http($cfg, 'POST', $cfg['edge_base'] . '/edge/local/enroll', null, '{}', ['Content-Type: application/json', 'Accept: application/json']);
            $result += ['status' => $r['status'], 'tls_verified' => $r['tls_verified'], 'error' => $r['error'], 'enabled' => $r['status'] === 422];
            break;

        case 'online-down':
            $r = cp5_http($cfg, 'GET', $cfg['base'] . '/pos', null);
            $result += ['status' => $r['status'], 'error' => $r['error'], 'unreachable' => $r['status'] === 0];
            break;

        default:
            fwrite(STDERR, "unknown mode {$mode}\n");
            exit(2);
    }
} finally {
    foreach ($jars as $j) {
        @unlink($j);
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(! empty($result['ok']) || in_array($mode, ['download', 'online-down', 'edge-probe'], true) ? 0 : 1);
