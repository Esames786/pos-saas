<?php

namespace App\Services\Edge;

use Illuminate\Support\Facades\Http;
use App\Exceptions\EdgeAuthorityRefusedException;
use App\Models\Edge\EdgeLocalMeta;
use RuntimeException;

/**
 * OFFLINE EDGE — the appliance's transport for the branch authority lease (heartbeat / handback) to the Cloud.
 * Same device authentication as the sale sync transport (device id header + bearer secret, TLS verified).
 * A transport failure is a controlled RuntimeException — the caller decides what a missed beat means.
 *
 * W-F VERSION REPORTING — every heartbeat also carries an optional, informational `build` block (what this appliance
 * runs and can consume). It never influences the lease: an old Cloud ignores the unknown key, a new Cloud records it
 * only when it changed. Building it can never fail a beat — when the facts are unavailable the block is null.
 */
class EdgeAuthorityLeaseClient
{
    public function __construct(private readonly ?EdgeBuildInfoService $buildInfo = null)
    {
    }

    public function heartbeat(int $seq, string $edgeState, ?array $build = null): array
    {
        return $this->post((string) config('edge.authority.heartbeat_url'), $this->heartbeatPayload($seq, $edgeState, $build));
    }

    public function handback(int $outboxPending, int $failedPermanent): array
    {
        return $this->post((string) config('edge.authority.handback_url'), ['outbox_pending' => $outboxPending, 'failed_permanent' => $failedPermanent]);
    }

    /** The exact heartbeat request body (seq + edge_state are the lease contract; `build` is informational). */
    public function heartbeatPayload(int $seq, string $edgeState, ?array $build = null): array
    {
        return ['seq' => $seq, 'edge_state' => $edgeState, 'build' => $build];
    }

    /**
     * The non-secret build/compatibility facts reported on the heartbeat. `$meta` is the bound edge_local_meta row (the
     * APPLIED Edge schema lives there; null while unbound). Never throws: any failure reports `null` (no block).
     *
     * @return array{edge_app_version:?string, git_commit:?string, artifact_version:?string, bootstrap_schema:?string, config_schema:?string, edge_schema_version:?string, applied_edge_schema_version:?string, envelope_versions:list<string>, capabilities:list<string>}|null
     */
    public function buildReport(?EdgeLocalMeta $meta = null): ?array
    {
        try {
            $info = ($this->buildInfo ?? app(EdgeBuildInfoService::class))->info();

            return [
                'edge_app_version' => self::fact($info['edge_app_version'] ?? null),
                'git_commit' => self::fact($info['git_commit'] ?? null),
                'artifact_version' => self::fact($info['artifact_version'] ?? null),
                'bootstrap_schema' => self::fact($info['bootstrap_schema'] ?? null),
                'config_schema' => self::fact($info['config_schema'] ?? null),
                'edge_schema_version' => self::fact($info['edge_schema_version'] ?? null, 190),
                'applied_edge_schema_version' => self::fact($meta?->getAttribute('edge_schema_version'), 190),
                'envelope_versions' => self::envelopeVersions(),
                'capabilities' => array_values(array_filter(array_map(
                    fn ($c) => self::fact($c, 100),
                    (array) ($info['capabilities'] ?? config('edge.capabilities', []))
                ))),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Every sync-envelope generation this build can EMIT (the Cloud must be able to ingest each of them). */
    public static function envelopeVersions(): array
    {
        return [
            EdgeSaleEnvelopeBuilder::SCHEMA_VERSION,
            EdgeSaleEnvelopeBuilder::SCHEMA_VERSION_V2,
            EdgeReturnEnvelopeBuilder::SCHEMA,
            EdgePurchaseReturnEnvelopeBuilder::SCHEMA,
            EdgeSupplierFinanceEnvelopeBuilder::SCHEMA_PAYMENT,
            EdgeSupplierFinanceEnvelopeBuilder::SCHEMA_AP_JOURNAL,
        ];
    }

    /** A reported fact as a bounded string, or null when it is absent/unknown (never an empty string). */
    private static function fact(mixed $value, int $max = 64): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function post(string $url, array $body): array
    {
        if ($url === '') {
            throw new RuntimeException('AUTHORITY_URL_MISSING');
        }
        try {
            $response = Http::withHeaders([
                    'X-Edge-Device-ID' => (string) config('edge.sync.device_id'),
                    'Authorization' => 'Bearer ' . (string) config('edge.sync.device_secret'),
                    'Accept' => 'application/json',
                ])
                ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
                ->timeout((int) config('edge.sync.timeout', 20))
                ->post($url, $body);
        } catch (\Throwable $e) {
            throw new RuntimeException('AUTHORITY_UNREACHABLE: ' . $e->getMessage(), 0, $e);
        }
        $json = $response->json();
        if (! $response->successful() || ! is_array($json)) {
            throw new EdgeAuthorityRefusedException('AUTHORITY_REFUSED: HTTP ' . $response->status() . ' ' . (string) ($json['failure_code'] ?? $json['message'] ?? ''), (int) $response->status(), is_array($json) ? $json : []);
        }

        return $json;
    }
}
