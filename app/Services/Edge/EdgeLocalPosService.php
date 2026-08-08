<?php

namespace App\Services\Edge;

use App\Exceptions\SaleIdempotencyConflictException;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SaleIdempotencyService;
use App\Services\Sales\SaleOperationalSettlementService;
use App\Services\Sales\SalePricingService;
use App\Services\Sales\SalesTotalsService;
use App\Services\Sales\ShiftService;
use App\Support\EdgeUserAuthz;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * EDGE-LOCAL-POS-1 — the OFFLINE local paid-sale orchestrator for a Branch Server.
 *
 * It reuses the shared Cloud domain (SalePricingService, SalesTotalsService, SaleIdempotencyService canonical
 * payload/hash, canonical identities, EdgeOperationalStockService) to complete an OPERATIONAL local sale, but
 * it NEVER calls `SalesService::finalizePaidSale` — the GL journal, COGS, FEFO valuation, cash/bank finance
 * movement and department custody are Cloud-only accounting authority and must not run offline. A local sale
 * is OPERATIONAL / PROVISIONAL: `edge_sync_state='pending'` + `edge_operational_stock_posted=true` (NOT
 * `inventory_posted`, which on Cloud means the official FEFO posting ran) so Cloud runs its authoritative
 * inventory/GL posting at sync.
 *
 * SECURITY BOUNDARY (this service is the authority, not the caller):
 *  - tenant / branch / activation_epoch come from `EdgeBranchContext::requireCurrent()` — never request input.
 *  - the user must be the authenticated local Tenant\User, active, Edge-eligible, authorized for the bound branch.
 *  - the terminal must belong to the bound branch and be active.
 *  - the OPEN shift is LOCKED + revalidated inside the SAME transaction (ShiftService::lockOpenShiftForTerminal),
 *    so a concurrent shift-close is deterministic; business_date + timezone come from that locked shift.
 *  - a valid client_uuid is REQUIRED; replay vs conflict is decided by the shared payload hash; a genuine
 *    same-key insert race is resolved by the unique constraint + retry (one sale, one payment, one stock move).
 *  - STANDARD line prices/tax are resolved server-side from catalog/config — a request cannot choose them
 *    (no free-sale bypass); combo/component selling is BLOCKED offline this sprint (no client-supplied topology).
 *  - cash only this sprint; card/wallet/credit and (until proven) other manual methods are refused offline.
 *
 * Everything runs in ONE `tenant` transaction (the edge-local DB on a branch_server). No master/Cloud call.
 */
class EdgeLocalPosService
{
    /** Cash only for the first offline sprint (card/wallet need a provider; other manual methods = phased). */
    private const OFFLINE_PAYMENT_TYPES = ['cash'];

