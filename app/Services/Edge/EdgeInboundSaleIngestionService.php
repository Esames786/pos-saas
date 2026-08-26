<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Customer;
use App\Models\Tenant\EdgeInboundSaleIngestion;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Services\Finance\JournalPostingService;
use App\Services\Inventory\InventoryService;
use App\Services\Kitchen\RecipeConsumptionService;
use App\Services\Sales\SalesService;
use App\Support\Edge\EdgeIdentity;
use App\Support\EdgeRuntime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * OFFLINE-SYNC-ENGINE-1C — CLOUD-AUTHORITATIVE ingestion of ONE immutable Edge paid-sale envelope.
 *
 * The Cloud is the ONLY authority for the official sale projection, official FEFO/COGS, GL/journals,
 * cash/bank effects, the official sale number and durable ingestion status. The Edge envelope is an
 * immutable intent/evidence package — NOT an already-posted Cloud sale. This service therefore NEVER
 * calls SalesService::finalizePaidSale (proven unsafe for the Edge ingest contract by the 1A spike):
 * that method early-returns on an already-'paid' row (skipping FEFO/COGS) while still posting GL. Instead
 * it projects a fresh Cloud sales_order from the envelope and composes the authoritative sub-services
 * directly, in ONE tenant transaction.
 *
 * Idempotency + conflict (registry keyed by canonical sale_uuid):
 *   - first envelope            -> apply once, store the accepted result;
 *   - same sale_uuid + same hash-> return the stored result, ZERO further effects;
 *   - same sale_uuid + diff hash-> hard conflict, NO mutation, the first truth is never overwritten.
 *
 * Split-brain: ingestion posts official stock for a branch handed to its Branch Server ONLY inside the
 * per-branch EdgeIngestionAuthority scope — it never reopens ordinary Cloud POS mutation for that branch.
 *
 * NO transport here (1D owns HTTP/auth-over-the-wire/retry/ACK delivery). This service is directly
 * callable inside the Cloud application with the tenant connection already active.
 */
class EdgeInboundSaleIngestionService
{
    private const SUPPORTED_ENVELOPE_SCHEMA = 'edge-sale-envelope-v1';
    private const SUPPORTED_ORDER_TYPES = ['quick_sale', 'takeaway', 'dine_in'];
    private const SUPPORTED_METHOD_TYPES = ['cash'];

    public function __construct(
        private readonly EdgeBootstrapService $canonical,          // canonicalJson (the ONE hash strategy)
        private readonly InventoryService $inventory,
        private readonly RecipeConsumptionService $recipes,
        private readonly JournalPostingService $journal,
        private readonly SalesService $sales,                      // nextSaleNo() authority only
        private readonly EdgeActivationEpochService $epochs,
        private readonly EdgeFinancePostingVerifier $financeVerifier,
    ) {
    }

