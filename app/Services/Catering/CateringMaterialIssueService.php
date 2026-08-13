<?php

namespace App\Services\Catering;

use App\Models\Tenant\Branch;
use App\Models\Tenant\CateringMaterialIssue;
use App\Models\Tenant\CateringMaterialIssueLine;
use App\Models\Tenant\CateringProductionRelease;
use App\Models\Tenant\Product;
use App\Services\Finance\JournalPostingService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CATERING-GO-LIVE-READINESS-1 (§7/§8): the explicit "Issue Materials" action.
 *
 * The production release stays a pure immutable PLAN — printing it never moves
 * stock. THIS service is the only bridge from plan to inventory, and the only
 * mutation authority it uses is InventoryService::postOutFefo (official FEFO,
 * split-brain fenced, allow-negative per branch policy). Stock tables are
 * never written directly.
 *
 * One immutable CateringMaterialIssue per release (unique key): an exact
 * retry returns the existing document — quantities are never issued twice.
 * COGS posts at ACTUAL FEFO cost via the JournalPostingService translator
 * (Dr 5200 / Cr 1400); the Material Rate Book (quoting cost) plays no part.
 */
class CateringMaterialIssueService
{
    public function __construct(
        private readonly CateringNumberService $numbers,
        private readonly InventoryService $inventory,
        private readonly JournalPostingService $journalPosting,
    ) {}

    public function issue(CateringProductionRelease $release, ?int $branchId = null, ?int $userId = null): CateringMaterialIssue
    {
        if ($release->status !== CateringProductionRelease::STATUS_RELEASED) {
            throw new RuntimeException("Release {$release->release_no} is {$release->status}; only a released document can issue materials.");
        }

        // Idempotent retry: the unique release constraint means one issue, ever.
        if ($existing = CateringMaterialIssue::where('catering_production_release_id', $release->id)->first()) {
            return $existing;
        }

        $branchId = $branchId ?? $release->event?->branch_id;
        if (! $branchId) {
            throw new RuntimeException('Material issue needs an explicit branch — the event has none set.');
        }
        $branch = Branch::find($branchId);
        if (! $branch || $branch->status !== 'active') {
            throw new RuntimeException('Material issue needs a valid active branch.');
        }

        $requirements = $release->requirements_snapshot['requirements'] ?? [];
        if ($requirements === []) {
            throw new RuntimeException("Release {$release->release_no} has no consolidated requirements to issue.");
        }

        return DB::connection('tenant')->transaction(function () use ($release, $branch, $requirements, $userId) {
            $issue = CateringMaterialIssue::create([
                'issue_no' => $this->numbers->nextMaterialIssueNo(),
                'catering_production_release_id' => $release->id,
                'catering_event_id' => $release->catering_event_id,
                'branch_id' => $branch->id,
                'status' => CateringMaterialIssue::STATUS_ISSUED,
                'issued_at' => now(),
                'issued_by_user_id' => $userId,
            ]);

            $totalCost = 0.0;
            foreach ($requirements as $requirement) {
                $product = ! empty($requirement['product_id']) ? Product::find($requirement['product_id']) : null;
                $quantity = round((float) ($requirement['required_qty'] ?? 0), 3);

                if (! $product || ! $product->is_stock_tracked || $quantity <= 0) {
                    // Service / non-stock materials never fake a stock movement.
                    CateringMaterialIssueLine::create([
                        'catering_material_issue_id' => $issue->id,
                        'product_id' => $product?->id,
                        'item_name' => $requirement['name'] ?? ($product?->name ?? '(unknown)'),
                        'required_qty' => $quantity,
                        'issued_qty' => 0,
                        'unit_code' => $requirement['unit_code'] ?? null,
                        'line_status' => CateringMaterialIssueLine::STATUS_NON_STOCK,
                        'fefo_cost_total' => 0,
                    ]);

                    continue;
                }

                // The ONE approved stock mutator — official FEFO, fenced, branch policy.
                $ledgers = $this->inventory->postOutFefo(
                    $branch,
                    $product,
                    null,
                    $quantity,
                    'recipe_consumption',
                    'catering_material_issue',
                    $issue->id,
                    $issue->issue_no,
                    'Catering production '.$release->release_no,
                    $userId,
                    (bool) $branch->allow_negative_stock,
                );

                $lineCost = round(array_sum(array_map(fn ($ledger) => (float) $ledger->total_cost, $ledgers)), 4);
                $totalCost += $lineCost;

                CateringMaterialIssueLine::create([
                    'catering_material_issue_id' => $issue->id,
                    'product_id' => $product->id,
                    'item_name' => $requirement['name'] ?? $product->name,
                    'required_qty' => $quantity,
                    'issued_qty' => $quantity,
                    'unit_code' => $requirement['unit_code'] ?? $product->unit?->code,
                    'line_status' => CateringMaterialIssueLine::STATUS_ISSUED,
                    'stock_ledger_ids' => array_map(fn ($ledger) => (int) $ledger->id, $ledgers),
                    'fefo_cost_total' => $lineCost,
                ]);
            }

            // Freeze the actual FEFO total on the immutable document (still inside create-window:
            // the guard permits nothing, so write via query builder before any further reads).
            CateringMaterialIssue::whereKey($issue->id)->toBase()->update(['total_fefo_cost' => round($totalCost, 4)]);
            $issue = $issue->fresh();

            // §8 COGS at actual FEFO cost — throws on failure, rolling back the whole issue.
            $cogsEntry = $this->journalPosting->postCateringCogs($issue, $userId);
            if ($cogsEntry) {
                $issue->forceFill(['cogs_journal_entry_id' => $cogsEntry->id])->save();
            }

            return $issue->fresh(['lines']);
        });
    }
}
