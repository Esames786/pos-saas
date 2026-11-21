<?php

namespace App\Services\Edge\Packaging;

/**
 * Edge 0.9 (stream E) — does a Branch Server TLS certificate satisfy the client platforms on the branch LAN?
 * (edge:audit-package --server-cert=<file>; pure PHP openssl_x509_parse, PEM or DER input.)
 *
 * Rules (the strictest of iOS / macOS / Android / Chrome):
 *   - validity period (notBefore..notAfter) <= 825 days — Apple refuses longer server certificates even from a trusted CA;
 *   - extendedKeyUsage contains serverAuth (TLS Web Server Authentication) — required by Apple since 2019;
 *   - subjectAltName carries at least one DNS name AND one IP address (the cashier reaches the server by bingoo-edge.local
 *     and by its LAN IP; clients ignore the CN) — and the expected host / IP when given;
 *   - RSA >= 2048 bits (or EC >= 256), SHA-2 signature; not a CA certificate; currently within its validity window.
 */
final class ServerCertificateCheck
{
    public const MAX_VALIDITY_DAYS = 825;

    /** @return array{ok:bool, problems:list<string>, subject_cn:?string, issuer_cn:?string, not_before:?string, not_after:?string,
     *               validity_days:?float, max_validity_days:int, server_auth_eku:bool, san_dns:list<string>, san_ip:list<string>,
     *               key_type:?string, key_bits:?int, signature_algorithm:?string, is_ca:bool, expired:bool, not_yet_valid:bool} */
    public function inspect(string $certificate, ?string $expectHost = null, ?string $expectIp = null): array
    {
        $pem = str_contains($certificate, '-----BEGIN CERTIFICATE-----') ? $certificate
            : "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($certificate), 64, "\n") . "-----END CERTIFICATE-----\n";
        $info = @openssl_x509_parse($pem);
        $base = ['ok' => false, 'problems' => [], 'subject_cn' => null, 'issuer_cn' => null, 'not_before' => null, 'not_after' => null,
            'validity_days' => null, 'max_validity_days' => self::MAX_VALIDITY_DAYS, 'server_auth_eku' => false, 'san_dns' => [], 'san_ip' => [],
            'key_type' => null, 'key_bits' => null, 'signature_algorithm' => null, 'is_ca' => false, 'expired' => false, 'not_yet_valid' => false];
        if (! is_array($info)) {
            $base['problems'][] = 'CERT_UNREADABLE: not a PEM or DER X.509 certificate';

            return $base;
        }
        $problems = [];
        $from = (int) ($info['validFrom_time_t'] ?? 0);
        $to = (int) ($info['validTo_time_t'] ?? 0);
        $days = round(($to - $from) / 86400, 2);
        if ($days > self::MAX_VALIDITY_DAYS) {
            $problems[] = "VALIDITY_TOO_LONG: {$days} days > " . self::MAX_VALIDITY_DAYS . ' (iOS/macOS refuse it even from a trusted CA)';
        }
        $now = time();
        $expired = $to <= $now;
        $notYet = $from > $now;
        if ($expired) {
            $problems[] = 'CERT_EXPIRED: notAfter ' . gmdate('Y-m-d', $to);
        }
        if ($notYet) {
            $problems[] = 'CERT_NOT_YET_VALID: notBefore ' . gmdate('Y-m-d H:i', $from) . ' UTC (check the server clock)';
        }
        $ext = (array) ($info['extensions'] ?? []);
        $eku = (string) ($ext['extendedKeyUsage'] ?? '');
        $serverAuth = stripos($eku, 'TLS Web Server Authentication') !== false || str_contains($eku, '1.3.6.1.5.5.7.3.1');
        if (! $serverAuth) {
            $problems[] = 'EKU_SERVER_AUTH_MISSING: extendedKeyUsage must contain serverAuth' . ($eku === '' ? ' (no EKU extension)' : ' (has: ' . $eku . ')');
        }
        $dns = [];
        $ips = [];
        foreach (array_map('trim', explode(',', (string) ($ext['subjectAltName'] ?? ''))) as $entry) {
            if (stripos($entry, 'DNS:') === 0) {
                $dns[] = strtolower(trim(substr($entry, 4)));
            } elseif (stripos($entry, 'IP Address:') === 0) {
                $ips[] = trim(substr($entry, 11));
            }
        }
        if ($dns === []) {
            $problems[] = 'SAN_DNS_MISSING: subjectAltName has no DNS name (clients ignore the CN)';
        }
        if ($ips === []) {
            $problems[] = 'SAN_IP_MISSING: subjectAltName has no IP address (a phone opening https://<LAN IP>:8443 would fail)';
        }
        if ($expectHost !== null && $expectHost !== '' && ! in_array(strtolower($expectHost), $dns, true)) {
            $problems[] = "SAN_HOST_MISMATCH: {$expectHost} is not a DNS SAN (" . implode(', ', $dns) . ')';
        }
        if ($expectIp !== null && $expectIp !== '' && ! in_array($expectIp, $ips, true)) {
            $problems[] = "SAN_IP_MISMATCH: {$expectIp} is not an IP SAN (" . implode(', ', $ips) . ')';
        }
        $isCa = stripos((string) ($ext['basicConstraints'] ?? ''), 'CA:TRUE') !== false;
        if ($isCa) {
            $problems[] = 'CERT_IS_CA: a server certificate must not be a CA (basicConstraints CA:TRUE)';
        }
        $keyType = null;
        $bits = null;
        $pub = @openssl_pkey_get_public($pem);
        if ($pub !== false) {
            $d = openssl_pkey_get_details($pub) ?: [];
            $bits = isset($d['bits']) ? (int) $d['bits'] : null;
            $keyType = match ($d['type'] ?? null) { OPENSSL_KEYTYPE_RSA => 'rsa', OPENSSL_KEYTYPE_EC => 'ec', default => 'other' };
        }
        if (($keyType === 'rsa' && (int) $bits < 2048) || ($keyType === 'ec' && (int) $bits < 256) || $keyType === null || $keyType === 'other') {
            $problems[] = 'KEY_TOO_WEAK: ' . ($keyType ?? 'unknown') . ' ' . ($bits ?? '?') . ' bits (RSA >= 2048 or EC >= 256)';
        }
        $sigAlg = (string) ($info['signatureTypeLN'] ?? $info['signatureTypeSN'] ?? '');
        if (preg_match('/sha(256|384|512)|ecdsa-with-SHA(256|384|512)/i', $sigAlg) !== 1) {
            $problems[] = 'SIGNATURE_NOT_SHA2: ' . ($sigAlg === '' ? 'unknown' : $sigAlg);
        }

        return array_merge($base, [
            'ok' => $problems === [],
            'problems' => $problems,
            'subject_cn' => $info['subject']['CN'] ?? null,
            'issuer_cn' => $info['issuer']['CN'] ?? null,
            'not_before' => gmdate('c', $from),
            'not_after' => gmdate('c', $to),
            'validity_days' => $days,
            'server_auth_eku' => $serverAuth,
            'san_dns' => $dns,
            'san_ip' => $ips,
            'key_type' => $keyType,
            'key_bits' => $bits,
            'signature_algorithm' => $sigAlg,
            'is_ca' => $isCa,
            'expired' => $expired,
            'not_yet_valid' => $notYet,
        ]);
    }
}
