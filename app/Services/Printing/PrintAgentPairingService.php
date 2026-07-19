<?php

namespace App\Services\Printing;

use App\Models\Tenant\PrintAgent;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * PRINT-AGENT-INSTALLER-1 — pairing-code exchange.
 *
 * A 6-digit code (shown once in the admin UI, 15-minute expiry, single use)
 * is exchanged by the installed agent for the permanent 64-char token. The
 * permanent token is returned exactly once over TLS and stored only as a
 * bcrypt hash (existing token_hash pattern) — it is never displayed in the
 * browser. Legacy raw-token creation/regeneration keeps working unchanged.
 */
class PrintAgentPairingService
{
    public const CODE_TTL_MINUTES = 15;
    public const MAX_ATTEMPTS     = 5;

    /** Issue a fresh pairing code for an agent; invalidates any previous code. */
    public function generatePairingCode(PrintAgent $agent): string
    {
        $code = (string) random_int(100000, 999999);

        $agent->update([
            'pairing_code_hash'  => $this->digest($code),
            'pairing_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'pairing_attempts'   => 0,
        ]);

        $this->audit('print_agent.pairing_code_generated', $agent);

        return $code;
    }

    /**
     * Exchange a pairing code for the permanent token.
     *
     * @return array{agent_code: string, token: string}
     * @throws RuntimeException with a client-safe message on any failure
     */
    public function pair(string $code, array $deviceInfo = []): array
    {
        $normalized = preg_replace('/\D+/', '', $code) ?? '';

        if (strlen($normalized) !== 6) {
            throw new RuntimeException('Invalid pairing code format.');
        }

        $agent = PrintAgent::where('pairing_code_hash', $this->digest($normalized))->first();

        if (! $agent) {
            throw new RuntimeException('Invalid pairing code.');
        }

        if (! $agent->is_active) {
            throw new RuntimeException('This agent has been deactivated.');
        }

        if ($agent->pairing_attempts >= self::MAX_ATTEMPTS) {
            throw new RuntimeException('Too many failed attempts for this code. Generate a new pairing code.');
        }

        if (! $agent->pairing_expires_at || $agent->pairing_expires_at->isPast()) {
            // Burn the code so an expired code can never be retried.
            $agent->update(['pairing_code_hash' => null, 'pairing_expires_at' => null]);
            throw new RuntimeException('This pairing code has expired. Generate a new one.');
        }

        $plainToken = Str::random(64);

        $agent->update([
            'token_hash'             => Hash::make($plainToken),
            'pairing_code_hash'      => null,   // single use
            'pairing_expires_at'     => null,
            'pairing_attempts'       => 0,
            'paired_at'              => now(),
            'paired_device_name'     => Str::limit((string) ($deviceInfo['device_name'] ?? ''), 190, ''),
            'paired_device_platform' => Str::limit((string) ($deviceInfo['device_platform'] ?? ''), 190, ''),
            'paired_device_ip'       => Str::limit((string) ($deviceInfo['ip'] ?? ''), 64, ''),
            'device_name'            => ($deviceInfo['device_name'] ?? null) ?: $agent->device_name,
            'device_os'              => ($deviceInfo['device_platform'] ?? null) ?: $agent->device_os,
        ]);

        $this->audit('print_agent.paired', $agent, [
            'device_name'     => $deviceInfo['device_name'] ?? null,
            'device_platform' => $deviceInfo['device_platform'] ?? null,
        ]);

        return [
            'agent_code' => $agent->agent_code,
            'token'      => $plainToken,
        ];
    }

    /**
     * Deterministic queryable digest — HMAC-SHA256 keyed with the app key so
     * a leaked database dump does not reveal usable codes.
     */
    public function digest(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * Structured audit log line. A central audit table is future work
     * (documented in the installer runbook); log entries carry the same fields.
     */
    public function audit(string $event, PrintAgent $agent, array $extra = []): void
    {
        Log::info("[print-agent-audit] {$event}", array_merge([
            'agent_id'   => $agent->id,
            'agent_code' => $agent->agent_code,
            'user_id'    => auth('tenant')->id(),
        ], $extra));
    }
}
