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
 * verdicts (hash conflict, wrong binding, revoked device, stale epoch, unsupported feature, invalid
 * payload) move the row to failed_permanent for 1E operator handling — never an infinite hot loop.
 * W6: an unsupported envelope SCHEMA (a Cloud not yet upgraded) is retried with a bounded backoff instead.
 */
class EdgeSyncSender
{
    /** Cloud ACK statuses that mean "this envelope is durably, authoritatively applied". */
    private const TERMINAL_SUCCESS = ['applied', 'already_applied'];

    /** Failure codes that are TERMINAL — one shared list with the Cloud ingestions (EdgeIngestionVerdicts). */
    private const TERMINAL_FAILURES = EdgeIngestionVerdicts::TERMINAL_FAILURE_CODES;


    /** W6: the one refusal the appliance retries (Cloud-first deploy ordering) instead of parking as failed_permanent. */
    public const SCHEMA_RETRY_CODE = 'SCHEMA_UNSUPPORTED';

    public function __construct(private readonly EdgeSyncOutboxService $outbox)
    {
    }

    /** Bounded exponential backoff for SCHEMA_UNSUPPORTED: base·2^(attempts−1), capped (config edge.sync.schema_retry_*). */
    public static function schemaRetryDelaySeconds(int $attempts): int
    {
        $base = max(1, (int) config('edge.sync.schema_retry_base_seconds', 60));
        $max = max($base, (int) config('edge.sync.schema_retry_max_seconds', 900));
        $exp = min(max($attempts, 1) - 1, 16);

        return (int) min($max, $base * (2 ** $exp));
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
        // F1: a return event travels the same outbox to the Cloud's RETURN ingestion; a sale to the sale ingestion.
        // F2: a supplier-finance event (payment / AP journal) travels the same outbox to the Cloud's supplier-finance ingestion.
        $schema = (string) $row->envelope_schema_version;
        [$urlKey, $envName] = match (true) {
            $schema === EdgeReturnEnvelopeBuilder::SCHEMA => ['edge.sync.returns_url', 'EDGE_SYNC_RETURNS_URL'],
            EdgeSupplierFinanceEnvelopeBuilder::isFinanceSchema($schema) => ['edge.sync.supplier_finance_url', 'EDGE_SYNC_SUPPLIER_FINANCE_URL'],
            $schema === EdgePurchaseReturnEnvelopeBuilder::SCHEMA => ['edge.sync.purchase_returns_url', 'EDGE_SYNC_PURCHASE_RETURNS_URL'],
            default => ['edge.sync.url', 'EDGE_SYNC_URL'],
        };
        $url = (string) config($urlKey);
        if ($url === '') {
            $this->outbox->releaseLease($row, $envName . ' not configured');

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

        // ACK identity must match THIS envelope exactly, or we never acknowledge. A CONFLICT verdict names the truth the Cloud
        // already holds in `content_hash` and the envelope it refused in `incoming_content_hash` — that is the identity proof
        // for this row (the hashes differ by definition), so a conflict on OUR hash may become terminal; any other mismatch is rejected.
        $conflictNamesThisEnvelope = (string) ($ack['status'] ?? '') === 'conflict'
            && hash_equals((string) $row->content_hash, (string) ($ack['incoming_content_hash'] ?? ''));
        if (($ack['sale_uuid'] ?? null) !== $row->sale_uuid
            || (! hash_equals((string) $row->content_hash, (string) ($ack['content_hash'] ?? '')) && ! $conflictNamesThisEnvelope)) {
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

        // W6 (coordinator-approved, 25 Sep 2026) — CLOUD-FIRST DEPLOY ORDERING: a Cloud that does not (yet) speak this
        // envelope's schema answers SCHEMA_UNSUPPORTED. For the appliance that is NOT a terminal verdict about the
        // envelope — the Cloud will be upgraded and the identical immutable bytes will then apply (the Cloud registry
        // re-attempts a refused, never-applied row with the SAME content). Park it with a BOUNDED exponential backoff
        // instead of failed_permanent. The shared EdgeIngestionVerdicts list is unchanged (the Cloud still answers
        // `refused` with the envelope identity).
        if ($code === self::SCHEMA_RETRY_CODE && $status === 'refused') {
            $delay = self::schemaRetryDelaySeconds((int) $row->attempts);
            try {
                $this->outbox->deferLease($row, $delay, $status . ':' . $code . ' (Cloud not upgraded yet; retry in ' . $delay . 's)');
            } catch (Throwable $e) {
                $this->outbox->releaseLease($row->fresh() ?? $row, 'defer: ' . mb_substr($e->getMessage(), 0, 200));
            }
            $this->audit('schema_unsupported_deferred', $row, ['status' => $status, 'failure_code' => $code, 'retry_in_seconds' => $delay]);

            return 'retry';
        }

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
