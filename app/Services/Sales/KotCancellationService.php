<?php

namespace App\Services\Sales;

use App\Models\Tenant\Branch;
use App\Models\Tenant\ManagerApproval;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\SalesOrderLineCancellation;
use App\Models\Tenant\VoidReason;
use App\Models\Tenant\User;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class KotCancellationService
{
    public function __construct(
        private readonly ManagerApprovalService $approvalService,
        private readonly PrintJobService $printJobService,
    ) {}

    public function cancelHeldOrder(
        SalesOrder $sale,
        int $reasonId,
        ?int $managerApprovalId,
        int $requestingUserId,
        ?string $terminalId = null,
    ): array {
        $this->assertCancellationPermission($requestingUserId);

        return DB::connection('tenant')->transaction(function () use ($sale, $reasonId, $managerApprovalId, $requestingUserId, $terminalId) {
            $sale = SalesOrder::with(['branch', 'lines'])->lockForUpdate()->findOrFail($sale->id);
            if ($sale->status !== 'held') {
                throw ValidationException::withMessages(['sale' => 'Only held sales can be cancelled.']);
            }

            $reason = $this->activeReason($reasonId);
            $quantities = $sale->lines
                ->filter(fn (SalesOrderLine $line) => (float) $line->kot_sent_quantity > 0)
                ->mapWithKeys(fn (SalesOrderLine $line) => [(string) $line->id => (float) $line->kot_sent_quantity])
                ->all();

            $approval = null;
            if ($quantities && $this->requiresManager($sale->branch)) {
                $approval = $this->consumeApproval(
                    $managerApprovalId,
                    'cancel_held_order',
                    $requestingUserId,
                    ['sales_order_id' => $sale->id]
                );
            }

            // RECALL-REPRINT-TERMINAL-2: the CANCELLING counter's terminal, falling back to the one
            // that created the order. Same rule the rest of the print path already follows.
            $queued = $this->printJobService->queueCancellationKot($sale, $quantities, $terminalId ?: $sale->terminal_id);
            foreach ($sale->lines as $line) {
                $quantity = (float) ($quantities[(string) $line->id] ?? 0);
                if ($quantity <= 0) {
                    continue;
                }
                $this->record($sale, $line, $quantity, $reason, $approval, $queued['batch']?->id, $requestingUserId, 'order');
            }

            $reminderJobs = $this->queueCorrectionReminders($sale, $queued['batch'], true, $terminalId ?: $sale->terminal_id);

            $sale->update(['status' => 'cancelled']);

            return [
                'sale' => $sale,
                'jobs' => $queued['jobs'],
                'reminder_jobs' => $reminderJobs,
                'batch' => $queued['batch'],
            ];
        });
    }

    /**
     * @param array<int, array{line_id:int,quantity:float,reason_id:int,manager_approval_id:?int}> $cancellations
     */
    public function recordLineCancellations(SalesOrder $sale, array $cancellations, int $requestingUserId, ?string $terminalId = null): array
    {
        if (!$cancellations) {
            return ['jobs' => [], 'batch' => null];
        }

        $this->assertCancellationPermission($requestingUserId);

        return DB::connection('tenant')->transaction(function () use ($sale, $cancellations, $requestingUserId, $terminalId) {
            $sale = SalesOrder::with(['branch', 'lines'])->lockForUpdate()->findOrFail($sale->id);
            if ($sale->status !== 'held') {
                throw ValidationException::withMessages(['sale' => 'Only held sales can be edited.']);
            }

            $quantities = [];
            $resolved = [];
            foreach ($cancellations as $request) {
                $line = $sale->lines->firstWhere('id', (int) $request['line_id']);
                $quantity = round((float) $request['quantity'], 6);
                if (!$line || $quantity <= 0 || $quantity > (float) $line->kot_sent_quantity + 0.000001) {
                    throw ValidationException::withMessages(['lines' => 'Cancelled quantity exceeds the quantity sent to kitchen.']);
                }

                $reason = $this->activeReason((int) $request['reason_id']);
                $quantities[(string) $line->id] = $quantity;
                $resolved[] = compact('line', 'quantity', 'reason') + [
                    'manager_approval_id' => $request['manager_approval_id'] ?? null,
                ];
            }

            if ($this->requiresManager($sale->branch, 'line')) {
                if (count($resolved) === 1) {
                    $item = $resolved[0];
                    $approval = $this->consumeApproval(
                        $item['manager_approval_id'],
                        'void_kot_item',
                        $requestingUserId,
                        [
                            'sales_order_id' => $sale->id,
                            'sales_order_line_id' => $item['line']->id,
                            'quantity' => $item['quantity'],
                        ]
                    );
                    $resolved[0]['approval'] = $approval;
                } else {
                    $approvalIds = collect($resolved)->pluck('manager_approval_id')->filter()->unique()->values();
                    if ($approvalIds->count() !== 1) {
                        throw ValidationException::withMessages([
                            'manager_approval_id' => 'One manager approval is required for this grouped cancellation.',
                        ]);
                    }
                    $approvalPayload = collect($resolved)
                        ->map(fn ($item) => [
                            'line_id' => (int) $item['line']->id,
                            'quantity' => (float) $item['quantity'],
                        ])
                        ->sortBy('line_id')
                        ->values()
                        ->all();
                    $approval = $this->consumeApproval(
                        (int) $approvalIds->first(),
                        'void_kot_items',
                        $requestingUserId,
                        [
                            'sales_order_id' => $sale->id,
                            'cancellations' => $approvalPayload,
                        ]
                    );
                    foreach ($resolved as &$item) {
                        $item['approval'] = $approval;
                    }
                    unset($item);
                }
            } else {
                foreach ($resolved as &$item) {
                    $item['approval'] = null;
                }
                unset($item);
            }

            $queued = $this->printJobService->queueCancellationKot($sale, $quantities, $terminalId ?: $sale->terminal_id);
            foreach ($resolved as $item) {
                $this->record(
                    $sale,
                    $item['line'],
                    $item['quantity'],
                    $item['reason'],
                    $item['approval'],
                    $queued['batch']?->id,
                    $requestingUserId,
                    'line'
                );
            }

            $queued['reminder_jobs'] = [];

            return $queued;
        });
    }

    public function queueCorrectionReminders(SalesOrder $sale, $batch, bool $wholeOrder = false, ?string $terminalId = null): array
    {
        if (!$batch) {
            return [];
        }

        try {
            return $this->printJobService->queueCancellationReminders($sale, $batch, $wholeOrder, $terminalId);
        } catch (\Throwable $exception) {
            Log::warning('Cancellation Reminder failed after Cancel KOT was queued.', [
                'sales_order_id' => $sale->id,
                'kot_batch_id' => $batch->id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function activeReason(int $reasonId): VoidReason
    {
        $reason = VoidReason::where('is_active', true)->find($reasonId);
        if (!$reason) {
            throw ValidationException::withMessages(['reason_id' => 'Select an active cancellation reason.']);
        }

        return $reason;
    }

    /**
     * Cancelling a WHOLE order and reducing ONE line's quantity are separately configurable —
     * a shop may want a manager for a full cancellation but not for shaving a quantity (or the
     * reverse). Unset line setting falls back to the order setting (pre-split behaviour).
     */
    private function requiresManager(Branch $branch, string $scope = 'order'): bool
    {
        return $this->cancellationMode($branch, $scope) !== Branch::KOT_CANCELLATION_AUTO_APPROVE;
    }

    private function cancellationMode(Branch $branch, string $scope = 'order'): string
    {
        return $scope === 'line'
            ? ($branch->held_kot_line_cancellation_approval_mode ?: $branch->held_kot_cancellation_approval_mode)
            : $branch->held_kot_cancellation_approval_mode;
    }

    private function assertCancellationPermission(int $userId): void
    {
        $user = User::find($userId);
        if (!$user || !$user->can('tenant.pos.void-kot-item')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to cancel items already sent to kitchen.',
            ]);
        }
    }

    private function consumeApproval(?int $approvalId, string $action, int $requestingUserId, array $payload): ManagerApproval
    {
        if (!$approvalId) {
            throw ValidationException::withMessages(['manager_approval_id' => 'Manager approval is required for this branch.']);
        }

        $approval = ManagerApproval::whereKey($approvalId)->lockForUpdate()->first();
        if (!$approval) {
            throw ValidationException::withMessages(['manager_approval_id' => 'Manager approval was not found.']);
        }

        try {
            return $this->approvalService->consume($approval, $action, $requestingUserId, $payload);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['manager_approval_id' => $exception->getMessage()]);
        }
    }

    private function record(
        SalesOrder $sale,
        SalesOrderLine $line,
        float $quantity,
        VoidReason $reason,
        ?ManagerApproval $approval,
        ?int $batchId,
        int $requestingUserId,
        string $scope,
    ): void {
        $approvalMode = $this->cancellationMode($sale->branch, $scope);

        SalesOrderLineCancellation::create([
            'sales_order_id' => $sale->id,
            'sales_order_line_id' => $line->id,
            // EDGE-IDENTITY: immutable canonical-reference snapshots so this cancellation stays self-contained
            // and cross-system resolvable after the source line is churned (Add Round) or across divergent ids.
            'source_line_uuid' => $line->line_uuid,
            // canonical event_uuid form of THIS row's kot_batch_id (the cancel KOT batch the workflow creates,
            // NOT necessarily the original sending batch) — makes the numeric FK cross-system resolvable.
            'referenced_kot_event_uuid' => $batchId ? \App\Models\Tenant\KotBatch::find($batchId)?->event_uuid : null,
            'void_reason_id' => $reason->id,
            'manager_approval_id' => $approval?->id,
            'kot_batch_id' => $batchId,
            'requested_by_user_id' => $requestingUserId,
            'approved_by_user_id' => $approval?->approved_by_user_id,
            'approval_mode' => $approvalMode,
            'product_name' => $line->product_name,
            'variant_name' => $line->variant_name,
            'quantity' => $quantity,
            'policy_snapshot' => [
                'branch_id' => $sale->branch_id,
                'scope' => $scope,
                'approval_mode' => $approvalMode,
                'branch_updated_at' => $sale->branch->updated_at?->toIso8601String(),
            ],
            'cancelled_at' => now(),
            // Anchor the void to the order's business day, so a cancellation punched after midnight
            // on a still-open shift reports on the same day the order (and its sale) belongs to.
            'business_date' => $sale->business_date,
        ]);
    }
}