    /**
     * Ingest one envelope. Returns the ACK (also stored on the registry). Never throws for a business
     * refusal/conflict/exception — those are deterministic ACKs; only a programming error propagates.
     *
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>  the ACK
     */
    public function ingest(array $envelope): array
    {
        if (EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('EdgeInboundSaleIngestionService is Cloud-only; it must never run on a Branch Server.');
        }

        $saleUuid = (string) ($envelope['sale_uuid'] ?? '');
        $contentHash = (string) ($envelope['content_hash'] ?? '');

        // ── structural validation before any lookup ──
        if (! EdgeIdentity::isValid($saleUuid, EdgeIdentity::FORMAT_ULID)) {
            return $this->refuse($envelope, 'SALE_UUID_INVALID', 'the envelope has no valid canonical sale_uuid');
        }
        if ((string) ($envelope['envelope_schema_version'] ?? '') !== self::SUPPORTED_ENVELOPE_SCHEMA) {
            return $this->refuse($envelope, 'SCHEMA_UNSUPPORTED', 'unsupported envelope schema version');
        }
        if (! $this->hashMatches($envelope, $contentHash)) {
            return $this->refuse($envelope, 'HASH_INVALID', 'the content hash is not self-consistent');
        }

        // ── idempotency: decide against the durable registry BEFORE any mutation ──
        $existing = EdgeInboundSaleIngestion::query()->where('sale_uuid', $saleUuid)->first();
        if ($existing !== null) {
            if ($existing->isApplied()) {
                if (! hash_equals((string) $existing->content_hash, $contentHash)) {
                    return $this->conflictAck($existing, $contentHash);        // same uuid, different content -> hard conflict
                }
                // Exact replay: the SAME durable truth, marked already_applied, with ZERO further effects.
                return array_merge($existing->ack_payload ?? [], ['status' => 'already_applied']);
            }
            if ($existing->status === EdgeInboundSaleIngestion::STATUS_CONFLICT) {
                return $existing->ack_payload;                                 // terminal conflict is never re-evaluated
            }
            // A prior refused/exception row (never applied) may be re-attempted with the SAME content only.
            if (! hash_equals((string) $existing->content_hash, $contentHash)) {
                return $this->conflictAck($existing, $contentHash);
            }
        }

        // ── authority validation (device/branch/tenant/epoch/customer/payment/order) ──
        try {
            [$device, $branch] = $this->validateAuthority($envelope);
        } catch (IngestionRefusal $e) {
            return $this->refuse($envelope, $e->refusalCode, $e->getMessage());
        }

        // ── authoritative posting: one tenant transaction, under the per-branch ingestion authority ──
        try {
            return EdgeIngestionAuthority::run((int) $branch->id, function () use ($envelope, $device, $branch, $saleUuid, $contentHash) {
                return DB::connection('tenant')->transaction(function () use ($envelope, $device, $branch, $saleUuid, $contentHash) {
                    // Claim the sale_uuid atomically — the registry unique index is the concurrency guard.
                    $ingestionUuid = (string) Str::ulid();
                    $registry = $this->claimRegistry($envelope, $device, $branch, $contentHash, $ingestionUuid);

                    $sale = $this->projectSale($envelope, $branch);
                    $this->projectLinesWithOfficialStock($envelope, $sale, $branch);
                    $this->projectPayments($envelope, $sale);

                    // TEST-ONLY seam: fires AFTER official stock/COGS + payments, BEFORE finance posting, INSIDE
                    // the transaction. Production no-op; a subclass may throw here to prove the whole ingestion
                    // (sale + official stock + registry) rolls back as one — no half-posted official state.
                    $this->afterOfficialStock();

                    $sale->refresh()->load(['payments.method', 'lines']);

                    // Official finance — same authorities the Cloud POS uses, INSIDE this transaction so a
                    // GL failure rolls the whole ingestion back (registry never claims success).
                    $this->journal->postPaidSale($sale, (int) $sale->created_by_user_id);
                    $this->journal->postSalesCashBankMovement($sale, (int) $sale->created_by_user_id);

                    // FINANCE ATOMICITY (1C closure): the shared finance authorities report-and-swallow their
                    // internal errors, so APPLIED must be gated on the required GL + cash-bank evidence actually
                    // being durable. A missing/malformed effect throws here and rolls the WHOLE ingestion back —
                    // official sale/stock/COGS/payments/registry included; the registry never claims success.
                    $this->financeVerifier->verifyPaidSale($sale->fresh()->load(['payments.method', 'lines']));

                    $ack = $this->buildAck($saleUuid, $contentHash, $ingestionUuid, $sale, (int) $envelope['activation_epoch'], $envelope['config_revision'] ?? null, EdgeInboundSaleIngestion::STATUS_APPLIED);
                    $registry->update([
                        'status' => EdgeInboundSaleIngestion::STATUS_APPLIED,
                        'ingested_sales_order_id' => $sale->id,
                        'official_sale_no' => $sale->sale_no,
                        'ack_payload' => $ack,
                        'ingested_at' => now(),
                    ]);

                    Log::info('[edge-sync-ingest] applied', ['sale_uuid' => $saleUuid, 'sales_order_id' => $sale->id, 'sale_no' => $sale->sale_no]);

                    return $ack;
                });
            });
        } catch (QueryException $e) {
            // A concurrent worker claimed the same sale_uuid first — converge on its committed result.
            if ($this->isSaleUuidCollision($e)) {
                $winner = EdgeInboundSaleIngestion::query()->where('sale_uuid', $saleUuid)->first();
                if ($winner && $winner->isApplied()) {
                    return hash_equals((string) $winner->content_hash, $contentHash) ? $winner->ack_payload : $this->conflictAck($winner, $contentHash);
                }
            }
            return $this->recordException($envelope, $contentHash, 'DB_ERROR', $e->getMessage());
        } catch (IngestionRefusal $e) {
            // A domain refusal raised mid-posting (e.g. insufficient stock) — the transaction already rolled
            // back; record a deterministic exception result (no sale/GL/stock/payment partials survive).
            return $this->recordException($envelope, $contentHash, $e->refusalCode, $e->getMessage());
        } catch (Throwable $e) {
            return $this->recordException($envelope, $contentHash, 'INGEST_FAILED', $e->getMessage());
        }
    }

