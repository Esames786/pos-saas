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
    /**
     * W6 (0.7.0-edge): v1 = every pre-W6 sale shape (+ additive optional keys: tip, line discounts, notes);
     * v2 = a sale with a stock-consuming modifier — the ONLY shape whose modifier linked stock is posted here.
     * v1 semantics stay FROZEN (a v1 envelope never consumes modifier stock, exactly as before).
     */
    public const ENVELOPE_SCHEMA_V1 = 'edge-sale-envelope-v1';
    public const ENVELOPE_SCHEMA_V2 = 'edge-sale-envelope-v2';

    /**
     * EDGE PHASE 4 — v3 ⊇ v2 (modifier stock consumed alike) + the `new_customer` kind: a customer / address born on the
     * appliance, resolved here by the deterministic create-or-match (resolveCustomer). The kind is accepted on v3 ONLY
     * (ENVELOPE_INVALID otherwise); an older Cloud answers SCHEMA_UNSUPPORTED to v3 and the appliance parks the row.
     */
    public const ENVELOPE_SCHEMA_V3 = 'edge-sale-envelope-v3';

    /**
     * EDGE 0.9 CONTINUITY (stream A) — v4 ⊇ v3 + the continuity keys: `shift.continues_cloud_shift_uuid` (the sale is attached to
     * that ORIGINAL Cloud shift and settled into its counters exactly like a Cloud POS sale — one shift per counter) and/or
     * `adoption` (the sale IS an open Cloud order adopted from the live-state mirror, same sale_uuid: the EXISTING order is
     * completed, never a second one; any change of that order after the mirror revision is REFUSED, never guessed).
     */
    public const ENVELOPE_SCHEMA_V4 = 'edge-sale-envelope-v4';

    /** Continuity verdicts answered as the terminal `conflict` shape (see continuityConflict). */
    public const CONTINUITY_CONFLICT_CODES = ['ADOPTED_ORDER_CONFLICT', 'ADOPTED_ORDER_UNKNOWN', 'ADOPTED_ORDER_UNSUPPORTED', 'ADOPTED_LINES_DIVERGED', 'CONTINUED_SHIFT_UNKNOWN', 'CONTINUED_SHIFT_MISMATCH'];
    private const SUPPORTED_ORDER_TYPES = ['quick_sale', 'takeaway', 'dine_in', 'delivery'];
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
        if (! in_array((string) ($envelope['envelope_schema_version'] ?? ''), $this->supportedEnvelopeSchemas(), true)) {
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
            // EDGE 0.9 CONTINUITY: the original shift / the adopted order must resolve and be UNCHANGED since the mirror —
            // otherwise a classified refusal (needs attention) before any mutation.
            $plan = $this->continuityPlan($envelope, $branch);
        } catch (IngestionRefusal $e) {
            if (in_array($e->refusalCode, self::CONTINUITY_CONFLICT_CODES, true)) {
                return $this->continuityConflict($envelope, $e->refusalCode, $e->getMessage());
            }

            return $this->refuse($envelope, $e->refusalCode, $e->getMessage());
        }

        // ── authoritative posting: one tenant transaction, under the per-branch ingestion authority ──
        try {
            return EdgeIngestionAuthority::run((int) $branch->id, function () use ($envelope, $device, $branch, $saleUuid, $contentHash, $plan) {
                return DB::connection('tenant')->transaction(function () use ($envelope, $device, $branch, $saleUuid, $contentHash, $plan) {
                    // Claim the sale_uuid atomically — the registry unique index is the concurrency guard.
                    $ingestionUuid = (string) Str::ulid();
                    $registry = $this->claimRegistry($envelope, $device, $branch, $contentHash, $ingestionUuid);

                    $continuity = null;
                    if ($plan['order_id'] !== null) {
                        // ADOPTED ORDER: complete the EXISTING Cloud order (re-verified under its row lock), never a second one.
                        [$sale, $keptByIndex] = $this->mergeAdoptedOrder($envelope, $branch, $device, (int) $plan['order_id']);
                        $this->projectLinesWithOfficialStock($envelope, $sale, $branch, $keptByIndex);
                        $continuity = ['adopted_order' => ['sales_order_id' => (int) $sale->id, 'kept_lines' => count($keptByIndex),
                            'appended_lines' => count((array) ($envelope['lines'] ?? [])) - count($keptByIndex)]];
                    } else {
                        $sale = $this->projectSale($envelope, $branch, $device);
                        $this->projectLinesWithOfficialStock($envelope, $sale, $branch);
                    }
                    $this->projectPayments($envelope, $sale);
                    if ($plan['cloud_shift_id'] !== null) {
                        $continuity = (array) $continuity + ['shift' => $this->attachContinuedShift($sale, (int) $plan['cloud_shift_id'], (string) $plan['cloud_shift_uuid'])];
                    } elseif ($plan['order_id'] !== null) {
                        // An adopted order paid in an Edge-only shift: its cash never reached a Cloud drawer — same as any Edge sale.
                        $sale->update(['shift_id' => null]);
                    }

                    // TEST-ONLY seam: fires AFTER official stock/COGS + payments, BEFORE finance posting, INSIDE
                    // the transaction. Production no-op; a subclass may throw here to prove the whole ingestion
                    // (sale + official stock + registry) rolls back as one — no half-posted official state.
                    $this->afterOfficialStock();

                    $sale->refresh()->load(['payments.method', 'lines']);

                    if (($continuity['shift']['status'] ?? null) === 'merged') {
                        // ONE SHIFT PER COUNTER: the continued sale gets the SAME operational settlement a Cloud POS sale gets
                        // (shift counters + operational sales ledger) — exactly once, inside this transaction (a replay is
                        // already_applied before it is reached).
                        app(\App\Services\Sales\SaleOperationalSettlementService::class)->settle($sale);
                    }
                    if ($plan['order_id'] !== null) {
                        // Payment settles the table — the SAME custody rule the Cloud POS and the appliance apply.
                        $this->sales->closeRestaurantTableSession($sale);
                    }

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
                    if ($continuity !== null) {
                        $ack['continuity'] = $continuity;   // audit: where the sale went (stored on the registry, replayed verbatim)
                    }
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
            // A domain refusal raised mid-posting — the transaction already rolled back (no sale/GL/stock/payment partials
            // survive). RELIABILITY (post-F2): a TERMINAL verdict (the identical immutable envelope can never be accepted)
            // answers `refused` WITH the envelope identity so the appliance parks the row as failed_permanent instead of
            // retrying forever; a retryable verdict (INSUFFICIENT_STOCK — 1E-gated) stays a deterministic `exception`.
            if (in_array($e->refusalCode, self::CONTINUITY_CONFLICT_CODES, true)) {
                return $this->continuityConflict($envelope, $e->refusalCode, $e->getMessage());   // moved under the lock
            }
            if (EdgeIngestionVerdicts::isTerminal($e->refusalCode)) {
                return $this->refuse($envelope, $e->refusalCode, $e->getMessage());
            }

            return $this->recordException($envelope, $contentHash, $e->refusalCode, $e->getMessage());
        } catch (Throwable $e) {
            return $this->recordException($envelope, $contentHash, 'INGEST_FAILED', $e->getMessage());
        }
    }

    /**
     * The envelope schemas this Cloud accepts. A method (not a constant) so a test can stand in an OLDER Cloud
     * (v1 only) and prove the Cloud-first deploy ordering: a v2 row refused SCHEMA_UNSUPPORTED is re-attempted
     * with the SAME content after the upgrade and applies exactly once.
     *
     * @return list<string>
     */
    protected function supportedEnvelopeSchemas(): array
    {
        return [self::ENVELOPE_SCHEMA_V1, self::ENVELOPE_SCHEMA_V2, self::ENVELOPE_SCHEMA_V3, self::ENVELOPE_SCHEMA_V4];
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

    /**
     * Resolve the customer identity or fail closed (walk-in is explicit; a customer travels by uuid). Runs INSIDE the
     * posting transaction (a refusal rolls everything back; nothing partial).
     *
     * PHASE 4 — DETERMINISTIC CREATE-OR-MATCH (docs/status/edge-phase4-offline-add-customer.md §3), in this order:
     *   kind `customer`      1. customer_uuid known → that customer;  2. known as an ALIAS → the canonical customer;
     *                        else CUSTOMER_UNKNOWN (never a phone guess for a kind that claims a synced identity).
     *   kind `new_customer`  (v3 only) 1. customer_uuid known → use it (a second sale of the same offline customer);
     *                        2. alias known → the canonical customer;
     *                        3. phone_normalized matches an EXISTING customer (row lock; CustomerDirectory's legacy spellings as
     *                           the fallback) → bind the sale to it, record `customer_uuid_aliases[offline_uuid → canonical]`,
     *                           fill ONLY empty name/email (never rename) — NO duplicate customer;
     *                        4. else CREATE the customer WITH the offline customer_uuid as its canonical identity.
     *                        In every branch the intent's addresses are created once by address_uuid (ensureAddresses).
     * Idempotent: a replay of the same envelope never reaches here (already_applied); a retry after a rolled-back attempt
     * re-runs the ladder and lands on step 1 or 2; two sales of one offline customer land on step 1/2 after the first.
     */
    private function resolveCustomerId(array $envelope, EdgeDevice $device): ?int
    {
        $customer = $envelope['customer'] ?? null;
        if (! is_array($customer) || ($customer['kind'] ?? null) === 'walk_in' || $customer === null) {
            return null; // walk-in: never invent a customer
        }
        $kind = (string) ($customer['kind'] ?? '');
        $uuid = (string) ($customer['customer_uuid'] ?? '');
        if (! EdgeIdentity::isValid($uuid, EdgeIdentity::FORMAT_ULID)) {
            throw new IngestionRefusal('CUSTOMER_INVALID', 'the customer identity is not a canonical customer_uuid');
        }

        // 1. the canonical identity itself.
        $row = Customer::on('tenant')->where('customer_uuid', $uuid)->lockForUpdate()->first();
        // 2. an offline identity this Cloud already matched.
        if (! $row) {
            $aliasCustomerId = \App\Models\Tenant\CustomerUuidAlias::on('tenant')->where('alias_uuid', $uuid)->value('customer_id');
            $row = $aliasCustomerId ? Customer::on('tenant')->whereKey((int) $aliasCustomerId)->lockForUpdate()->first() : null;
        }

        if ($kind !== EdgeSaleEnvelopeBuilder::CUSTOMER_KIND_NEW) {
            if ($kind !== EdgeSaleEnvelopeBuilder::CUSTOMER_KIND_CUSTOMER) {
                throw new IngestionRefusal('CUSTOMER_INVALID', "unknown customer kind [{$kind}]");
            }
            if (! $row) {
                // Fail closed (Sync design): never silently bind by phone or a local numeric id.
                throw new IngestionRefusal('CUSTOMER_UNKNOWN', 'the customer_uuid does not resolve in this tenant');
            }

            return (int) $row->id;
        }

        // ── kind new_customer (v3, and v4 ⊇ v3) ──
        if (! in_array((string) ($envelope['envelope_schema_version'] ?? ''), [self::ENVELOPE_SCHEMA_V3, self::ENVELOPE_SCHEMA_V4], true)) {
            throw new IngestionRefusal('ENVELOPE_INVALID', 'the new_customer kind requires ' . self::ENVELOPE_SCHEMA_V3);
        }
        $intent = $customer['new_customer'] ?? null;
        if (! is_array($intent) || trim((string) ($intent['name'] ?? '')) === '' && trim((string) ($intent['phone'] ?? '')) === '') {
            throw new IngestionRefusal('CUSTOMER_INVALID', 'the new_customer intent needs a name or a phone');
        }
        $phone = trim((string) ($intent['phone'] ?? ''));
        $phoneKey = \App\Support\PhoneNormalizer::normalize($phone);
        if ($phoneKey !== null && (string) ($intent['phone_normalized'] ?? $phoneKey) !== $phoneKey) {
            throw new IngestionRefusal('CUSTOMER_INVALID', 'phone_normalized does not match the shared normaliser for the given phone');
        }

        if (! $row && $phoneKey !== null) {
            // 3. an EXISTING Cloud customer owns this phone → alias, never a duplicate.
            $row = Customer::on('tenant')->where('phone_normalized', $phoneKey)->orderBy('id')->lockForUpdate()->first()
                ?? app(\App\Services\Tenant\CustomerDirectory::class)->findByPhone($phone, true);
            if ($row) {
                \App\Models\Tenant\CustomerUuidAlias::on('tenant')->create([
                    'alias_uuid' => $uuid,
                    'customer_id' => (int) $row->id,
                    'customer_uuid' => (string) $row->customer_uuid,
                    'source_device_uuid' => (string) $device->public_uuid,
                    'source' => \App\Models\Tenant\CustomerUuidAlias::SOURCE_PHONE_MATCH,
                ]);
                // Online quick-store rule: top up what is missing, never rename.
                $row->fill(array_filter([
                    'name' => $row->name ?: trim((string) ($intent['name'] ?? '')),
                    'email' => $row->email ?: ($intent['email'] ?? null),
                ]))->save();
                Log::info('[edge-sync-ingest] customer aliased by phone', ['alias_uuid' => $uuid, 'customer_id' => $row->id]);
            }
        }

        if (! $row) {
            // 4. a genuinely new customer: the offline identity BECOMES the canonical one (the only path allowed to set it).
            $row = new Customer([
                'code' => null,
                'name' => trim((string) ($intent['name'] ?? '')) !== '' ? mb_substr(trim((string) $intent['name']), 0, 190) : ($phone !== '' ? $phone : 'Customer'),
                'phone' => $phone !== '' ? app(\App\Services\Tenant\CustomerDirectory::class)->normalizePhone($phone) : null,
                'email' => isset($intent['email']) && is_string($intent['email']) && trim($intent['email']) !== '' ? mb_substr(trim($intent['email']), 0, 190) : null,
                'status' => 'active',
            ]);
            $row->customer_uuid = $uuid;   // preserved, never regenerated (immutable cross-system identity)
            $row->save();
            Log::info('[edge-sync-ingest] customer created from offline intent', ['customer_uuid' => $uuid, 'customer_id' => $row->id]);
        }

        $this->ensureAddresses($row, is_array($intent['addresses'] ?? null) ? $intent['addresses'] : []);

        return (int) $row->id;
    }

    /**
     * Create each intent address ONCE by address_uuid (idempotent across retries and across sales of the same customer).
     * Online's storeAddressOnce text rule applies too: an identical address already in the customer's book is reused (the
     * offline address_uuid then never exists on the Cloud; the appliance's refresh merges its local copy — applier rule).
     * The first address of a customer becomes the default; an intent `is_default` on a customer that already has one does
     * NOT demote the Cloud's default (the Cloud book is the authority on which address is the usual one).
     */
    private function ensureAddresses(Customer $customer, array $addresses): void
    {
        foreach ($addresses as $a) {
            if (! is_array($a)) {
                continue;
            }
            $addressUuid = (string) ($a['address_uuid'] ?? '');
            $text = trim(preg_replace('/\s+/', ' ', (string) ($a['address'] ?? '')) ?? '');
            if (! EdgeIdentity::isValid($addressUuid, EdgeIdentity::FORMAT_ULID) || $text === '') {
                throw new IngestionRefusal('CUSTOMER_INVALID', 'an intent address needs a canonical address_uuid and a non-empty address');
            }
            if (\App\Models\Tenant\CustomerAddress::on('tenant')->where('address_uuid', $addressUuid)->exists()) {
                continue;   // already created (retry / second sale)
            }
            $book = $customer->addresses()->lockForUpdate()->get();
            $same = $book->first(fn ($row) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $row->address) ?? '')) === mb_strtolower($text));
            if ($same) {
                continue;   // Online storeAddressOnce: identical text is the same address
            }
            $row = new \App\Models\Tenant\CustomerAddress([
                'customer_id' => (int) $customer->id,
                'label' => isset($a['label']) && is_string($a['label']) && trim($a['label']) !== '' ? mb_substr(trim($a['label']), 0, 50) : null,
                'address' => mb_substr($text, 0, 500),
                'is_default' => $book->isEmpty(),
            ]);
            $row->address_uuid = $addressUuid;   // preserved offline identity
            $row->save();
        }
    }

    // ── projection + official posting ───────────────────────────────────────────

    private function projectSale(array $envelope, Branch $branch, EdgeDevice $device): SalesOrder
    {
        $totals = $envelope['totals'] ?? [];
        $customerId = $this->resolveCustomerId($envelope, $device);

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
            // W6 (B5): the held-check / Direct-Pay note — additive optional key (older envelopes lack it => null).
            'notes' => isset($envelope['notes']) && is_string($envelope['notes']) && trim($envelope['notes']) !== '' ? mb_substr(trim($envelope['notes']), 0, 1000) : null,
            'order_source' => in_array((string) ($envelope['order_source'] ?? 'pos'), ['pos', 'manual'], true) ? (string) $envelope['order_source'] : 'pos', // enum(pos,manual); edge-origin marked by edge_sync_state
            'order_type' => (string) $envelope['order_type'],
            'sale_date' => $envelope['sale_date'] ?? now(),
            'business_date' => $envelope['business_date'] ?? null,
            'subtotal' => (float) ($totals['subtotal'] ?? 0),
            // DISCOUNT/PROMO/DELIVERY parity: the till's attribution is projected as-is (older envelopes lack the
            // keys and keep the previous defaults). The money itself is unchanged — frozen totals, never repriced.
            'discount_type' => in_array((string) ($totals['discount_type'] ?? 'none'), ['none', 'fixed', 'percent'], true) ? (string) ($totals['discount_type'] ?? 'none') : 'none',
            'discount_value' => (float) ($totals['discount_value'] ?? 0),
            'discount_amount' => (float) ($totals['discount_amount'] ?? 0),
            'promo_code' => $totals['promo_code'] ?? null,
            'tax_amount' => (float) ($totals['tax_amount'] ?? 0),
            'service_charge_amount' => (float) ($totals['service_charge_amount'] ?? 0),
            'delivery_charge_amount' => (float) ($totals['delivery_charge_amount'] ?? 0),
            'delivery_channel_id' => $envelope['delivery']['delivery_channel_id'] ?? null,
            'delivery_rider_id' => $envelope['delivery']['delivery_rider_id'] ?? null,
            'delivery_address' => $envelope['delivery']['delivery_address'] ?? null,
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

    // ── EDGE 0.9 CONTINUITY: merge into the ORIGINAL shift / the EXISTING order ─────────────────────────────────────

    /**
     * Pre-mutation plan for a v4 envelope (refusals are classified and answered before anything is written):
     *   shift.continues_cloud_shift_uuid  must name a Cloud shift of THIS branch and THIS terminal (CONTINUED_SHIFT_UNKNOWN /
     *                                     CONTINUED_SHIFT_MISMATCH otherwise) — the Cloud never creates a shift here;
     *   adoption                          must name THIS sale_uuid, resolve to a Cloud order of this branch that is still held,
     *                                     unpaid, made of plain lines, with the fingerprint the cashier adopted, every Cloud
     *                                     line still present on the appliance (ADOPTED_ORDER_UNKNOWN / ADOPTED_ORDER_CONFLICT /
     *                                     ADOPTED_ORDER_UNSUPPORTED / ADOPTED_LINES_DIVERGED otherwise — never guessed).
     *
     * @return array{cloud_shift_id:?int, cloud_shift_uuid:?string, order_id:?int}
     */
    private function continuityPlan(array $envelope, Branch $branch): array
    {
        $plan = ['cloud_shift_id' => null, 'cloud_shift_uuid' => null, 'order_id' => null];
        $continues = $envelope['shift']['continues_cloud_shift_uuid'] ?? null;
        $adoption = $envelope['adoption'] ?? null;
        if ((string) ($envelope['envelope_schema_version'] ?? '') !== self::ENVELOPE_SCHEMA_V4) {
            if ($continues !== null || $adoption !== null) {
                throw new IngestionRefusal('ENVELOPE_INVALID', 'continuity keys are carried by ' . self::ENVELOPE_SCHEMA_V4 . ' only');
            }

            return $plan;
        }
        if ($continues === null && $adoption === null) {
            throw new IngestionRefusal('ENVELOPE_INVALID', 'a ' . self::ENVELOPE_SCHEMA_V4 . ' envelope must carry continuity data');
        }
        if ($continues !== null) {
            if (! EdgeIdentity::isValid((string) $continues, EdgeIdentity::FORMAT_ULID)) {
                throw new IngestionRefusal('ENVELOPE_INVALID', 'continues_cloud_shift_uuid is not a canonical shift identity');
            }
            $shift = \App\Models\Tenant\Shift::on('tenant')->where('shift_uuid', (string) $continues)->first();
            if (! $shift) {
                throw new IngestionRefusal('CONTINUED_SHIFT_UNKNOWN', 'the online shift this Edge shift continues does not exist on the Cloud');
            }
            if ((int) $shift->branch_id !== (int) $branch->id || (int) $shift->terminal_id !== (int) ($envelope['terminal_id'] ?? 0)) {
                throw new IngestionRefusal('CONTINUED_SHIFT_MISMATCH', 'the continued online shift belongs to another branch or counter');
            }
            $plan['cloud_shift_id'] = (int) $shift->id;
            $plan['cloud_shift_uuid'] = (string) $continues;
        }
        if ($adoption !== null) {
            if (! is_array($adoption) || (string) ($adoption['adopted_from_order_uuid'] ?? '') !== (string) ($envelope['sale_uuid'] ?? '')
                || ! preg_match('/^[0-9a-f]{64}$/', (string) ($adoption['cloud_fingerprint'] ?? ''))) {
                throw new IngestionRefusal('ENVELOPE_INVALID', 'an adoption must name this sale_uuid and carry the mirrored order fingerprint');
            }
            $order = SalesOrder::on('tenant')->where('sale_uuid', (string) $envelope['sale_uuid'])->first();
            if (! $order) {
                throw new IngestionRefusal('ADOPTED_ORDER_UNKNOWN', 'the adopted online order does not exist on the Cloud');
            }
            if ((int) $order->branch_id !== (int) $branch->id) {
                throw new IngestionRefusal('WRONG_BRANCH', 'the adopted online order belongs to another branch');
            }
            $this->adoptedOrderLineMap($order, $order->lines()->orderBy('id')->get(), $envelope);
            $plan['order_id'] = (int) $order->id;
        }

        return $plan;
    }

    /**
     * Verify the Cloud order is EXACTLY what was adopted and map each Cloud line onto the envelope line that continues it.
     * Matching: the same line_uuid first (an untouched adopted line keeps it), then the same product / variant / unit price with
     * the same quantity, then with a quantity not below the Cloud's (a round added on the same item). A Cloud line with no
     * continuation (voided or reduced on the appliance) is refused — milestone 1 does not merge voids of mirrored lines.
     *
     * @return array<int,\App\Models\Tenant\SalesOrderLine> envelope line index → kept Cloud line
     */
    private function adoptedOrderLineMap(SalesOrder $order, $cloudLines, array $envelope): array
    {
        if ((string) $order->status !== 'held') {
            throw new IngestionRefusal('ADOPTED_ORDER_CONFLICT', "the online order is now [{$order->status}] — it changed on the Cloud after it was mirrored");
        }
        if ($order->payments()->exists()) {
            throw new IngestionRefusal('ADOPTED_ORDER_CONFLICT', 'the online order carries a payment recorded on the Cloud — it cannot be paid twice');
        }
        foreach ($cloudLines as $cl) {
            if ((string) ($cl->line_kind ?? 'standard') !== 'standard' || $cl->combo_id !== null) {
                throw new IngestionRefusal('ADOPTED_ORDER_UNSUPPORTED', 'the online order carries deal rows, which the live-state mirror does not adopt');
            }
        }
        if (! hash_equals((string) $envelope['adoption']['cloud_fingerprint'], Continuity\ContinuityFingerprint::fromOrder($order, $cloudLines))) {
            throw new IngestionRefusal('ADOPTED_ORDER_CONFLICT', 'the online order changed on the Cloud after the mirror revision ' . (string) ($envelope['adoption']['mirror_revision'] ?? '?'));
        }
        $env = array_values((array) ($envelope['lines'] ?? []));
        $map = [];
        $same = fn ($cl, array $el, bool $exactQty) => (string) ($el['line_kind'] ?? 'standard') === 'standard'
            && (int) ($el['product_id'] ?? 0) === (int) $cl->product_id
            && (int) ($el['product_variant_id'] ?? 0) === (int) ($cl->product_variant_id ?? 0)
            && abs((float) ($el['unit_price'] ?? 0) - (float) $cl->unit_price) < 0.005
            && ($exactQty ? abs((float) ($el['quantity'] ?? 0) - (float) $cl->quantity) < 0.0005 : (float) ($el['quantity'] ?? 0) + 0.0005 >= (float) $cl->quantity);
        $pending = [];
        foreach ($cloudLines as $cl) {
            foreach ($env as $i => $el) {
                if (! isset($map[$i]) && (string) ($el['line_uuid'] ?? '') === (string) $cl->line_uuid && $same($cl, $el, false)) {
                    $map[$i] = $cl;
                    continue 2;
                }
            }
            $pending[] = $cl;
        }
        foreach ([true, false] as $exact) {
            foreach ($pending as $k => $cl) {
                foreach ($env as $i => $el) {
                    if (! isset($map[$i]) && $same($cl, $el, $exact)) {
                        $map[$i] = $cl;
                        unset($pending[$k]);
                        continue 2;
                    }
                }
            }
        }
        if ($pending !== []) {
            throw new IngestionRefusal('ADOPTED_LINES_DIVERGED', count($pending) . ' line(s) of the online order were removed or reduced on the Branch Server — a void of a mirrored line needs a supervisor');
        }

        return $map;
    }

    /**
     * Complete the EXISTING Cloud order with the envelope's frozen commercial truth, re-verified under the order's row lock.
     * Identity (sale_uuid, sale_no, client_uuid), table session, opening shift / business date and creator are the Cloud's;
     * money, payment state and the settling counter are the envelope's.
     *
     * @return array{0: SalesOrder, 1: array<int,\App\Models\Tenant\SalesOrderLine>}
     */
    private function mergeAdoptedOrder(array $envelope, Branch $branch, EdgeDevice $device, int $orderId): array
    {
        $order = SalesOrder::on('tenant')->where('id', $orderId)->lockForUpdate()->firstOrFail();
        $lines = $order->lines()->orderBy('id')->lockForUpdate()->get();
        // Under the lock (a non-terminal exception if it moved since the plan: the retry meets the plan's terminal refusal).
        $kept = $this->adoptedOrderLineMap($order, $lines, $envelope);
        $totals = $envelope['totals'] ?? [];
        $customerId = $this->resolveCustomerId($envelope, $device);
        $order->fill([
            'terminal_id' => (int) ($envelope['terminal_id'] ?? 0) ?: $order->terminal_id,
            'customer_id' => $customerId ?? $order->customer_id,
            'customer_name' => $customerId !== null ? ($envelope['customer']['name'] ?? null) : ($order->customer_name ?? ($envelope['customer']['name'] ?? null)),
            'customer_phone' => $customerId !== null ? ($envelope['customer']['phone'] ?? null) : ($order->customer_phone ?? ($envelope['customer']['phone'] ?? null)),
            'notes' => isset($envelope['notes']) && is_string($envelope['notes']) && trim($envelope['notes']) !== '' ? mb_substr(trim($envelope['notes']), 0, 1000) : $order->notes,
            'order_type' => (string) $envelope['order_type'],
            'subtotal' => (float) ($totals['subtotal'] ?? 0),
            'discount_type' => in_array((string) ($totals['discount_type'] ?? 'none'), ['none', 'fixed', 'percent'], true) ? (string) ($totals['discount_type'] ?? 'none') : 'none',
            'discount_value' => (float) ($totals['discount_value'] ?? 0),
            'discount_amount' => (float) ($totals['discount_amount'] ?? 0),
            'promo_code' => $totals['promo_code'] ?? null,
            'tax_amount' => (float) ($totals['tax_amount'] ?? 0),
            'service_charge_amount' => (float) ($totals['service_charge_amount'] ?? 0),
            'delivery_charge_amount' => (float) ($totals['delivery_charge_amount'] ?? 0),
            'tip_amount' => (float) ($totals['tip_amount'] ?? 0),
            'grand_total' => (float) ($totals['grand_total'] ?? 0),
            'paid_amount' => (float) ($totals['paid_amount'] ?? 0),
            'change_amount' => (float) ($totals['change_amount'] ?? 0),
            'status' => 'paid',
            'is_draft' => false,
            'inventory_posted' => false,
            'completed_at' => $envelope['completed_at'] ?? now(),
            'edge_sync_state' => 'synced',
            'edge_activation_epoch' => (int) ($envelope['activation_epoch'] ?? 0),
        ]);
        $order->save();

        return [$order, $kept];
    }

    /**
     * ONE SHIFT PER COUNTER: attach the sale to the ORIGINAL Cloud shift its Edge shift continues — while that shift is still
     * open (the Cloud cannot close it while fenced). A shift closed meanwhile is never reopened or rewritten: the sale then
     * lands without a shift, exactly like any other Edge sale, and the ACK says so.
     *
     * @return array{cloud_shift_uuid:string, status:string}
     */
    private function attachContinuedShift(SalesOrder $sale, int $shiftId, string $shiftUuid): array
    {
        $shift = \App\Models\Tenant\Shift::on('tenant')->where('id', $shiftId)->lockForUpdate()->first();
        if (! $shift || (string) $shift->status !== 'open') {
            $sale->update(['shift_id' => null]);
            Log::warning('[edge-sync-ingest] continued shift not open — sale kept without a shift', ['sale_uuid' => $sale->sale_uuid, 'cloud_shift_uuid' => $shiftUuid]);

            return ['cloud_shift_uuid' => $shiftUuid, 'status' => 'cloud_shift_not_open'];
        }
        $sale->update(['shift_id' => (int) $shift->id]);

        return ['cloud_shift_uuid' => $shiftUuid, 'status' => 'merged'];
    }

    /**
     * @param  array<int,\App\Models\Tenant\SalesOrderLine>  $keptByIndex  EDGE 0.9 CONTINUITY: envelope line index → the adopted
     *                                                                     Cloud line it continues (kept: identity, KOT links)
     */
    private function projectLinesWithOfficialStock(array $envelope, SalesOrder $sale, Branch $branch, array $keptByIndex = []): void
    {
        // W6 v2 and PHASE 4 v3 (⊇ v2) and 0.9 v4 (⊇ v3) consume modifier linked stock; v1 semantics stay frozen.
        $consumeModifiers = in_array((string) ($envelope['envelope_schema_version'] ?? ''), [self::ENVELOPE_SCHEMA_V2, self::ENVELOPE_SCHEMA_V3, self::ENVELOPE_SCHEMA_V4], true);
        $sale->setRelation('branch', $branch);
        $headerIdByCombo = [];   // DEAL-SPLIT-REPORT-1 ingestion (rehearsal chunk 11): the last header seen per deal, in envelope order
        foreach (($envelope['lines'] ?? []) as $i => $line) {
            $product = Product::on('tenant')->findOrFail((int) $line['product_id']);
            $variant = ($line['product_variant_id'] ?? null)
                ? $this->inventory->resolveVariant($product, (int) $line['product_variant_id'])
                : null;
            $qty = (float) $line['quantity'];

            if (isset($keptByIndex[$i])) {
                // EDGE 0.9 CONTINUITY — an adopted Cloud line: the SAME row (line_uuid, KOT batch links, kitchen state) carries
                // the frozen envelope money and final quantity; official stock/COGS follow below exactly as for a new line (the
                // Cloud never posted stock for an unpaid held order).
                $newLine = $keptByIndex[$i];
                $newLine->update([
                    'quantity' => $qty,
                    'unit_price' => (float) $line['unit_price'],
                    'unit_cost' => 0,
                    'cost_total' => 0,
                    'discount_amount' => (float) ($line['discount_amount'] ?? 0),
                    'tax_amount' => (float) ($line['tax_amount'] ?? 0),
                    'line_total' => (float) $line['line_total'],
                ]);
                $this->postLineOfficialStock($line, $newLine, $product, $variant, $qty, $sale, $branch, $consumeModifiers);
                continue;
            }

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
            // DEAL-SPLIT-REPORT-1 (canonical 7762ab39) on an Edge-sold deal: the Cloud report pairs a deal's parts with their header
            // by parent_sales_order_line_id (SalesReportEngine::dealSplitPartOn) — a component without the link is not a part and
            // the header keeps the whole money. The envelope carries no parent reference (local ids mean nothing here), so a
            // component is linked to the most recent header of its deal in envelope order — the order the till wrote them
            // (header first, then its components; EdgeLocalPosService::resolveLines/expandCombo). deal_share itself is stamped by
            // SalesOrderLine::creating (DealSplit::stamp) from the CLOUD's parts when the tenant's Master switch is on.
            $lineKind = (string) ($line['line_kind'] ?? 'standard');
            if ($lineKind === 'component' && ! empty($line['combo_id'])) {
                $newLine->parent_sales_order_line_id = $headerIdByCombo[(int) $line['combo_id']] ?? null;
            }
            $newLine->save();
            if ($lineKind === 'combo_header' && ! empty($line['combo_id'])) {
                $headerIdByCombo[(int) $line['combo_id']] = (int) $newLine->id;
            }

            $this->postLineOfficialStock($line, $newLine, $product, $variant, $qty, $sale, $branch, $consumeModifiers);
        }

        $sale->update(['inventory_posted' => true]);
    }

    /**
     * Official COGS / stock (Cloud FEFO, recipe, modifier linked stock) for ONE projected line — the rule every ingested line
     * follows, whether it was created from the envelope or is an adopted Cloud line kept by the 0.9 continuity merge.
     */
    private function postLineOfficialStock(array $line, \App\Models\Tenant\SalesOrderLine $newLine, Product $product, $variant, float $qty, SalesOrder $sale, Branch $branch, bool $consumeModifiers): void
    {
        // Official COGS/stock authority (Cloud FEFO) — never the Edge provisional movement.
        $method = $product->inventory_consumption_method ?? 'stock_item';
        if (($line['line_kind'] ?? 'standard') === 'combo_header') {
            // DEAL parity (Cloud SalesService::finalize): the header row is the priced bundle, never stock/COGS.
            $method = 'none';
        }
        if ($method === 'recipe') {
            $cost = $this->recipes->consumeForSalesOrderLine($sale, $newLine, $branch);
            if ($cost > 0) {
                $newLine->update(['unit_cost' => $qty > 0 ? round($cost / $qty, 4) : 0, 'cost_total' => round($cost, 4)]);
            }
        } elseif ($method === 'stock_item' && $product->is_stock_tracked && (($line['line_kind'] ?? 'standard') !== 'combo_header')) {
            // DEAL parity (Cloud SalesService::finalize): the header row is the priced bundle, never stock —
            // its components are the ordinary lines that consume official stock and carry COGS.
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

        // W6 (B1) — envelope v2 ONLY: official modifier linked-stock consumption through the SAME shared rule the
        // Cloud POS runs (SalesService::consumeLineModifiers — Cloud's CURRENT modifier book, unit conversion,
        // FEFO, branch negative-stock policy), inside this ingestion transaction (a replay is already_applied with
        // zero further effects). Its cost is added on top of the line COGS exactly as Cloud finalize does. A deal
        // header never carries modifiers. Any failure rolls the WHOLE ingestion back as a retryable exception.
        if ($consumeModifiers && (($line['line_kind'] ?? 'standard') !== 'combo_header')) {
            try {
                $modifierCost = $this->sales->consumeLineModifiers($sale, $newLine);
            } catch (Throwable $e) {
                throw new IngestionRefusal('MODIFIER_STOCK_FAILED', "modifier stock cannot be posted for line product {$line['product_id']}: " . $e->getMessage());
            }
            if ($modifierCost > 0) {
                $costTotal = round((float) $newLine->cost_total + $modifierCost, 4);
                $newLine->update(['cost_total' => $costTotal, 'unit_cost' => $qty > 0 ? round($costTotal / $qty, 4) : 0]);
            }
        }
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

    /**
     * EDGE 0.9 CONTINUITY — the Cloud's truth for the continued shift / adopted order is not what the envelope continues (changed,
     * settled, voided or unknown on the Cloud). The identical immutable envelope can NEVER be accepted, so the verdict is the
     * existing TERMINAL `conflict` shape (the appliance parks the row failed_permanent → the handback is blocked as "needs
     * attention"; the rest of the outbox keeps draining) with a classified failure_code, persisted as STATUS_CONFLICT (never
     * re-evaluated). Nothing was written: it is decided before the posting transaction, or the transaction rolled back.
     */
    private function continuityConflict(array $envelope, string $code, string $message): array
    {
        $hash = (string) ($envelope['content_hash'] ?? '');
        $ack = [
            'status' => 'conflict',
            'failure_code' => $code,
            'sale_uuid' => (string) ($envelope['sale_uuid'] ?? ''),
            'content_hash' => $hash,
            'incoming_content_hash' => $hash,   // the envelope this verdict answers (the appliance's identity proof for a conflict)
            'message' => $message,
        ];
        $this->persistTerminal($envelope, $hash, EdgeInboundSaleIngestion::STATUS_CONFLICT, $code, $message, $ack);
        Log::warning('[edge-sync-ingest] continuity conflict', ['sale_uuid' => $ack['sale_uuid'], 'failure_code' => $code]);

        return $ack;
    }

    /** Record a deterministic refusal (no mutation happened) and return it as the ACK. */
    private function refuse(array $envelope, string $code, string $message): array
    {
        $ack = [
            'status' => 'refused',
            'failure_code' => $code,
            'sale_uuid' => (string) ($envelope['sale_uuid'] ?? ''),
            'content_hash' => (string) ($envelope['content_hash'] ?? ''),   // the envelope this verdict answers — the sender's identity proof
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
            'content_hash' => $contentHash,
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
