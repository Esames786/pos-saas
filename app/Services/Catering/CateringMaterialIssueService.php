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
        private readonly CateringStoreRequirementService $storeRequirements,
    ) {}

    /**
     * CAT-STORE-001 — a second issue must be a top-up, not an accident.
     *
     * Required 10, issued 6, so 4 remain. Asking for 4 is a top-up and is
     * normal. Asking for 10 again is almost always the same sheet being handed
     * over twice, an hour apart, by two different people — and before this
     * nothing on any screen would have looked wrong afterwards.
     *
     * Bounded ONLY where a requirement exists to bound it:
     *
     *   - no booking referenced        unbounded. Daily prep and staff food
     *                                  leave the store against no booking, and
     *                                  always could. A blank reference is a
     *                                  complete, valid record.
     *   - material not required        unbounded. The kitchen asked for
     *                                  something nobody quoted; that is a real
     *                                  event and refusing it would only teach
     *                                  the storeman to leave the reference off.
     *   - material required            bounded by what is left.
     *
     * Over-issuing stays possible, because sometimes a deg really does need
     * more — but it becomes a deliberate act with a reason attached, not a
     * silent double.
     *
     * @param  array<int, array{product_id: int, quantity: float}>  $lines
     * @param  array<int, int>  $eventIds
     */
    private function assertWithinRemaining(array $lines, array $eventIds, int $branchId, bool $allowOverIssue): void
    {
        if ($eventIds === [] || $allowOverIssue) {
            return;
        }

        $remaining = $this->storeRequirements->remainingByMaterial($eventIds, $branchId);
        if ($remaining === []) {
            return;
        }

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];

            if (! array_key_exists($productId, $remaining)) {
                continue;
            }

            $left = round($remaining[$productId], 3);
            $asked = round((float) $line['quantity'], 3);

            if ($asked <= $left + 0.0005) {
                continue;
            }

            $name = Product::whereKey($productId)->value('name') ?? "material #{$productId}";

            throw new RuntimeException(
                "These bookings still need {$this->trim($left)} of {$name}, but the issue is for "
                ."{$this->trim($asked)}. If some has already gone out, issue only what is left; if this "
                .'really is an extra handover, confirm it deliberately and say why.'
            );
        }
    }

    /** Quantities read to a storeman, not to a database. */
    private function trim(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.') ?: '0';
    }

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

            // A release belongs to exactly one booking, but it still records that
            // reference through the same relation as everything else — so "which
            // bookings is this issue for" has one answer everywhere.
            if ($release->catering_event_id) {
                $issue->events()->sync([$release->catering_event_id]);
            }

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

    /**
     * KASHIF-CATERING-STORE-1 — the store issues what the kitchen asks for.
     *
     * The method above starts from a production release and hands over exactly
     * what it calculated. That is not how this kitchen works: a man walks to the
     * store with a sheet and asks for what he is actually cooking, which may
     * cover ten bookings, one, part of one, or tomorrow's prep with no booking at
     * all.
     *
     * So this takes the quantities as given. Bookings may be referenced, and
     * those references are notes — the stock movement is the fact, and it is
     * real whether or not anyone wrote an order number next to it.
     *
     * KASHIF-CATERING-STORE-2: MANY bookings, because one morning trip covers
     * twelve weddings. The number of references has no effect whatsoever on what
     * leaves the store: eighty kilos issued against twelve bookings is one
     * document, one set of FEFO stock-outs, one COGS posting. Twelve references
     * are twelve reasons, not twelve movements.
     *
     * Everything below the document header is shared with the release path: the
     * same InventoryService::postOutFefo, the same FEFO costing, the same COGS
     * posting. There is one stock mutator, and this does not become a second.
     *
     * @param  array<int, array{product_id: int, quantity: float}>  $lines
     * @param  array<int, int>  $eventIds  bookings this issue was made for; may be empty
     */
    public function issueDirect(
        array $lines,
        int $branchId,
        array $eventIds = [],
        ?int $releaseId = null,
        ?int $userId = null,
        ?string $note = null,
        bool $allowOverIssue = false,
    ): CateringMaterialIssue {
        $branch = Branch::find($branchId);
        if (! $branch || $branch->status !== 'active') {
            throw new RuntimeException('A store issue needs a valid active branch.');
        }

        $clean = [];
        foreach ($lines as $line) {
            $quantity = round((float) ($line['quantity'] ?? 0), 3);
            if ($quantity > 0 && ! empty($line['product_id'])) {
                $clean[] = ['product_id' => (int) $line['product_id'], 'quantity' => $quantity];
            }
        }

        if ($clean === []) {
            throw new RuntimeException('Nothing to issue — add at least one material with a quantity above zero.');
        }

        $eventIds = collect($eventIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        $this->assertWithinRemaining($clean, $eventIds->all(), $branch->id, $allowOverIssue);

        return DB::connection('tenant')->transaction(function () use ($clean, $branch, $eventIds, $releaseId, $userId, $note) {
            $issue = CateringMaterialIssue::create([
                'issue_no' => $this->numbers->nextMaterialIssueNo(),
                // All optional. A blank reference is a normal, complete record —
                // daily prep and staff food leave the store too.
                'catering_production_release_id' => $releaseId,
                // Legacy mirror of the first booking. New code reads events();
                // this keeps anything still reading the column, and a rollback of
                // the pivot migration, pointing at a real booking.
                'catering_event_id' => $eventIds->first(),
                'branch_id' => $branch->id,
                'status' => CateringMaterialIssue::STATUS_ISSUED,
                'issued_at' => now(),
                'issued_by_user_id' => $userId,
            ]);

            // Attached before the stock moves and inside the same transaction, so
            // a failed issue leaves no dangling references — and so the reasons
            // are part of the posted document rather than something editable
            // afterwards. This is an immutable stock record.
            if ($eventIds->isNotEmpty()) {
                $issue->events()->sync($eventIds->all());
            }

            $totalCost = 0.0;

            foreach ($clean as $line) {
                $product = Product::find($line['product_id']);
                $quantity = $line['quantity'];

                if (! $product || ! $product->is_stock_tracked) {
                    CateringMaterialIssueLine::create([
                        'catering_material_issue_id' => $issue->id,
                        'product_id' => $product?->id,
                        'item_name' => $product?->name ?? '(unknown)',
                        'required_qty' => $quantity,
                        'issued_qty' => 0,
                        'unit_code' => $product?->unit?->code,
                        'line_status' => CateringMaterialIssueLine::STATUS_NON_STOCK,
                        'fefo_cost_total' => 0,
                    ]);

                    continue;
                }

                $ledgers = $this->inventory->postOutFefo(
                    $branch,
                    $product,
                    null,
                    $quantity,
                    'recipe_consumption',
                    'catering_material_issue',
                    $issue->id,
                    $issue->issue_no,
                    $note ?: 'Store issue to kitchen',
                    $userId,
                    (bool) $branch->allow_negative_stock,
                );

                $lineCost = round(array_sum(array_map(fn ($ledger) => (float) $ledger->total_cost, $ledgers)), 4);
                $totalCost += $lineCost;

                CateringMaterialIssueLine::create([
                    'catering_material_issue_id' => $issue->id,
                    'product_id' => $product->id,
                    'item_name' => $product->name,
                    'required_qty' => $quantity,
                    'issued_qty' => $quantity,
                    'unit_code' => $product->unit?->code,
                    'line_status' => CateringMaterialIssueLine::STATUS_ISSUED,
                    'stock_ledger_ids' => array_map(fn ($ledger) => (int) $ledger->id, $ledgers),
                    'fefo_cost_total' => $lineCost,
                ]);
            }

            CateringMaterialIssue::whereKey($issue->id)->toBase()->update(['total_fefo_cost' => round($totalCost, 4)]);
            $issue = $issue->fresh();

            $cogsEntry = $this->journalPosting->postCateringCogs($issue, $userId);
            if ($cogsEntry) {
                $issue->forceFill(['cogs_journal_entry_id' => $cogsEntry->id])->save();
            }

            return $issue->fresh(['lines']);
        });
    }
}
