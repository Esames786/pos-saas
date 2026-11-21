<?php

/*
 * TEST-ONLY COPY of stream C's contract K7 (docs/plans/edge-0.9-impact-map-2026-11-21.md), so stream D can prove
 * EdgeCounterSetupRenderer satisfies it before C's real interface (app/Contracts/Edge/CounterSetupRenderer.php) is on this
 * branch. It is loaded with require_once ONLY when the real interface does not exist; delete this file at integration
 * once C's interface has landed (the tests then check the real one).
 */

namespace App\Contracts\Edge;

interface CounterSetupRenderer
{
    /**
     * PowerShell text: CA PEM embedded, hosts line, 3 shortcuts with the Bingoo icon, TLS-validated check, no bypass.
     *
     * @param  array<string, mixed>  $lanProfile  lan_hostname, lan_ip, lan_https_port, lan_ca_pem, lan_ca_sha256
     */
    public function renderWindows(array $lanProfile, string $onlinePosUrl, string $branchLabel): string;

    /**
     * Login URL by IP, CA download URL/route, per-OS steps — for the phone onboarding partial.
     *
     * @param  array<string, mixed>  $lanProfile
     * @return array<string, mixed>
     */
    public function phoneOnboarding(array $lanProfile): array;
}
