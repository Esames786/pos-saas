<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ManagerApproval;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\Shift;
use App\Models\Tenant\User;
use App\Services\Sales\ManagerApprovalService;
use App\Services\Sales\SalesReturnService;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * OFFLINE EDGE — F1: the appliance's SALES-RETURN authority (post-settlement void = sales return).
 *
 * Same functional contract as the Online SalesReturnController + SalesReturnService — the arithmetic IS the canonical
 * one (SalesReturnService::computeReturn) — with offline-correct authority:
 *   - sales it may return: local paid sales it made, and returnable Cloud sales from the warm cache (shadows) while
 *     the cache is provably fresh (fail closed with a business message otherwise; never a synchronous Cloud call);
 *   - refunds: CASH only offline, OUT of the till that physically pays (the original sale's local open shift as Online,
 *     else the current terminal's open shift); card / bank / provider refunds need the Online POS (ONLINE_REQUIRED);
 *   - manager approval exactly as Online when the branch requires it (same action_type, same payload binding, same
 *     single-use/expiry/requester semantics), verified locally against provisioned credentials;
 *   - the local document (sales_returns/lines, returned_quantity), the LOCAL_OPERATIONAL stock return and the
 *     immutable return EVENT (outbox) are ONE transaction; the Cloud posts the OFFICIAL return exactly once at ingestion.
 */
class EdgeLocalReturnService
{
    private const RETURNABLE_STATUSES = ['paid', 'partially_returned', EdgeReturnableSaleCacheService::SHADOW_STATUS];

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly SalesReturnService $returns,
        private readonly EdgeReturnableSaleCacheService $cache,
        private readonly EdgeOperationalStockService $opStock,
        private readonly EdgeSyncOutboxService $outbox,
        private readonly EdgeReturnEnvelopeBuilder $envelopes,
        private readonly ManagerApprovalService $approvals,
        private readonly EdgeAuthorityService $authority,
    ) {
    }

    /** Sales the till may return, by number / customer (local + mirrored Cloud). */
    public function search(string $q, int $limit = 20): array
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $q = trim($q);
        $rows = SalesOrder::on('tenant')->where('branch_id', $branchId)->whereIn('status', self::RETURNABLE_STATUSES)
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('sale_no', 'like', "%{$q}%")->orWhere('customer_name', 'like', "%{$q}%")->orWhere('customer_phone', 'like', "%{$q}%");
            }))
            ->orderByDesc('id')->limit($limit)->get();

        return $rows->map(fn (SalesOrder $s) => [
            'id' => (int) $s->id, 'sale_no' => (string) $s->sale_no, 'business_date' => self::dateString($s->business_date),
            'order_type' => (string) $s->order_type, 'grand_total' => (float) $s->grand_total, 'customer_name' => $s->customer_name,
            'origin' => $this->cache->isShadow($s) ? 'online' : 'local', 'status' => $this->cache->isShadow($s) ? 'paid' : (string) $s->status,
        ])->all();
    }

    /** The return screen's data for one sale — what Online's create() shows, plus the offline facts. */
    public function returnable(int $saleId, User $user): array
    {
        $meta = $this->context->requireCurrent();
        $sale = $this->loadSale($saleId, (int) $meta->branch_id, lock: false);
        $branch = Branch::on('tenant')->find((int) $meta->branch_id);
        $shadow = $this->cache->isShadow($sale);
        $freshness = $shadow ? $this->cache->freshness() : ['ok' => true, 'reasons' => [], 'watermark' => null, 'as_of' => null];
        $lines = [];
        foreach ($sale->lines as $line) {
            if (in_array($line->line_kind, ['component', 'modifier'], true)) {
                continue;
            }
            $returnable = max((float) $line->quantity - (float) $line->returned_quantity, 0);
            $allocation = $this->returns->originalLineAllocation($sale, $line);
            $remainingDiscount = max((float) $allocation['discount'] - (float) $line->returnLines->sum('discount_amount'), 0);
            $remainingTax = max((float) $allocation['tax'] - (float) $line->returnLines->sum('tax_amount'), 0);
            $unit = $line->product?->unit;
            $unitType = $unit?->unit_type ?? 'quantity';
            $lines[] = [
                'sales_order_line_id' => (int) $line->id,
                'product_id' => (int) $line->product_id,
                'product_variant_id' => $line->product_variant_id ? (int) $line->product_variant_id : null,
                'name' => (string) $line->product_name,
                'line_kind' => (string) ($line->line_kind ?? 'standard'),
                'quantity' => (float) $line->quantity,
                'returned_quantity' => (float) $line->returned_quantity,
                'returnable' => $returnable,
                'unit_code' => $line->unit_code ?? $unit?->code,
                'unit_type' => $unitType,
                'qty_step' => $unitType !== 'quantity' ? 0.001 : 1,   // RETURN-UX: whole units step 1; weight/volume/length 0.001
                'unit_price' => (float) $line->unit_price,
                'discount_per_unit' => $returnable > 0 ? $remainingDiscount / $returnable : 0,
                'tax_per_unit' => $returnable > 0 ? $remainingTax / $returnable : 0,
            ];
        }
        $primary = $sale->payments->sortByDesc('amount')->first();
        $type = (string) ($primary?->method?->method_type ?? '');
        $defaultRefund = match ($type) { 'cash' => 'cash', 'card' => 'card', 'bank_transfer' => 'bank_transfer', '' => null, default => 'other' };
        $outstandingDelivery = max(round((float) $sale->delivery_charge_amount - (float) $sale->returns()->whereIn('status', ['posted', 'cloud_mirror'])->sum('delivery_charge_amount'), 2), 0);
        $offlineMethods = (array) config('edge.returns.offline_refund_methods', ['cash']);

        return [
            'sale' => ['id' => (int) $sale->id, 'sale_no' => (string) $sale->sale_no, 'business_date' => self::dateString($sale->business_date), 'order_type' => (string) $sale->order_type, 'grand_total' => (float) $sale->grand_total, 'customer_name' => $sale->customer_name, 'origin' => $shadow ? 'online' : 'local'],
            'lines' => $lines,
            'outstanding_delivery' => $outstandingDelivery,
            'default_refund_method' => $defaultRefund,
            'offline_refund_methods' => $offlineMethods,
            'online_required_refund' => $defaultRefund !== null && ! in_array($defaultRefund, $offlineMethods, true),
            'needs_manager_approval' => $this->needsManagerApproval($branch),
            'fresh' => (bool) $freshness['ok'],
            'freshness_message' => $freshness['ok'] ? null : 'Return information for Online sales is not current on this branch server — complete this return on the Online POS or ask a supervisor.',
            'can_return' => (bool) $freshness['ok'] && collect($lines)->sum('returnable') > 0,
        ];
    }

    /**
     * Post a return. $lines = [['sales_order_line_id' => int, 'quantity' => float], ...]. Returns the local document view.
     *
     * @throws ValidationException with business messages; RuntimeException for authority failures.
     */
    public function processReturn(int $saleId, array $lines, ?string $reason, string $refundMethod, ?float $refundAmount, User $user, int $terminalId, ?int $approvalId = null): array
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Offline returns run on the Branch Server.');
        }
        $this->authority->assertLocalMutationAllowed();
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $branch = Branch::on('tenant')->findOrFail($branchId);
        if (! \App\Support\EdgeUserAuthz::mayOperateBranch($user, $branchId)) {
            throw new RuntimeException('This user is not authorized on this branch.');
        }
        if (collect($lines)->sum(fn ($l) => (float) ($l['quantity'] ?? 0)) <= 0) {
            throw ValidationException::withMessages(['lines' => 'Enter a return quantity for at least one item.']);
        }
        // ORIGINAL TENDER POLICY: only what the local till can physically give back.
        $offlineMethods = (array) config('edge.returns.offline_refund_methods', ['cash']);
        if (! in_array($refundMethod, ['cash', 'bank_transfer', 'card', 'other'], true)) {
            throw ValidationException::withMessages(['refund_method' => 'Select how the refund is paid.']);
        }
        if (! in_array($refundMethod, $offlineMethods, true)) {
            throw ValidationException::withMessages(['refund_method' => 'A ' . str_replace('_', ' ', $refundMethod) . ' refund needs the Online POS (the provider/bank must authorise it) — offline the till can only refund cash.']);
        }
        $needsManager = $this->needsManagerApproval($branch);
        if ($needsManager) {
            if ($refundAmount === null) {
                throw ValidationException::withMessages(['refund_amount' => 'Enter the refund amount — a manager-approved return is approved for a specific figure.']);
            }
            if (! $approvalId) {
                throw ValidationException::withMessages(['manager_approval_id' => 'Manager approval is required to post a return at this branch.']);
            }
        }

        return DB::connection('tenant')->transaction(function () use ($saleId, $branchId, $branch, $lines, $reason, $refundMethod, $refundAmount, $user, $terminalId, $approvalId, $needsManager, $meta) {
            $sale = $this->loadSale($saleId, $branchId, lock: true);
            $shadow = $this->cache->isShadow($sale);
            $freshness = ['watermark' => null, 'as_of' => null];
            if ($shadow) {
                $f = $this->cache->freshness();
                if (! $f['ok']) {
                    throw ValidationException::withMessages(['sale' => 'Return information for Online sales is not current on this branch server — complete this return on the Online POS or ask a supervisor. (' . implode('; ', $f['reasons']) . ')']);
                }
                $freshness = ['watermark' => $f['watermark'], 'as_of' => $f['as_of']];
            }

            // THE arithmetic is the canonical one.
            try {
                $computed = $this->returns->computeReturn($sale, $lines);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['lines' => $e->getMessage()]);
            }
            $grandTotal = (float) $computed['grand_total'];
            if ($refundAmount !== null && abs($refundAmount - $grandTotal) > 0.01) {
                throw ValidationException::withMessages(['refund_amount' => 'Refund amount must match the calculated refund of ' . number_format($grandTotal, 2) . '.']);
            }
            // Manager approval, bound exactly as Online (sale, branch, method, amount) — single use, expiry, requester.
            $approvalAudit = null;
            if ($needsManager) {
                $approval = ManagerApproval::on('tenant')->find($approvalId);
                if (! $approval) {
                    throw ValidationException::withMessages(['manager_approval_id' => 'Manager approval was not found.']);
                }
                try {
                    $this->approvals->consume($approval, 'sales_return', (int) $user->id, [
                        'sales_order_id' => (int) $sale->id, 'branch_id' => $branchId, 'refund_method' => $refundMethod, 'refund_amount' => round((float) $refundAmount, 2),
                    ]);
                } catch (RuntimeException $e) {
                    throw ValidationException::withMessages(['manager_approval_id' => $e->getMessage()]);
                }
                $approval->refresh();
                $approvalAudit = [
                    'approval_no' => (string) $approval->approval_no, 'approval_uuid' => $approval->approval_uuid ?? null,
                    'approved_by_user_id' => (int) $approval->approved_by_user_id, 'approved_at' => optional($approval->approved_at)->toIso8601String(),
                    'consumed_at' => optional($approval->consumed_at)->toIso8601String(), 'requested_by_user_id' => (int) $approval->requested_by_user_id,
                    'binding' => ['sales_order_id' => (int) $sale->id, 'branch_id' => $branchId, 'refund_method' => $refundMethod, 'refund_amount' => round($grandTotal, 2)],
                ];
            }

            // The till that physically pays: the sale's own local open shift (Online rule) else this terminal's open shift.
            $shift = null;
            if ($refundMethod === 'cash') {
                $shift = $sale->shift_id ? Shift::on('tenant')->where('id', $sale->shift_id)->where('status', 'open')->lockForUpdate()->first() : null;
                $shift = $shift ?: Shift::on('tenant')->where('branch_id', $branchId)->where('terminal_id', $terminalId)->where('status', 'open')->lockForUpdate()->first();
                if (! $shift) {
                    throw ValidationException::withMessages(['shift' => 'Open a shift on this terminal before refunding cash — the refund leaves this till.']);
                }
            }

            $returnUuid = (string) Str::ulid();
            $return = SalesReturn::on('tenant')->create([
                'return_no' => 'SR-' . $branchId . '-' . $terminalId . '-' . $returnUuid,
                'sales_order_id' => $sale->id,
                'branch_id' => $branchId,
                'return_date' => now(),
                'business_date' => $sale->business_date,                 // Online: the ORDER's business day
                'subtotal' => $computed['subtotal'], 'discount_amount' => $computed['discount'], 'tax_amount' => $computed['tax'],
                'delivery_charge_amount' => $computed['delivery_refund'], 'grand_total' => $grandTotal,
                'refund_method' => $refundMethod, 'refund_amount' => $grandTotal,
                'status' => 'posted', 'created_by_user_id' => $user->id, 'reason' => $reason,
            ]);
            // Appliance-only identity columns (not mass-assignable on the canonical model): the return EVENT this document is.
            $return->forceFill(['edge_return_uuid' => $returnUuid, 'edge_origin' => 'local'])->save();

            $cloudLineIds = $shadow ? $this->cache->cloudLineIds((int) $sale->id) : [];
            $envelopeLines = [];
            foreach ($computed['lines'] as $item) {
                /** @var SalesOrderLine $orderLine */
                $orderLine = $item['order_line'];
                $qty = (float) $item['quantity'];
                $originalQty = (float) $orderLine->quantity;
                $returnLine = $return->lines()->create([
                    'sales_order_line_id' => $orderLine->id, 'product_id' => $orderLine->product_id, 'product_variant_id' => $orderLine->product_variant_id,
                    'quantity' => $qty, 'unit_price' => $orderLine->unit_price, 'discount_amount' => $item['discount'], 'tax_amount' => $item['tax'], 'line_total' => $item['line_total'],
                ]);
                $orderLine->increment('returned_quantity', $qty);
                $returnLineUuid = (string) Str::ulid();
                // LOCAL_OPERATIONAL_RETURN: stock-tracked items (combo components in proportion) become sellable again.
                if ($orderLine->line_kind === 'combo_header' && $originalQty > 0) {
                    foreach ($sale->lines->where('parent_sales_order_line_id', $orderLine->id) as $child) {
                        $childQty = round(((float) $child->quantity / $originalQty) * $qty, 6);
                        $child->increment('returned_quantity', $childQty);
                        if ($child->product) {
                            $this->opStock->returnIn($returnUuid, $returnLineUuid . ':' . $child->id, $child->product, $child->variant, $childQty);
                        }
                    }
                } elseif ($orderLine->product) {
                    $this->opStock->returnIn($returnUuid, $returnLineUuid, $orderLine->product, $orderLine->variant, $qty);
                }
                $envelopeLines[] = [
                    'return_line_uuid' => $returnLineUuid,
                    'cloud_sales_order_line_id' => $cloudLineIds[(int) $orderLine->id] ?? null,
                    'line_uuid' => $orderLine->line_uuid ?? null,
                    'product_id' => (int) $orderLine->product_id, 'product_variant_id' => $orderLine->product_variant_id,
                    'quantity' => $qty, 'unit_code' => $orderLine->unit_code ?? $orderLine->product?->unit?->code,
                    'unit_price' => (float) $orderLine->unit_price, 'discount_amount' => $item['discount'], 'tax_amount' => $item['tax'], 'line_total' => $item['line_total'],
                ];
            }

            if (! $shadow) {
                // Online status flip for the appliance's OWN sale; a shadow keeps its cache status.
                $sale->refresh()->load('lines');
                $customerLines = $sale->lines->reject(fn ($l) => in_array($l->line_kind, ['component', 'modifier'], true));
                $sale->update(['status' => $customerLines->sum('returned_quantity') >= $customerLines->sum('quantity') ? 'returned' : 'partially_returned']);
            }

            // CASH REFUND: the local drawer decreases exactly once (this transaction), Online's shift buckets.
            if ($shift) {
                $shift->increment('total_refunds', $grandTotal);
                $shift->increment('total_cash_refunds', $grandTotal);
                $shift->decrement('expected_cash', $grandTotal);
            }

            // The immutable RETURN EVENT — one transaction with the document and the operational effect.
            $mapping = $shadow ? $this->cache->mappingFor((int) $sale->id) : null;
            $origin = [
                'kind' => $shadow ? 'cloud' : 'edge',
                'cloud_sales_order_id' => $mapping ? (int) $mapping->cloud_sales_order_id : null,
                'sale_uuid' => $shadow ? ($mapping->sale_uuid ?? null) : (string) $sale->sale_uuid,
            ];
            $envelope = $this->envelopes->build($return->fresh(), $sale, $meta, $origin, $envelopeLines, $approvalAudit, [
                'user_id' => (int) $user->id, 'employee_code' => $user->employee_code ?? null, 'terminal_id' => $terminalId, 'shift_id' => $shift?->id,
            ], $freshness);
            $this->outbox->createForReturn($envelope);

            return $this->view($return->fresh(), $sale, $shadow);
        });
    }

    public function show(int $returnId): array
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $return = SalesReturn::on('tenant')->where('id', $returnId)->where('branch_id', $branchId)->where('edge_origin', 'local')->firstOrFail();
        $sale = SalesOrder::on('tenant')->findOrFail($return->sales_order_id);

        return $this->view($return, $sale, $this->cache->isShadow($sale));
    }

    private function view(SalesReturn $return, SalesOrder $sale, bool $shadow): array
    {
        $return->loadMissing('lines');
        $sync = DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $return->edge_return_uuid)->value('state');

        return [
            'id' => (int) $return->id, 'return_no' => (string) $return->return_no, 'return_uuid' => (string) $return->edge_return_uuid,
            'sale' => ['id' => (int) $sale->id, 'sale_no' => (string) $sale->sale_no, 'origin' => $shadow ? 'online' : 'local'],
            'business_date' => self::dateString($return->business_date),
            'lines' => $return->lines->map(fn ($l) => ['product_id' => (int) $l->product_id, 'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'discount_amount' => (float) $l->discount_amount, 'tax_amount' => (float) $l->tax_amount, 'line_total' => (float) $l->line_total])->all(),
            'totals' => ['subtotal' => (float) $return->subtotal, 'discount_amount' => (float) $return->discount_amount, 'tax_amount' => (float) $return->tax_amount, 'delivery_charge_amount' => (float) $return->delivery_charge_amount, 'grand_total' => (float) $return->grand_total],
            'refund_method' => (string) $return->refund_method, 'refund_amount' => (float) $return->refund_amount,
            'sync' => $sync === 'acknowledged' ? 'synced' : ($sync === 'failed_permanent' ? 'attention' : 'pending'),
        ];
    }

    private static function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    private function needsManagerApproval(?Branch $branch): bool
    {
        return ($branch?->sales_return_approval_mode ?? Branch::SALES_RETURN_AUTO_APPROVE) !== Branch::SALES_RETURN_AUTO_APPROVE;
    }

    private function loadSale(int $saleId, int $branchId, bool $lock): SalesOrder
    {
        $query = SalesOrder::on('tenant')->where('id', $saleId)->where('branch_id', $branchId)->whereIn('status', self::RETURNABLE_STATUSES);
        $sale = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $sale) {
            throw ValidationException::withMessages(['sale' => 'That sale is not returnable here (not found, not paid, or already fully returned).']);
        }
        $linesQuery = SalesOrderLine::on('tenant')->where('sales_order_id', $sale->id)->with(['product.unit', 'variant', 'returnLines']);
        $sale->setRelation('lines', $lock ? $linesQuery->lockForUpdate()->get() : $linesQuery->get());
        $sale->load(['payments.method', 'branch']);

        return $sale;
    }
}
