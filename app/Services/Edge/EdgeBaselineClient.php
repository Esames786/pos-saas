<?php

namespace App\Services\Edge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PRODUCTIZATION GATE 0 — the Edge-side authenticated fetch + acceptance of an issued baseline package.
 *
 * Same transport posture as the sender: device-auth headers, TLS verify ON, explicit timeouts. It fetches the
 * package the Cloud computed from official stock, validates it locally (identity resolvable, no impossible
 * quantity) and then delegates to the ATOMIC cutover authority (EdgeBaselineCutoverService::acceptCutover),
 * which independently re-checks branch/epoch/revision/integrity and enforces the drain gate. Nothing here
 * bypasses the cutover authority; nothing posts Cloud effects.
 */
class EdgeBaselineClient
{
    public function __construct(
        private readonly EdgeBaselineCutoverService $cutover,
        private readonly EdgeBranchContext $context,
    ) {
    }

    /** Fetch the issued baseline package for the current binding's revision/epoch. */
    public function fetch(string $sourceRevision, int $activationEpoch): array
    {
        $url = (string) config('edge.sync.baseline_url');
        if ($url === '') {
            throw new RuntimeException('BASELINE_URL_MISSING: edge.sync.baseline_url is not configured.');
        }

        $response = Http::withHeaders([
            'X-Edge-Device-ID' => (string) config('edge.sync.device_id'),
            'Authorization' => 'Bearer ' . (string) config('edge.sync.device_secret'),
        ])
            ->withOptions(['verify' => true]) // TLS verification ON — never disabled
            ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
            ->timeout((int) config('edge.sync.timeout', 20))
            ->asJson()
            ->post($url, ['source_revision' => $sourceRevision, 'activation_epoch' => $activationEpoch]);

        if (! $response->successful()) {
            throw new RuntimeException('BASELINE_HTTP_' . $response->status() . ': baseline issuance request failed.');
        }
        $package = $response->json('package');
        if (! is_array($package)) {
            throw new RuntimeException('BASELINE_MALFORMED: the issuance response carried no package.');
        }

        return $package;
    }

    /**
     * Fetch and accept the baseline for the current binding. Validates local resolvability before delegating
     * to the atomic cutover authority. Returns the newly accepted baseline row.
     */
    public function fetchAndAccept(string $performedBy, string $reason): object
    {
        $meta = $this->context->requireCurrent();
        $package = $this->fetch((string) $meta->source_revision, (int) $meta->activation_epoch);

        $this->assertResolvable($package);

        return $this->cutover->acceptCutover($package, $performedBy, $reason);
    }

    /**
     * Fail-closed local validation before acceptance: every product identity must resolve locally (Cloud-stable
     * PK), and no quantity may be impossible (negative). Duplicate rows and non-finite quantities are rejected
     * downstream by the canonicalizer inside the cutover authority.
     */
    public function assertResolvable(array $package): void
    {
        $items = $package['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new RuntimeException('BASELINE_EMPTY: the baseline package has no items.');
        }
        foreach ($items as $i => $item) {
            $qty = $item['quantity'] ?? null;
            if (! is_numeric($qty) || (float) $qty < 0) {
                throw new RuntimeException("BASELINE_IMPOSSIBLE_QUANTITY: item #{$i} has an impossible quantity.");
            }
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0 || ! DB::connection('tenant')->table('products')->where('id', $productId)->exists()) {
                throw new RuntimeException("BASELINE_UNKNOWN_PRODUCT: item #{$i} product_id [{$productId}] does not resolve locally.");
            }
            $variantId = $item['product_variant_id'] ?? null;
            if ($variantId !== null && $variantId !== '' && (int) $variantId > 0
                && ! DB::connection('tenant')->table('product_variants')->where('id', (int) $variantId)->exists()) {
                throw new RuntimeException("BASELINE_UNKNOWN_VARIANT: item #{$i} product_variant_id [{$variantId}] does not resolve locally.");
            }
        }
    }
}
