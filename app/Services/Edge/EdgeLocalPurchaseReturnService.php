<?php

namespace App\Services\Edge;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\User;
use App\Support\EdgeRuntime;
use App\Support\EdgeUserAuthz;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * OFFLINE EDGE — F3: the appliance's purchase-return operator authority (Branch Server only).
 *
 * Online is the specification: a return to a supplier is built against the SOURCE goods receipt ("Received Lines":
 * received, already returned, returnable, return qty, unit cost from the receipt line, reason per line or on the
 * header) and posted; Online also allows a return without a source receipt (validated against official stock only) —
 * offline that path needs the Online POS (fail closed). The appliance:
 *   - validates against the FRESH warm projection (stale → fail closed, never a guessed returnable quantity),
 *   - reduces LOCAL OPERATIONAL stock exactly once (the goods physically leave the branch) — canonical semantics:
 *     the branch must hold the stock it returns,
 *   - shows the provisional supplier payable effect (Cr subledger) through the F2 position — no local GL, ever,
 *   - records a PENDING SYNC event and queues the immutable PURCHASE_RETURN envelope for the Cloud, which posts the
 *     OFFICIAL return exactly once through PurchaseReturnService (stock OUT FEFO, subledger, Dr 2100 / Cr 1400).
 */
class EdgeLocalPurchaseReturnService
{
    public const PERM_STORE = 'tenant.purchase-returns.store';
    public const PERM_POST = 'tenant.purchase-returns.post';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeAuthorityService $authority,
        private readonly EdgePurchaseReturnCacheService $cache,
        private readonly EdgeOperationalStockService $opStock,
        private readonly EdgePurchaseReturnEnvelopeBuilder $envelopes,
        private readonly EdgeSyncOutboxService $outbox,
    ) {
    }

    public function permissionsFor(User $user): array
    {
        return [
            'can_view' => (bool) ($user->can(self::PERM_STORE) || $user->can(self::PERM_POST)),
            'can_post' => (bool) ($user->can(self::PERM_STORE) && $user->can(self::PERM_POST)),
        ];
    }

    public function options(User $user): array
    {
        $meta = $this->context->requireCurrent();
        $branch = Branch::on('tenant')->find((int) $meta->branch_id);

        return [
            'branch' => ['id' => (int) $meta->branch_id, 'name' => $branch?->name],
            'today' => now()->toDateString(),
            'freshness' => $this->cache->freshness(),
            'rules' => $this->cache->rules(),
            'permissions' => $this->permissionsFor($user),
            'suppliers' => $this->cache->suppliers(),
            'grns' => $this->cache->grns(),
            'recent_events' => $this->cache->recentEvents(30),
            'local_mode' => $this->localMutationAllowed(),
        ];
    }

    /** The "Received Lines" view of one goods receipt: received / already returned / returnable / unit cost per line. */
    public function grn(int $cloudGrnId): array
    {
        $g = $this->cache->grn($cloudGrnId);
        if (! $g) {
            throw ValidationException::withMessages(['grn' => 'This goods receipt is not in the branch server\'s purchase projection (outside the mirrored window, or another branch).']);
        }
        $lines = $this->cache->lines($cloudGrnId);
        foreach ($lines as &$l) {
            $l['local_on_hand'] = $this->localOnHand((int) $l['product_id'], $l['product_variant_id']);
        }
        unset($l);

        return ['grn' => $this->cache->grnView($g), 'lines' => $lines, 'freshness' => $this->cache->freshness()];
    }

    public function event(string $uuid): array
    {
        $view = $this->cache->event($uuid);
        if (! $view) {
            throw ValidationException::withMessages(['event' => 'No such purchase return on this branch server.']);
        }

        return $view;
    }

    /**
     * @param array $data cloud_grn_id, return_date?, reason_code?, notes?, lines[] {cloud_grn_line_id, quantity, reason_code?}
     */
    public function postReturn(array $data, User $user, ?int $terminalId = null): array
    {
        $meta = $this->guardMutation($user);
        $branchId = (int) $meta->branch_id;
        $rules = $this->cache->rules();
        $grnId = (int) ($data['cloud_grn_id'] ?? 0);
        if ($grnId <= 0) {
            throw ValidationException::withMessages(['cloud_grn_id' => 'Pick the goods receipt being returned against. A return without a source receipt needs the Online POS (it is validated against official stock there).']);
        }
        $rawLines = array_values(array_filter((array) ($data['lines'] ?? []), fn ($l) => round((float) ($l['quantity'] ?? 0), 3) > 0));
        if ($rawLines === []) {
            throw ValidationException::withMessages(['lines' => 'Enter the quantity to return on at least one received line.']);
        }
        $headerReason = ! empty($data['reason_code']) ? (string) $data['reason_code'] : null;
        $codes = (array) $rules['reason_codes'];
        if ($headerReason !== null && ! in_array($headerReason, $codes, true)) {
            throw ValidationException::withMessages(['reason_code' => 'Select a valid return reason.']);
        }
        foreach ($rawLines as $i => $l) {
            if (! empty($l['reason_code']) && ! in_array((string) $l['reason_code'], $codes, true)) {
                throw ValidationException::withMessages(["lines.$i.reason_code" => 'Select a valid return reason.']);
            }
        }
        if ($headerReason === null && ! collect($rawLines)->contains(fn ($l) => ! empty($l['reason_code']))) {
            throw ValidationException::withMessages(['reason_code' => 'A return reason is required (header or per line).']);
        }
        $returnDate = $this->dateOr($data['return_date'] ?? null);

        return DB::connection('tenant')->transaction(function () use ($data, $user, $terminalId, $meta, $branchId, $grnId, $rawLines, $headerReason, $returnDate) {
            // Serialise on the projected receipt and its lines FIRST (before any consistent read fixes a snapshot).
            $grn = $this->cache->grn($grnId, lock: true);
            if (! $grn) {
                throw ValidationException::withMessages(['cloud_grn_id' => 'This goods receipt is not in the branch server\'s purchase projection.']);
            }
            if ((int) $grn->branch_id !== $branchId) {
                throw ValidationException::withMessages(['cloud_grn_id' => 'This goods receipt belongs to another branch.']);
            }
            if ((string) $grn->supplier_status !== 'active') {
                throw ValidationException::withMessages(['cloud_grn_id' => 'The supplier of this receipt is inactive — returns to inactive suppliers are refused (as Online).']);
            }
            $lineIds = collect($rawLines)->pluck('cloud_grn_line_id')->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
            foreach ($lineIds as $lid) {
                $this->cache->line($lid, lock: true);
            }
            $fresh = $this->cache->freshness();
            if (! $fresh['ok']) {
                throw ValidationException::withMessages(['grn' => 'Purchase information is not current on this branch server — post this return on the Online POS or ask a supervisor. (' . implode('; ', $fresh['reasons']) . ')']);
            }
            $projected = collect($this->cache->lines($grnId, lock: true))->keyBy('cloud_grn_line_id');

            $eventUuid = (string) Str::ulid();
            $lines = [];
            $grandTotal = 0.0;
            $byLine = [];
            foreach ($rawLines as $l) {
                $lid = (int) $l['cloud_grn_line_id'];
                $byLine[$lid] = ($byLine[$lid] ?? 0.0) + round((float) $l['quantity'], 3);
            }
            foreach ($rawLines as $i => $l) {
                $lid = (int) $l['cloud_grn_line_id'];
                $p = $projected[$lid] ?? null;
                if (! $p) {
                    throw ValidationException::withMessages(["lines.$i.cloud_grn_line_id" => 'This line does not belong to the selected goods receipt.']);
                }
                $qty = round((float) $l['quantity'], 3);
                if ($byLine[$lid] - (float) $p['returnable'] > 0.0005) {
                    throw ValidationException::withMessages(["lines.$i.quantity" => 'Cannot return ' . number_format($byLine[$lid], 3) . ' of ' . $p['product_name'] . ': only ' . number_format((float) $p['returnable'], 3)
                        . ' returnable on this receipt line (received ' . number_format((float) $p['quantity_received'], 3) . ', already returned ' . number_format((float) $p['already_returned'], 3) . ').']);
                }
                $product = Product::on('tenant')->find((int) $p['product_id']);
                if (! $product) {
                    throw ValidationException::withMessages(["lines.$i.cloud_grn_line_id" => 'The received product is not in this branch server\'s catalogue — refresh the configuration first.']);
                }
                $variant = $p['product_variant_id'] ? \App\Models\Tenant\ProductVariant::on('tenant')->find((int) $p['product_variant_id']) : null;
                $lineUuid = (string) Str::ulid();
                // The goods physically leave the branch: LOCAL OPERATIONAL stock out, exactly once (idempotent on event + line).
                try {
                    $this->opStock->purchaseReturnOut($eventUuid, $lineUuid, $product, $variant, $qty);
                } catch (RuntimeException $e) {
                    throw ValidationException::withMessages(["lines.$i.quantity" => $e->getMessage()]);
                }
                $unitCost = round((float) $p['unit_cost'], 4);
                $lineTotal = round($qty * $unitCost, 4);
                $grandTotal += $lineTotal;
                $lines[] = [
                    'line_uuid' => $lineUuid, 'cloud_grn_line_id' => $lid, 'product_id' => (int) $p['product_id'], 'product_variant_id' => $p['product_variant_id'],
                    'product_name' => $p['product_name'], 'unit_code' => $p['unit_code'], 'quantity' => $qty, 'unit_cost' => $unitCost, 'line_total' => $lineTotal,
                    'reason_code' => ! empty($l['reason_code']) ? (string) $l['reason_code'] : null,
                ];
            }
            $grandTotal = round($grandTotal, 4);
            $return = [
                'cloud_grn_id' => (int) $grn->cloud_grn_id, 'grn_no' => (string) $grn->grn_no, 'cloud_supplier_id' => (int) $grn->cloud_supplier_id,
                'supplier_code' => (string) $grn->supplier_code, 'supplier_name' => (string) $grn->supplier_name, 'return_date' => $returnDate, 'reason_code' => $headerReason,
                'notes' => isset($data['notes']) ? mb_substr(trim((string) $data['notes']), 0, 1000) : null, 'cloud_bill_id' => $grn->cloud_bill_id, 'bill_no' => $grn->bill_no, 'lines' => $lines,
            ];
            $envelope = $this->envelopes->build($meta, $eventUuid, $return, ['user_id' => (int) $user->id, 'employee_code' => $user->employee_code ?? null, 'terminal_id' => $terminalId], $fresh);

            $this->cache->recordEvent([
                'event_uuid' => $eventUuid, 'cloud_grn_id' => (int) $grn->cloud_grn_id, 'cloud_supplier_id' => (int) $grn->cloud_supplier_id, 'branch_id' => $branchId,
                'terminal_id' => $terminalId, 'user_id' => (int) $user->id, 'return_date' => $returnDate, 'reason_code' => $headerReason, 'notes' => $return['notes'],
                'grand_total' => $grandTotal, 'payload' => $return + ['grand_total' => $grandTotal], 'envelope_schema_version' => $envelope['envelope_schema_version'],
                'content_hash' => $envelope['content_hash'], 'projection_watermark' => $fresh['watermark'],
            ], $lines);
            // The provisional supplier payable effect rides the F2 supplier position (Cr subledger = payable down); the
            // F2 projection's applied set includes purchase-return events once the Cloud has applied them.
            DB::connection('tenant')->table(EdgeSupplierFinanceCacheService::T_EFFECTS)->insert([
                'event_uuid' => $eventUuid, 'cloud_supplier_id' => (int) $grn->cloud_supplier_id, 'cloud_cash_bank_account_id' => null, 'cloud_bill_id' => $grn->cloud_bill_id,
                'payable_delta' => -$grandTotal, 'cash_delta' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->outbox->createForFinanceEvent($envelope);

            return $this->cache->event($eventUuid);
        });
    }

    private function localOnHand(int $productId, ?int $variantId): ?float
    {
        $baseline = app(EdgeOperationalBaselineService::class)->currentAccepted();
        if (! $baseline) {
            return null;
        }
        $row = DB::connection('tenant')->table('edge_operational_stock_balances')->where('balance_key', $baseline->id . '-' . $productId . '-' . ($variantId ?: 0))->first();

        return $row ? round((float) $row->quantity_on_hand, 3) : 0.0;
    }

    private function guardMutation(User $user): \App\Models\Edge\EdgeLocalMeta
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Offline purchase returns run on the Branch Server.');
        }
        $this->authority->assertLocalMutationAllowed();
        $meta = $this->context->requireCurrent();
        if (! EdgeUserAuthz::mayOperateBranch($user, (int) $meta->branch_id)) {
            throw new RuntimeException('This user is not authorized on this branch.');
        }
        if (! $user->can(self::PERM_STORE) || ! $user->can(self::PERM_POST)) {
            throw new RuntimeException('You are not allowed to post purchase returns (' . self::PERM_STORE . ' + ' . self::PERM_POST . ').');
        }

        return $meta;
    }

    private function localMutationAllowed(): bool
    {
        try {
            $this->authority->assertLocalMutationAllowed();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function dateOr(mixed $value): string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return now()->toDateString();
        }
        try {
            return Carbon::parse($s)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['return_date' => 'Enter a valid return date.']);
        }
    }
}
