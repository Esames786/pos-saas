<?php

namespace App\Services\Edge;

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
 */
class EdgeBaselineIssuanceService
{
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

        // A stable position hash over the authoritative snapshot (evidence for the cutover audit).
        $positionHash = hash('sha256', json_encode(EdgeOperationalBaselineService::canonicalizeItems($items)) . '|' . $branchId . '|' . $sourceRevision);

        return EdgeBaselineCutoverService::buildPackage(
            $branchId,
            $activationEpoch,
            $sourceRevision,
            $items,
            ['as_of' => now()->toIso8601String(), 'hash' => $positionHash],
        );
    }
}
