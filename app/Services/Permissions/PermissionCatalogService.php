<?php

namespace App\Services\Permissions;

use App\Models\Master\Module;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * PERMISSION CENTER (Khatri onboarding, section C–K) — the business-friendly PRESENTATION layer over
 * the existing granular permissions.
 *
 * HARD RULES: permission keys (= route names) are NEVER renamed/merged/deleted — enforcement stays
 * exactly the granular `EnsureRoutePermission` model. This service only classifies each key into
 * Module → Feature → (View | Add | Edit | Delete | named special action) for the editor UI, using the
 * repository's real verb-suffix taxonomy + explicit overrides — never fragile guessing for SENSITIVE
 * actions: a suffix in SENSITIVE_ACTIONS can never land inside a generic CRUD bucket, so granting
 * "Edit" can never silently grant refund/void/post/approve/close.
 */
class PermissionCatalogService
{
    /** CRUD buckets by exact trailing route segment. */
    private const CRUD = [
        'view' => ['index', 'show'],
        'add' => ['create', 'store'],
        'edit' => ['edit', 'update'],
        'delete' => ['destroy'],
    ];

    /**
     * Segments that are ALWAYS a separately-visible action (security requirement — never folded into
     * CRUD). Grounded from the live route census.
     */
    private const SENSITIVE_ACTIONS = [
        'approve', 'reject', 'submit', 'post', 'reverse', 'void', 'cancel', 'close', 'close-form',
        'close-branch', 'close-branch-form', 'complete', 'resolve', 'ignore', 'payout', 'move',
        'merge', 'split-bill', 'retry', 'queue-kot', 'queue-receipt', 'mark-printed',
        'reprint-reminder', 'confirm-reminders', 'export', 'generate', 'revoke', 'activate',
        'deactivate', 'regenerate-token', 'default', 'verify', 'reset-password', 'manager-pin',
        'bulk-import', 'test-print', 'pairing-code', 'download-windows', 'sync', 'void-kot-item',
        'handovers', 'local-mode', 'reprint',
    ];

    /**
     * Explicit placement for support/lookup permissions (section I): each lives under the business
     * feature that consumes it; `shared` marks lookups used by MULTIPLE screens — the UI warns before
     * unchecking them and they are never bundled invisibly into another checkbox.
     */
    private const SUPPORT_OVERRIDES = [
        'tenant.ajax.products' => ['module' => 'catalog', 'feature' => 'Product Lookup (shared)', 'shared' => true],
        'tenant.ajax.sales' => ['module' => 'pos', 'feature' => 'Sale Lookup', 'shared' => false],
        'tenant.ajax.goods-receipts' => ['module' => 'purchasing', 'feature' => 'GRN Lookup', 'shared' => false],
        'tenant.ajax.manufacturing-customers' => ['module' => 'manufacturing', 'feature' => 'Manufacturing Lookups', 'shared' => true],
        'tenant.ajax.production-orders' => ['module' => 'manufacturing', 'feature' => 'Manufacturing Lookups', 'shared' => true],
        'tenant.ajax.material-requisitions' => ['module' => 'manufacturing', 'feature' => 'Manufacturing Lookups', 'shared' => true],
        'tenant.ajax.wip-jobs' => ['module' => 'manufacturing', 'feature' => 'Manufacturing Lookups', 'shared' => true],
        'tenant.ajax.finished-goods' => ['module' => 'manufacturing', 'feature' => 'Manufacturing Lookups', 'shared' => true],
    ];

