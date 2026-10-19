<?php

namespace App\Services\Saas;

/**
 * TRIAL-SIGNUP-QUEUE-1 — is this address served over HTTPS with a certificate that names it?
 *
 * A new trial's subdomain joins the SSL certificate through `bingoo-cert-sync` (a root cron on the
 * server, not this app). Until it does, the browser shows "Not secure" for the login link the
 * welcome email carries. The probe performs a real TLS handshake with peer verification and SNI —
 * the same check the customer's browser makes — and reports only yes or no.
 */
class TrialHttpsProbe
{
    public function serves(string $host, int $timeoutSeconds = 5): bool
    {
        $context = stream_context_create(['ssl' => [
            'peer_name'         => $host,
            'SNI_enabled'       => true,
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ]]);

        $socket = @stream_socket_client('ssl://' . $host . ':443', $errno, $error, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