    /** Only order types whose full offline workflow exists yet. dine_in/delivery are added when wired. */
    private const OFFLINE_ORDER_TYPES = ['quick_sale', 'takeaway'];

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly ShiftService $shiftService,
        private readonly SalePricingService $pricing,
        private readonly SalesTotalsService $totals,
        private readonly InventoryService $inventory,           // resolveVariant() only
        private readonly EdgeOperationalStockService $opStock,
        private readonly SaleIdempotencyService $idempotency,
        private readonly SaleOperationalSettlementService $settlement,
    ) {
    }

    /**
     * (2C test seam) Called AFTER pre-flight validation and BEFORE the sale transaction opens. A no-op in
     * production; TOCTOU tests override it to change state (deactivate cashier/terminal) in the window the
     * in-transaction revalidation must catch.
     */
    protected function beforeSaleTransaction(): void
    {
    }

    /** Complete a paid local cash sale. Terminal is chosen by the operator; every authority is derived here. */
    public function completePaidSale(array $data, User $user, ?int $terminalId): SalesOrder
    {
        // ── authority from the bound appliance, NOT the request ──
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $activationEpoch = (int) $meta->activation_epoch;
        $branch = Branch::on('tenant')->findOrFail($branchId);

        // (B) The sale principal MUST be the authenticated local cashier — a caller cannot attribute the sale
        // to another branch-authorized user. (Manager re-auth is a SEPARATE identity for manager actions.)
        $authUser = auth('tenant')->user();
        if ($authUser && (int) $authUser->id !== (int) $user->id) {
            throw ValidationException::withMessages(['user' => 'The sale principal must be the authenticated cashier.']);
        }
        if (! EdgeUserAuthz::isActive($user) || ! EdgeUserAuthz::isEdgeLoginEligible($user) || ! EdgeUserAuthz::mayOperateBranch($user, $branchId)) {
            throw ValidationException::withMessages(['user' => 'This user is not authorized to sell on this Branch Server.']);
        }

        $terminal = Terminal::on('tenant')->where('id', (int) $terminalId)->where('branch_id', $branchId)->where('status', 'active')->first();
        if (! $terminal) {
            throw ValidationException::withMessages(['terminal_id' => 'Select an active terminal on this branch.']);
        }

        // (D) Only order types whose offline workflow exists this sprint — AND the user must be allowed it.
        $orderType = (string) ($data['order_type'] ?? 'quick_sale');
        if (! in_array($orderType, self::OFFLINE_ORDER_TYPES, true)) {
            throw ValidationException::withMessages(['order_type' => "Order type [{$orderType}] is not yet available on the Branch Server."]);
        }
        if (! $user->allowsOrderType($orderType)) {
            throw ValidationException::withMessages(['order_type' => "Order type [{$orderType}] is not allowed for this user."]);
        }

        // (C) No unauthorized price reduction — discounts/promotions are BLOCKED offline this sprint (no proven
        // manual-discount permission/manager-approval wiring yet). A request that carries any is refused.
        $this->assertNoDiscountOrPromo($data);

        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => (float) ($l['quantity'] ?? 0) > 0));
        $payments = array_values(array_filter($data['payments'] ?? [], fn ($p) => (float) ($p['amount'] ?? 0) > 0));
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'A sale needs at least one line.']);
        }
        if (! $payments) {
            throw ValidationException::withMessages(['payments' => 'A paid sale needs at least one payment.']);
        }
        $this->assertNoComboSelling($lines);
        $this->assertPaymentsOffline($payments);

        // ── idempotency: a valid client_uuid is MANDATORY for a durable retry ──
        $clientUuid = $this->idempotency->normalizeClientUuid($data['client_uuid'] ?? null);
        if ($clientUuid === null) {
            throw ValidationException::withMessages(['client_uuid' => 'A local Direct Pay requires a valid client_uuid for safe retries.']);
        }
        // (A) Hash the EFFECTIVE authoritative intent, not spoofable/ignored request fields: bound branch,
        // validated terminal, order_source=pos, no discounts/promo, and strip the unit_price/tax Edge ignores.
        $payloadHash = $this->idempotency->buildPayloadHash($this->idempotency->canonicalSalePayload(
            $this->effectiveIntent($data, $branchId, $terminal->id, $orderType, $lines, $payments)
        ));

        if ($existing = $this->idempotency->findFinalized($clientUuid)) {
            return $this->replayOrConflict($existing, $payloadHash);
        }

        $this->beforeSaleTransaction(); // 2C test seam — no-op in production

        try {
            return DB::connection('tenant')->transaction(function () use ($data, $user, $branch, $branchId, $terminal, $activationEpoch, $orderType, $lines, $payments, $clientUuid, $payloadHash) {
                // Race-safe: a concurrent request may already have finalized this key.
                if ($winner = $this->idempotency->findFinalized($clientUuid)) {
                    return $this->replayOrConflict($winner, $payloadHash);
                }

                // (2C) Revalidate the principal + terminal INSIDE the transaction against fresh rows — a
                // cashier/terminal deactivated (or a principal swapped) after preflight must still be refused.
                $user = User::on('tenant')->find($user->id);
                $authId = auth('tenant')->id();
                if (! $user || ($authId && (int) $authId !== (int) $user->id)) {
                    throw ValidationException::withMessages(['user' => 'The sale principal must be the authenticated cashier.']);
                }
                if (! EdgeUserAuthz::isActive($user) || ! EdgeUserAuthz::isEdgeLoginEligible($user) || ! EdgeUserAuthz::mayOperateBranch($user, $branchId)) {
                    throw ValidationException::withMessages(['user' => 'This user is not authorized to sell on this Branch Server.']);
                }
                $terminal = Terminal::on('tenant')->where('id', $terminal->id)->where('branch_id', $branchId)->where('status', 'active')->first();
                if (! $terminal) {
                    throw ValidationException::withMessages(['terminal_id' => 'Select an active terminal on this branch.']);
                }

                // Lock + revalidate the open shift for THIS terminal in the same transaction (TOCTOU-safe vs close).
                $shift = $this->shiftService->lockOpenShiftForTerminal($terminal);
                $businessDate = $shift->business_date->toDateString();

                $resolved = $this->resolveLines($lines, $branch);
                $totals = $this->totals->calculate($resolved, (string) ($data['discount_type'] ?? 'none'), (float) ($data['discount_value'] ?? 0), $branchId, $orderType, $data['promo_code'] ?? null, 0);

                $paidAmount = array_sum(array_map(fn ($p) => (float) $p['amount'], $payments));
                if ($paidAmount + 1e-6 < (float) $totals['grand_total']) {
                    throw ValidationException::withMessages(['payments' => 'Paid amount is less than the sale total.']);
                }

                // (E) ONE ULID: pre-mint the canonical sale identity and derive the display number from it, so
                // sale_no is directly traceable to the immutable sale_uuid (sale_no stays a human label).
                $saleUlid = (string) Str::ulid();

                $sale = new SalesOrder([
                    'sale_no' => 'SO-' . $branchId . '-' . $terminal->id . '-' . $saleUlid,
                    'client_uuid' => $clientUuid,
                    'client_payload_hash' => $payloadHash,
                    'branch_id' => $branchId,
                    'terminal_id' => $terminal->id,
                    'shift_id' => $shift->id,
                    'business_date' => $businessDate,
                    'order_source' => 'pos',
                    'order_type' => $orderType,
                    'sale_date' => now(),
                    'subtotal' => $totals['subtotal'],
                    'discount_type' => (string) ($data['discount_type'] ?? 'none'),
                    'discount_value' => (float) ($data['discount_value'] ?? 0),
                    'discount_amount' => $totals['discount_amount'],
                    'promotion_id' => $totals['promotion_id'],
                    'promo_code' => $totals['promo_code'],
                    'tax_amount' => $totals['tax_amount'],
                    'service_charge_amount' => $totals['service_charge_amount'],
                    'tip_amount' => 0,
                    'grand_total' => $totals['grand_total'],
                    'paid_amount' => $paidAmount,
                    'change_amount' => max($paidAmount - (float) $totals['grand_total'], 0),
                    'status' => 'paid',
                    'completed_at' => now(),
                    'created_by_user_id' => $user->id,
                    // PROVISIONAL — NOT Cloud-official. inventory_posted stays false (Cloud posts FEFO at sync).
                    // edge_operational_stock_posted stays false until the stock decrement below actually succeeds.
                    'edge_sync_state' => 'pending',
                    'edge_activation_epoch' => $activationEpoch,
                ]);
                // sale_uuid is deliberately NOT mass-assignable — set the pre-minted identity directly (the
                // HasCanonicalIdentity hook keeps a non-empty value; immutability guard applies after save).
                $sale->sale_uuid = $saleUlid;
                $sale->save();

                foreach ($resolved as $r) {
                    $qty = (float) $r['quantity'];
                    $price = (float) $r['unit_price'];
                    $sale->lines()->create([
                        'product_id' => $r['product_id'], 'product_name' => $r['_product']->name,
                        'product_variant_id' => $r['_variant']?->id, 'line_kind' => 'standard',
                        'quantity' => $qty, 'unit_price' => $price, 'unit_cost' => 0, 'cost_total' => 0,
                        'discount_amount' => (float) $r['discount_amount'], 'tax_amount' => (float) $r['tax_amount'],
                        'line_total' => $qty * $price - (float) $r['discount_amount'] + (float) $r['tax_amount'],
                        'modifiers' => $r['modifiers'] ?? null,
                    ]);
                }
                foreach ($payments as $p) {
                    // Grounded Cloud cash semantics: amount = applied to the invoice; tendered = physical cash;
                    // per-payment change = tendered − amount (what leaves the drawer as change).
                    $amount = (float) $p['amount'];
                    $tendered = isset($p['tendered_amount']) && $p['tendered_amount'] !== null ? (float) $p['tendered_amount'] : null;
                    $sale->payments()->create([
                        'payment_method_id' => (int) $p['payment_method_id'],
                        'amount' => $amount,
                        'tendered_amount' => $tendered,
                        'change_amount' => $tendered !== null ? max($tendered - $amount, 0) : 0,
                        'transaction_ref' => $p['transaction_ref'] ?? null,
                    ]);
                }

                // OPERATIONAL stock decrement (quantity only, no FEFO/COGS/GL) — same transaction.
                $this->opStock->consumeForSale($sale->fresh(), $user->id);
                // (2B) mark operational stock posted ONLY after every decrement succeeded (same txn).
                $sale->update(['edge_operational_stock_posted' => true]);

                // (G) SHARED operational settlement — the same sales-subledger + shift-counter rules Cloud
                // finalizePaidSale uses, inside this same transaction (rolls back with the sale).
                $this->settlement->settle($sale->fresh());

                return $sale->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // (2A) ONLY a collision on the client_uuid unique index is an idempotency race — an unrelated
            // unique violation (sale_uuid/sale_no/line_uuid/payment_uuid/etc.) is a real failure and must rethrow.
            if (! $this->isClientUuidCollision($e->getMessage())) {
                throw $e;
            }
            $winner = $this->idempotency->resolveFinalizedWithRetry($clientUuid);
            if ($winner) {
                return $this->replayOrConflict($winner, $payloadHash);
            }
            throw new SaleIdempotencyConflictException(null, SaleIdempotencyConflictException::CODE_PENDING);
        }
    }

    /**
     * (2A) True ONLY when a unique-constraint failure is on the client_uuid index — the sole collision that
     * means "another request finalized this same key". Any other unique index (sale_uuid/sale_no/line_uuid/
     * payment_uuid/…) is an unrelated real failure that must propagate, never be masked as an idempotency race.
     *
     * IMPORTANT (caught by the real-path test): the exception message embeds the full INSERT SQL, whose column
     * list contains `client_uuid` for EVERY sales_orders violation — so a whole-message substring check
     * misclassifies unrelated collisions. Only the violated KEY NAME ("Duplicate entry … for key '…'") is
     * discriminating. Kept private: proven end-to-end through the real catch path (frozen-ULID collision test
     * + the two-process client_uuid race), not via a public seam.
     */
    private function isClientUuidCollision(string $message): bool
    {
        return preg_match("/for key '[^']*client_uuid[^']*'/i", $message) === 1;
    }

    /** Replay an existing finalized sale, or raise a controlled conflict — same contract as the Cloud controller. */
    private function replayOrConflict(SalesOrder $existing, string $payloadHash): SalesOrder
    {
        if (! $this->idempotency->hasVerifiableHash($existing)) {
            throw new SaleIdempotencyConflictException($existing->id, SaleIdempotencyConflictException::CODE_UNVERIFIABLE);
        }
        if ($this->idempotency->payloadMatches($existing, $payloadHash)) {
            return $existing; // idempotent replay — no re-post
        }
        throw new SaleIdempotencyConflictException($existing->id);
    }

    /** (C) Refuse any discount/promotion until a real authorized manual-discount contract is wired offline. */
    private function assertNoDiscountOrPromo(array $data): void
    {
        $type = (string) ($data['discount_type'] ?? 'none');
        if ($type !== 'none' || (float) ($data['discount_value'] ?? 0) != 0.0 || ! empty($data['promo_code'])) {
            throw ValidationException::withMessages(['discount' => 'Discounts and promotions are not yet available on the Branch Server.']);
        }
        foreach ($data['lines'] ?? [] as $line) {
            if ((float) ($line['discount_amount'] ?? 0) != 0.0) {
                throw ValidationException::withMessages(['discount' => 'Line discounts are not yet available on the Branch Server.']);
            }
        }
    }

    /**
     * (A) The EFFECTIVE, authoritative sale intent for idempotency hashing — reuses the shared canonicalizer
     * but on values Edge actually honours: request-controlled branch/terminal/order_source and the price/tax/
     * discount fields Edge ignores are normalized away, so a harmless browser value never forces a false
     * conflict and a spoofed branch/terminal can never become the hashed authority.
     */
    private function effectiveIntent(array $data, int $branchId, int $terminalId, string $orderType, array $lines, array $payments): array
    {
        return [
            'branch_id' => $branchId,
            'terminal_id' => $terminalId,
            'order_source' => 'pos',
            'order_type' => $orderType,
            'discount_type' => 'none',
            'discount_value' => 0,
            'promo_code' => null,
            'lines' => array_map(fn ($l) => [
                'product_id' => $l['product_id'] ?? null,
                'product_variant_id' => $l['product_variant_id'] ?? null,
                'line_kind' => 'standard',
                'quantity' => $l['quantity'] ?? null,
                'modifiers' => $l['modifiers'] ?? null,
                // unit_price / tax_amount / discount_amount deliberately omitted — Edge resolves them server-side.
            ], $lines),
            'payments' => array_map(fn ($p) => [
                'payment_method_id' => $p['payment_method_id'] ?? null,
                'amount' => $p['amount'] ?? null,
            ], $payments),
        ];
    }

    private function assertNoComboSelling(array $lines): void
    {
        foreach ($lines as $line) {
            if (in_array($line['line_kind'] ?? 'standard', ['combo_header', 'component'], true)) {
                throw ValidationException::withMessages(['lines' => 'Combo selling is not yet supported on the Branch Server. Sell standard products.']);
            }
        }
    }

    /** Cash only offline this sprint — provider (card/wallet) and unproven manual methods are refused. */
    private function assertPaymentsOffline(array $payments): void
    {
        // Grounded MVP: exactly ONE cash payment row per local Direct Pay (no split payments offline yet).
        if (count($payments) !== 1) {
            throw ValidationException::withMessages(['payments' => 'The Branch Server accepts exactly one cash payment per sale (no split payments offline).']);
        }
        foreach ($payments as $p) {
            $method = PaymentMethod::on('tenant')->find((int) ($p['payment_method_id'] ?? 0));
            if (! $method || ! $method->is_active) {
                throw ValidationException::withMessages(['payments' => 'Unknown or inactive payment method.']);
            }
            if (! in_array($method->method_type, self::OFFLINE_PAYMENT_TYPES, true)) {
                throw ValidationException::withMessages([
                    'payments' => "Payment method [{$method->name}] ({$method->method_type}) is not available offline. Cash only.",
                ]);
            }
        }
    }

    /** Server-side line resolution. STANDARD lines NEVER trust request price/tax — resolved from catalog/config. */
    private function resolveLines(array $lines, Branch $branch): array
    {
        return array_map(function ($line) use ($branch) {
            $product = Product::on('tenant')->with('unit')->findOrFail($line['product_id']);
            $variant = $this->inventory->resolveVariant($product, $line['product_variant_id'] ?? null);
            if (! $product->is_sellable || ! $product->is_pos_visible || $product->status !== 'active') {
                throw new RuntimeException($product->name . ' is not available for POS sale.');
            }
            $qty = (float) $line['quantity'];
            // H6: ignore any submitted unit_price/tax on a standard line — server is the price authority.
            $price = $this->pricing->resolveSellingPrice($product, $variant, $branch->id, null);
            $disc = (float) ($line['discount_amount'] ?? 0);
            $tax = $this->pricing->resolveTaxAmount($product, $qty, $price, $disc, null);

            return [
                '_product' => $product, '_variant' => $variant, 'product_id' => $product->id,
                'category_id' => $product->category_id, 'quantity' => $qty, 'unit_price' => $price,
                'discount_amount' => $disc, 'tax_amount' => $tax, 'line_kind' => 'standard',
                'modifiers' => $line['modifiers'] ?? null,
            ];
        }, $lines);
    }
}