    /** TEST-ONLY seam (see ingest): production no-op; a subclass may throw to prove atomic rollback. */
    protected function afterOfficialStock(): void
    {
    }

    // ── validation ────────────────────────────────────────────────────────────

    /** @return array{0: EdgeDevice, 1: Branch} */
    private function validateAuthority(array $envelope): array
    {
        $device = EdgeDevice::query()->where('public_uuid', (string) ($envelope['device_public_uuid'] ?? ''))->first();
        if (! $device) {
            throw new IngestionRefusal('DEVICE_UNKNOWN', 'no such Edge device');
        }
        if ($device->isRevoked() || $device->active_slot !== EdgeDevice::ACTIVE_SLOT) {
            throw new IngestionRefusal('DEVICE_REVOKED', 'the Edge device is revoked or not the active slot');
        }
        if ((int) $device->tenant_id !== (int) ($envelope['tenant_id'] ?? 0)) {
            throw new IngestionRefusal('WRONG_TENANT', 'the envelope tenant does not match the device');
        }
        if ((int) $device->branch_id !== (int) ($envelope['branch_id'] ?? 0)) {
            throw new IngestionRefusal('WRONG_BRANCH', 'the envelope branch does not match the device');
        }

        $branch = Branch::on('tenant')->find((int) $envelope['branch_id']);
        if (! $branch) {
            throw new IngestionRefusal('BRANCH_UNKNOWN', 'the branch does not resolve in this tenant');
        }

        // Activation epoch: the envelope must belong to the CURRENT generation for this branch.
        $current = $this->epochs->currentGeneration((int) $device->tenant_id, (int) $branch->id);
        if ($current === 0 || (int) ($envelope['activation_epoch'] ?? -1) !== $current) {
            throw new IngestionRefusal('STALE_ACTIVATION', "activation epoch {$envelope['activation_epoch']} is not the current generation {$current}");
        }

        // Order type + payment methods supported offline.
        if (! in_array((string) ($envelope['order_type'] ?? ''), self::SUPPORTED_ORDER_TYPES, true)) {
            throw new IngestionRefusal('ORDER_TYPE_UNSUPPORTED', 'order type not supported offline');
        }
        foreach (($envelope['payments'] ?? []) as $payment) {
            if (! in_array((string) ($payment['method_type'] ?? ''), self::SUPPORTED_METHOD_TYPES, true)) {
                throw new IngestionRefusal('PAYMENT_UNSUPPORTED', 'payment method type not supported offline');
            }
        }

        $totals = $envelope['totals'] ?? [];
        if ((float) ($totals['paid_amount'] ?? 0) + 1e-6 < (float) ($totals['grand_total'] ?? 0)) {
            throw new IngestionRefusal('ENVELOPE_INVALID', 'paid amount is less than the grand total');
        }

        // Every line's product identity must resolve in this tenant (Cloud-stable replicated config id).
        foreach (($envelope['lines'] ?? []) as $line) {
            if (! Product::on('tenant')->whereKey((int) ($line['product_id'] ?? 0))->exists()) {
                throw new IngestionRefusal('PRODUCT_UNRESOLVED', "line product {$line['product_id']} does not resolve in this tenant");
            }
        }

        return [$device, $branch];
    }

    /** Resolve the customer identity or fail closed (walk-in is explicit; a customer travels by uuid). */
    private function resolveCustomerId(array $envelope): ?int
    {
        $customer = $envelope['customer'] ?? null;
        if (! is_array($customer) || ($customer['kind'] ?? null) === 'walk_in' || $customer === null) {
            return null; // walk-in: never invent a customer
        }
        $uuid = (string) ($customer['customer_uuid'] ?? '');
        if (! EdgeIdentity::isValid($uuid, EdgeIdentity::FORMAT_ULID)) {
            throw new IngestionRefusal('CUSTOMER_INVALID', 'the customer identity is not a canonical customer_uuid');
        }
        $row = Customer::on('tenant')->where('customer_uuid', $uuid)->first();
        if (! $row) {
            // Fail closed (Sync design): never silently bind by phone or a local numeric id.
            throw new IngestionRefusal('CUSTOMER_UNKNOWN', 'the customer_uuid does not resolve in this tenant');
        }

        return (int) $row->id;
    }

