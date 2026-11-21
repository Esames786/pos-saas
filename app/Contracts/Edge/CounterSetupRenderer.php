<?php

namespace App\Contracts\Edge;

/**
 * EDGE 0.9 (K7) — renders the per-branch COUNTER SETUP material from a Branch Server's PUBLIC LAN profile.
 * Owned by stream C (contract); implemented by stream D as App\Services\Edge\EdgeCounterSetupRenderer. The Cloud resolves
 * the implementation lazily (a container binding when present, else the D class when it exists) so the Offline Edge page
 * works — with its counter/phone buttons disabled — before D is merged.
 *
 * The $lanProfile array the Cloud passes (PUBLIC material only — never a key):
 *   lan_hostname        string   e.g. "bingoo-edge.local"
 *   lan_ip              string   e.g. "192.168.100.52"
 *   https_port          int      e.g. 8443
 *   ca_certificate_pem  string   the branch CA certificate (one PEM block, basicConstraints CA:TRUE, validated by the Cloud)
 *   ca_sha256           string   lowercase hex SHA-256 over the CA's DER bytes (= openssl x509 -fingerprint -sha256)
 *   device_uuid         string   the Branch Server's public device id
 *   branch_id           int
 *   reported_at         ?string  ISO-8601, when the appliance reported the profile
 *   ca_download_url     ?string  (phoneOnboarding only) a 30-minute SIGNED Cloud URL that serves the CA over the Cloud's
 *                                public HTTPS — so a phone can install the CA without logging in and without plain HTTP
 *   branch_name         ?string  (phoneOnboarding only)
 */
interface CounterSetupRenderer
{
    /**
     * The Windows counter setup script (PowerShell text, ASCII): CA PEM embedded → Trusted Root, hosts line
     * "<lan_ip> <lan_hostname>", three shortcuts (Online = $onlinePosUrl, Offline = https://<lan_hostname>:<port>/edge/local/login,
     * Status = …/edge/local/pos/health) with the Bingoo icon, a TLS-validated reachability check, NO certificate bypass.
     */
    public function renderWindows(array $lanProfile, string $onlinePosUrl, string $branchLabel): string;

    /**
     * EDGE 0.9 (owner, 21 Nov 2026) — the cashier SELF-SERVICE Offline Counter wizard (WPF, PowerShell 5.1: Install / Repair /
     * Remove, explicit confirmation before the root CA is trusted). The console renderWindows() stays for operator/unattended use.
     *
     * $context (all keys optional):
     *   tenant_label    string  business name
     *   terminal        {id: int, label: string}  the user's default counter (a binding HINT)
     *   signed_profile  {profile: {lan_hostname, lan_ip, https_port, ca_sha256, branch_id, …}, signature: base64 Ed25519 over
     *                   EdgeCanonicalJson::encode(profile) (= EdgeEnrollmentCrypto::canonicalJson) with the Cloud ENROLLMENT
     *                   signing key, public_key: base64}
     *   enrollment      {assertion: EdgeEnrollmentIssuer::issue(...) for the LOGGED-IN user only, user_label: "Name (code)"}
     * A download carrying `enrollment` holds a single-use 15-minute assertion: it is served no-store, only to that user.
     */
    public function renderWindowsWizard(array $lanProfile, string $onlinePosUrl, string $branchLabel, array $context): string;

    /**
     * Phone onboarding data for resources/views/tenant/offline-edge/partials/phone-onboarding.blade.php (stream D).
     * Expected keys (the Cloud page renders QR codes for login_url and ca_download_url when present):
     *   login_url        string  https://<lan_ip>:<port>/edge/local/login (by IP — phones do not use the hosts file)
     *   status_url       ?string
     *   ca_download_url  ?string
     *   ca_sha256        string
     *   steps            array<string, list<string>>  per OS: 'android', 'ios' (and optionally 'windows')
     *
     * @return array<string, mixed>
     */
    public function phoneOnboarding(array $lanProfile): array;
}
