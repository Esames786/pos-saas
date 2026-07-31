<?php

namespace App\Services\Edge;

use App\Exceptions\EdgeBootstrapException;
use App\Models\Master\EdgeBootstrapSnapshot;
use App\Models\Master\EdgeBootstrapSnapshotSection;
use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BRANCH-BOOTSTRAP-SNAPSHOT-1 (+HARDEN-1) — build and serve a deterministic, immutable,
 * BRANCH-SCOPED bootstrap snapshot for a paired device.
 *
 * HARDEN-1 closes: (2) revoke/create/ack races via FRESH locked models; (3) the full
 * security contract is re-checked at create AND acknowledge through ONE shared method;
 * (4) guaranteed tenancy cleanup in finally on every path; (5) sections built inside a
 * single consistent tenant read boundary + source-revision re-check; (6) complete
 * source revision; (7) complete-download tracking (ack requires the full verified section
 * set); (8) tightened data policy (active+POS products only, cash-only payments, own
 * delivery only).
 */
class EdgeBootstrapService
{
    public const SCHEMA_VERSION = 'edge-bootstrap-v1';
    public const TTL_HOURS      = 72;
    private const BUILD_WAIT_MS = 120;
    private const BUILD_WAITS   = 25;

    /** First-offline-phase allowlists — no card/gateway/wallet/cheque, no aggregator delivery. */
    private const PHASE1_PAYMENT_TYPES  = ['cash'];
    private const OFFLINE_DELIVERY_TYPES = ['own'];

    public function __construct(
        private readonly OfflineEdgeEntitlementService $entitlement,
        private readonly TenancyManager $tenancy,
    ) {}

    /* ── shared security contract (create AND acknowledge) ───────────────── */