    // ── projection + official posting ───────────────────────────────────────────

    private function projectSale(array $envelope, Branch $branch): SalesOrder
    {
        $totals = $envelope['totals'] ?? [];
        $customerId = $this->resolveCustomerId($envelope);

        $sale = new SalesOrder([
            'sale_no' => $this->sales->nextSaleNo(),                 // official Cloud sale number authority
            'client_uuid' => $envelope['client_uuid'] ?? null,
            'branch_id' => (int) $branch->id,
            'terminal_id' => (int) ($envelope['terminal_id'] ?? 0) ?: null,
            'customer_id' => $customerId,
            'customer_name' => $envelope['customer']['name'] ?? null,
            'customer_phone' => $envelope['customer']['phone'] ?? null,
            'restaurant_waiter_id' => $envelope['restaurant_waiter_id'] ?? null,
            'vehicle_number' => $envelope['vehicle_number'] ?? null,
            'order_source' => in_array((string) ($envelope['order_source'] ?? 'pos'), ['pos', 'manual'], true) ? (string) $envelope['order_source'] : 'pos', // enum(pos,manual); edge-origin marked by edge_sync_state
            'order_type' => (string) $envelope['order_type'],
            'sale_date' => $envelope['sale_date'] ?? now(),
            'business_date' => $envelope['business_date'] ?? null,
            'subtotal' => (float) ($totals['subtotal'] ?? 0),
            'discount_type' => 'none',
            'discount_value' => 0,
            'discount_amount' => (float) ($totals['discount_amount'] ?? 0),
            'tax_amount' => (float) ($totals['tax_amount'] ?? 0),
            'service_charge_amount' => (float) ($totals['service_charge_amount'] ?? 0),
            'tip_amount' => (float) ($totals['tip_amount'] ?? 0),
            'grand_total' => (float) ($totals['grand_total'] ?? 0),
            'paid_amount' => (float) ($totals['paid_amount'] ?? 0),
            'change_amount' => (float) ($totals['change_amount'] ?? 0),
            'status' => 'paid',
            'inventory_posted' => false,                              // set true after official FEFO below
            'completed_at' => $envelope['completed_at'] ?? now(),
            'created_by_user_id' => (int) ($envelope['user_id'] ?? 0) ?: null,
            // This IS the official Cloud projection of a synced Edge sale — mark it synced, keep the Edge id.
            'edge_sync_state' => 'synced',
            'edge_activation_epoch' => (int) ($envelope['activation_epoch'] ?? 0),
        ]);
        // Canonical identity is preserved from the Edge envelope, not regenerated (immutable cross-system id).
        $sale->sale_uuid = (string) $envelope['sale_uuid'];
        $sale->save();

        return $sale;
    }

