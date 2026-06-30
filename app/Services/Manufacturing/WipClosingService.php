<?php

namespace App\Services\Manufacturing;

use App\Models\Tenant\FinishedGoodReceipt;
use App\Models\Tenant\WipJob;
use App\Services\Finance\JournalService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * MFG-FIN-E Phase: WIP Job Closing — variance journal.
 *
 * When a WIP job is closed, any residual WIP balance (accumulated_cost minus
 * FG cost already transferred out) is cleared to Production Variance:
 *
 *   Residual > 0 (under-absorbed):   Dr Production Variance / Cr WIP
 *   Residual < 0 (over-absorbed):    Dr WIP / Cr Production Variance
 *
 * This zeroes out the WIP account for this job. Settings-gated, idempotent.
 * Does NOT close scrap/rejection costs — those remain unlinked for Phase F.
 */
class WipClosingService
{
    public const SOURCE_TYPE = 'manufacturing_wip_close';

    public function __construct(
        private readonly ManufacturingPostingService $posting,
        private readonly JournalService $journal,
    ) {}

    public function close(WipJob $wip, ?int $userId = null): WipJob
    {
        $settings = $this->posting->assertSettingsReady($wip->branch_id);

        if ($wip->status === 'completed' || $wip->status === 'cancelled') {
            throw new RuntimeException('WIP job is already closed/cancelled.');
        }

        if (! $settings->wip_inventory_account_id || ! $settings->production_variance_account_id) {
            throw new RuntimeException('WIP / Production Variance accounts not mapped in posting settings.');
        }

        return DB::connection('tenant')->transaction(function () use ($wip, $settings, $userId) {

            $accumulatedCost = round((float) $wip->accumulated_cost, 2);
            // Residual = cost still sitting in this WIP job that did not transfer
            // to FG. Keep this scoped to the job; branch-level sums mix unrelated
            // production runs.
            $fgCredited = FinishedGoodReceipt::query()
                ->where('wip_job_id', $wip->id)
                ->where('posting_status', 'posted')
                ->sum('total_cost');

            $residual = round($accumulatedCost - $fgCredited, 2);

            // Already balanced (no posting needed), just close the job.
            if (abs($residual) < 0.01) {
                $wip->forceFill([
                    'status'           => 'completed',
                    'completed_quantity' => max((float) $wip->completed_quantity, (float) $wip->planned_quantity),
                ])->save();
                $wip->recalculateProgress();
                $wip->save();
                return $wip->fresh();
            }

            // Post variance journal.
            if (! $this->posting->alreadyHasJournal(self::SOURCE_TYPE, $wip->id)) {
                if ($residual > 0) {
                    // Under-absorbed: cost left in WIP — move to variance expense.
                    $debitAccount  = (int) $settings->production_variance_account_id;
                    $creditAccount = (int) $settings->wip_inventory_account_id;
                } else {
                    // Over-absorbed: WIP has been over-credited — reverse variance.
                    $debitAccount  = (int) $settings->wip_inventory_account_id;
                    $creditAccount = (int) $settings->production_variance_account_id;
                }

                $amount = round(abs($residual), 2);

                $this->journal->post(
                    sourceType:  self::SOURCE_TYPE,
                    sourceId:    $wip->id,
                    sourceNo:    $wip->wip_no,
                    description: 'WIP closing variance — ' . $wip->wip_no,
                    entryDate:   now()->toDateString(),
                    lines: [
                        [
                            'account_id'  => $debitAccount,
                            'branch_id'   => $wip->branch_id,
                            'description' => 'WIP variance close ' . $wip->wip_no,
                            'debit'       => $amount,
                            'credit'      => 0,
                        ],
                        [
                            'account_id'  => $creditAccount,
                            'branch_id'   => $wip->branch_id,
                            'description' => 'WIP variance close ' . $wip->wip_no,
                            'debit'       => 0,
                            'credit'      => $amount,
                        ],
                    ],
                    userId: $userId,
                );
            }

            $wip->forceFill([
                'status'             => 'completed',
                'completed_quantity' => max((float) $wip->completed_quantity, (float) $wip->planned_quantity),
            ])->save();
            $wip->recalculateProgress();
            $wip->save();

            return $wip->fresh();
        });
    }
}