    /**
     * CATERING-V1-CLOSURE-1 (§9): business-friendly grouping for the Catering
     * module — the editor shows a short list of human actions instead of a flat
     * list of raw routes. PRESENTATION ONLY: keys stay route names, buckets/sensitive
     * classification is unchanged, and route-level enforcement is untouched.
     */
    private const CATERING_FEATURES = [
        'tenant.catering.events.index' => 'View Catering',
        'tenant.catering.events.show' => 'View Catering',
        'tenant.catering.events.create' => 'Create / Edit Estimate',
        'tenant.catering.events.store' => 'Create / Edit Estimate',
        'tenant.catering.events.edit' => 'Create / Edit Estimate',
        'tenant.catering.events.update' => 'Create / Edit Estimate',
        'tenant.catering.estimates.update' => 'Create / Edit Estimate',
        'tenant.catering.estimates.reprice' => 'Create / Edit Estimate',
        // Adjusting one booking's materials or quoted price is estimate editing,
        // not product configuration — it changes a quotation, never a dish.
        'tenant.catering.line-cost-blocks.update' => 'Create / Edit Estimate',
        'tenant.catering.line-cost-blocks.reset' => 'Create / Edit Estimate',
        'tenant.catering.estimate-lines.quoted-rate' => 'Create / Edit Estimate',
        'tenant.catering.estimates.send' => 'Send / Revise Quote',
        'tenant.catering.estimates.accept' => 'Send / Revise Quote',
        'tenant.catering.estimates.revise' => 'Send / Revise Quote',
        'tenant.catering.events.confirm' => 'Confirm Booking',
        'tenant.catering.events.cancel' => 'Confirm Booking',
        'tenant.catering.material-rates.index' => 'Manage Material Rates',
        'tenant.catering.material-rates.store' => 'Manage Material Rates',
        'tenant.catering.rate-impact.index' => 'Manage Material Rates',
        'tenant.catering.rate-impact.apply' => 'Manage Material Rates',
        'tenant.catering.advances.store' => 'Record Advance',
        // Money OUT is its own grant. Whoever may take a payment does not
        // automatically get to hand one back.
        'tenant.catering.refunds.store' => 'Refund Customer',
        'tenant.catering.production-releases.store' => 'Release Production',
        'tenant.catering.production-releases.show' => 'Release Production',
        'tenant.catering.production-releases.print' => 'Print / Reprint',
        'tenant.catering.production-releases.reprint' => 'Print / Reprint',
        'tenant.catering.documents.estimate' => 'Print / Reprint',
        'tenant.catering.documents.kitchen-sheet' => 'Print / Reprint',
        'tenant.catering.documents.final-invoice' => 'Print / Reprint',
        'tenant.catering.material-issues.store' => 'Issue Materials',
        // The store counter, and the booking lookup its selection modal reads.
        // Grouped together so the screen and the list it needs are granted as
        // one thing — a granted screen whose lookup is denied shows an empty
        // modal and looks broken.
        'tenant.catering.store-issues.index' => 'Store Issue',
        'tenant.catering.store-issues.store' => 'Store Issue',
        'tenant.catering.store-issues.bookings' => 'Store Issue',
        'tenant.catering.final-invoices.store' => 'Finalise Event',
        'tenant.catering.events.close' => 'Finalise Event',
        'tenant.catering.profiles.index' => 'Catering Products',
        'tenant.catering.profiles.store' => 'Catering Products',
        'tenant.catering.profiles.update' => 'Catering Products',
        // Same feature as the profile it hangs off: whoever may configure a
        // catering product may say what it is made of.
        'tenant.catering.cost-blocks.edit' => 'Catering Products',
        'tenant.catering.cost-blocks.update' => 'Catering Products',
        'tenant.catering.printer-mappings.index' => 'Catering Settings',
        'tenant.catering.printer-mappings.store' => 'Catering Settings',
        'tenant.catering.printer-mappings.destroy' => 'Catering Settings',
        'tenant.catering.printer-mappings.copy-from-pos' => 'Catering Settings',
        'tenant.catering.settings.index' => 'Catering Settings',
        'tenant.catering.settings.update' => 'Catering Settings',
    ];

