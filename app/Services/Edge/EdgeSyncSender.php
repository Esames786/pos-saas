<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeSyncOutbox;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OFFLINE-SYNC-ENGINE-1D — the Edge-side authenticated HTTP transport for the sync outbox. TRANSPORT ONLY:
 * it leases one immutable outbox envelope, POSTs the exact stored bytes to the Cloud device-authenticated
 * ingestion endpoint (a thin boundary around 1C), and acts on the VERIFIED ACK. It never duplicates the 1C
 * posting pipeline and never regenerates business data on retry — the same outbox row always sends the same
 * immutable content and the same sale_uuid.
 *
 * The Edge outbox row is marked ACKNOWLEDGED only on a VERIFIED terminal-success ACK for the SAME sale_uuid
 * + content_hash (never merely because HTTP returned 200). Transient failures (network/DNS/TLS/connect
 * timeout, HTTP 5xx, a Cloud EXCEPTION result) release the lease for bounded-backoff retry; terminal
 * verdicts (hash conflict, wrong binding, revoked device, stale epoch, unsupported schema/feature, invalid
 * payload) move the row to failed_permanent for 1E operator handling — never an infinite hot loop.
 */
class EdgeSyncSender
{
    /** Cloud ACK statuses that mean "this envelope is durably, authoritatively applied". */
    private const TERMINAL_SUCCESS = ['applied', 'already_applied'];

    /** Failure codes that are TERMINAL — retrying the identical immutable envelope can never succeed. */
    private const TERMINAL_FAILURES = [
        'ENVELOPE_CONFLICT', 'WRONG_TENANT', 'WRONG_BRANCH', 'DEVICE_UNKNOWN', 'DEVICE_REVOKED',
        'STALE_ACTIVATION', 'SCHEMA_UNSUPPORTED', 'ORDER_TYPE_UNSUPPORTED', 'PAYMENT_UNSUPPORTED',
        'SALE_UUID_INVALID', 'HASH_INVALID', 'ENVELOPE_INVALID', 'CUSTOMER_INVALID', 'CUSTOMER_UNKNOWN',
        'PRODUCT_UNRESOLVED',
    ];

    public function __construct(private readonly EdgeSyncOutboxService $outbox)
    {
    }

    /**
     * Lease and transport the next pending envelope. Returns a machine outcome:
     *   'idle' (nothing to send) | 'acknowledged' | 'retry' (transient; lease released) |
     *   'terminal' (failed_permanent) | 'reject' (ACK did not verify; lease released).
     */
    public function sendNext(string $owner): string
    {
        $row = $this->outbox->lease($owner);
        if (! $row) {
            return 'idle';
        }

        return $this->transport($row);
    }

    private function transport(EdgeSyncOutbox $row): string
    {
        $url = (string) config('edge.sync.url');
        if ($url === '') {
            $this->outbox->releaseLease($row, 'EDGE_SYNC_URL not configured');

            return 'retry';
        }

        try {
            $response = Http::withHeaders([
                    'X-Edge-Device-ID' => (string) config('edge.sync.device_id'),
                    'Authorization' => 'Bearer ' . (string) config('edge.sync.device_secret'),
                    'Accept' => 'application/json',
                ])
                ->withOptions(['verify' => true])                       // TLS verification ON (never disabled)
                ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
                ->timeout((int) config('edge.sync.timeout', 20))
                ->asJson()
                ->post($url, ['envelope' => $row->envelopeArray()]);    // the exact immutable stored bytes
        } catch (ConnectionException $e) {
            // Network unavailable / DNS / TLS / connect+request timeout — transient.
            $this->outbox->releaseLease($row, 'transport: ' . mb_substr($e->getMessage(), 0, 300));
            $this->audit('transport_error', $row, ['error' => mb_substr($e->getMessage(), 0, 120)]);

            return 'retry';
        } catch (Throwable $e) {
            $this->outbox->releaseLease($row, 'transport: ' . mb_substr($e->getMessage(), 0, 300));

            return 'retry';
        }

        // HTTP 5xx / 429 -> Cloud temporarily unavailable -> transient.
        if ($response->serverError() || $response->status() === 429) {
            $this->outbox->releaseLease($row, 'HTTP ' . $response->status());
            $this->audit('http_transient', $row, ['status' => $response->status()]);

            return 'retry';
        }

        $ack = $response->json();
        if (! is_array($ack) || ! isset($ack['status'])) {
            $this->outbox->releaseLease($row, 'malformed ACK body (HTTP ' . $response->status() . ')');

            return 'reject';
        }

        // ACK identity must match THIS envelope exactly, or we never acknowledge.
        if (($ack['sale_uuid'] ?? null) !== $row->sale_uuid
            || ! hash_equals((string) $row->content_hash, (string) ($ack['content_hash'] ?? ''))) {
            $this->outbox->releaseLease($row, 'ACK identity mismatch (sale_uuid/content_hash)');
            $this->audit('ack_identity_mismatch', $row, ['ack_status' => $ack['status'] ?? null]);

            return 'reject';
        }

        $status = (string) $ack['status'];

        // Verified terminal success — replay ('already_applied') converges to the SAME official truth.
        if (in_array($status, self::TERMINAL_SUCCESS, true)) {
            try {
                $this->outbox->markAcknowledged($row, (string) ($ack['ingestion_uuid'] ?? ''), $ack);
            } catch (Throwable $e) {
                // Lost the lease to a reclaimer between send and ack, or an identity guard — never a duplicate:
                // release and let the current owner converge on the same idempotent Cloud result.
                $this->outbox->releaseLease($row->fresh() ?? $row, 'ack apply: ' . mb_substr($e->getMessage(), 0, 200));

                return 'reject';
            }
            $this->audit('acknowledged', $row, ['ingestion_uuid' => $ack['ingestion_uuid'] ?? null, 'status' => $status]);

            return 'acknowledged';
        }

        $code = (string) ($ack['failure_code'] ?? '');
        if ($status === 'conflict' || in_array($code, self::TERMINAL_FAILURES, true)) {
            $this->outbox->markFailedPermanent($row, $status . ':' . $code);
            $this->audit('terminal_failure', $row, ['status' => $status, 'failure_code' => $code]);

            return 'terminal';
        }

        // EXCEPTION (e.g. INSUFFICIENT_STOCK) or an unrecognised non-success — retryable but recorded, never a
        // hot loop: 1E owns supervisor resolution / baseline cutover. Released for bounded-backoff retry.
        $this->outbox->releaseLease($row, $status . ':' . $code);
        $this->audit('retryable_exception', $row, ['status' => $status, 'failure_code' => $code]);

        return 'retry';
    }

    /** Safe correlation logging only — never device secrets or full payloads. */
    private function audit(string $event, EdgeSyncOutbox $row, array $extra = []): void
    {
        Log::info("[edge-sync-transport] {$event}", array_merge([
            'sale_uuid' => $row->sale_uuid,
            'device_uuid' => (string) config('edge.sync.device_id'),
            'attempt' => (int) $row->attempts,
        ], $extra));
    }
}
