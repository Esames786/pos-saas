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
 * BRANCH-BOOTSTRAP-SNAPSHOT-1 — build and serve a deterministic, immutable, BRANCH-SCOPED
 * bootstrap snapshot for a paired device.
 *
 * Security contract (re-checked at create AND acknowledge):
 *   - device is active-slot, not revoked, status pending_bootstrap|ready
 *   - tenant subscription active + offline_edge entitled + EDGE_FEATURE_ENABLED
 *   - branch lifecycle is PENDING only
 * Tenant + branch are resolved ONLY from EdgeDevice — request tenant_id/branch_id are ignored.
 * Nothing here activates Local POS or moves the branch to active.
 *
 * Sections use EXPLICIT column allowlists (never SELECT *), so finance/cost/credentials/other
 * branches cannot leak. Every section is canonically ordered + hashed; repeated reads of an
 * immutable snapshot return identical bytes/hashes.
 */
class EdgeBootstrapService
{
    public const SCHEMA_VERSION = 'edge-bootstrap-v1';
    public const TTL_HOURS      = 72;
    private const BUILD_WAIT_MS = 120;   // bounded wait for a concurrent build to finish
    private const BUILD_WAITS   = 25;

    public function __construct(
        private readonly OfflineEdgeEntitlementService $entitlement,
        private readonly TenancyManager $tenancy,
    ) {}

    /* ── security contract ───────────────────────────────────────────────── */

