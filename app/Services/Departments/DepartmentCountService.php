<?php

namespace App\Services\Departments;

use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentCountAdjustment;
use App\Models\Tenant\DepartmentCountLine;
use App\Models\Tenant\DepartmentCountSession;
use App\Models\Tenant\DepartmentStockBalance;
use App\Models\Tenant\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DEPT-4 — end-day department count lifecycle.
 *
 * draft → submitted → approved / rejected (draft can be cancelled).
 * Approval brings CUSTODY stock to the counted quantity via
 * DepartmentInventoryService adjustments (department_adjustment_in/out).
 * Official branch stock, stock_ledgers, and GL are NEVER touched.
 */
class DepartmentCountService
{
    public function __construct(private readonly DepartmentInventoryService $inventory) {}

    public function createDraft(int $branchId, int $departmentId, string $countDate, ?string $notes = null, ?int $userId = null): DepartmentCountSession
    {
        $department = Department::where('id', $departmentId)->where('branch_id', $branchId)->first();
        if (! $department) {
            throw new RuntimeException('Department does not belong to the selected branch.');
        }

        return DB::connection('tenant')->transaction(function () use ($branchId, $departmentId, $countDate, $notes, $userId) {
            $session = DepartmentCountSession::create([
                'branch_id'     => $branchId,
                'department_id' => $departmentId,
                'count_no'      => 'DCNT-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                'count_date'    => $countDate,
                'status'        => 'draft',
                'notes'         => $notes,
                'created_by'    => $userId,
            ]);

            $this->populateExpectedLines($session);

            return $session;
        });
    }

    /**
     * One line per non-zero custody balance; counted defaults to expected
     * so an untouched line means "matches" with zero variance.
     */
    public function populateExpectedLines(DepartmentCountSession $session): void
    {
        $balances = DepartmentStockBalance::query()
            ->where('branch_id', $session->branch_id)
            ->where('department_id', $session->department_id)
            ->where('quantity_on_hand', '!=', 0)
            ->get();

        foreach ($balances as $balance) {
            DepartmentCountLine::updateOrCreate(
                ['line_key' => $session->id . '-' . $balance->product_id . '-' . ($balance->product_variant_id ?: 0)],
                [
                    'department_count_session_id' => $session->id,
                    'product_id'                  => $balance->product_id,
                    'product_variant_id'          => $balance->product_variant_id,
                    'expected_qty'                => $balance->quantity_on_hand,
                    'counted_qty'                 => $balance->quantity_on_hand,
                    'variance_qty'                => 0,
                    'average_cost'                => $balance->average_cost,
                    'variance_value'              => 0,
                ]
            );
        }
    }

    /**
     * Manually add a zero-custody product (counted something not on the books).
     */
    public function addLine(DepartmentCountSession $session, int $productId, ?int $variantId = null): DepartmentCountLine
    {
        $this->assertEditable($session);

        $product = Product::findOrFail($productId);
        $variant = $this->inventory->resolveVariant($product, $variantId
            ? \App\Models\Tenant\ProductVariant::where('product_id', $product->id)->find($variantId)
            : null);

        $expected = $this->inventory->departmentOnHand($session->department_id, $product->id, $variant?->id);

        return DepartmentCountLine::updateOrCreate(
            ['line_key' => $session->id . '-' . $product->id . '-' . ($variant?->id ?: 0)],
            [
                'department_count_session_id' => $session->id,
                'product_id'                  => $product->id,
                'product_variant_id'          => $variant?->id,
                'expected_qty'                => $expected,
                'counted_qty'                 => $expected,
                'variance_qty'                => 0,
                'average_cost'                => 0,
                'variance_value'              => 0,
            ]
        );
    }

    /**
     * Apply counted quantities + reasons from the edit form.
     *
     * @param array<int, array{counted_qty?: mixed, reason_code?: mixed, notes?: mixed}> $lines keyed by line id
     */
    public function updateCounts(DepartmentCountSession $session, array $lines): void
    {
        $this->assertEditable($session);

        DB::connection('tenant')->transaction(function () use ($session, $lines) {
            foreach ($session->lines as $line) {
                if (! isset($lines[$line->id])) {
                    continue;
                }
                $input = $lines[$line->id];

                $counted = (float) ($input['counted_qty'] ?? $line->counted_qty);
                if ($counted < 0) {
                    throw new RuntimeException('Counted quantity cannot be negative.');
                }

                $line->counted_qty = $counted;
                $line->reason_code = $input['reason_code'] ?? null ?: null;
                $line->notes       = $input['notes'] ?? null ?: null;
                $line->recalculate();
                $line->save();
            }
        });
    }

