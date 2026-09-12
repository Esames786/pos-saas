<?php

namespace App\Services\Edge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * P4 FIRST-INSTALL — pull the Cloud bootstrap snapshot over the device-authenticated API and assemble the SAME
 * package shape the file-based importer consumes ({manifest, sections}). Every section is hash-verified against
 * the manifest (X-Content-SHA256 over the uncompressed JSON) before it is accepted; a mismatch fails closed.
 * acknowledge() closes the loop so the Cloud marks the device READY.
 */
class EdgeBootstrapPullClient
{
    public function pull(string $cloudBaseUrl): array
    {
        $api = rtrim($cloudBaseUrl, '/') . '/api/edge/bootstrap/snapshots';
        $create = $this->send(fn (PendingRequest $r) => $r->post($api, []));
        $uuid = (string) ($create['snapshot_uuid'] ?? '');
        if ($uuid === '') {
            throw new RuntimeException('BOOTSTRAP_CREATE_INVALID: no snapshot uuid returned.');
        }
        $manifest = $this->send(fn (PendingRequest $r) => $r->get($api . '/' . $uuid . '/manifest'));
        $summary = (array) ($manifest['sections'] ?? []);
        if ($summary === []) {
            throw new RuntimeException('BOOTSTRAP_MANIFEST_EMPTY: the snapshot lists no sections.');
        }
        $sections = [];
        $receipts = [];
        foreach ($summary as $name => $info) {
            $name = (string) (is_array($info) && isset($info['name']) ? $info['name'] : $name);
            $expected = is_array($info) ? strtolower((string) ($info['content_hash'] ?? $info['hash'] ?? '')) : '';
            $raw = $this->sendRaw(fn (PendingRequest $r) => $r->withHeaders(['Accept-Encoding' => 'identity'])->get($api . '/' . $uuid . '/sections/' . $name));
            $json = $raw['body'];
            $hash = hash('sha256', $json);
            $header = strtolower((string) ($raw['headers']['X-Content-SHA256'] ?? $raw['headers']['x-content-sha256'] ?? ''));
            if ($header !== '' && ! hash_equals($header, $hash)) {
                throw new RuntimeException("BOOTSTRAP_SECTION_HASH_MISMATCH: section [{$name}] bytes do not match the Cloud's receipt hash.");
            }
            if ($expected !== '' && ! hash_equals($expected, $hash)) {
                throw new RuntimeException("BOOTSTRAP_SECTION_HASH_MISMATCH: section [{$name}] does not match the manifest.");
            }
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                throw new RuntimeException("BOOTSTRAP_SECTION_INVALID: section [{$name}] is not a JSON array.");
            }
            $sections[$name] = $decoded;
            $receipts[$name] = $hash;
        }

        return ['manifest' => $manifest, 'sections' => $sections, 'receipts' => $receipts, 'snapshot_uuid' => $uuid];
    }

    public function acknowledge(string $cloudBaseUrl, string $snapshotUuid, string $schemaVersion, string $manifestHash, array $receipts): array
    {
        $api = rtrim($cloudBaseUrl, '/') . '/api/edge/bootstrap/snapshots/' . $snapshotUuid . '/acknowledge';

        return $this->send(fn (PendingRequest $r) => $r->post($api, [
            'schema_version' => $schemaVersion,
            'manifest_hash' => $manifestHash,
            'sections' => $receipts,
        ]));
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders([
                'X-Edge-Device-ID' => (string) config('edge.sync.device_id'),
                'Authorization' => 'Bearer ' . (string) config('edge.sync.device_secret'),
            ])
            ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
            ->timeout(max(60, (int) config('edge.sync.timeout', 20)));
    }

    private function send(callable $fn): array
    {
        $raw = $this->sendRaw($fn);
        $json = json_decode($raw['body'], true);

        return is_array($json) ? $json : [];
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    private function sendRaw(callable $fn): array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = $fn($this->client());
        } catch (ConnectionException $e) {
            throw new RuntimeException('BOOTSTRAP_CLOUD_UNREACHABLE: ' . $e->getMessage(), 0, $e);
        }
        if (! $response->successful()) {
            $json = $response->json() ?? [];
            throw new RuntimeException('BOOTSTRAP_REFUSED: HTTP ' . $response->status() . ' ' . (string) ($json['code'] ?? $json['failure_code'] ?? $json['message'] ?? ''));
        }
        $headers = [];
        foreach ($response->headers() as $k => $v) {
            $headers[$k] = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        }

        return ['status' => $response->status(), 'body' => (string) $response->body(), 'headers' => $headers];
    }
}