    private function projectLinesWithOfficialStock(array $envelope, SalesOrder $sale, Branch $branch): void
    {
        foreach (($envelope['lines'] ?? []) as $line) {
            $product = Product::on('tenant')->findOrFail((int) $line['product_id']);
            $variant = ($line['product_variant_id'] ?? null)
                ? $this->inventory->resolveVariant($product, (int) $line['product_variant_id'])
                : null;
            $qty = (float) $line['quantity'];

            // Set the canonical line identity on the NEW model BEFORE the first save so the immutable-identity
            // hook preserves it (line_uuid is not mass-assignable; a post-save overwrite would be refused).
            $newLine = new \App\Models\Tenant\SalesOrderLine([
                'sales_order_id' => $sale->id,
                'line_kind' => (string) ($line['line_kind'] ?? 'standard'),
                'combo_id' => $line['combo_id'] ?? null,
                'product_id' => (int) $line['product_id'],
                'product_variant_id' => $variant?->id,
                'product_name' => (string) ($line['product_name'] ?? $product->name),
                'quantity' => $qty,
                'unit_price' => (float) $line['unit_price'],
                'unit_cost' => 0,
                'cost_total' => 0,
                'discount_amount' => (float) ($line['discount_amount'] ?? 0),
                'tax_amount' => (float) ($line['tax_amount'] ?? 0),
                'line_total' => (float) $line['line_total'],
                'modifiers' => $line['modifiers'] ?? null,
            ]);
            $newLine->line_uuid = (string) $line['line_uuid'];
            $newLine->save();

            // Official COGS/stock authority (Cloud FEFO) — never the Edge provisional movement.
            $method = $product->inventory_consumption_method ?? 'stock_item';
            if ($method === 'recipe') {
                $cost = $this->recipes->consumeForSalesOrderLine($sale, $newLine, $branch);
                if ($cost > 0) {
                    $newLine->update(['unit_cost' => $qty > 0 ? round($cost / $qty, 4) : 0, 'cost_total' => round($cost, 4)]);
                }
            } elseif ($method === 'stock_item' && $product->is_stock_tracked) {
                try {
                    $ledgers = $this->inventory->postOutFefo(
                        branch: $branch,
                        product: $product,
                        variant: $variant,
                        quantity: $qty,
                        movementType: 'sale',
                        referenceType: 'sales_order',
                        referenceId: (int) $sale->id,
                        referenceNo: $sale->sale_no,
                        notes: 'Edge sync stock out',
                        userId: (int) $sale->created_by_user_id,
                        allowNegative: (bool) $branch->allow_negative_stock,
                    );
                } catch (Throwable $e) {
                    // Respect the branch's CURRENT Cloud negative-stock policy: if official stock cannot fulfil
                    // and negatives are disallowed, the whole ingestion aborts (no partial) — a deterministic
                    // INSUFFICIENT_STOCK exception, recorded by the caller.
                    throw new IngestionRefusal('INSUFFICIENT_STOCK', "official stock cannot fulfil line product {$line['product_id']}: " . $e->getMessage());
                }
                $costTotal = collect($ledgers)->sum(fn ($l) => (float) $l->total_cost);
                $newLine->update(['unit_cost' => $qty > 0 ? $costTotal / $qty : 0, 'cost_total' => $costTotal]);
            }
            // 'none' (service/non-stock): no official stock movement, no COGS.
        }

        $sale->update(['inventory_posted' => true]);
    }

    private function projectPayments(array $envelope, SalesOrder $sale): void
    {
        foreach (($envelope['payments'] ?? []) as $payment) {
            $row = new \App\Models\Tenant\SalePayment([
                'sales_order_id' => $sale->id,
                'payment_method_id' => (int) $payment['payment_method_id'],
                'amount' => (float) $payment['amount'],
                'tendered_amount' => $payment['tendered_amount'] ?? null,
                'change_amount' => (float) ($payment['change_amount'] ?? 0),
                'transaction_ref' => $payment['transaction_ref'] ?? null,
            ]);
            $row->payment_uuid = (string) $payment['payment_uuid']; // canonical identity set before first save
            $row->save();
        }
    }

    // ── registry + ACK helpers ──────────────────────────────────────────────────

    private function claimRegistry(array $envelope, EdgeDevice $device, Branch $branch, string $contentHash, string $ingestionUuid): EdgeInboundSaleIngestion
    {
        // updateOrCreate on the unique sale_uuid: a prior REFUSED/EXCEPTION attempt (never applied) is
        // re-claimed in place; a concurrent worker that already inserted this uuid makes create() collide on
        // the unique index (caught by the caller, which converges on the winner). An applied row was already
        // returned before this point, so this never overwrites an accepted truth.
        return EdgeInboundSaleIngestion::updateOrCreate(['sale_uuid' => (string) $envelope['sale_uuid']], [
            'content_hash' => $contentHash,
            'envelope_schema_version' => (string) $envelope['envelope_schema_version'],
            'tenant_id' => (int) $device->tenant_id,
            'branch_id' => (int) $branch->id,
            'device_public_uuid' => (string) $device->public_uuid,
            'activation_epoch' => (int) $envelope['activation_epoch'],
            'config_revision' => $envelope['config_revision'] ?? null,
            'ingestion_uuid' => $ingestionUuid,
            'status' => EdgeInboundSaleIngestion::STATUS_EXCEPTION, // provisional until the transaction succeeds
        ]);
    }