    public function submit(DepartmentCountSession $session, ?int $userId = null): void
    {
        if (! $session->isDraft()) {
            throw new RuntimeException('Only draft counts can be submitted.');
        }

        $session->load('lines');
        if ($session->lines->isEmpty()) {
            throw new RuntimeException('Cannot submit a count without lines.');
        }

        foreach ($session->lines as $line) {
            if ((float) $line->counted_qty < 0) {
                throw new RuntimeException('Counted quantity cannot be negative.');
            }
            if (abs((float) $line->variance_qty) > 0.0005 && empty($line->reason_code)) {
                throw new RuntimeException(
                    'A variance reason is required for ' . ($line->product?->name ?? ('#' . $line->product_id)) . '.'
                );
            }
        }

        $session->update([
            'status'       => 'submitted',
            'submitted_by' => $userId,
            'submitted_at' => now(),
        ]);
    }

    /**
     * Approve: custody becomes the counted quantity. Locks the header row so
     * double approval is impossible; blocks if custody moved after counting.
     */
    public function approve(DepartmentCountSession $session, ?int $userId = null): DepartmentCountSession
    {
        return DB::connection('tenant')->transaction(function () use ($session, $userId) {
            /** @var DepartmentCountSession $locked */
            $locked = DepartmentCountSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isSubmitted()) {
                throw new RuntimeException('Only submitted counts can be approved (current status: ' . $locked->status . ').');
            }

            $locked->load('lines.product');

            // Stale-stock guard: if custody moved since the count was taken,
            // the variance math is no longer meaningful — force a recount.
            foreach ($locked->lines as $line) {
                $current = $this->inventory->departmentOnHand($locked->department_id, $line->product_id, $line->product_variant_id);
                if (abs($current - (float) $line->expected_qty) > 0.0005) {
                    throw new RuntimeException(
                        'Department stock changed after count for ' . ($line->product?->name ?? ('#' . $line->product_id))
                        . ' (expected ' . number_format((float) $line->expected_qty, 3) . ', now ' . number_format($current, 3)
                        . ') — refresh/recount required.'
                    );
                }
            }

            foreach ($locked->lines as $line) {
                $variance = (float) $line->variance_qty;
                if (abs($variance) <= 0.0005) {
                    continue; // counted matches expected — nothing to adjust
                }

                $direction = $variance > 0 ? 'in' : 'out';
                $quantity  = abs($variance);

                $ledger = $direction === 'in'
                    ? $this->inventory->postIn(
                        $locked->branch_id, $locked->department_id, $line->product_id, $line->product_variant_id, null,
                        $quantity, (float) $line->average_cost, 'department_adjustment_in',
                        'department_count_session', $locked->id, $locked->count_no,
                        'Count reconciliation' . ($line->reason_code ? ' (' . $line->reason_code . ')' : ''), $userId
                    )
                    : $this->inventory->postOut(
                        $locked->branch_id, $locked->department_id, $line->product_id, $line->product_variant_id, null,
                        $quantity, null, 'department_adjustment_out',
                        'department_count_session', $locked->id, $locked->count_no,
                        'Count reconciliation' . ($line->reason_code ? ' (' . $line->reason_code . ')' : ''), $userId
                    );

                DepartmentCountAdjustment::create([
                    'department_count_session_id' => $locked->id,
                    'department_count_line_id'    => $line->id,
                    'department_stock_ledger_id'  => $ledger->id,
                    'direction'                   => $direction,
                    'quantity'                    => $quantity,
                    'unit_cost'                   => $ledger->unit_cost,
                ]);
            }

            $locked->update([
                'status'      => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $locked->fresh(['lines', 'adjustments']);
        });
    }

    public function reject(DepartmentCountSession $session, string $reason, ?int $userId = null): void
    {
        if (! $session->isSubmitted()) {
            throw new RuntimeException('Only submitted counts can be rejected.');
        }

        $session->update([
            'status'           => 'rejected',
            'rejection_reason' => $reason,
            'rejected_by'      => $userId,
            'rejected_at'      => now(),
        ]);
    }

    public function cancel(DepartmentCountSession $session, ?int $userId = null): void
    {
        if (! in_array($session->status, ['draft', 'submitted'], true)) {
            throw new RuntimeException('Only draft or submitted counts can be cancelled.');
        }

        $session->update([
            'status'       => 'cancelled',
            'cancelled_by' => $userId,
            'cancelled_at' => now(),
        ]);
    }

    private function assertEditable(DepartmentCountSession $session): void
    {
        if (! $session->isDraft()) {
            throw new RuntimeException('Only draft counts can be edited.');
        }
    }
}