    /**
     * Build the editor catalog.
     *
     * @param  Collection<int, \Spatie\Permission\Models\Permission>  $permissions  all tenant-guard permissions
     * @param  array<int, string>|null  $entitledModuleKeys  module keys enabled for the tenant's plan (null = show all)
     * @return array{modules: array, managed: array<int,string>, unavailable_modules: array<int,string>}
     */
    public function build(Collection $permissions, ?array $entitledModuleKeys = null): array
    {
        $modules = Module::orderBy('sort_order')->get()->keyBy('key');
        $routeKeyToModule = [];
        foreach ($modules as $module) {
            foreach ((array) $module->route_module_keys as $routeKey) {
                $routeKeyToModule[$routeKey] = $module->key;
            }
        }

        $tree = [];
        $managed = [];
        foreach ($permissions->sortBy('name') as $permission) {
            $name = $permission->name;
            $placed = $this->place($name, $routeKeyToModule);
            if ($placed === null) {
                continue; // non-tenant or unmappable key — never rendered, never touched by the editor
            }
            [$moduleKey, $feature, $bucket, $label, $shared] = $placed;

            if ($entitledModuleKeys !== null && ! in_array($moduleKey, $entitledModuleKeys, true)) {
                continue; // non-entitled module: hidden AND unmanaged — existing grants survive untouched
            }

            $managed[] = $name;
            $tree[$moduleKey]['key'] = $moduleKey;
            $tree[$moduleKey]['name'] = $modules[$moduleKey]->name ?? Str::title(str_replace('_', ' ', $moduleKey));
            $tree[$moduleKey]['features'][$feature]['name'] = $feature;
            if ($bucket === 'action') {
                $tree[$moduleKey]['features'][$feature]['actions'][] = ['name' => $name, 'label' => $label, 'shared' => $shared];
            } else {
                $tree[$moduleKey]['features'][$feature]['groups'][$bucket][] = ['name' => $name, 'label' => $label, 'shared' => $shared];
            }
        }

        // stable ordering: module sort_order, then feature name.
        $ordered = [];
        foreach ($modules as $module) {
            if (isset($tree[$module->key])) {
                $node = $tree[$module->key];
                ksort($node['features']);
                $ordered[] = $node;
            }
        }

        $unavailable = [];
        if ($entitledModuleKeys !== null) {
            $unavailable = $modules->keys()->diff($entitledModuleKeys)->values()->all();
        }

        return ['modules' => $ordered, 'managed' => array_values(array_unique($managed)), 'unavailable_modules' => $unavailable];
    }

    /** @return array{0:string,1:string,2:string,3:string,4:bool}|null [moduleKey, feature, bucket|'action', label, shared] */
    private function place(string $name, array $routeKeyToModule): ?array
    {
        if (! str_starts_with($name, 'tenant.')) {
            return null;
        }

        if (isset(self::SUPPORT_OVERRIDES[$name])) {
            $o = self::SUPPORT_OVERRIDES[$name];

            return [$o['module'], $o['feature'], 'action', $this->humanize(Str::afterLast($name, '.')).' (support)', $o['shared']];
        }

        $segments = explode('.', $name);
        $routeKey = implode('.', array_slice($segments, 0, 2)); // PermissionSyncService::moduleKey() convention
        $moduleKey = $routeKeyToModule[$routeKey] ?? null;
        if ($moduleKey === null) {
            return null; // unmapped (billing/api/etc.) — out of the editor's scope by design
        }

        $last = end($segments);
        $featureSegments = array_slice($segments, 1, max(count($segments) - 2, 1));
        $feature = self::CATERING_FEATURES[$name] ?? $this->humanize(implode(' ', $featureSegments));

        // SENSITIVE first — a sensitive suffix can never be classified as CRUD.
        if (in_array($last, self::SENSITIVE_ACTIONS, true)) {
            return [$moduleKey, $feature, 'action', $this->humanize($last), false];
        }
        foreach (self::CRUD as $bucket => $suffixes) {
            if (in_array($last, $suffixes, true)) {
                return [$moduleKey, $feature, $bucket, $this->humanize($last), false];
            }
        }

        // everything else stays individually visible (never silently bundled).
        return [$moduleKey, $feature, 'action', $this->humanize($last), false];
    }

    private function humanize(string $value): string
    {
        return Str::title(str_replace(['-', '_', '.'], ' ', $value));
    }
}