    private function buildAck(string $saleUuid, string $contentHash, string $ingestionUuid, SalesOrder $sale, int $epoch, ?int $configRevision, string $status): array
    {
        return [
            'status' => $status,
            'sale_uuid' => $saleUuid,
            'content_hash' => $contentHash,
            'ingestion_uuid' => $ingestionUuid,
            'sales_order_id' => (int) $sale->id,
            'official_sale_no' => (string) $sale->sale_no,
            'activation_epoch' => $epoch,
            'config_revision' => $configRevision,
            'ingested_at' => now()->toIso8601String(),
        ];
    }

    private function conflictAck(EdgeInboundSaleIngestion $existing, string $incomingHash): array
    {
        return [
            'status' => 'conflict',
            'failure_code' => 'ENVELOPE_CONFLICT',
            'sale_uuid' => (string) $existing->sale_uuid,
            'content_hash' => (string) $existing->content_hash,
            'incoming_content_hash' => $incomingHash,
            'message' => 'this sale_uuid was already ingested with different content; the first accepted truth is authoritative',
        ];
    }

    /** Record a deterministic refusal (no mutation happened) and return it as the ACK. */
    private function refuse(array $envelope, string $code, string $message): array
    {
        $ack = [
            'status' => 'refused',
            'failure_code' => $code,
            'sale_uuid' => (string) ($envelope['sale_uuid'] ?? ''),
            'message' => $message,
        ];
        $this->persistTerminal($envelope, (string) ($envelope['content_hash'] ?? ''), EdgeInboundSaleIngestion::STATUS_REFUSED, $code, $message, $ack);

        return $ack;
    }

    /** Record a deterministic exception (a rolled-back attempt) and return it as the ACK. */
    private function recordException(array $envelope, string $contentHash, string $code, string $message): array
    {
        $ack = [
            'status' => 'exception',
            'failure_code' => $code,
            'sale_uuid' => (string) ($envelope['sale_uuid'] ?? ''),
            'message' => $message,
        ];
        $this->persistTerminal($envelope, $contentHash, EdgeInboundSaleIngestion::STATUS_EXCEPTION, $code, $message, $ack);

        return $ack;
    }

    /**
     * Persist a non-applied outcome in its OWN transaction (the main ingest transaction, if any, has already
     * rolled back). Never overwrites an already-APPLIED row. Best-effort: a registry-write failure here must
     * not mask the real outcome.
     */
    private function persistTerminal(array $envelope, string $contentHash, string $status, string $code, string $message, array $ack): void
    {
        $saleUuid = (string) ($envelope['sale_uuid'] ?? '');
        if ($saleUuid === '') {
            return;
        }
        try {
            $existing = EdgeInboundSaleIngestion::query()->where('sale_uuid', $saleUuid)->first();
            if ($existing && $existing->isApplied()) {
                return; // never downgrade an accepted truth
            }
            $attrs = [
                'content_hash' => $contentHash,
                'envelope_schema_version' => (string) ($envelope['envelope_schema_version'] ?? ''),
                'tenant_id' => (int) ($envelope['tenant_id'] ?? 0),
                'branch_id' => (int) ($envelope['branch_id'] ?? 0),
                'device_public_uuid' => (string) ($envelope['device_public_uuid'] ?? ''),
                'activation_epoch' => (int) ($envelope['activation_epoch'] ?? 0),
                'config_revision' => $envelope['config_revision'] ?? null,
                'status' => $status,
                'failure_code' => $code,
                'last_error' => mb_substr($message, 0, 1900),
                'ack_payload' => $ack,
                'ingested_at' => now(),
            ];
            if ($existing) {
                $existing->update($attrs);
            } else {
                EdgeInboundSaleIngestion::create($attrs + ['sale_uuid' => $saleUuid, 'ingestion_uuid' => (string) Str::ulid()]);
            }
        } catch (Throwable $e) {
            Log::warning('[edge-sync-ingest] failed to persist terminal outcome', ['sale_uuid' => $saleUuid, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    private function hashMatches(array $envelope, string $contentHash): bool
    {
        if ($contentHash === '') {
            return false;
        }
        $copy = $envelope;
        unset($copy['content_hash']);

        return hash_equals(hash('sha256', $this->canonical->canonicalJson($copy)), $contentHash);
    }

    private function isSaleUuidCollision(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            && str_contains((string) $e->getMessage(), 'sale_uuid');
    }
}