    /** Re-check the full contract. Tenant is activated as a side effect (for branch reads). */
    public function assertBootstrapAllowed(EdgeDevice $device): array
    {
        if ($device->isRevoked() || $device->active_slot !== EdgeDevice::ACTIVE_SLOT) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::DEVICE_REVOKED);
        }
        if (! in_array($device->status, [EdgeDevice::STATUS_PENDING_BOOTSTRAP, EdgeDevice::STATUS_READY], true)) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::NOT_ALLOWED);
        }

        $tenant = Tenant::find($device->tenant_id);
        if (! $tenant) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::NOT_ALLOWED);
        }
        $this->tenancy->activate($tenant);   // sets tenant DB + app('tenant')

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

    /* ── create / reuse ──────────────────────────────────────────────────── */

    public function createOrReuse(EdgeDevice $device): EdgeBootstrapSnapshot
    {
        [$tenant, $branch] = $this->assertBootstrapAllowed($device);
        $revision = $this->sourceRevision($branch);

        // Atomically claim: reuse a live snapshot for this device+revision, or reuse an
        // in-progress build, or create a new 'building' placeholder — all under a device lock.
        $claim = DB::connection('master')->transaction(function () use ($device, $tenant, $branch, $revision) {
            EdgeDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();

            $existing = EdgeBootstrapSnapshot::where('edge_device_id', $device->id)
                ->where('source_revision', $revision)
                ->whereIn('status', [
                    EdgeBootstrapSnapshot::STATUS_READY,
                    EdgeBootstrapSnapshot::STATUS_DOWNLOADED,
                    EdgeBootstrapSnapshot::STATUS_ACKNOWLEDGED,
                    EdgeBootstrapSnapshot::STATUS_BUILDING,
                ])
                ->orderByDesc('id')
                ->first();

            if ($existing && ($existing->isReusable() || $existing->status === EdgeBootstrapSnapshot::STATUS_BUILDING)) {
                return ['snapshot' => $existing, 'build' => false];
            }

            $snapshot = EdgeBootstrapSnapshot::create([
                'public_uuid'     => (string) Str::uuid(),
                'tenant_id'       => $tenant->id,
                'branch_id'       => $branch->id,
                'edge_device_id'  => $device->id,
                'schema_version'  => self::SCHEMA_VERSION,
                'status'          => EdgeBootstrapSnapshot::STATUS_BUILDING,
                'source_revision' => $revision,
            ]);

            return ['snapshot' => $snapshot, 'build' => true];
        });

        /** @var EdgeBootstrapSnapshot $snapshot */
        $snapshot = $claim['snapshot'];

        if ($claim['build']) {
            $this->build($snapshot, $tenant, $branch);   // outside the lock (long tenant reads)
        } elseif ($snapshot->status === EdgeBootstrapSnapshot::STATUS_BUILDING) {
            $this->waitForBuild($snapshot);              // another request is building — converge
        }

        return $snapshot->fresh();
    }

    private function waitForBuild(EdgeBootstrapSnapshot $snapshot): void
    {
        for ($i = 0; $i < self::BUILD_WAITS; $i++) {
            $fresh = $snapshot->fresh();
            if ($fresh && $fresh->status !== EdgeBootstrapSnapshot::STATUS_BUILDING) {
                return;
            }
            usleep(self::BUILD_WAIT_MS * 1000);
        }
    }

    private function build(EdgeBootstrapSnapshot $snapshot, Tenant $tenant, Branch $branch): void
    {
        try {
            $sections = $this->buildSections($tenant, $branch);
            $summary  = [];

            foreach ($sections as $name => $rows) {
                $json  = $this->canonicalJson($rows);
                $hash  = hash('sha256', $json);
                EdgeBootstrapSnapshotSection::create([
                    'snapshot_id'  => $snapshot->id,
                    'name'         => $name,
                    'content_hash' => $hash,
                    'row_count'    => is_array($rows) ? count($rows) : 0,
                    'payload_gz'   => base64_encode(gzencode($json, 6)),   // base64 → ASCII-safe in a text column
                ]);
                $summary[$name] = ['hash' => $hash, 'count' => is_array($rows) ? count($rows) : 0];
            }

            $manifestHash = $this->manifestHash($snapshot, $summary);

            $snapshot->update([
                'status'          => EdgeBootstrapSnapshot::STATUS_READY,
                'manifest_hash'   => $manifestHash,
                'section_summary' => $summary,
                'generated_at'    => now(),
                'expires_at'      => now()->addHours(self::TTL_HOURS),
                'failure_code'    => null,
                'last_error'      => null,
            ]);
        } catch (\Throwable $e) {
            // A failed build leaves a controlled 'failed' snapshot, NOT a half-ready one.
            $snapshot->update([
                'status'       => EdgeBootstrapSnapshot::STATUS_FAILED,
                'failure_code' => 'EDGE_BOOTSTRAP_BUILD_FAILED',
                'last_error'   => substr($e->getMessage(), 0, 500),
            ]);
            EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->delete();
            Log::error('[edge-bootstrap-audit] build_failed', [
                'snapshot' => $snapshot->public_uuid, 'error' => substr($e->getMessage(), 0, 190),
            ]);
        }
    }

    /* ── manifest / section / acknowledge ────────────────────────────────── */

    public function manifest(EdgeBootstrapSnapshot $snapshot): array
    {
        $this->assertServable($snapshot);
        $tenant = Tenant::find($snapshot->tenant_id);

        return [
            'schema_version'  => $snapshot->schema_version,
            'snapshot_uuid'   => $snapshot->public_uuid,
            'tenant_code'     => $tenant?->tenant_code,
            'branch_id'       => $snapshot->branch_id,
            'status'          => $snapshot->status,
            'manifest_hash'   => $snapshot->manifest_hash,
            'generated_at'    => optional($snapshot->generated_at)->toIso8601String(),
            'expires_at'      => optional($snapshot->expires_at)->toIso8601String(),
            'sections'        => $snapshot->section_summary ?? [],
        ];
    }

    public function sectionPayload(EdgeBootstrapSnapshot $snapshot, string $name): array
    {
        $this->assertServable($snapshot);
        $section = EdgeBootstrapSnapshotSection::where('snapshot_id', $snapshot->id)->where('name', $name)->first();
        if (! $section) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_NOT_FOUND);
        }

        // Mark downloaded once the device has fetched at least one section (best-effort watermark).
        if (! $snapshot->downloaded_at) {
            $snapshot->forceFill([
                'downloaded_at' => now(),
                'status'        => $snapshot->status === EdgeBootstrapSnapshot::STATUS_READY
                    ? EdgeBootstrapSnapshot::STATUS_DOWNLOADED : $snapshot->status,
            ])->save();
        }

        return ['json' => $section->json(), 'hash' => $section->content_hash, 'count' => $section->row_count];
    }

    public function acknowledge(EdgeDevice $device, EdgeBootstrapSnapshot $snapshot, string $schemaVersion, string $manifestHash): array
    {
        // Ownership: the snapshot must belong to THIS device (never trust request identifiers).
        if ((int) $snapshot->edge_device_id !== (int) $device->id) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_NOT_FOUND);
        }
        // Revocation must block acknowledgment even after a successful download.
        if ($device->isRevoked() || $device->active_slot !== EdgeDevice::ACTIVE_SLOT) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::DEVICE_REVOKED);
        }
        if ($schemaVersion !== $snapshot->schema_version || $schemaVersion !== self::SCHEMA_VERSION) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SCHEMA_UNSUPPORTED);
        }
        if ($snapshot->isExpired()) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_EXPIRED);
        }
        if (! $snapshot->manifest_hash || ! hash_equals((string) $snapshot->manifest_hash, $manifestHash)) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::HASH_MISMATCH);
        }

        // Idempotent: acknowledging again is a no-op success. Moves device pending_bootstrap→ready;
        // NEVER touches the branch (stays pending) and NEVER activates Local POS.
        DB::connection('master')->transaction(function () use ($device, $snapshot) {
            EdgeDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            if (! $snapshot->acknowledged_at) {
                $snapshot->forceFill([
                    'status'          => EdgeBootstrapSnapshot::STATUS_ACKNOWLEDGED,
                    'acknowledged_at' => now(),
                ])->save();
            }
            if ($device->status === EdgeDevice::STATUS_PENDING_BOOTSTRAP) {
                $device->forceFill(['status' => EdgeDevice::STATUS_READY])->save();
            }
        });

        $this->audit('acknowledged', $snapshot);

        return [
            'snapshot_uuid' => $snapshot->public_uuid,
            'device_status' => $device->fresh()->status,
            'acknowledged'  => true,
        ];
    }

    private function assertServable(EdgeBootstrapSnapshot $snapshot): void
    {
        if ($snapshot->schema_version !== self::SCHEMA_VERSION) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SCHEMA_UNSUPPORTED);
        }
        if ($snapshot->status === EdgeBootstrapSnapshot::STATUS_FAILED
            || $snapshot->status === EdgeBootstrapSnapshot::STATUS_BUILDING) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_NOT_FOUND);
        }
        if ($snapshot->isExpired()) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_EXPIRED);
        }
    }

    /* ── source watermark ────────────────────────────────────────────────── */

    private function sourceRevision(Branch $branch): string
    {
        $conn = DB::connection('tenant');
        $b = (int) $branch->id;
        $wm = [];
        $add = function (string $table, ?string $branchCol = null, $branchVal = null) use ($conn, &$wm, $b) {
            $q = $conn->table($table);
            if ($branchCol) { $q->where($branchCol, $b); }
            $wm[] = $table . '=' . (string) $q->max('updated_at') . '|' . $q->count();
        };
        $add('branches', 'id');
        $add('terminals', 'branch_id');
        $add('categories'); $add('units');
        $add('products'); $add('product_variants'); $add('product_barcodes');
        $add('product_branch_prices', 'branch_id');
        $add('modifier_groups', 'branch_id'); $add('modifiers');
        $add('combos', 'branch_id'); $add('combo_components');
        $add('payment_methods');
        $add('restaurant_floors', 'branch_id'); $add('restaurant_tables', 'branch_id'); $add('restaurant_waiters', 'branch_id');
        $add('delivery_channels'); $add('delivery_riders', 'branch_id');
        $add('printers', 'branch_id'); $add('receipt_layout_settings', 'branch_id');
        $add('category_printer_mappings', 'branch_id'); $add('service_charge_settings', 'branch_id');
        $add('void_reasons'); $add('users', 'default_branch_id'); $add('roles');

        return hash('sha256', implode("\n", $wm));
    }

    /* ── deterministic branch-scoped sections (explicit allowlists) ───────── */

    private function buildSections(Tenant $tenant, Branch $branch): array
    {
        $conn = DB::connection('tenant');
        $b = (int) $branch->id;
        $rows = fn ($q, array $cols) => collect($q->orderBy('id')->get($cols))->map(fn ($r) => (array) $r)->all();

        // Branch terminals (needed for terminal-printer scoping).
        $terminalIds = $conn->table('terminals')->where('branch_id', $b)->pluck('id')->all();
        $groupIds    = $conn->table('modifier_groups')->where('branch_id', $b)->pluck('id')->all();
        $comboIds    = $conn->table('combos')->where('branch_id', $b)->pluck('id')->all();

        return [
            'tenant' => [[
                'tenant_code'   => $tenant->tenant_code,
                'business_name' => $tenant->business_name,
                'currency_code' => $tenant->currency_code,
            ]],
            'branch' => [[
                'id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name,
                'business_type' => $branch->business_type, 'allow_negative_stock' => (bool) $branch->allow_negative_stock,
                'timezone' => $branch->timezone, 'tax_registration_no' => $branch->tax_registration_no,
                'is_tax_enabled' => (bool) $branch->is_tax_enabled, 'show_tax_number_on_invoice' => (bool) $branch->show_tax_number_on_invoice,
                'receipt_footer' => $branch->receipt_footer, 'address' => $branch->address,
                'phone' => $branch->phone, 'email' => $branch->email, 'status' => $branch->status,
            ]],
            'terminals' => $rows(
                $conn->table('terminals')->where('branch_id', $b)->where('status', 'active'),
                ['id', 'branch_id', 'code', 'name', 'device_identifier', 'requires_shift', 'status']
            ),
            'categories' => $rows(
                $conn->table('categories')->where('is_active', 1),
                ['id', 'parent_id', 'code', 'name', 'slug', 'sort_order', 'is_active']
            ),
            'units' => $rows(
                $conn->table('units')->where('is_active', 1),
                ['id', 'code', 'name', 'unit_type', 'base_factor', 'is_base', 'is_active']
            ),
            // Products: sellable/POS-visible; COST columns (default_purchase_price) EXCLUDED.
            'products' => $rows(
                $conn->table('products')->where('is_sellable', 1),
                ['id', 'category_id', 'unit_id', 'sku', 'name', 'slug', 'product_type', 'item_kind',
                 'is_pos_visible', 'is_stock_tracked', 'has_variants', 'default_selling_price',
                 'is_taxable', 'tax_rate_percent', 'image_path', 'status']
            ),
            // Variants: purchase_price (cost) EXCLUDED.
            'product_variants' => $rows(
                $conn->table('product_variants')->where('is_active', 1),
                ['id', 'product_id', 'sku', 'name', 'barcode', 'selling_price', 'is_default', 'is_active']
            ),
            'product_barcodes' => $rows(
                $conn->table('product_barcodes'),
                ['id', 'product_id', 'product_variant_id', 'barcode', 'barcode_type', 'is_primary']
            ),
            'product_branch_prices' => $rows(
                $conn->table('product_branch_prices')->where('branch_id', $b),
                ['id', 'branch_id', 'product_id', 'product_variant_id', 'selling_price', 'minimum_selling_price', 'is_available']
            ),
            'modifier_groups' => $rows(
                $conn->table('modifier_groups')->where('branch_id', $b),
                ['id', 'branch_id', 'name', 'min_select', 'max_select', 'is_required', 'sort_order', 'status']
            ),
            'modifiers' => $rows(
                $conn->table('modifiers')->whereIn('modifier_group_id', $groupIds ?: [0]),
                ['id', 'modifier_group_id', 'name', 'price_delta', 'linked_product_id', 'consume_stock',
                 'linked_quantity', 'linked_unit_id', 'is_default', 'sort_order', 'status']
            ),
            'combos' => $rows(
                $conn->table('combos')->where('branch_id', $b),
                ['id', 'branch_id', 'code', 'name', 'price', 'sort_order', 'status', 'description']
            ),
            'combo_components' => $rows(
                $conn->table('combo_components')->whereIn('combo_id', $comboIds ?: [0]),
                ['id', 'combo_id', 'product_id', 'product_variant_id', 'quantity', 'sort_order']
            ),
            // Payment methods: cash_bank_account_id (finance link) EXCLUDED.
            'payment_methods' => $rows(
                $conn->table('payment_methods')->where('is_active', 1),
                ['id', 'code', 'name', 'method_type', 'requires_reference', 'is_cash_drawer', 'is_active']
            ),
            'restaurant_floors' => $rows(
                $conn->table('restaurant_floors')->where('branch_id', $b),
                ['id', 'branch_id', 'name', 'code', 'status', 'sort_order']
            ),
            'restaurant_tables' => $rows(
                $conn->table('restaurant_tables')->where('branch_id', $b),
                ['id', 'branch_id', 'restaurant_floor_id', 'table_no', 'name', 'capacity', 'status', 'sort_order']
            ),
            'restaurant_waiters' => $rows(
                $conn->table('restaurant_waiters')->where('branch_id', $b),
                ['id', 'branch_id', 'name', 'code', 'phone', 'status']
            ),
            'delivery_channels' => $rows(
                $conn->table('delivery_channels')->where('is_active', 1),
                ['id', 'name', 'type', 'commission_percent', 'is_active', 'sort_order']
            ),
            'delivery_riders' => $rows(
                $conn->table('delivery_riders')->where('branch_id', $b),
                ['id', 'branch_id', 'name', 'phone', 'status']
            ),
            // Printer IP/port are branch-LAN facing (needed by the Print Agent), no secrets.
            'printers' => $rows(
                $conn->table('printers')->where('branch_id', $b),
                ['id', 'branch_id', 'name', 'code', 'printer_type', 'print_role', 'ip_address', 'port',
                 'paper_size', 'characters_per_line', 'is_default', 'is_active', 'agent_enabled']
            ),
            'receipt_layout_settings' => $rows(
                $conn->table('receipt_layout_settings')->where('branch_id', $b),
                ['id', 'branch_id', 'document_type', 'paper_size', 'show_logo', 'show_branch_name',
                 'show_branch_address', 'show_branch_phone', 'show_tax_number', 'show_cashier_name',
                 'show_customer_name', 'show_table_info', 'show_order_no', 'show_item_codes',
                 'show_payment_breakdown', 'header_text', 'footer_text', 'font_size', 'kot_font_size', 'is_active']
            ),
            'category_printer_mappings' => $rows(
                $conn->table('category_printer_mappings')->where('branch_id', $b),
                ['id', 'branch_id', 'category_id', 'printer_id', 'print_role', 'is_active']
            ),
            'terminal_printer_settings' => $rows(
                $conn->table('terminal_printer_settings')->whereIn('terminal_id', $terminalIds ?: [0]),
                ['id', 'terminal_id', 'receipt_printer_id', 'kot_printer_id', 'auto_print_receipt', 'auto_print_kot']
            ),
            'service_charge_settings' => $rows(
                $conn->table('service_charge_settings')->where('branch_id', $b),
                ['id', 'branch_id', 'charge_type', 'charge_value', 'order_types', 'is_taxable', 'is_active']
            ),
            'void_reasons' => $rows(
                $conn->table('void_reasons')->where('is_active', 1),
                ['id', 'name', 'reason_type', 'requires_manager_approval', 'is_active']
            ),
            // Staff: MINIMUM identity + role + branch assignment. NO password, remember_token,
            // email, phone, or reset tokens. Local staff auth/PINs are NOT shipped in v1.
            'users' => $this->userSection($conn, $b),
            'roles' => $rows(
                $conn->table('roles')->where('guard_name', 'tenant'),
                ['id', 'name', 'guard_name']
            ),
        ];
    }

    private function userSection($conn, int $branchId): array
    {
        $users = $conn->table('users')
            ->where('default_branch_id', $branchId)
            ->orderBy('id')
            ->get(['id', 'employee_code', 'name', 'default_branch_id', 'default_terminal_id', 'status', 'locale']);

        $userIds = $users->pluck('id')->all();
        // Spatie tenant role assignments (model_has_roles → roles.name), branch staff only.
        $roleMap = [];
        if ($userIds) {
            $assign = $conn->table('model_has_roles as mhr')
                ->join('roles as r', 'r.id', '=', 'mhr.role_id')
                ->where('mhr.model_type', \App\Models\Tenant\User::class)
                ->whereIn('mhr.model_id', $userIds)
                ->orderBy('r.name')
                ->get(['mhr.model_id as uid', 'r.name as role']);
            foreach ($assign as $a) {
                $roleMap[$a->uid][] = $a->role;
            }
        }

        return collect($users)->map(fn ($u) => [
            'id'                 => $u->id,
            'employee_code'      => $u->employee_code,
            'name'               => $u->name,
            'default_branch_id'  => $u->default_branch_id,
            'default_terminal_id' => $u->default_terminal_id,
            'status'             => $u->status,
            'locale'             => $u->locale,
            'roles'              => $roleMap[$u->id] ?? [],
        ])->all();
    }

    /* ── determinism helpers ─────────────────────────────────────────────── */

    /** Canonical JSON: recursively ksort assoc arrays; preserve list order (rows already id-sorted). */
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
        $manifest = [
            'schema_version' => $snapshot->schema_version,
            'snapshot_uuid'  => $snapshot->public_uuid,
            'tenant_id'      => $snapshot->tenant_id,
            'branch_id'      => $snapshot->branch_id,
            'sections'       => $summary,
        ];
        return hash('sha256', $this->canonicalJson($manifest));
    }

    public function audit(string $event, EdgeBootstrapSnapshot $snapshot): void
    {
        Log::info("[edge-bootstrap-audit] {$event}", [
            'snapshot' => $snapshot->public_uuid, 'tenant_id' => $snapshot->tenant_id, 'branch_id' => $snapshot->branch_id,
        ]);
    }
}