    /**
     * The ONE contract. Activates the tenant (caller MUST deactivate in finally). Re-checks
     * device, subscription/entitlement/flag, current-active-device ownership and branch=pending.
     * @return array{0: Tenant, 1: Branch}
     */
    public function assertContract(EdgeDevice $device): array
    {
        if ($device->isRevoked() || $device->active_slot !== EdgeDevice::ACTIVE_SLOT) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::DEVICE_REVOKED);
        }
        if (! in_array($device->status, [EdgeDevice::STATUS_PENDING_BOOTSTRAP, EdgeDevice::STATUS_READY], true)) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::NOT_ALLOWED);
        }

        // The device must still be THE current active device for its tenant+branch (a
        // replacement device that took the slot invalidates this one).
        $current = EdgeDevice::active()
            ->where('tenant_id', $device->tenant_id)
            ->where('branch_id', $device->branch_id)
            ->first();
        if (! $current || (int) $current->id !== (int) $device->id) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::DEVICE_REVOKED);
        }

        $tenant = Tenant::find($device->tenant_id);
        if (! $tenant) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::NOT_ALLOWED);
        }
        $this->tenancy->activate($tenant);

        if (! $this->entitlement->featureIsEnabled() || ! $this->entitlement->tenantHasOfflineEdgeAccess()) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::NOT_ALLOWED);
        }

        $branch = Branch::find($device->branch_id);
        if (! $branch) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::NOT_ALLOWED);
        }
        if ($branch->local_edge_status !== Branch::STATUS_PENDING) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::BRANCH_NOT_PENDING);
        }

        return [$tenant, $branch];
    }

    /** Re-validate a freshly LOCKED device row (never trust the pre-lock model). */
    private function revalidateLocked(EdgeDevice $locked, EdgeDevice $expected): void
    {
        if (! $locked
            || $locked->isRevoked()
            || $locked->active_slot !== EdgeDevice::ACTIVE_SLOT
            || (int) $locked->tenant_id !== (int) $expected->tenant_id
            || (int) $locked->branch_id !== (int) $expected->branch_id
            || ! in_array($locked->status, [EdgeDevice::STATUS_PENDING_BOOTSTRAP, EdgeDevice::STATUS_READY], true)) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::DEVICE_REVOKED);
        }
    }

    /* ── create / reuse ──────────────────────────────────────────────────── */

    /** @return array{snapshot: EdgeBootstrapSnapshot, built: bool} */
    public function createOrReuse(EdgeDevice $device): array
    {
        try {
            [$tenant, $branch] = $this->assertContract($device);
            $revision = $this->sourceRevision($branch);

            $claim = DB::connection('master')->transaction(function () use ($device, $tenant, $branch, $revision) {
                // FRESH locked device — a concurrent revoke committed before this line wins.
                $locked = EdgeDevice::whereKey($device->id)->lockForUpdate()->first();
                $this->revalidateLocked($locked, $device);

                $existing = EdgeBootstrapSnapshot::where('edge_device_id', $device->id)
                    ->where('source_revision', $revision)
                    ->whereIn('status', [
                        EdgeBootstrapSnapshot::STATUS_READY, EdgeBootstrapSnapshot::STATUS_DOWNLOADED,
                        EdgeBootstrapSnapshot::STATUS_ACKNOWLEDGED, EdgeBootstrapSnapshot::STATUS_BUILDING,
                    ])
                    ->orderByDesc('id')->first();

                if ($existing && ($existing->isReusable() || $existing->status === EdgeBootstrapSnapshot::STATUS_BUILDING)) {
                    return ['snapshot' => $existing, 'build' => false];
                }

                $snapshot = EdgeBootstrapSnapshot::create([
                    'public_uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
                    'branch_id' => $branch->id, 'edge_device_id' => $device->id,
                    'schema_version' => self::SCHEMA_VERSION, 'status' => EdgeBootstrapSnapshot::STATUS_BUILDING,
                    'source_revision' => $revision,
                ]);
                return ['snapshot' => $snapshot, 'build' => true];
            });

            /** @var EdgeBootstrapSnapshot $snapshot */
            $snapshot = $claim['snapshot'];
            $built = false;

            if ($claim['build']) {
                $this->build($snapshot, $tenant, $branch, $revision);
                $built = $snapshot->fresh()->status === EdgeBootstrapSnapshot::STATUS_READY;
            } elseif ($snapshot->status === EdgeBootstrapSnapshot::STATUS_BUILDING) {
                $this->waitForBuild($snapshot);
            }

            return ['snapshot' => $snapshot->fresh(), 'built' => $built];
        } finally {
            $this->tenancy->deactivate();   // guaranteed cleanup on success AND every exception
        }
    }

    private function waitForBuild(EdgeBootstrapSnapshot $snapshot): void
    {
        for ($i = 0; $i < self::BUILD_WAITS; $i++) {
            if (($snapshot->fresh()->status ?? null) !== EdgeBootstrapSnapshot::STATUS_BUILDING) {
                return;
            }
            usleep(self::BUILD_WAIT_MS * 1000);
        }
    }

    private function build(EdgeBootstrapSnapshot $snapshot, Tenant $tenant, Branch $branch, string $claimRevision): void
    {
        try {
            // Consistent read boundary: one tenant transaction (MySQL REPEATABLE READ) so every
            // section reflects a SINGLE point in time — no mixed products/prices/printers.
            $result = DB::connection('tenant')->transaction(function () use ($tenant, $branch, $claimRevision) {
                $sections = $this->buildSections($tenant, $branch);
                $revInside = $this->sourceRevision($branch);   // consistent within this txn
                return [$sections, $revInside];
            });
            [$sections, $revInside] = $result;

            // If the source moved between the claim and the consistent read, don't publish —
            // fail controlled so the client retries and gets a snapshot for the new revision.
            if (! hash_equals($claimRevision, $revInside)) {
                $snapshot->update([
                    'status' => EdgeBootstrapSnapshot::STATUS_FAILED,
                    'failure_code' => EdgeBootstrapException::SOURCE_CHANGED,
                    'last_error' => 'source revision changed during build',
                ]);
                throw EdgeBootstrapException::of(EdgeBootstrapException::SOURCE_CHANGED);
            }

            $summary = [];
            foreach ($sections as $name => $rows) {
                $json = $this->canonicalJson($rows);
                $hash = hash('sha256', $json);
                EdgeBootstrapSnapshotSection::create([
                    'snapshot_id' => $snapshot->id, 'name' => $name, 'content_hash' => $hash,
                    'row_count' => is_array($rows) ? count($rows) : 0,
                    'payload_gz' => base64_encode(gzencode($json, 6)),
                ]);
                $summary[$name] = ['hash' => $hash, 'count' => is_array($rows) ? count($rows) : 0];
            }

            $snapshot->update([
                'status' => EdgeBootstrapSnapshot::STATUS_READY,
                'manifest_hash' => $this->manifestHash($snapshot, $summary),
                'section_summary' => $summary, 'generated_at' => now(),
                'expires_at' => now()->addHours(self::TTL_HOURS),
                'failure_code' => null, 'last_error' => null,
            ]);
        } catch (EdgeBootstrapException $e) {
            throw $e;   // already marked (e.g. SOURCE_CHANGED)
        } catch (\Throwable $e) {
            $snapshot->update([
                'status' => EdgeBootstrapSnapshot::STATUS_FAILED,
                'failure_code' => EdgeBootstrapException::BUILD_FAILED,
                'last_error' => substr($e->getMessage(), 0, 500),
            ]);
            EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->delete();
            Log::error('[edge-bootstrap-audit] build_failed', ['snapshot' => $snapshot->public_uuid, 'error' => substr($e->getMessage(), 0, 190)]);
            throw EdgeBootstrapException::of(EdgeBootstrapException::BUILD_FAILED);
        }
    }

    /* ── manifest / section / acknowledge ────────────────────────────────── */

    public function manifest(EdgeBootstrapSnapshot $snapshot): array
    {
        $this->assertServable($snapshot);
        $tenant = Tenant::find($snapshot->tenant_id);

        return [
            'schema_version' => $snapshot->schema_version, 'snapshot_uuid' => $snapshot->public_uuid,
            'tenant_code' => $tenant?->tenant_code, 'branch_id' => $snapshot->branch_id,
            'status' => $snapshot->status, 'manifest_hash' => $snapshot->manifest_hash,
            'generated_at' => optional($snapshot->generated_at)->toIso8601String(),
            'expires_at' => optional($snapshot->expires_at)->toIso8601String(),
            'sections' => $snapshot->section_summary ?? [],
        ];
    }

    public function sectionPayload(EdgeBootstrapSnapshot $snapshot, string $name): array
    {
        $this->assertServable($snapshot);
        $section = EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->where('name', $name)->first();
        if (! $section) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_NOT_FOUND);
        }

        // Record THIS section's delivery. The snapshot only becomes 'downloaded' once EVERY
        // section has been fetched (fetching one section never marks the whole set complete).
        $section->forceFill([
            'downloaded_at' => $section->downloaded_at ?? now(),
            'delivered_hash' => $section->content_hash,
            'attempts' => (int) $section->attempts + 1,
        ])->save();

        if ($snapshot->status === EdgeBootstrapSnapshot::STATUS_READY && $this->allSectionsDelivered($snapshot)) {
            $snapshot->forceFill(['downloaded_at' => now(), 'status' => EdgeBootstrapSnapshot::STATUS_DOWNLOADED])->save();
        }

        return ['json' => $section->json(), 'hash' => $section->content_hash, 'count' => $section->row_count];
    }

    private function allSectionsDelivered(EdgeBootstrapSnapshot $snapshot): bool
    {
        $total = EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->count();
        $done  = EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->whereNotNull('downloaded_at')->count();
        return $total > 0 && $total === $done;
    }

    /**
     * Acknowledge. Re-runs the FULL contract, verifies schema + manifest hash + expiry, and
     * requires the COMPLETE, verified section-hash map (every manifest section, correct hash,
     * actually downloaded). Moves the DEVICE pending_bootstrap→ready via a fresh locked model;
     * never touches the branch (stays pending). Idempotent.
     *
     * @param array<string,string> $sectionHashes  name => sha256 supplied by the device
     */
    public function acknowledge(EdgeDevice $device, EdgeBootstrapSnapshot $snapshot, string $schemaVersion, string $manifestHash, array $sectionHashes): array
    {
        try {
            if ((int) $snapshot->edge_device_id !== (int) $device->id) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_NOT_FOUND);
            }

            // FULL contract re-check (subscription/entitlement/flag/current-device/branch pending).
            $this->assertContract($device);

            if ($schemaVersion !== $snapshot->schema_version || $schemaVersion !== self::SCHEMA_VERSION) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::SCHEMA_UNSUPPORTED);
            }
            if ($snapshot->status === EdgeBootstrapSnapshot::STATUS_FAILED || $snapshot->status === EdgeBootstrapSnapshot::STATUS_BUILDING) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_NOT_FOUND);
            }
            if ($snapshot->isExpired()) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_EXPIRED);
            }
            if (! $snapshot->manifest_hash || ! hash_equals((string) $snapshot->manifest_hash, $manifestHash)) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::HASH_MISMATCH);
            }

            // Complete-download verification against the immutable manifest.
            $this->assertCompleteDelivery($snapshot, $sectionHashes);

            DB::connection('master')->transaction(function () use ($device, $snapshot) {
                $locked = EdgeDevice::whereKey($device->id)->lockForUpdate()->first();
                $this->revalidateLocked($locked, $device);           // revoke-vs-ack race: revoked → throws
                $lockedSnap = EdgeBootstrapSnapshot::whereKey($snapshot->id)->lockForUpdate()->first();
                if ($lockedSnap->isExpired()) {
                    throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_EXPIRED);
                }

                if (! $lockedSnap->acknowledged_at) {
                    $lockedSnap->forceFill(['status' => EdgeBootstrapSnapshot::STATUS_ACKNOWLEDGED, 'acknowledged_at' => now()])->save();
                }
                if ($locked->status === EdgeDevice::STATUS_PENDING_BOOTSTRAP) {
                    $locked->forceFill(['status' => EdgeDevice::STATUS_READY])->save();   // write through the LOCKED model
                }
            });

            $this->audit('acknowledged', $snapshot);

            return [
                'snapshot_uuid' => $snapshot->public_uuid,
                'device_status' => EdgeDevice::whereKey($device->id)->value('status'),
                'acknowledged' => true,
            ];
        } finally {
            $this->tenancy->deactivate();
        }
    }

    /** Every manifest section must be supplied, hash-correct, and actually downloaded. */
    private function assertCompleteDelivery(EdgeBootstrapSnapshot $snapshot, array $sectionHashes): void
    {
        $manifest = $snapshot->section_summary ?? [];
        $manifestNames = array_keys($manifest);
        $suppliedNames = array_keys($sectionHashes);
        sort($manifestNames); sort($suppliedNames);

        if ($manifestNames !== $suppliedNames) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::INCOMPLETE);   // missing OR extra sections
        }

        foreach ($manifest as $name => $meta) {
            if (! hash_equals((string) $meta['hash'], strtolower((string) $sectionHashes[$name]))) {
                throw EdgeBootstrapException::of(EdgeBootstrapException::SECTION_HASH_MISMATCH);
            }
        }

        // Every required section must have actually been fetched (delivered).
        $delivered = EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)
            ->whereNotNull('downloaded_at')->pluck('name')->all();
        sort($delivered);
        if ($delivered !== $manifestNames) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::INCOMPLETE);
        }
    }

    private function assertServable(EdgeBootstrapSnapshot $snapshot): void
    {
        if ($snapshot->schema_version !== self::SCHEMA_VERSION) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SCHEMA_UNSUPPORTED);
        }
        if ($snapshot->status === EdgeBootstrapSnapshot::STATUS_FAILED) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::BUILD_FAILED);
        }
        if ($snapshot->status === EdgeBootstrapSnapshot::STATUS_BUILDING) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::BUILD_IN_PROGRESS);
        }
        if ($snapshot->isExpired()) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_EXPIRED);
        }
    }

    /* ── source watermark (COMPLETE) ─────────────────────────────────────── */

    private function sourceRevision(Branch $branch): string
    {
        $conn = DB::connection('tenant');
        $b = (int) $branch->id;
        $wm = [];
        $add = function (string $table, ?string $branchCol = null) use ($conn, &$wm, $b) {
            $q = $conn->table($table);
            if ($branchCol) { $q->where($branchCol, $b); }
            $wm[] = $table . '=' . (string) $q->max('updated_at') . '|' . $q->count();
        };
        $termIds = $conn->table('terminals')->where('branch_id', $b)->pluck('id')->all();
        $userIds = $conn->table('users')->where('default_branch_id', $b)->pluck('id')->all();

        $add('branches', 'id'); $add('terminals', 'branch_id');
        $add('categories'); $add('units'); $add('products'); $add('product_variants'); $add('product_barcodes');
        $add('product_branch_prices', 'branch_id'); $add('modifier_groups', 'branch_id'); $add('modifiers');
        $add('combos', 'branch_id'); $add('combo_components'); $add('payment_methods');
        $add('restaurant_floors', 'branch_id'); $add('restaurant_tables', 'branch_id'); $add('restaurant_waiters', 'branch_id');
        $add('delivery_channels'); $add('delivery_riders', 'branch_id');
        $add('printers', 'branch_id'); $add('receipt_layout_settings', 'branch_id'); $add('category_printer_mappings', 'branch_id');
        $add('service_charge_settings', 'branch_id'); $add('void_reasons');
        $add('users', 'default_branch_id'); $add('roles');

        // HARDEN-1: printer routing per terminal + staff role assignments affect emitted bytes.
        $tps = $conn->table('terminal_printer_settings')->whereIn('terminal_id', $termIds ?: [0]);
        $wm[] = 'terminal_printer_settings=' . (string) $tps->max('updated_at') . '|' . $tps->count();
        $mhr = $conn->table('model_has_roles')->where('model_type', \App\Models\Tenant\User::class)->whereIn('model_id', $userIds ?: [0]);
        $wm[] = 'model_has_roles=' . $mhr->count() . '|' . implode(',', $mhr->orderBy('role_id')->orderBy('model_id')->pluck('role_id')->all());

        return hash('sha256', implode("\n", $wm));
    }

    /* ── deterministic branch-scoped sections (explicit allowlists) ───────── */

    private function buildSections(Tenant $tenant, Branch $branch): array
    {
        $conn = DB::connection('tenant');
        $b = (int) $branch->id;
        $rows = fn ($q, array $cols) => collect($q->orderBy('id')->get($cols))->map(fn ($r) => (array) $r)->all();

        $terminalIds = $conn->table('terminals')->where('branch_id', $b)->pluck('id')->all();
        $groupIds    = $conn->table('modifier_groups')->where('branch_id', $b)->pluck('id')->all();
        $comboIds    = $conn->table('combos')->where('branch_id', $b)->pluck('id')->all();

        // HARDEN-1: only ACTIVE, sellable, POS-visible products; everything below is constrained
        // to this included set so no orphan variant/barcode/price is emitted.
        $productIds = $conn->table('products')
            ->where('is_sellable', 1)->where('is_pos_visible', 1)->where('status', 'active')
            ->pluck('id')->all();
        $pidFilter = $productIds ?: [0];

        return [
            'tenant' => [[
                'tenant_code' => $tenant->tenant_code, 'business_name' => $tenant->business_name, 'currency_code' => $tenant->currency_code,
            ]],
            'branch' => [[
                'id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name, 'business_type' => $branch->business_type,
                'allow_negative_stock' => (bool) $branch->allow_negative_stock, 'timezone' => $branch->timezone,
                'tax_registration_no' => $branch->tax_registration_no, 'is_tax_enabled' => (bool) $branch->is_tax_enabled,
                'show_tax_number_on_invoice' => (bool) $branch->show_tax_number_on_invoice, 'receipt_footer' => $branch->receipt_footer,
                'address' => $branch->address, 'phone' => $branch->phone, 'email' => $branch->email, 'status' => $branch->status,
            ]],
            'terminals' => $rows($conn->table('terminals')->where('branch_id', $b)->where('status', 'active'),
                ['id', 'branch_id', 'code', 'name', 'device_identifier', 'requires_shift', 'status']),
            'categories' => $rows($conn->table('categories')->where('is_active', 1),
                ['id', 'parent_id', 'code', 'name', 'slug', 'sort_order', 'is_active']),
            'units' => $rows($conn->table('units')->where('is_active', 1),
                ['id', 'code', 'name', 'unit_type', 'base_factor', 'is_base', 'is_active']),
            'products' => $rows($conn->table('products')->whereIn('id', $pidFilter),
                ['id', 'category_id', 'unit_id', 'sku', 'name', 'slug', 'product_type', 'item_kind',
                 'is_pos_visible', 'is_stock_tracked', 'has_variants', 'default_selling_price',
                 'is_taxable', 'tax_rate_percent', 'image_path', 'status']),
            'product_variants' => $rows($conn->table('product_variants')->where('is_active', 1)->whereIn('product_id', $pidFilter),
                ['id', 'product_id', 'sku', 'name', 'barcode', 'selling_price', 'is_default', 'is_active']),
            'product_barcodes' => $rows($conn->table('product_barcodes')->whereIn('product_id', $pidFilter),
                ['id', 'product_id', 'product_variant_id', 'barcode', 'barcode_type', 'is_primary']),
            'product_branch_prices' => $rows($conn->table('product_branch_prices')->where('branch_id', $b)->whereIn('product_id', $pidFilter),
                ['id', 'branch_id', 'product_id', 'product_variant_id', 'selling_price', 'minimum_selling_price', 'is_available']),
            'modifier_groups' => $rows($conn->table('modifier_groups')->where('branch_id', $b),
                ['id', 'branch_id', 'name', 'min_select', 'max_select', 'is_required', 'sort_order', 'status']),
            'modifiers' => $rows($conn->table('modifiers')->whereIn('modifier_group_id', $groupIds ?: [0]),
                ['id', 'modifier_group_id', 'name', 'price_delta', 'linked_product_id', 'consume_stock',
                 'linked_quantity', 'linked_unit_id', 'is_default', 'sort_order', 'status']),
            'combos' => $rows($conn->table('combos')->where('branch_id', $b),
                ['id', 'branch_id', 'code', 'name', 'price', 'sort_order', 'status', 'description']),
            'combo_components' => $rows($conn->table('combo_components')->whereIn('combo_id', $comboIds ?: [0]),
                ['id', 'combo_id', 'product_id', 'product_variant_id', 'quantity', 'sort_order']),
            // HARDEN-1: cash/manual-supported payments only — no card/wallet/bank/cheque gateways.
            'payment_methods' => $rows($conn->table('payment_methods')->where('is_active', 1)->whereIn('method_type', self::PHASE1_PAYMENT_TYPES),
                ['id', 'code', 'name', 'method_type', 'requires_reference', 'is_cash_drawer', 'is_active']),
            'restaurant_floors' => $rows($conn->table('restaurant_floors')->where('branch_id', $b),
                ['id', 'branch_id', 'name', 'code', 'status', 'sort_order']),
            'restaurant_tables' => $rows($conn->table('restaurant_tables')->where('branch_id', $b),
                ['id', 'branch_id', 'restaurant_floor_id', 'table_no', 'name', 'capacity', 'status', 'sort_order']),
            'restaurant_waiters' => $rows($conn->table('restaurant_waiters')->where('branch_id', $b),
                ['id', 'branch_id', 'name', 'code', 'phone', 'status']),
            // HARDEN-1: own/manual delivery only — aggregator channels are cloud-dependent.
            'delivery_channels' => $rows($conn->table('delivery_channels')->where('is_active', 1)->whereIn('type', self::OFFLINE_DELIVERY_TYPES),
                ['id', 'name', 'type', 'commission_percent', 'is_active', 'sort_order']),
            'delivery_riders' => $rows($conn->table('delivery_riders')->where('branch_id', $b),
                ['id', 'branch_id', 'name', 'phone', 'status']),
            'printers' => $rows($conn->table('printers')->where('branch_id', $b),
                ['id', 'branch_id', 'name', 'code', 'printer_type', 'print_role', 'ip_address', 'port',
                 'paper_size', 'characters_per_line', 'is_default', 'is_active', 'agent_enabled']),
            'receipt_layout_settings' => $rows($conn->table('receipt_layout_settings')->where('branch_id', $b),
                ['id', 'branch_id', 'document_type', 'paper_size', 'show_logo', 'show_branch_name', 'show_branch_address',
                 'show_branch_phone', 'show_tax_number', 'show_cashier_name', 'show_customer_name', 'show_table_info',
                 'show_order_no', 'show_item_codes', 'show_payment_breakdown', 'header_text', 'footer_text', 'font_size', 'kot_font_size', 'is_active']),
            'category_printer_mappings' => $rows($conn->table('category_printer_mappings')->where('branch_id', $b),
                ['id', 'branch_id', 'category_id', 'printer_id', 'print_role', 'is_active']),
            'terminal_printer_settings' => $rows($conn->table('terminal_printer_settings')->whereIn('terminal_id', $terminalIds ?: [0]),
                ['id', 'terminal_id', 'receipt_printer_id', 'kot_printer_id', 'auto_print_receipt', 'auto_print_kot']),
            'service_charge_settings' => $rows($conn->table('service_charge_settings')->where('branch_id', $b),
                ['id', 'branch_id', 'charge_type', 'charge_value', 'order_types', 'is_taxable', 'is_active']),
            'void_reasons' => $rows($conn->table('void_reasons')->where('is_active', 1),
                ['id', 'name', 'reason_type', 'requires_manager_approval', 'is_active']),
            'users' => $this->userSection($conn, $b),
            'roles' => $rows($conn->table('roles')->where('guard_name', 'tenant'), ['id', 'name', 'guard_name']),
            // Operational restrictions the Edge MUST enforce in the first offline phase.
            'restrictions' => [[
                'phase' => 'offline-v1',
                'blocked_payment_types' => ['card', 'wallet', 'bank_transfer', 'cheque', 'customer_credit'],
                'allowed_payment_types' => self::PHASE1_PAYMENT_TYPES,
                'blocked_delivery_types' => ['aggregator'],
                'allowed_delivery_types' => self::OFFLINE_DELIVERY_TYPES,
                'blocked_capabilities' => ['card_gateway', 'external_aggregator_api', 'customer_credit_ledger',
                    'returns_against_cloud', 'purchasing', 'stock_operations', 'manufacturing', 'cloud_manager_approval'],
            ]],
        ];
    }

    private function userSection($conn, int $branchId): array
    {
        $users = $conn->table('users')->where('default_branch_id', $branchId)->orderBy('id')
            ->get(['id', 'employee_code', 'name', 'default_branch_id', 'default_terminal_id', 'status', 'locale']);
        $userIds = $users->pluck('id')->all();
        $roleMap = [];
        if ($userIds) {
            foreach ($conn->table('model_has_roles as mhr')->join('roles as r', 'r.id', '=', 'mhr.role_id')
                ->where('mhr.model_type', \App\Models\Tenant\User::class)->whereIn('mhr.model_id', $userIds)
                ->orderBy('r.name')->get(['mhr.model_id as uid', 'r.name as role']) as $a) {
                $roleMap[$a->uid][] = $a->role;
            }
        }
        return collect($users)->map(fn ($u) => [
            'id' => $u->id, 'employee_code' => $u->employee_code, 'name' => $u->name,
            'default_branch_id' => $u->default_branch_id, 'default_terminal_id' => $u->default_terminal_id,
            'status' => $u->status, 'locale' => $u->locale, 'roles' => $roleMap[$u->id] ?? [],
        ])->all();
    }

    /* ── determinism helpers ─────────────────────────────────────────────── */

    public function canonicalJson(mixed $data): string
    {
        return json_encode($this->canonicalize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalize(mixed $v): mixed
    {
        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map(fn ($x) => $this->canonicalize($x), $v);
            }
            ksort($v);
            return array_map(fn ($x) => $this->canonicalize($x), $v);
        }
        return $v;
    }

    private function manifestHash(EdgeBootstrapSnapshot $snapshot, array $summary): string
    {
        return hash('sha256', $this->canonicalJson([
            'schema_version' => $snapshot->schema_version, 'snapshot_uuid' => $snapshot->public_uuid,
            'tenant_id' => $snapshot->tenant_id, 'branch_id' => $snapshot->branch_id, 'sections' => $summary,
        ]));
    }

    public function audit(string $event, EdgeBootstrapSnapshot $snapshot): void
    {
        Log::info("[edge-bootstrap-audit] {$event}", [
            'snapshot' => $snapshot->public_uuid, 'tenant_id' => $snapshot->tenant_id, 'branch_id' => $snapshot->branch_id,
        ]);
    }
}
