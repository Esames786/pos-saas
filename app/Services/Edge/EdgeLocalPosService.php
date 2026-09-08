<?php

namespace App\Services\Edge;

use App\Exceptions\SaleIdempotencyConflictException;
use App\Models\Tenant\Branch;
use App\Models\Tenant\KotBatch;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Combo;
use App\Models\Tenant\Product;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\RestaurantWaiter;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
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
    private const OFFLINE_ORDER_TYPES = ['quick_sale', 'takeaway', 'delivery'];

    /** Order types a HELD (open-check) workflow exists for offline — dine_in additionally requires a table session. */
    private const OFFLINE_HELD_ORDER_TYPES = ['quick_sale', 'takeaway', 'dine_in', 'delivery'];

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly ShiftService $shiftService,
        private readonly SalePricingService $pricing,
        private readonly SalesTotalsService $totals,
        private readonly InventoryService $inventory,           // resolveVariant() only
        private readonly EdgeOperationalStockService $opStock,
        private readonly SaleIdempotencyService $idempotency,
        private readonly SaleOperationalSettlementService $settlement,
        private readonly \App\Services\Printing\PrintJobService $printJobs,
        private readonly \App\Services\Sales\KotCancellationService $kotCancellations,
        private readonly \App\Services\Sales\SalesService $salesService, // closeRestaurantTableSession ONLY (everything else is fenced on branch_server)
        private readonly EdgeSyncOutboxService $outbox,     // OFFLINE-SYNC-ENGINE-1B: same-txn envelope
    ) {
    }

    /**
     * (1B test seam — same audit rationale as beforeSaleTransaction) Called INSIDE the finalizing
     * transaction AFTER sale/lines/payments/operational-stock/settlement and BEFORE the outbox
     * envelope insert. Production body is an unconditional no-op; only a test subclass can override
     * it, so failure-atomicity tests can prove the WHOLE sale transaction (including the envelope)
     * commits or rolls back as one.
     */
    protected function beforeOutboxInsert(): void
    {
    }

    /**
     * (2C test seam — AUDIT NOTE) Called AFTER pre-flight validation and BEFORE the sale transaction opens.
     * Safety review: it is `protected` (not public API), its production body is an UNCONDITIONAL no-op, no
     * request/env/config value can select behaviour, and nothing binds a callback into it at runtime — the
     * only way to change it is a subclass, which the production container never registers (the service is
     * resolved by its own class name). It exists solely so TOCTOU tests can deterministically mutate state
     * (deactivate cashier/terminal) inside the preflight→transaction window that the in-transaction
     * revalidation must catch. It cannot alter production business behaviour.
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

        // (B) The sale principal MUST be the authenticated local cashier — an authenticated tenant session is
        // REQUIRED (a bare User model is not authority), and it must be the same user. A caller can neither
        // sell unauthenticated nor attribute the sale to another branch-authorized user. (Manager re-auth is
        // a SEPARATE identity for manager actions.)
        $this->requireAuthorizedPrincipal($user, $branchId);
        $terminal = $this->requireActiveTerminal($terminalId, $branchId);

        // (D) Only order types whose offline workflow exists this sprint — AND the user must be allowed it.
        $orderType = (string) ($data['order_type'] ?? 'quick_sale');
        if (! in_array($orderType, self::OFFLINE_ORDER_TYPES, true)) {
            throw ValidationException::withMessages(['order_type' => "Order type [{$orderType}] is not yet available on the Branch Server."]);
        }
        if (! $user->allowsOrderType($orderType)) {
            throw ValidationException::withMessages(['order_type' => "Order type [{$orderType}] is not allowed for this user."]);
        }

        // (C) Manual discount / promo code ride the SHARED SalesTotalsService (same calculation order as Cloud);
        // a manual discount is gated by the branch approval mode and its manager approval is CONSUMED inside
        // the transaction with the same payload binding as Cloud POST /pos. Never a silent price reduction.
        $this->assertDiscountFieldsValid($data);
        // PHASE 2b parity (canonical 0d41617): a Quick Sale REQUIRES a vehicle number AND a waiter — the SAME
        // server-side rule as the Cloud POS (required_if), so an offline quick sale carries identical attribution.
        $this->assertQuickSaleAttribution($orderType, $data);
        // DELIVERY parity (canonical SalesOrderController::store): channel required; own delivery needs a
        // customer, an aggregator channel owns its customer; rider optional; charge follows the branch lock rule.
        $delivery = $this->resolveDeliveryAttribution($orderType, $data, $branch, null);

        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => (float) ($l['quantity'] ?? 0) > 0));
        $payments = array_values(array_filter($data['payments'] ?? [], fn ($p) => (float) ($p['amount'] ?? 0) > 0));
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'A sale needs at least one line.']);
        }
        if (! $payments) {
            throw ValidationException::withMessages(['payments' => 'A paid sale needs at least one payment.']);
        }
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
            return DB::connection('tenant')->transaction(function () use ($data, $user, $branch, $branchId, $terminal, $activationEpoch, $orderType, $lines, $payments, $clientUuid, $payloadHash, $delivery) {
                // Race-safe: a concurrent request may already have finalized this key.
                if ($winner = $this->idempotency->findFinalized($clientUuid)) {
                    return $this->replayOrConflict($winner, $payloadHash);
                }

                // (2C) Revalidate the principal + terminal INSIDE the transaction against fresh rows — a
                // cashier/terminal deactivated (or a principal swapped) after preflight must still be refused.
                [$user, $terminal] = $this->revalidateInTxn($user, $branchId, $terminal->id);

                // Lock + revalidate the open shift for THIS terminal in the same transaction (TOCTOU-safe vs close).
                $shift = $this->shiftService->lockOpenShiftForTerminal($terminal);
                $businessDate = $shift->business_date->toDateString();

                // Deals expand server-side from the synced combo book (header carries the bundle price, components 0).
                $resolved = $this->resolveLines($lines, $branch);
                $totals = $this->totals->calculate(
                    resolvedLines: $resolved,
                    discountType: (string) ($data['discount_type'] ?? 'none'),
                    discountValue: (float) ($data['discount_value'] ?? 0),
                    branchId: $branchId,
                    orderType: $orderType,
                    promoCode: $data['promo_code'] ?? null,
                    tipAmount: 0,
                    deliveryCharge: $delivery['charge'],
                );
                $this->consumeManualDiscountApproval($totals, $data, $branch, $user, 0, $clientUuid);
                $customer = $this->resolveHeldSaleCustomer($data, null);

                $paidAmount = array_sum(array_map(fn ($p) => (float) $p['amount'], $payments));
                if ($paidAmount + 1e-6 < (float) $totals['grand_total']) {
                    throw ValidationException::withMessages(['payments' => 'Paid amount is less than the sale total.']);
                }
                // Same grounded change rule as Cloud finalizePaidSale: tendered − applied per payment.
                $changeTotal = array_sum(array_map(function ($p) {
                    $tendered = isset($p['tendered_amount']) && $p['tendered_amount'] !== null ? (float) $p['tendered_amount'] : null;

                    return $tendered !== null ? max($tendered - (float) $p['amount'], 0) : 0;
                }, $payments));

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
                    // VEHICLE-NUMBER-1: quick-sale (drive-through) capture — offline parity with Cloud.
                    'vehicle_number' => $this->vehicleNumberFor($orderType, $data),
                    // PHASE 2b parity: a quick sale may carry its own waiter (validated against the bound branch).
                    'restaurant_waiter_id' => $this->waiterIdFor($orderType, $data, $branchId),
                    'customer_id' => $customer['id'],
                    'customer_name' => $customer['name'],
                    'customer_phone' => $customer['phone'],
                    'delivery_channel_id' => $delivery['channel_id'],
                    'delivery_rider_id' => $delivery['rider_id'],
                    'delivery_address' => $delivery['address'],
                    'delivery_charge_amount' => (float) ($totals['delivery_charge_amount'] ?? 0),
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
                    'change_amount' => max($changeTotal, max($paidAmount - (float) $totals['grand_total'], 0)),
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

                $this->createSaleLines($sale, $resolved, []);
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

                // OFFLINE-SYNC-ENGINE-1B: the immutable sync envelope is part of THIS transaction —
                // a paid offline sale cannot commit without its outbox row (and vice versa).
                $this->beforeOutboxInsert();
                $this->outbox->createForPaidSale($sale->fresh());

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
    /**
     * Discount request validity — the same vocabulary the Cloud POS validates (none/fixed/percent, non-negative,
     * percent ≤ 100). Whether a manual discount is ALLOWED is decided by the branch approval mode when the totals
     * are known (consumeManualDiscountApproval). Line-level discounts remain a Cloud-only input.
     */
    private function assertDiscountFieldsValid(array $data): void
    {
        $type = (string) ($data['discount_type'] ?? 'none');
        $value = (float) ($data['discount_value'] ?? 0);
        if (! in_array($type, ['none', 'fixed', 'percent'], true)) {
            throw ValidationException::withMessages(['discount_type' => 'Discount type must be none, fixed or percent.']);
        }
        if ($value < 0 || ($type === 'percent' && $value > 100)) {
            throw ValidationException::withMessages(['discount_value' => 'The discount value is out of range.']);
        }
        if ($type === 'none' && $value > 0) {
            throw ValidationException::withMessages(['discount_type' => 'A discount value needs a discount type.']);
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
            // The commercial intent the client controls: discount request, promo code, customer, delivery attribution.
            'discount_type' => (string) ($data['discount_type'] ?? 'none'),
            'discount_value' => round((float) ($data['discount_value'] ?? 0), 2),
            'promo_code' => $data['promo_code'] ?? null,
            'customer_id' => isset($data['customer_id']) && $data['customer_id'] !== '' ? (int) $data['customer_id'] : null,
            'delivery' => $orderType === 'delivery' ? [
                'channel_id' => $data['delivery_channel_id'] ?? null,
                'rider_id' => $data['delivery_rider_id'] ?? null,
                'address' => $data['delivery_address'] ?? null,
                'charge' => $data['delivery_charge_amount'] ?? null,
            ] : null,
            'lines' => array_map(fn ($l) => [
                'product_id' => $l['product_id'] ?? null,
                'product_variant_id' => $l['product_variant_id'] ?? null,
                'combo_id' => $l['combo_id'] ?? null,
                'line_kind' => ! empty($l['combo_id']) ? 'deal' : 'standard',
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

    /** (B) The principal MUST be the authenticated local cashier and be branch-authorized (preflight + in-txn). */
    /**
     * ONLINE-POS PARITY — Preview Bill: the running bill computed on the SAME server-side sale/totals truth,
     * with ZERO mutation (no sale, payment, stock movement, outbox, KOT, receipt, or Cloud call). Read-only.
     */
    public function previewBill(array $data, User $user, ?int $terminalId): array
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $this->requireAuthorizedPrincipal($user, $branchId);
        $this->requireActiveTerminal($terminalId, $branchId);
        $branch = Branch::on('tenant')->findOrFail($branchId);
        $orderType = (string) ($data['order_type'] ?? 'quick_sale');

        $resolved = $this->resolveLines($data['lines'] ?? [], $branch);
        // The preview shows the SAME total the payment will charge — including the delivery charge under the
        // branch lock rule (validation of channel/customer happens at payment; the preview only prices).
        $deliveryCharge = $orderType === 'delivery' && empty($data['restaurant_table_session_id'])
            ? ($branch->delivery_charge_locked ? (float) $branch->default_delivery_charge : max((float) ($data['delivery_charge_amount'] ?? 0), 0.0))
            : 0.0;
        $totals = $this->totals->calculate(
            resolvedLines: $resolved,
            discountType: (string) ($data['discount_type'] ?? 'none'),
            discountValue: (float) ($data['discount_value'] ?? 0),
            branchId: $branchId,
            orderType: $orderType,
            promoCode: $data['promo_code'] ?? null,
            tipAmount: 0,
            deliveryCharge: $deliveryCharge,
        );

        return ['order_type' => $orderType, 'lines' => $resolved, 'totals' => $totals];
    }

    private function requireAuthorizedPrincipal(User $user, int $branchId): void
    {
        $authUser = auth('tenant')->user();
        if (! $authUser) {
            throw ValidationException::withMessages(['user' => 'A local sale requires an authenticated Edge cashier session.']);
        }
        if ((int) $authUser->id !== (int) $user->id) {
            throw ValidationException::withMessages(['user' => 'The sale principal must be the authenticated cashier.']);
        }
        if (! EdgeUserAuthz::isActive($user) || ! EdgeUserAuthz::isEdgeLoginEligible($user) || ! EdgeUserAuthz::mayOperateBranch($user, $branchId)) {
            throw ValidationException::withMessages(['user' => 'This user is not authorized to sell on this Branch Server.']);
        }
    }

    /**
     * ONLINE-POS PARITY — the customer for a held sale: the request's explicit customer wins; otherwise a
     * SEATED reservation on this session's table carries its customer onto the order. Returns [id, name, phone].
     */
    private function resolveHeldSaleCustomer(array $data, ?RestaurantTableSession $session): array
    {
        if (array_key_exists('customer_id', $data) || array_key_exists('customer_name', $data) || array_key_exists('customer_phone', $data)) {
            return [
                'id' => isset($data['customer_id']) && $data['customer_id'] !== '' ? (int) $data['customer_id'] : null,
                'name' => $data['customer_name'] ?? null,
                'phone' => $data['customer_phone'] ?? null,
            ];
        }
        if ($session) {
            $r = \App\Models\Edge\EdgeTableReservation::on('tenant')
                ->where('restaurant_table_session_id', $session->id)
                ->where('status', \App\Models\Edge\EdgeTableReservation::STATUS_SEATED)->latest('id')->first();
            if ($r) {
                return ['id' => $r->customer_id !== null ? (int) $r->customer_id : null, 'name' => $r->customer_name, 'phone' => $r->customer_phone];
            }
        }

        return ['id' => null, 'name' => null, 'phone' => null];
    }

    private function requireActiveTerminal(?int $terminalId, int $branchId): Terminal
    {
        $terminal = Terminal::on('tenant')->where('id', (int) $terminalId)->where('branch_id', $branchId)->where('status', 'active')->first();
        if (! $terminal) {
            throw ValidationException::withMessages(['terminal_id' => 'Select an active terminal on this branch.']);
        }

        return $terminal;
    }

    /** (2C) Fresh-row revalidation INSIDE a write transaction. @return array{0: User, 1: Terminal} */
    /**
     * VEHICLE-NUMBER-1: quick-sale-only drive-through capture, trimmed and length-capped to the
     * column width. Other order types always persist NULL (never a stale carried-over value).
     */
    /**
     * OFFLINE quick-sale WAITER attribution (canonical PHASE 2b, 0d41617): a quick sale may carry its own
     * waiter; dine-in inherits the session's. The waiter must be an ACTIVE waiter of the BOUND branch — a
     * foreign or inactive id is refused, never silently dropped. Requiring it offline is a Batch-2 decision
     * (docs/status/edge-canonical-gap-2026-08-23.md #17); the CAPTURE here is canonical-equivalent.
     */
    /** Canonical PHASE 2b rule: quick_sale => vehicle_number + restaurant_waiter_id are REQUIRED (takeaway carries neither). */
    private function assertQuickSaleAttribution(string $orderType, array $data): void
    {
        if ($orderType !== 'quick_sale') {
            return;
        }
        if (trim((string) ($data['vehicle_number'] ?? '')) === '') {
            throw ValidationException::withMessages(['vehicle_number' => 'A Quick Sale requires a vehicle number.']);
        }
        if (empty($data['restaurant_waiter_id'])) {
            throw ValidationException::withMessages(['restaurant_waiter_id' => 'A Quick Sale requires a waiter.']);
        }
    }

    private function waiterIdFor(string $orderType, array $data, int $branchId): ?int
    {
        if ($orderType !== 'quick_sale' || empty($data['restaurant_waiter_id'])) {
            return null;
        }
        $waiterId = (int) $data['restaurant_waiter_id'];
        $ok = RestaurantWaiter::on('tenant')->where('id', $waiterId)->where('branch_id', $branchId)->where('status', 'active')->exists();
        if (! $ok) {
            throw ValidationException::withMessages(['restaurant_waiter_id' => 'The selected waiter is not an active waiter of this branch.']);
        }

        return $waiterId;
    }

    private function vehicleNumberFor(string $orderType, array $data): ?string
    {
        if ($orderType !== 'quick_sale') {
            return null;
        }
        $value = trim((string) ($data['vehicle_number'] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 50);
    }

    private function revalidateInTxn(User $user, int $branchId, int $terminalId): array
    {
        $user = User::on('tenant')->find($user->id);
        $authId = auth('tenant')->id();
        if (! $user || ! $authId || (int) $authId !== (int) $user->id) {
            throw ValidationException::withMessages(['user' => 'The sale principal must be the authenticated cashier.']);
        }
        $this->requireAuthorizedPrincipal($user, $branchId);

        return [$user, $this->requireActiveTerminal($terminalId, $branchId)];
    }

    // ═══════════════════════════════ EDGE-LOCAL-POS-1 — restaurant layer ═══════════════════════════════
    //
    // Reuses the CLOUD restaurant semantics with the frozen identity contracts: session_uuid on the table
    // session; durable sale_uuid across held→revise→settle (line/payment rows churn — proven in
    // EDGE-IDENTITY); KOT business events through the REAL PrintJobService::queueKot (kot_batches +
    // kot_batch_lines with event_uuid / kot_line_uuid / source_line_uuid). NO print transport runs on the
    // appliance this sprint: the KOT BUSINESS EVENT's sent bookkeeping is acknowledged explicitly
    // (applyKotSentBookkeeping) while every print_jobs row stays a queued logical intent — nothing may
    // claim `printed` until a real transport exists. Held orders NEVER touch operational stock or
    // settlement — both happen exactly once at settle, mirroring "inventory posts at finalize".
    //
    // CANONICAL LOCK ORDER for every restaurant mutation (documented once, enforced everywhere):
    //     shift row → table / table-session row → sale row → dependent rows (lines/payments/ledger)
    //   open-table: shift → table;  hold/Add Round: shift → session → sale;  settle: shift → session →
    //   sale (ids discovered by a non-locking probe first);  session close paths lock the session then
    //   take LOCKING (current) reads of remaining held work, so a concurrent hold — which always holds
    //   the session lock (a dine-in revision REQUIRES its session) — serializes against the close.
    //   Invariant: a session is never closed (and its table never freed) while a held order survives.

    /** Open a dine-in table session (Cloud RestaurantTableSessionController::open semantics). */
    public function openTableSession(int $tableId, array $data, User $user, ?int $terminalId): RestaurantTableSession
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $this->requireAuthorizedPrincipal($user, $branchId);
        $terminal = $this->requireActiveTerminal($terminalId, $branchId);
        if (! $user->allowsOrderType('dine_in')) {
            throw ValidationException::withMessages(['order_type' => 'Dine-in is not allowed for this user.']);
        }

        $waiterId = isset($data['restaurant_waiter_id']) && $data['restaurant_waiter_id'] !== null ? (int) $data['restaurant_waiter_id'] : null;
        if ($waiterId !== null) {
            $waiter = RestaurantWaiter::on('tenant')->where('id', $waiterId)->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))->first();
            if (! $waiter) {
                throw ValidationException::withMessages(['restaurant_waiter_id' => 'Select an active waiter on this branch.']);
            }
        }
        $guests = (int) ($data['guest_count'] ?? 1);
        if ($guests < 1 || $guests > 100) {
            throw ValidationException::withMessages(['guest_count' => 'Guest count must be between 1 and 100.']);
        }

        return DB::connection('tenant')->transaction(function () use ($user, $branchId, $terminal, $tableId, $waiterId, $guests, $data) {
            [$user, $terminal] = $this->revalidateInTxn($user, $branchId, $terminal->id);
            // Cloud lock order: shift FIRST, then the table row.
            $shift = $this->shiftService->lockOpenShiftForTerminal($terminal);
            $table = RestaurantTable::on('tenant')->where('id', $tableId)->where('branch_id', $branchId)
                ->where('status', '!=', 'inactive')->lockForUpdate()->first();
            if (! $table) {
                throw ValidationException::withMessages(['table' => 'Select an active table on this branch.']);
            }
            // LOCKING (current) read — under REPEATABLE READ a plain exists() reads this transaction's
            // snapshot and can MISS a session another terminal committed after our snapshot but before we
            // acquired the table lock (proven by the two-process same-table race). The locking read sees
            // the current committed state and gap-locks the index, so exactly one open ever wins.
            $hasOpen = RestaurantTableSession::on('tenant')->where('restaurant_table_id', $table->id)
                ->whereIn('status', ['open', 'bill_requested'])->lockForUpdate()->exists();
            if ($hasOpen) {
                throw new RuntimeException('Table already has an open session.');
            }

            // ONE pre-minted ULID: the canonical session identity, with the display number derived from it.
            $sessionUlid = (string) Str::ulid();
            $session = new RestaurantTableSession([
                'session_no' => 'TS-' . $branchId . '-' . $sessionUlid,
                'branch_id' => $branchId,
                'restaurant_table_id' => $table->id,
                'restaurant_waiter_id' => $waiterId,
                'opened_by_user_id' => $user->id,
                'opened_shift_id' => $shift->id,
                'business_date' => $shift->business_date->toDateString(),
                'guest_count' => $guests,
                'status' => 'open',
                'opened_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);
            $session->session_uuid = $sessionUlid; // not mass-assignable — frozen identity, set directly
            $session->save();

            $table->update(['status' => 'occupied']);

            // ONLINE-POS PARITY: opening a RESERVED table seats its reservation and carries the customer onto
            // this session, so the first held sale inherits it (see the held-sale customer resolution below).
            \App\Models\Edge\EdgeTableReservation::on('tenant')
                ->where('restaurant_table_id', $table->id)
                ->where('status', \App\Models\Edge\EdgeTableReservation::STATUS_ACTIVE)
                ->lockForUpdate()->latest('id')->first()
                ?->update(['status' => \App\Models\Edge\EdgeTableReservation::STATUS_SEATED, 'restaurant_table_session_id' => $session->id, 'seated_at' => now()]);

            return $session->fresh();
        });
    }

    /**
     * Create a HELD sale or revise it (Add Round) — Cloud HeldSaleController::store semantics: status
     * stays `held`, shift_id/business_date preserved on revise, lines delete+recreate with the KOT-sent
     * state carried over, PER-LINE CAPTURED PRICE (a carried line keeps its stored unit_price; only NEW
     * lines price from the catalog — the server never trusts a submitted price), and reducing a line
     * below its kitchen-sent quantity REQUIRES an explicit void with reason (+ manager approval when the
     * branch demands it) through the REAL KotCancellationService. No stock, no settlement, no payments.
     */
    public function holdOrReviseSale(array $data, User $user, ?int $terminalId): SalesOrder
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $activationEpoch = (int) $meta->activation_epoch;
        $branch = Branch::on('tenant')->findOrFail($branchId);
        $this->requireAuthorizedPrincipal($user, $branchId);
        $terminal = $this->requireActiveTerminal($terminalId, $branchId);

        $orderType = (string) ($data['order_type'] ?? 'quick_sale');
        if (! in_array($orderType, self::OFFLINE_HELD_ORDER_TYPES, true)) {
            throw ValidationException::withMessages(['order_type' => "Order type [{$orderType}] is not yet available on the Branch Server."]);
        }
        if (! $user->allowsOrderType($orderType)) {
            throw ValidationException::withMessages(['order_type' => "Order type [{$orderType}] is not allowed for this user."]);
        }
        $this->assertDiscountFieldsValid($data);
        // PHASE 2b parity (canonical 0d41617): a Quick Sale REQUIRES a vehicle number AND a waiter — the SAME
        // server-side rule as the Cloud POS (required_if), so an offline quick sale carries identical attribution.
        $this->assertQuickSaleAttribution($orderType, $data);
        // POS-DRAFT-1 offline parity (canonical 0b5df5a): every save writes the current intent — a normal
        // Hold clears a draft; save_as_draft parks the held sale WITHOUT a kitchen ticket (server-enforced below).
        $isDraft = (bool) ($data['save_as_draft'] ?? false);
        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => (float) ($l['quantity'] ?? 0) > 0));
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'A held order needs at least one line.']);
        }
        return DB::connection('tenant')->transaction(function () use ($data, $user, $branch, $branchId, $terminal, $activationEpoch, $orderType, $lines, $isDraft) {
            [$user, $terminal] = $this->revalidateInTxn($user, $branchId, $terminal->id);
            $shift = $this->shiftService->lockOpenShiftForTerminal($terminal);

            // ── table-session resolution (dine_in REQUIRES one; explicit session forces dine_in) ──
            $session = null;
            if (! empty($data['restaurant_table_session_id'])) {
                $session = RestaurantTableSession::on('tenant')->where('id', (int) $data['restaurant_table_session_id'])
                    ->where('branch_id', $branchId)->whereIn('status', ['open', 'bill_requested'])->lockForUpdate()->first();
                if (! $session) {
                    throw ValidationException::withMessages(['restaurant_table_session_id' => 'No open table session found.']);
                }
                $orderType = 'dine_in';
            }
            if ($orderType === 'dine_in' && ! $session) {
                throw ValidationException::withMessages(['restaurant_table_session_id' => 'Dine-in requires an open table session — open the table first.']);
            }
            // The check keeps the SESSION's frozen business date (Add Round never rolls it forward).
            $businessDate = $session?->business_date?->toDateString() ?? $shift->business_date->toDateString();

            if (! empty($data['held_sale_id'])) {
                return $this->reviseHeldSale($data, $user, $branch, $branchId, $shift, $session, $lines);
            }

            // ── new held sale (one open check per session) — LOCKING read: a snapshot exists() could
            //    miss a check committed by another terminal after this transaction's snapshot. ──
            if ($session && SalesOrder::on('tenant')->where('restaurant_table_session_id', $session->id)->where('status', 'held')->lockForUpdate()->exists()) {
                throw new RuntimeException('This table already has an open order — continue it (Add Round) instead of starting another.');
            }

            $delivery = $this->resolveDeliveryAttribution($orderType, $data, $branch, $session);
            $resolved = $this->resolveLines($lines, $branch);
            $totals = $this->totals->calculate(
                resolvedLines: $resolved,
                discountType: (string) ($data['discount_type'] ?? 'none'),
                discountValue: (float) ($data['discount_value'] ?? 0),
                branchId: $branchId,
                orderType: $orderType,
                promoCode: $data['promo_code'] ?? null,
                tipAmount: 0,
                deliveryCharge: $delivery['charge'],
            );
            $this->consumeManualDiscountApproval($totals, $data, $branch, $user, 0, null);

            // ONLINE-POS PARITY: the request's customer wins; otherwise a seated reservation on this session's
            // table carries its customer onto the order (matching "open reserved table -> customer on order").
            $customer = $this->resolveHeldSaleCustomer($data, $session);

            $saleUlid = (string) Str::ulid();
            $sale = new SalesOrder([
                'sale_no' => 'SO-' . $branchId . '-' . $terminal->id . '-' . $saleUlid,
                'branch_id' => $branchId,
                'terminal_id' => $terminal->id,
                'shift_id' => $shift->id,
                'business_date' => $businessDate,
                'order_source' => 'pos',
                'order_type' => $orderType,
                'vehicle_number' => $this->vehicleNumberFor($orderType, $data),
                'sale_date' => now(),
                'subtotal' => $totals['subtotal'],
                'discount_type' => (string) ($data['discount_type'] ?? 'none'),
                'discount_value' => (float) ($data['discount_value'] ?? 0),
                'discount_amount' => $totals['discount_amount'],
                'promotion_id' => $totals['promotion_id'],
                'promo_code' => $totals['promo_code'],
                'tax_amount' => $totals['tax_amount'],
                'service_charge_amount' => $totals['service_charge_amount'],
                'delivery_channel_id' => $delivery['channel_id'],
                'delivery_rider_id' => $delivery['rider_id'],
                'delivery_address' => $delivery['address'],
                'delivery_charge_amount' => (float) ($totals['delivery_charge_amount'] ?? 0),
                'tip_amount' => 0,
                'grand_total' => $totals['grand_total'],
                'paid_amount' => 0, 'change_amount' => 0,
                'status' => 'held',
                'is_draft' => $isDraft,
                'created_by_user_id' => $user->id,
                'customer_id' => $customer['id'],
                'customer_name' => $customer['name'],
                'customer_phone' => $customer['phone'],
                'restaurant_table_session_id' => $session?->id,
                'restaurant_table_id' => $session?->restaurant_table_id,
                'restaurant_floor_id' => $session?->table?->restaurant_floor_id,
                'restaurant_waiter_id' => $session?->restaurant_waiter_id ?? $this->waiterIdFor($orderType, $data, $branchId),
                'inventory_posted' => false,
                'edge_sync_state' => 'pending',
                'edge_activation_epoch' => $activationEpoch,
            ]);
            $sale->sale_uuid = $saleUlid;
            $sale->save();
            $this->createSaleLines($sale, $resolved, []);

            return $sale->fresh();
        });
    }

    /** Add Round / revision of an existing held sale — runs INSIDE holdOrReviseSale's transaction. */
    private function reviseHeldSale(array $data, User $user, Branch $branch, int $branchId, Shift $shift, ?RestaurantTableSession $session, array $lines): SalesOrder
    {
        $sale = SalesOrder::on('tenant')->where('id', (int) $data['held_sale_id'])
            ->where('branch_id', $branchId)->where('status', 'held')->lockForUpdate()->first();
        if (! $sale) {
            throw ValidationException::withMessages(['held_sale_id' => 'No held sale found to revise.']);
        }
        // LOCK-ORDER GUARD: a dine-in check may only be revised with its session supplied (and therefore
        // LOCKED, shift → session → sale) — otherwise a revision could run unserialized against a
        // concurrent session close. A mismatched or omitted session is refused, never inferred.
        if ((int) $sale->restaurant_table_session_id !== (int) ($session?->id ?? 0)) {
            throw ValidationException::withMessages(['restaurant_table_session_id' => 'This held sale belongs to a different table session — submit its own session.']);
        }

        // LOCKING (current) read of the lines — a stale snapshot here is how a conflicting Add Round
        // could silently overwrite another terminal's committed round (proven by the two-process race):
        // the loser must see the CURRENT lines, fail the belongs-to check, and be refused.
        $existing = $sale->lines()->lockForUpdate()->get()->keyBy('id');
        $seenOldIds = [];
        foreach ($lines as $l) {
            if (empty($l['sales_order_line_id'])) {
                continue;
            }
            $oldId = (int) $l['sales_order_line_id'];
            if (! $existing->has($oldId)) {
                throw ValidationException::withMessages(['lines' => 'A submitted line does not belong to this order.']);
            }
            // (captured-price authority) an old line id is NOT a reusable cheap-price token.
            if (isset($seenOldIds[$oldId])) {
                throw ValidationException::withMessages(['lines' => 'The same order line was submitted more than once.']);
            }
            $seenOldIds[$oldId] = true;
        }

        // ── resolve FIRST (a deal line expands server-side into header + components), carrying the captured
        //    price of every named line and linking each resolved row to the old row it continues ──
        $resolved = [];
        foreach ($lines as $l) {
            $oldId = ! empty($l['sales_order_line_id']) ? (int) $l['sales_order_line_id'] : null;
            foreach ($this->resolveLines([$l], $branch, $oldId !== null) as $r) {
                if ($oldId !== null && ($r['line_kind'] ?? 'standard') !== 'component') {
                    // The stored captured price belongs to ONE economic identity — a carried line id whose
                    // product/variant/kind differs from the original is refused outright (it could otherwise
                    // inherit a cheaper captured price, and its KOT-sent state would be nonsense anyway).
                    $old = $existing->get($oldId);
                    if ((int) $old->product_id !== (int) $r['product_id']
                        || (int) ($old->product_variant_id ?? 0) !== (int) ($r['_variant']?->id ?? 0)
                        || (string) $old->line_kind !== (string) $r['line_kind']) {
                        throw ValidationException::withMessages(['lines' => 'A carried line no longer matches its original product/variant — submit the change as a new line.']);
                    }
                    // captured price: the round the guest ordered at keeps its price even if the catalog moved.
                    $price = (float) $old->unit_price;
                    $r['unit_price'] = $price;
                    $r['tax_amount'] = $this->pricing->resolveTaxAmount($r['_product'], (float) $r['quantity'], $price, 0.0, null);
                    $r['_old_line_id'] = $oldId;
                } elseif ($oldId !== null) {
                    // component of a CARRIED deal: continue the old component row of the same deal/product/variant
                    // so its kitchen-sent state carries (a deal's parts are never resent as new food).
                    $oldComponent = $existing->first(fn ($x) => (int) $x->parent_sales_order_line_id === $oldId
                        && (int) $x->product_id === (int) $r['product_id']
                        && (int) ($x->product_variant_id ?? 0) === (int) ($r['_variant']?->id ?? 0));
                    $r['_old_line_id'] = $oldComponent?->id;
                } else {
                    $r['_old_line_id'] = null;
                }
                $resolved[] = $r;
            }
        }

        // ── implicit-cancellation detection (Cloud rule): reducing below the KITCHEN-SENT quantity
        //    requires an explicit matching void entry — silent shrinkage of sent food is refused.
        //    Quantities are the RESOLVED ones (a deal's components scale with the deal). ──
        $newQtyByLineId = [];
        foreach ($resolved as $r) {
            if (! empty($r['_old_line_id'])) {
                $newQtyByLineId[$r['_old_line_id']] = ($newQtyByLineId[$r['_old_line_id']] ?? 0) + (float) $r['quantity'];
            }
        }
        $voidByLineId = collect($data['void_items'] ?? [])->keyBy(fn ($v) => (int) ($v['old_line_id'] ?? 0));
        $detected = [];
        foreach ($existing as $line) {
            $sent = (float) $line->kot_sent_quantity;
            if ($sent <= 0) {
                continue;
            }
            $newQty = (float) ($newQtyByLineId[$line->id] ?? 0);
            $cancelQty = $sent - min($newQty, $sent);
            if ($cancelQty <= 0) {
                continue;
            }
            $void = $voidByLineId->get($line->id);
            if (! $void || abs((float) ($void['quantity'] ?? 0) - $cancelQty) > 1e-6 || empty($void['reason_id'])) {
                throw ValidationException::withMessages(['void_items' => "Reducing [{$line->product_name}] below its kitchen-sent quantity requires a void with a reason" . ($cancelQty > 0 ? " (qty {$cancelQty})" : '') . '.']);
            }
            $detected[] = [
                'line_id' => $line->id, 'quantity' => $cancelQty,
                'reason_id' => (int) $void['reason_id'],
                'manager_approval_id' => isset($void['manager_approval_id']) ? (int) $void['manager_approval_id'] : null,
            ];
        }
        if ($detected) {
            // REAL Cloud service: permission + (branch-mode) manager approval consumption + cancel-KOT
            // business event + sales_order_line_cancellations rows with the frozen snapshot identities.
            $this->kotCancellations->recordLineCancellations($sale, $detected, (int) $user->id);
        }

        // ── KOT-sent carry-over, then Cloud's delete+recreate churn. The discount travels with the check: a
        //    revision may set/keep/clear it; a NEW manual discount consumes its manager approval (branch mode). ──
        $kotSentByLineId = $existing->map(fn ($l) => (float) $l->kot_sent_quantity);
        $discountType = array_key_exists('discount_type', $data) ? (string) ($data['discount_type'] ?? 'none') : (string) ($sale->discount_type ?? 'none');
        $discountValue = array_key_exists('discount_value', $data) ? (float) ($data['discount_value'] ?? 0) : (float) $sale->discount_value;
        $promoCode = array_key_exists('promo_code', $data) ? ($data['promo_code'] ?: null) : $sale->promo_code;
        $totals = $this->totals->calculate(
            resolvedLines: $resolved,
            discountType: $discountType,
            discountValue: $discountValue,
            branchId: $branchId,
            orderType: (string) $sale->order_type,
            promoCode: $promoCode,
            tipAmount: 0,
            deliveryCharge: (float) ($sale->delivery_charge_amount ?? 0),
        );
        $discountChanged = $discountType !== (string) ($sale->discount_type ?? 'none') || abs($discountValue - (float) $sale->discount_value) > 0.0001;
        if ($discountChanged) {
            $this->consumeManualDiscountApproval($totals, ['discount_type' => $discountType, 'discount_value' => $discountValue] + $data, $branch, $user, (int) $sale->id, null);
        }

        $sale->lines()->delete();
        $sale->update([
            // shift_id + business_date are FROZEN at first hold; a revision never rolls them forward.
            'is_draft' => (bool) ($data['save_as_draft'] ?? false),
            // Dine-in keeps its session waiter; a quick sale may (re)attribute its own, else keep the stored one.
            'restaurant_waiter_id' => $session?->restaurant_waiter_id
                ?? (((string) $sale->order_type === 'quick_sale' && ! empty($data['restaurant_waiter_id'])) ? $this->waiterIdFor((string) $sale->order_type, $data, $branchId) : $sale->restaurant_waiter_id),
            'subtotal' => $totals['subtotal'],
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $totals['discount_amount'],
            'promotion_id' => $totals['promotion_id'],
            'promo_code' => $totals['promo_code'],
            'tax_amount' => $totals['tax_amount'],
            'service_charge_amount' => $totals['service_charge_amount'],
            'grand_total' => $totals['grand_total'],
        ]);
        $this->createSaleLines($sale, $resolved, $kotSentByLineId->all());

        return $sale->fresh();
    }

    /**
     * Create the sale's line rows from resolved entries. A deal is written the way Cloud writes it: the header
     * row (bundle price, product_name = deal name, line_kind combo_header, combo_id) first, then its component
     * rows (price 0, line_kind component, combo_id, parent_sales_order_line_id → the header) — so KOT deal
     * identity, receipt name-only, report identity and stock (components only) all follow the shared truth.
     *
     * @param array<int,float> $kotSentByLineId sent quantities of the PRE-churn lines, keyed by old line id
     */
    private function createSaleLines(SalesOrder $sale, array $resolved, array $kotSentByLineId): void
    {
        $headerIds = []; // expansion key → created header line id
        foreach ($resolved as $r) {
            $qty = (float) $r['quantity'];
            $price = (float) $r['unit_price'];
            $sentQty = min((float) ($kotSentByLineId[$r['_old_line_id'] ?? 0] ?? 0), $qty);
            $kind = (string) ($r['line_kind'] ?? 'standard');
            $line = $sale->lines()->create([
                'product_id' => $r['product_id'],
                'product_name' => $r['_line_name'] ?? $r['_product']->name,
                'product_variant_id' => $r['_variant']?->id,
                'line_kind' => $kind,
                'combo_id' => $r['combo_id'] ?? null,
                'parent_sales_order_line_id' => isset($r['_component_of']) ? ($headerIds[$r['_component_of']] ?? null) : null,
                'quantity' => $qty, 'unit_price' => $price, 'unit_cost' => 0, 'cost_total' => 0,
                'discount_amount' => (float) $r['discount_amount'], 'tax_amount' => (float) $r['tax_amount'],
                'line_total' => $qty * $price - (float) $r['discount_amount'] + (float) $r['tax_amount'],
                'modifiers' => $r['modifiers'] ?? null,
                'kot_sent' => $sentQty > 0 && $qty <= $sentQty,
                'kot_sent_quantity' => $sentQty,
            ]);
            if ($kind === 'combo_header' && isset($r['_key'])) {
                $headerIds[$r['_key']] = (int) $line->id;
            }
        }
    }

    /**
     * Record the KOT BUSINESS EVENT for a held sale's unsent delta through the REAL Cloud pipeline
     * (kot_batches sequence/event_type + kot_batch_lines with kot_line_uuid + source_line_uuid snapshots,
     * logical_key idempotency, sale-row lock serialization inside queueKot).
     *
     * LOCKED RULE — business event ≠ physical print: NO transport runs on the appliance this sprint, so
     * nothing here may claim `printed`. After the batch commits, the SENT bookkeeping is advanced exactly
     * once via the shared applyKotSentBookkeeping (idempotent set-to-quantity — the same rule the network
     * path applies at queue time), while the print_jobs row remains a durable logical intent: `queued`,
     * printed_at NULL, no Print Agent invoked. EDGE-LOCAL-PRINT-1 will deliver these intents.
     * @return array{batch: ?KotBatch, jobs: array<int, \App\Models\Tenant\PrintJob>}
     */
    public function queueKotEvents(int $saleId, User $user, ?int $terminalId): array
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $this->requireAuthorizedPrincipal($user, $branchId);
        $this->requireActiveTerminal($terminalId, $branchId);

        $sale = SalesOrder::on('tenant')->where('id', $saleId)->where('branch_id', $branchId)
            ->whereIn('status', ['held', 'paid'])->first();
        if (! $sale) {
            throw ValidationException::withMessages(['sale' => 'No held or paid sale found for KOT.']);
        }
        // POS-DRAFT-1 offline: Cloud skips the KOT in the browser; the Edge POS is API-driven, so the skip is
        // enforced HERE — a draft is parked without a kitchen ticket until it is held normally.
        if ((bool) $sale->is_draft) {
            throw ValidationException::withMessages(['sale' => 'This order is saved as a DRAFT — hold it normally to send its KOT.']);
        }

        $jobs = $this->printJobs->queueKot($sale);
        foreach ($jobs as $job) {
            $eventType = (string) data_get($job->payload, 'kot_event_type', 'normal');
            if ($job->document_type === 'kot' && ! in_array($eventType, ['cancel', 'duplicate'], true)) {
                // acknowledge the BUSINESS EVENT only — the job itself stays queued / not printed.
                $this->printJobs->applyKotSentBookkeeping($sale->fresh(), $job);
            }
        }
        $batch = KotBatch::on('tenant')->where('sales_order_id', $sale->id)->orderByDesc('sequence_no')->first();

        return ['batch' => $jobs ? $batch : null, 'jobs' => $jobs];
    }

    /** Which manager permission each offline approval action demands — unknown actions fail closed. */
    private const MANAGER_ACTION_PERMISSIONS = [
        // DISCOUNT parity: on Cloud any manager-PIN holder approves a manual discount; on the appliance the
        // manager is the branch-manager permission holder authenticating with THEIR OWN Edge credential.
        'manual_discount' => 'tenant.pos.void-kot-item',
        'void_kot_item' => 'tenant.pos.void-kot-item',
        'void_kot_items' => 'tenant.pos.void-kot-item',
        'cancel_held_order' => 'tenant.pos.void-kot-item',
    ];

    /**
     * Manager re-auth on the appliance: the manager authenticates with THEIR OWN Edge-local credential
     * (EdgeLocalAuthService::verifyManager — branch_server-only, bound-branch, current activation_epoch,
     * lockout + durable audit, required permission). manager_pins are NEVER consulted: PIN hashes are
     * deliberately excluded from the bootstrap, so the Cloud PIN path cannot work offline. The approval
     * row is minted by the SAME shared ManagerApprovalService creator Cloud verifyPin uses (identity,
     * payload binding, expiry + single-use consume unchanged). The cashier session stays the cashier
     * session — this is elevation for ONE action, never login-as-manager.
     */
    public function verifyManagerApproval(string $employeeCode, string $credential, string $actionType, User $requestingUser, ?array $payload = null): \App\Models\Tenant\ManagerApproval
    {
        $meta = $this->context->requireCurrent();
        $this->requireAuthorizedPrincipal($requestingUser, (int) $meta->branch_id);

        $permission = self::MANAGER_ACTION_PERMISSIONS[$actionType] ?? null;
        if ($permission === null) {
            throw ValidationException::withMessages(['action_type' => "No offline manager-approval contract exists for [{$actionType}]."]);
        }

        $manager = app(\App\Services\Edge\EdgeLocalAuthService::class)->verifyManager($employeeCode, $credential, $permission);

        return app(\App\Services\Sales\ManagerApprovalService::class)
            ->createApprovalForAuthenticatedManager($manager, $actionType, (int) $requestingUser->id, $payload);
    }

    /**
     * Settle a HELD sale with cash — the dine-in/open-check counterpart of completePaidSale. Same row
     * (durable sale_uuid), payments created, operational stock consumed ONCE for the final quantities,
     * shared settlement, and the table session closes exactly as Cloud payment does. client_uuid is
     * REQUIRED (idempotent retry); the held sale's own OPEN shift takes the cash (row-locked vs close).
     */
    public function settleHeldSale(int $heldSaleId, array $data, User $user, ?int $terminalId): SalesOrder
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $this->requireAuthorizedPrincipal($user, $branchId);
        $terminal = $this->requireActiveTerminal($terminalId, $branchId);

        $payments = array_values(array_filter($data['payments'] ?? [], fn ($p) => (float) ($p['amount'] ?? 0) > 0));
        if (! $payments) {
            throw ValidationException::withMessages(['payments' => 'A paid sale needs at least one payment.']);
        }
        $this->assertPaymentsOffline($payments);

        $clientUuid = $this->idempotency->normalizeClientUuid($data['client_uuid'] ?? null);
        if ($clientUuid === null) {
            throw ValidationException::withMessages(['client_uuid' => 'Settling a held sale requires a valid client_uuid for safe retries.']);
        }

        $preflight = SalesOrder::on('tenant')->where('id', $heldSaleId)->where('branch_id', $branchId)->first();
        if (! $preflight) {
            throw ValidationException::withMessages(['held_sale_id' => 'No held sale found to settle.']);
        }
        // Effective settle intent: the held sale's durable identity + payments (lines are already
        // server-authoritative rows — nothing about them is request-controlled here).
        $payloadHash = $this->idempotency->buildPayloadHash($this->idempotency->canonicalSalePayload([
            'branch_id' => $branchId, 'terminal_id' => $terminal->id, 'order_source' => 'pos',
            'order_type' => (string) $preflight->order_type,
            'held_sale_id' => $preflight->sale_uuid, // the durable identity, never the local PK
            'restaurant_table_session_id' => $preflight->restaurant_table_session_id,
            'discount_type' => 'none', 'discount_value' => 0, 'promo_code' => null,
            'payments' => array_map(fn ($p) => ['payment_method_id' => $p['payment_method_id'] ?? null, 'amount' => $p['amount'] ?? null], $payments),
        ]));
        if ($existing = $this->idempotency->findFinalized($clientUuid)) {
            return $this->replayOrConflict($existing, $payloadHash);
        }

        $this->beforeSaleTransaction(); // shared 2C seam — no-op in production

        try {
            return DB::connection('tenant')->transaction(function () use ($heldSaleId, $user, $branchId, $terminal, $payments, $clientUuid, $payloadHash) {
                if ($winner = $this->idempotency->findFinalized($clientUuid)) {
                    return $this->replayOrConflict($winner, $payloadHash);
                }
                [$user, $terminal] = $this->revalidateInTxn($user, $branchId, $terminal->id);

                // ── CANONICAL RESTAURANT LOCK ORDER: shift → table-session → sale (matches open-table,
                // hold and Add Round, so no two restaurant mutations acquire these rows in opposite
                // order). The sale's ids are discovered with a non-locking read first, then each row is
                // locked in order and re-verified under its lock. ──
                $probe = SalesOrder::on('tenant')->where('id', $heldSaleId)->where('branch_id', $branchId)
                    ->where('status', 'held')->first();
                if (! $probe) {
                    throw ValidationException::withMessages(['held_sale_id' => 'No held sale found to settle.']);
                }

                // The check's OWN shift takes the cash (it is still open — a held sale blocks its close);
                // row-lock it so a concurrent close cannot interleave with the counter increments.
                $shift = Shift::on('tenant')->where('id', (int) $probe->shift_id)->lockForUpdate()->first();
                if (! $shift || $shift->status !== 'open') {
                    throw new RuntimeException('The shift this check belongs to is not open.');
                }
                if ($probe->restaurant_table_session_id) {
                    RestaurantTableSession::on('tenant')->where('id', (int) $probe->restaurant_table_session_id)
                        ->lockForUpdate()->first();
                }

                $sale = SalesOrder::on('tenant')->where('id', $heldSaleId)->where('branch_id', $branchId)
                    ->where('status', 'held')->lockForUpdate()->first();
                if (! $sale || (int) $sale->shift_id !== (int) $shift->id) {
                    // settled/cancelled (or impossibly re-homed) while we acquired locks — fail closed.
                    throw ValidationException::withMessages(['held_sale_id' => 'No held sale found to settle.']);
                }
                // LOCKING (current) read of the lines: the stock consumed MUST be the committed final
                // rounds, never this transaction's stale snapshot of them (phantom-read hazard).
                $currentLines = $sale->lines()->lockForUpdate()->get();

                $paidAmount = array_sum(array_map(fn ($p) => (float) $p['amount'], $payments));
                if ($paidAmount + 1e-6 < (float) $sale->grand_total) {
                    throw ValidationException::withMessages(['payments' => 'Paid amount is less than the sale total.']);
                }

                $sale->payments()->delete();
                foreach ($payments as $p) {
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

                $changeTotal = array_sum(array_map(function ($payment) {
                    $tendered = isset($payment['tendered_amount']) && $payment['tendered_amount'] !== null
                        ? (float) $payment['tendered_amount']
                        : null;

                    return $tendered !== null
                        ? max($tendered - (float) $payment['amount'], 0)
                        : 0;
                }, $payments));

                $sale->update([
                    'client_uuid' => $clientUuid,
                    'client_payload_hash' => $payloadHash,
                    'paid_amount' => $paidAmount,
                    'change_amount' => max($changeTotal, max($paidAmount - (float) $sale->grand_total, 0)),
                    'status' => 'paid',
                    // A paid order is never a draft — same finalization rule as Cloud SalesService::finalizePaidSale.
                    'is_draft' => false,
                    'completed_at' => now(),
                ]);

                // Operational stock: the FINAL quantities, exactly once, at settle (never during rounds).
                // The sale carries the LOCKED current lines (loadMissing respects a set relation).
                $saleForStock = $sale->fresh();
                $saleForStock->setRelation('lines', $currentLines);
                $this->opStock->consumeForSale($saleForStock, $user->id);
                $sale->update(['edge_operational_stock_posted' => true]);

                // Shared operational settlement + the shared "payment settles the table" custody rule.
                $this->settlement->settle($sale->fresh());
                $this->salesService->closeRestaurantTableSession($sale->fresh());

                // OFFLINE-SYNC-ENGINE-1B: ONE outbox row for the FINAL settled sale (never for holds
                // or Add Rounds) — inside this same transaction, after every operational effect.
                $this->beforeOutboxInsert();
                $this->outbox->createForPaidSale($sale->fresh());

                return $sale->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
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
     * Cancel a whole held order (REAL KotCancellationService — permission + branch approval mode + cancel-KOT
     * event, which frees the table). POS-CANCEL-TERMINAL-1 parity (canonical 22ad93e): the cancellation
     * KOT/reminder print where the OPERATOR STANDS — the current cancelling counter — while the order itself
     * keeps its original terminal_id for reporting (the shared service never rewrites it).
     */
    public function cancelHeldSale(int $saleId, int $reasonId, ?int $managerApprovalId, User $user, ?int $terminalId = null): array
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $this->requireAuthorizedPrincipal($user, $branchId);
        $terminal = $terminalId !== null ? $this->requireActiveTerminal($terminalId, $branchId) : null;

        $sale = SalesOrder::on('tenant')->where('id', $saleId)->where('branch_id', $branchId)->where('status', 'held')->first();
        if (! $sale) {
            throw ValidationException::withMessages(['held_sale_id' => 'No held sale found to cancel.']);
        }

        return $this->kotCancellations->cancelHeldOrder($sale, $reasonId, $managerApprovalId, (int) $user->id, $terminal ? (string) $terminal->id : null);
    }

    /**
     * SPLIT BILL parity (canonical SplitBillController::store): move selected quantities off a HELD check onto a
     * NEW HELD check on the same table session — same customer, waiter, business_date, sale_date (SALE-DATE-TRUTH),
     * terminal and shift — pro-rating discount / tax / service charge, carrying the kitchen-sent state (the split
     * portion was already cooked: no re-KOT, no spurious "cancellation required"), and recalculating the parent.
     * Each check then pays on its own (settleHeldSale → operational stock ONCE per check, ONE outbox row per settled
     * sale, exactly-once by its own sale_uuid); the table frees when the LAST check settles (shared custody rule).
     * Lock order: shift → session → sale (the restaurant order used everywhere else on the appliance).
     *
     * @return array{child: SalesOrder, parent: SalesOrder}
     */
    public function splitHeldSale(int $saleId, array $lines, User $user, ?int $terminalId, ?string $notes = null): array
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $activationEpoch = (int) $meta->activation_epoch;
        $this->requireAuthorizedPrincipal($user, $branchId);
        $terminal = $this->requireActiveTerminal($terminalId, $branchId);

        $selected = collect($lines)->filter(fn ($l) => ! empty($l['sales_order_line_id']) && (float) ($l['quantity'] ?? 0) > 0)->values();
        if ($selected->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Select at least one item quantity to split.']);
        }

        return DB::connection('tenant')->transaction(function () use ($saleId, $selected, $user, $branchId, $terminal, $activationEpoch, $notes) {
            [$user] = $this->revalidateInTxn($user, $branchId, $terminal->id);

            $probe = SalesOrder::on('tenant')->where('id', $saleId)->where('branch_id', $branchId)->where('status', 'held')->first();
            if (! $probe) {
                throw ValidationException::withMessages(['held_sale_id' => 'Only held sales can be split.']);
            }
            $shift = Shift::on('tenant')->where('id', (int) $probe->shift_id)->lockForUpdate()->first();
            if (! $shift || $shift->status !== 'open') {
                throw new RuntimeException('The shift this check belongs to is not open.');
            }
            if ($probe->restaurant_table_session_id) {
                RestaurantTableSession::on('tenant')->where('id', (int) $probe->restaurant_table_session_id)->lockForUpdate()->first();
            }
            $sale = SalesOrder::on('tenant')->where('id', $saleId)->where('status', 'held')->lockForUpdate()->first();
            if (! $sale) {
                throw ValidationException::withMessages(['held_sale_id' => 'Only held sales can be split.']);
            }
            $existing = $sale->lines()->lockForUpdate()->get()->keyBy('id');

            $childUlid = (string) Str::ulid();
            $child = new SalesOrder([
                'sale_no' => 'SO-' . $branchId . '-' . (int) $sale->terminal_id . '-' . $childUlid,
                'branch_id' => $branchId,
                'terminal_id' => $sale->terminal_id,                 // the check's own counter (never the splitter's)
                'shift_id' => $sale->shift_id,
                'customer_id' => $sale->customer_id,
                'customer_name' => $sale->customer_name,
                'customer_phone' => $sale->customer_phone,
                'customer_email' => $sale->customer_email,
                'restaurant_floor_id' => $sale->restaurant_floor_id,
                'restaurant_table_id' => $sale->restaurant_table_id,
                'restaurant_table_session_id' => $sale->restaurant_table_session_id,
                'restaurant_waiter_id' => $sale->restaurant_waiter_id,
                'order_source' => $sale->order_source,
                'order_type' => $sale->order_type,
                'vehicle_number' => $sale->vehicle_number,
                'delivery_channel_id' => $sale->delivery_channel_id,
                'delivery_rider_id' => $sale->delivery_rider_id,
                'delivery_address' => $sale->delivery_address,
                // SALE-DATE-TRUTH-1 / business date: the child IS the same check — it inherits when the food was ordered.
                'sale_date' => $sale->sale_date ?? now(),
                'business_date' => $sale->business_date?->toDateString() ?? $shift->business_date?->toDateString(),
                'subtotal' => 0, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
                'tax_amount' => 0, 'service_charge_amount' => 0, 'delivery_charge_amount' => 0, 'tip_amount' => 0,
                'grand_total' => 0, 'paid_amount' => 0, 'change_amount' => 0,
                'status' => 'held',
                'is_draft' => false,
                'inventory_posted' => false,
                'created_by_user_id' => $user->id,
                'notes' => $notes ?: ('Split from ' . $sale->sale_no),
                'edge_sync_state' => 'pending',
                'edge_activation_epoch' => $activationEpoch,
            ]);
            $child->sale_uuid = $childUlid;
            $child->save();

            $subtotal = 0.0; $discountTotal = 0.0; $taxTotal = 0.0; $grandTotal = 0.0;
            foreach ($selected as $input) {
                $heldLine = $existing->get((int) $input['sales_order_line_id']);
                if (! $heldLine) {
                    throw ValidationException::withMessages(['lines' => 'A split line does not belong to this order.']);
                }
                $splitQty = (float) $input['quantity'];
                $availableQty = (float) $heldLine->quantity;
                if ($splitQty > $availableQty + 0.0001) {
                    throw new RuntimeException('Split quantity exceeds available quantity for ' . $heldLine->product_name . '.');
                }
                $ratio = $availableQty > 0 ? $splitQty / $availableQty : 0;
                $splitDiscount = round((float) $heldLine->discount_amount * $ratio, 2);
                $splitTax = round((float) $heldLine->tax_amount * $ratio, 2);
                $splitSubtotal = round($splitQty * (float) $heldLine->unit_price, 2);
                $splitLineTotal = max($splitSubtotal - $splitDiscount + $splitTax, 0);

                $child->lines()->create([
                    'product_id' => $heldLine->product_id,
                    'product_variant_id' => $heldLine->product_variant_id,
                    'product_name' => $heldLine->product_name,
                    'variant_name' => $heldLine->variant_name,
                    'quantity' => $splitQty,
                    'unit_price' => $heldLine->unit_price,
                    'unit_cost' => 0, 'cost_total' => 0,
                    'discount_amount' => $splitDiscount,
                    'tax_amount' => $splitTax,
                    'line_total' => $splitLineTotal,
                    'modifiers' => $heldLine->modifiers ?? [],
                    'unit_code' => $heldLine->unit_code,
                    'line_kind' => $heldLine->line_kind ?? 'standard',
                    'combo_id' => $heldLine->combo_id,
                    'kitchen_note' => $heldLine->kitchen_note,
                    // already sent as part of the original order — paying it later must not re-KOT it.
                    'kot_sent' => (float) $heldLine->kot_sent_quantity > 0,
                    'kot_sent_quantity' => min((float) $heldLine->kot_sent_quantity, $splitQty),
                ]);
                $subtotal += $splitSubtotal; $discountTotal += $splitDiscount; $taxTotal += $splitTax; $grandTotal += $splitLineTotal;

                $remainingQty = $availableQty - $splitQty;
                if ($remainingQty <= 0.0001) {
                    $heldLine->delete();
                } else {
                    $remainingRatio = $remainingQty / $availableQty;
                    $heldLine->update([
                        'quantity' => $remainingQty,
                        'discount_amount' => round((float) $heldLine->discount_amount * $remainingRatio, 2),
                        'tax_amount' => round((float) $heldLine->tax_amount * $remainingRatio, 2),
                        'line_total' => round((float) $heldLine->line_total * $remainingRatio, 2),
                        'kot_sent_quantity' => min((float) $heldLine->kot_sent_quantity, $remainingQty),
                    ]);
                }
            }
            if ($child->lines()->count() === 0) {
                throw new RuntimeException('No valid split lines found.');
            }
            // service charge pro-rated by subtotal against the original check (canonical BUG-048 rule).
            $origSubtotal = (float) $sale->subtotal;
            $origSC = (float) $sale->service_charge_amount;
            $childSC = ($origSubtotal > 0 && $origSC > 0) ? round($origSC * ($subtotal / $origSubtotal), 2) : 0.0;
            $child->update([
                'subtotal' => $subtotal, 'discount_amount' => $discountTotal, 'tax_amount' => $taxTotal,
                'service_charge_amount' => $childSC, 'grand_total' => $grandTotal + $childSC,
            ]);
            $this->recalculateHeldSaleAfterSplit($sale, $origSubtotal, $origSC);

            return ['child' => $child->fresh(), 'parent' => $sale->fresh()];
        });
    }

    /**
     * Parent after a split — EXACTLY canonical SplitBillController::recalculateHeldSale: totals re-summed from the
     * remaining lines (grand_total = Σ line_total); an emptied parent is cancelled. Cloud and Edge must land on the
     * same numbers for the same split, so no Edge-side re-pro-rating is added here.
     */
    private function recalculateHeldSaleAfterSplit(SalesOrder $sale, float $origSubtotal, float $origSC): void
    {
        $lines = $sale->lines()->get();
        if ($lines->isEmpty()) {
            $sale->update(['subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 0, 'status' => 'cancelled']);

            return;
        }
        $sale->update([
            'subtotal' => round((float) $lines->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_price), 2),
            'discount_amount' => round((float) $lines->sum('discount_amount'), 2),
            'tax_amount' => round((float) $lines->sum('tax_amount'), 2),
            'grand_total' => round((float) $lines->sum('line_total'), 2),
        ]);
    }

    /** Close/cancel a table session with NO remaining open orders (Cloud close semantics) and free the table. */
    public function closeTableSession(int $sessionId, string $targetStatus, User $user): RestaurantTableSession
    {
        $meta = $this->context->requireCurrent();
        $branchId = (int) $meta->branch_id;
        $this->requireAuthorizedPrincipal($user, $branchId);
        if (! in_array($targetStatus, ['closed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => 'A table session can only be closed or cancelled.']);
        }

        return DB::connection('tenant')->transaction(function () use ($sessionId, $branchId, $targetStatus, $user) {
            $session = RestaurantTableSession::on('tenant')->where('id', $sessionId)->where('branch_id', $branchId)->lockForUpdate()->first();
            if (! $session || in_array($session->status, ['closed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['session' => 'No open table session found.']);
            }
            // LOCKING (current) read — a concurrent hold on this session serializes against the close
            // instead of slipping past the transaction snapshot (same rule as closeRestaurantTableSession).
            if (SalesOrder::on('tenant')->where('restaurant_table_session_id', $session->id)->whereIn('status', ['draft', 'held'])->lockForUpdate()->exists()) {
                throw new RuntimeException('This table still has open orders — settle or cancel them first.');
            }
            $session->update(['status' => $targetStatus, 'closed_by_user_id' => $user->id, 'closed_at' => now()]);
            // EDGE-CONFIG-REFRESH-1: never resurrect a tombstoned table — if a config refresh removed
            // this table while the session was open, closing must leave it 'inactive', not 'available'.
            RestaurantTable::on('tenant')->where('id', $session->restaurant_table_id)
                ->where('status', '!=', 'inactive')->update(['status' => 'available']);

            return $session->fresh();
        });
    }

    /**
     * Server-side line resolution. STANDARD lines NEVER trust request price/tax — resolved from catalog/config.
     *
     * HIDDEN-PRODUCT-HELD-BILL-1 parity (canonical cba7e09): a product hidden/deactivated AFTER it landed on
     * an open bill must keep that bill recallable AND payable. A CARRIED line (named by its existing line id
     * on Add Round) is therefore re-resolved for identity/tax only and never refused for visibility; a NEW
     * line still has to be live on the menu.
     */
    private function resolveLines(array $lines, Branch $branch, bool $carriedFromOpenBill = false): array
    {
        $out = [];
        foreach (array_values($lines) as $i => $line) {
            if (! empty($line['combo_id'])) {
                // DEAL parity: the client names the deal + quantity ONLY; topology and prices come from the
                // synced combo book (never a client-supplied header/component price).
                foreach ($this->expandCombo((int) $line['combo_id'], (float) ($line['quantity'] ?? 0), $branch, 'deal-' . $i . '-' . (int) $line['combo_id']) as $entry) {
                    $out[] = $entry;
                }
                continue;
            }
            $product = Product::on('tenant')->with('unit')->findOrFail($line['product_id']);
            $variant = $this->inventory->resolveVariant($product, $line['product_variant_id'] ?? null);
            if (! $carriedFromOpenBill && (! $product->is_sellable || ! $product->is_pos_visible || $product->status !== 'active')) {
                throw new RuntimeException($product->name . ' is not available for POS sale.');
            }
            $qty = (float) $line['quantity'];
            // H6: ignore any submitted unit_price/tax on a standard line — server is the price authority.
            $price = $this->pricing->resolveSellingPrice($product, $variant, $branch->id, null);
            $disc = (float) ($line['discount_amount'] ?? 0);
            $tax = $this->pricing->resolveTaxAmount($product, $qty, $price, $disc, null);

            $out[] = [
                '_product' => $product, '_variant' => $variant, 'product_id' => $product->id,
                'category_id' => $product->category_id, 'quantity' => $qty, 'unit_price' => $price,
                'discount_amount' => $disc, 'tax_amount' => $tax, 'line_kind' => 'standard',
                'modifiers' => $line['modifiers'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Expand a deal the way the current Online POS sells it: ONE header row (the first component's product as
     * the header product, product_name = the deal name, the bundle price, line_kind combo_header, combo_id) and
     * one component row per bundled item (price 0, line_kind component, quantity × deal quantity, combo_id).
     * Components are exempt from the grid-visibility rule (they are deliberately not POS-visible); the deal
     * itself must be active and on this branch.
     */
    private function expandCombo(int $comboId, float $qty, Branch $branch, string $key): array
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages(['lines' => 'A deal needs a quantity.']);
        }
        $combo = Combo::on('tenant')->with('components')->where('id', $comboId)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->first();
        if (! $combo || $combo->components->isEmpty()) {
            throw new RuntimeException('This deal is not available for POS sale.');
        }
        $components = $combo->components->sortBy('sort_order')->values();
        $headerProduct = Product::on('tenant')->with('unit')->findOrFail((int) $components->first()->product_id);
        $price = (float) $combo->price;

        $entries = [[
            '_product' => $headerProduct, '_variant' => null, 'product_id' => (int) $headerProduct->id,
            'category_id' => $headerProduct->category_id, 'quantity' => $qty, 'unit_price' => $price,
            'discount_amount' => 0.0, 'tax_amount' => $this->pricing->resolveTaxAmount($headerProduct, $qty, $price, 0.0, null),
            'line_kind' => 'combo_header', 'combo_id' => (int) $combo->id, '_line_name' => (string) $combo->name,
            '_key' => $key, 'modifiers' => null,
        ]];
        foreach ($components as $c) {
            $product = Product::on('tenant')->with('unit')->findOrFail((int) $c->product_id);
            $variant = $this->inventory->resolveVariant($product, $c->product_variant_id);
            $entries[] = [
                '_product' => $product, '_variant' => $variant, 'product_id' => (int) $product->id,
                'category_id' => $product->category_id, 'quantity' => round((float) $c->quantity * $qty, 3),
                'unit_price' => 0.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0,
                'line_kind' => 'component', 'combo_id' => (int) $combo->id, '_component_of' => $key, 'modifiers' => null,
            ];
        }

        return $entries;
    }

    /**
     * Manual discount gate — the same contract as Cloud POST /pos: a manual discount above 0.009 needs a manager
     * approval unless the branch auto-approves; the approval is CONSUMED (single use, payload-bound) inside the
     * sale transaction. On the appliance the approval is minted by verifyManagerApproval('manual_discount').
     */
    private function consumeManualDiscountApproval(array $totals, array $data, Branch $branch, User $user, int $saleId, ?string $clientUuid): void
    {
        $manual = (float) ($totals['manual_discount_amount'] ?? 0);
        if ($manual <= 0.009) {
            return;
        }
        $mode = $branch->manual_discount_approval_mode ?? Branch::MANUAL_DISCOUNT_MANAGER_REQUIRED;
        if ($mode === Branch::MANUAL_DISCOUNT_AUTO_APPROVE) {
            return;
        }
        if (empty($data['manager_approval_id'])) {
            throw ValidationException::withMessages(['manager_approval_id' => 'Manager approval is required for a manual discount.']);
        }
        $approval = \App\Models\Tenant\ManagerApproval::on('tenant')->find((int) $data['manager_approval_id']);
        if (! $approval) {
            throw ValidationException::withMessages(['manager_approval_id' => 'Manager approval was not found.']);
        }
        try {
            app(\App\Services\Sales\ManagerApprovalService::class)->consume($approval, 'manual_discount', (int) $user->id, [
                'sales_order_id' => $saleId,
                'branch_id' => (int) $branch->id,
                'client_uuid' => (string) ($clientUuid ?? ''),
                'discount_type' => (string) ($data['discount_type'] ?? 'none'),
                'discount_value' => round((float) ($data['discount_value'] ?? 0), 2),
                'discount_amount' => round($manual, 2),
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['manager_approval_id' => $e->getMessage()]);
        }
    }

    /**
     * DELIVERY parity (canonical SalesOrderController::store): a delivery order (without a table session) needs
     * an active channel; an own-delivery channel needs a customer from the (synced) book while an AGGREGATOR
     * channel owns its customer (AGGREGATOR-CUSTOMER-OPTIONAL); the rider is optional and must be active on this
     * branch; the charge follows the branch lock (locked → configured default, else the submitted amount).
     *
     * @return array{channel_id:?int, rider_id:?int, address:?string, charge:float}
     */
    private function resolveDeliveryAttribution(string $orderType, array $data, Branch $branch, ?RestaurantTableSession $session): array
    {
        $none = ['channel_id' => null, 'rider_id' => null, 'address' => null, 'charge' => 0.0];
        if ($orderType !== 'delivery' || $session) {
            return $none;
        }
        $channel = ! empty($data['delivery_channel_id'])
            ? \App\Models\Tenant\DeliveryChannel::on('tenant')->where('id', (int) $data['delivery_channel_id'])->where('is_active', true)->first()
            : null;
        if (! $channel) {
            throw ValidationException::withMessages(['delivery_channel_id' => 'Select a delivery channel for delivery orders.']);
        }
        if ($channel->type !== 'aggregator' && empty($data['customer_id'])) {
            throw ValidationException::withMessages(['customer_id' => 'Attach a customer before saving a delivery order.']);
        }
        if (! empty($data['customer_id']) && ! \App\Models\Tenant\Customer::on('tenant')->where('id', (int) $data['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Select a customer from the customer book.']);
        }
        $riderId = null;
        if (! empty($data['delivery_rider_id'])) {
            $rider = \App\Models\Tenant\DeliveryRider::on('tenant')->where('id', (int) $data['delivery_rider_id'])->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->first();
            if (! $rider) {
                throw ValidationException::withMessages(['delivery_rider_id' => 'Select an active rider on this branch.']);
            }
            $riderId = (int) $rider->id;
        }
        $charge = $branch->delivery_charge_locked
            ? (float) $branch->default_delivery_charge
            : (float) ($data['delivery_charge_amount'] ?? 0);

        return [
            'channel_id' => (int) $channel->id,
            'rider_id' => $riderId,
            'address' => isset($data['delivery_address']) && trim((string) $data['delivery_address']) !== '' ? mb_substr(trim((string) $data['delivery_address']), 0, 500) : null,
            'charge' => max($charge, 0.0),
        ];
    }
}
