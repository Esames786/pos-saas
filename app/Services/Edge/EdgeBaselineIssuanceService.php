<?php

namespace App\Services\Edge;

use App\Models\Tenant\Branch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PRODUCTIZATION GATE 0 — the CLOUD authority that issues an operational-stock baseline package.
 *
 * The Cloud remains the official stock authority. A baseline's on-hand quantities are computed from the
 * OFFICIAL Cloud inventory (stock_balances) for the branch — never from Edge provisional quantities. This
 * runs after the prior generation's sales have been ingested (official FEFO posted), so the summed
 * stock_balances IS the authoritative post-sale position (protocol step 2). The result is an immutable,
 * integrity-hashed package the appliance downloads and accepts through its atomic cutover authority.
 *
 * Product identity is the numeric product_id / product_variant_id — Cloud-stable on Edge by bootstrap
 * preservation and the uniform envelope/ingestion/baseline contract (see EdgeSaleEnvelopeBuilder /
 * EdgeInboundSaleIngestionService). This service never reads any edge_operational_* table.
 *
 * EDGE OFFLINE-READINESS FIX (24 Nov 2026, Khatri). The appliance refuses a WHOLE package that names a product it does not
 * hold, or that has no items (EdgeBaselineClient::assertResolvable: BASELINE_UNKNOWN_PRODUCT / BASELINE_EMPTY).
 * STOCK_AUTHORITY_READY has no supervisor override, so either refusal kept a warm standby from ever being able to take over.
 * The package now:
 *  - names ONLY the products / variants the appliance holds (EdgeBootstrapService::deliveredProductIdentities = the shipped
 *    `products` / `product_variants` sections). A purchase-only item with stock (a crate, packaging) can never be sold or
 *    consumed offline, so leaving it out changes nothing the appliance can do. Positive quantities of shipped products are
 *    unchanged;
 *  - states an EMPTY official position (a branch that does not track stock) explicitly, as zero rows for the shipped
 *    products. This is the same selling position: the appliance reads a missing balance row as 0
 *    (EdgeOperationalStockService::decrement).
 * The stock WATERMARK is unchanged, and so is the position it covers. stockWatermark() is advertised on every heartbeat with
 * no extra queries, and the package carries the same value, so the accepted baseline still equals the advertised position.
 */
class EdgeBaselineIssuanceService
{
    public function __construct(private readonly EdgeBootstrapService $bootstrap)
    {
    }

    /**
     * Issue the baseline package for a branch at a config watermark, from official Cloud stock.
     * The caller (the authenticated transport endpoint) supplies branch/epoch/revision from the device binding.
     */
    public function issue(int $branchId, int $activationEpoch, string $sourceRevision): array
    {
        if ($sourceRevision === '') {
            throw new RuntimeException('BASELINE_ISSUE_REVISION: a source revision (the new watermark) is required.');
        }

        // Authoritative official on-hand per product/variant = SUM over FEFO batches in official stock_balances.
        $position = $this->officialPosition($branchId);

        // Only what the appliance can resolve: the product / variant identities its configuration carries.
        $branch = Branch::on('tenant')->find($branchId);
        $delivered = $branch !== null ? $this->bootstrap->deliveredProductIdentities($branch) : ['products' => [], 'variants' => []];
        $items = array_values(array_filter($position, fn (array $item) => isset($delivered['products'][$item['product_id']])
            && ($item['product_variant_id'] === null || isset($delivered['variants'][$item['product_variant_id']]))));
        if ($items === []) {
            // No sellable official stock for anything the appliance holds: say so explicitly (0 each), never an empty package.
            foreach (array_keys($delivered['products']) as $productId) {
                $items[] = ['product_id' => (int) $productId, 'product_variant_id' => null, 'quantity' => 0.0];
            }
        }

        // A stable position hash over the issued snapshot (evidence for the cutover audit).
        $positionHash = hash('sha256', json_encode(EdgeOperationalBaselineService::canonicalizeItems($items)) . '|' . $branchId . '|' . $sourceRevision);
        $asOf = now()->toIso8601String();

        return EdgeBaselineCutoverService::buildPackage(
            $branchId,
            $activationEpoch,
            $sourceRevision,
            $items,
            // Q — the revision-independent official-stock WATERMARK the heartbeat advertises (stockWatermark(), same position):
            // the appliance can prove its accepted baseline equals the Cloud position the Cloud last told it about.
            ['as_of' => $asOf, 'hash' => $positionHash, 'stock_watermark' => self::stockWatermarkFor($position)],
        );
    }

    /**
     * Q — WARM STANDBY FRESHNESS: the branch's official-stock watermark = a content hash of the authoritative sellable
     * position (product/variant → on-hand). Any official movement changes it; equality proves the standby is current.
     *
     * @return array{stock_watermark:string, as_of:string}
     */
    public function stockWatermark(int $branchId): array
    {
        return ['stock_watermark' => self::stockWatermarkFor($this->officialPosition($branchId)), 'as_of' => now()->toIso8601String()];
    }

    public static function stockWatermarkFor(array $items): string
    {
        return 'sw:' . substr(hash('sha256', json_encode(EdgeOperationalBaselineService::canonicalizeItems($items))), 0, 40);
    }

    /**
     * The branch's official sellable on-hand per product / variant (positive only — zero / negative official positions are
     * omitted), over ALL products: the position the heartbeat watermark covers.
     *
     * @return array<int, array{product_id:int, product_variant_id:?int, quantity:float}>
     */
    private function officialPosition(int $branchId): array
    {
        $rows = DB::connection('tenant')->table('stock_balances')
            ->where('branch_id', $branchId)
            ->selectRaw('product_id, product_variant_id, SUM(quantity_on_hand) as qty')
            ->groupBy('product_id', 'product_variant_id')
            ->get();

        $items = [];
        foreach ($rows as $r) {
            $qty = (float) $r->qty;
            if ($qty <= 0) {
                continue; // a baseline carries sellable on-hand only; zero/negative official positions are omitted
            }
            $items[] = [
                'product_id' => (int) $r->product_id,
                'product_variant_id' => $r->product_variant_id !== null ? (int) $r->product_variant_id : null,
                'quantity' => $qty,
            ];
        }

        return $items;
    }
}
