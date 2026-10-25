<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Requests\Central\ProvisionTenantRequest;
use App\Http\Requests\Central\StoreTenantRequest;
use App\Http\Requests\Central\UpdateTenantRequest;
use App\Models\Master\Plan;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Master\TenantBackup;
use App\Models\Master\TenantBackupSetting;
use App\Models\Master\TenantDomain;
use App\Services\Central\MasterOverviewService;
use App\Services\Central\ModuleCatalogService;
use App\Services\Central\SystemUsageService;
use App\Services\Central\TenantBackupService;
use App\Services\Central\TenantBranchService;
use App\Services\Central\TenantBusinessService;
use App\Services\Central\TenantOpsService;
use App\Support\Business;
use App\Support\TenantFeatureDefaults;
use App\Support\TenantFeatures;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        $query = Tenant::query()
            ->with(['domains', 'database', 'subscription.plan'])
            ->latest();

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('tenant_code', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('owner_email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // MASTER-UNIVERSE-1: filter by primary business ("none" = not set yet).
        if ($request->filled('business')) {
            $request->business === 'none'
                ? $query->whereNull('primary_business')
                : $query->where('primary_business', $request->business);
        }

        $tenants = $query->paginate(15)->withQueryString();

        return view('central.tenants.index', compact('tenants'));
    }

    public function create()
    {
        return view('central.tenants.create', [
            'plans' => Plan::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreTenantRequest $request)
    {
        $trialDays = (int) ($request->trial_days ?: 60);
        // ROLLOUT-1: the switches a new tenant starts with, from the plan picked here (none without a plan).
        $plan = $request->filled('plan_id') ? Plan::with('enabledModules')->find($request->plan_id) : null;

        $tenant = Tenant::create([
            'tenant_code'   => $request->tenant_code,
            'business_name' => $request->business_name,
            'owner_name'    => $request->owner_name,
            'owner_email'   => $request->owner_email,
            'currency_code' => $request->currency_code,
            'status'        => 'pending',
            'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
            'features'      => $plan ? TenantFeatureDefaults::for(null, $plan->enabledModules->pluck('key')->all()) : null,
        ]);

        TenantDomain::create([
            'tenant_id'  => $tenant->id,
            'domain'     => $request->subdomain . '.' . config('tenancy.tenant_base_domain'),
            'is_primary' => true,
            'status'     => 'pending',
        ]);

        if ($request->filled('plan_id')) {
            Subscription::create([
                'tenant_id'    => $tenant->id,
                'plan_id'      => $request->plan_id,
                'status'       => 'trial',
                'trial_ends_at' => $tenant->trial_ends_at,
            ]);
        }

        return redirect('/tenants/' . $tenant->id)
            ->with('status', 'Tenant created. Provision the database to activate.');
    }

    /**
     * MASTER-UNIVERSE-1 — the tenant hub: Overview · Business & switches · Modules · Branches ·
     * Billing & invoices · Backups · Domains & database · Danger zone. Everything the old page did is
     * still here, moved into tabs. Only the Branches tab opens the tenant database (read-only).
     */
    public function show(
        Tenant $tenant,
        TenantBusinessService $business,
        ModuleCatalogService $catalog,
        TenantBranchService $branches,
        SystemUsageService $usage,
        MasterOverviewService $overview,
    ) {
        $tenant->load(['domains', 'database', 'subscription.plan', 'backupSetting']);

        $recentInvoices = $tenant->invoices()
            ->with('plan')
            ->latest()
            ->take(5)
            ->get();

        $planModules = $business->planModules($tenant);
        $snapshot = $usage->latestForTenant($tenant->id);
        $card = $overview->card(
            $tenant,
            $snapshot,
            $usage->lastBackups()[$tenant->id] ?? null,
            $overview->invoicesDue($tenant->id)[$tenant->id] ?? null,
            (int) $usage->latestPerTenant()->sum(fn ($s) => (int) ($s->sales_30d ?? 0)),
        );

        return view('central.tenants.show', [
            'tenant'         => $tenant,
            'plans'          => Plan::where('is_active', true)->orderBy('name')->get(),
            'recentInvoices' => $recentInvoices,
            'card'           => $card,
            'snapshot'       => $snapshot,
            'businesses'     => Business::all(),
            'planModules'    => $planModules,
            'features'       => TenantFeatures::all(),
            'history'        => $business->history($tenant, 30),
            'modules'        => $catalog->all(true),
            'branchList'     => $branches->list($tenant),
            'branchDefaultType' => TenantBranchService::defaultType($tenant->primary_business),
            'backups'        => TenantBackup::where('tenant_id', $tenant->id)->with('creator')->latest()->take(5)->get(),
            'backupCount'    => TenantBackup::where('tenant_id', $tenant->id)->count(),
            'backupSetting'  => $tenant->backupSetting ?? new TenantBackupSetting(['retention_days' => 7]),
            'resettable'     => $tenant->isDemo() && in_array($tenant->tenant_code, (array) config('saas.demos.reset_tenant_codes', []), true),
        ]);
    }

    /** MASTER-UNIVERSE-1 — add a branch from Master (same insert as the tenant's own Branches screen). */
    public function storeBranch(Request $request, Tenant $tenant, TenantBranchService $branches)
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:190'],
            'code'          => ['nullable', 'string', 'max:50'],
            'address'       => ['nullable', 'string', 'max:500'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'business_type' => ['required', Rule::in(['store', 'restaurant', 'hybrid'])],
            'timezone'      => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        try {
            $label = $branches->create($tenant, $data, $this->adminId());
        } catch (\RuntimeException $e) {
            return redirect('/tenants/' . $tenant->id . '#branches')->withInput()->withErrors(['branch' => $e->getMessage()]);
        }

        return redirect('/tenants/' . $tenant->id . '#branches')
            ->with('status', "Branch \"{$label}\" added to {$tenant->tenant_code}. Add its terminals and staff from the tenant's own screens.");
    }

    public function edit(Tenant $tenant)
    {
        $tenant->load(['subscription']);

        return view('central.tenants.edit', [
            'tenant' => $tenant,
            'plans'  => Plan::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        $tenant->update([
            'tenant_code'   => $request->tenant_code,
            'business_name' => $request->business_name,
            'owner_name'    => $request->owner_name,
            'owner_email'   => $request->owner_email,
            'currency_code' => $request->currency_code,
            'trial_ends_at' => $request->trial_ends_at,
        ]);

        if ($request->filled('plan_id')) {
            Subscription::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'plan_id'      => $request->plan_id,
                    'status'       => $tenant->subscription?->status ?: 'trial',
                    'trial_ends_at' => $tenant->trial_ends_at,
                ]
            );
        }

        return redirect('/tenants/' . $tenant->id)
            ->with('status', 'Tenant updated successfully.');
    }

    public function provision(ProvisionTenantRequest $request, Tenant $tenant, TenantProvisioner $provisioner)
    {
        $provisioner->provisionTenant($tenant, $request->owner_password);

        return redirect('/tenants/' . $tenant->id)
            ->with('status', 'Tenant database provisioned and activated successfully.');
    }

    public function activate(Tenant $tenant)
    {
        if (!$tenant->database || $tenant->database->migration_status !== 'completed') {
            return back()->withErrors([
                'tenant' => 'Tenant database must be provisioned before activation.',
            ]);
        }

        $tenant->update([
            'status'       => 'active',
            'activated_at' => $tenant->activated_at ?: now(),
        ]);

        $tenant->domains()->where('is_primary', true)->update(['status' => 'active']);

        return back()->with('status', 'Tenant activated successfully.');
    }

    public function suspend(Tenant $tenant)
    {
        $tenant->update(['status' => 'suspended']);

        return back()->with('status', 'Tenant suspended successfully.');
    }

    public function cancel(Tenant $tenant)
    {
        $tenant->update(['status' => 'cancelled']);

        return back()->with('status', 'Tenant cancelled successfully.');
    }

    public function updateSubscription(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'exists:plans,id'],
            'status' => ['required', Rule::in(['trial', 'active', 'past_due', 'cancelled'])],
            'trial_ends_at' => ['nullable', 'date'],
            'current_period_ends_at' => ['nullable', 'date'],
        ]);

        Subscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $data['plan_id'] ?? null,
                'status' => $data['status'],
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
                'current_period_ends_at' => $data['current_period_ends_at'] ?? null,
            ]
        );

        return redirect('/tenants/' . $tenant->id)
            ->with('status', 'Tenant subscription updated successfully.');
    }

    /* ─── MASTER-TENANT-OPS-1: backup / restore / reset / sync ───────────────── */

    private function adminId(): ?int
    {
        return auth('central')->id();
    }

    /** Create a manual backup of one tenant DB. */
    public function backup(Tenant $tenant, TenantBackupService $service)
    {
        try {
            $backup = $service->backup($tenant, 'manual', $this->adminId());
            return back()->with('status', "Backup created for {$tenant->tenant_code} ({$backup->humanSize()}).");
        } catch (\Throwable $e) {
            return back()->withErrors(['ops' => $e->getMessage()]);
        }
    }

    /** Backup history for one tenant. */
    public function backups(Tenant $tenant)
    {
        $backups = TenantBackup::where('tenant_id', $tenant->id)
            ->with('creator')
            ->latest()
            ->paginate(20);

        $setting = TenantBackupSetting::firstOrNew(['tenant_id' => $tenant->id]);

        return view('central.tenants.backups', compact('tenant', 'backups', 'setting'));
    }

    /** TENANT-AUTO-BACKUP-1: save a tenant's automatic backup schedule (on/off, up to 3 PKT times). */
    public function saveBackupSettings(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'is_enabled'     => ['nullable', 'boolean'],
            'times'          => ['array', 'max:3'],
            'times.*'        => ['nullable', 'date_format:H:i'],
            'timezone'       => ['nullable', 'string', 'max:64', 'timezone'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        // Normalise: drop blanks, de-dupe, keep at most 3, sorted.
        $times = collect($data['times'] ?? [])
            ->map(fn ($t) => trim((string) $t))
            ->filter(fn ($t) => preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $t))
            ->unique()->sort()->values()->take(3)->all();

        $enabled = $request->boolean('is_enabled');

        if ($enabled && empty($times)) {
            return back()->withErrors(['ops' => 'Add at least one backup time before turning auto-backup on.']);
        }

        TenantBackupSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'is_enabled'     => $enabled,
                'times'          => $times,
                'timezone'       => $data['timezone'] ?? 'Asia/Karachi',
                'retention_days' => (int) $data['retention_days'],
            ]
        );

        // MASTER-UNIVERSE-1: the tenant hub's Backups tab posts here too and wants to come back to it.
        $back = $request->input('return') === 'hub' ? '/tenants/' . $tenant->id . '#backups' : '/tenants/' . $tenant->id . '/backups';

        return redirect($back)
            ->with('status', $enabled
                ? 'Auto-backup ON — ' . implode(', ', $times) . ' (' . ($data['timezone'] ?? 'Asia/Karachi') . '), keeping ' . (int) $data['retention_days'] . ' days.'
                : 'Auto-backup turned off.');
    }

    /** Stream a backup file to the (super-admin) browser. Never exposes the abs path. */
    public function downloadBackup(TenantBackup $backup)
    {
        if (! $backup->fileExists()) {
            return back()->withErrors(['ops' => 'Backup file is missing on disk.']);
        }

        return Storage::disk($backup->disk)->download($backup->path, $backup->file_name, [
            'Content-Type' => 'application/sql',
        ]);
    }

    /** Restore a tenant from a backup (requires typing RESTORE + the tenant code). */
    public function restoreBackup(Request $request, TenantBackup $backup, TenantBackupService $backupService, TenantOpsService $opsService)
    {
        $tenant = Tenant::with('database')->findOrFail($backup->tenant_id);

        $this->assertConfirmed($request, 'confirm', 'RESTORE');
        $this->assertTenantCode($request, $tenant);

        try {
            $backupService->restore($backup, $tenant, $this->adminId());
            $sync = $opsService->syncTenant($tenant); // migrate + permissions after import
            $note = $sync['status'] === 'ok' ? '' : ' (sync warning: ' . $sync['error'] . ')';

            return redirect('/tenants/' . $tenant->id . '/backups')
                ->with('status', "Restored {$tenant->tenant_code} from {$backup->file_name}. A pre-restore backup was created first.{$note}");
        } catch (\Throwable $e) {
            return back()->withErrors(['ops' => $e->getMessage()]);
        }
    }

    public function deleteBackup(TenantBackup $backup, TenantBackupService $service)
    {
        $tenantId = $backup->tenant_id;
        $service->deleteBackup($backup);

        return redirect('/tenants/' . $tenantId . '/backups')->with('status', 'Backup deleted.');
    }

    /** Reset one tenant (requires RESET + tenant code + checkbox). Backup taken first. */
    public function reset(Request $request, Tenant $tenant, TenantOpsService $service)
    {
        $this->assertConfirmed($request, 'confirm', 'RESET');
        $this->assertTenantCode($request, $tenant);

        if (! $request->boolean('understand')) {
            throw ValidationException::withMessages(['understand' => 'Please confirm you understand this deletes and reseeds tenant data.']);
        }

        try {
            $service->resetTenant($tenant, $this->adminId());
            return back()->with('status', "Tenant {$tenant->tenant_code} reset (a pre-reset backup was created first).");
        } catch (\Throwable $e) {
            return back()->withErrors(['ops' => $e->getMessage()]);
        }
    }

    /** Sync one tenant (migrate + permissions). No data loss. */
    public function sync(Tenant $tenant, TenantOpsService $service)
    {
        $res = $service->syncTenant($tenant);

        return $res['status'] === 'ok'
            ? back()->with('status', "Synced {$tenant->tenant_code}: {$res['migrate']}, permissions {$res['permissions']}.")
            : back()->withErrors(['ops' => "Sync failed for {$tenant->tenant_code}: {$res['error']}"]);
    }

    /** Sync all tenants (requires typing SYNC ALL). */
    public function syncAll(Request $request, TenantOpsService $service)
    {
        $this->assertConfirmed($request, 'confirm', 'SYNC ALL');
        $results = $service->syncAll();

        return view('central.tenants.ops-result', [
            'title'   => 'Sync All Tenants — Result',
            'columns' => ['tenant_code', 'migrate', 'permissions', 'status', 'error'],
            'results' => $results,
        ]);
    }

    /** Backup all tenants (requires typing BACKUP ALL). */
    public function backupAll(Request $request, TenantOpsService $service)
    {
        $this->assertConfirmed($request, 'confirm', 'BACKUP ALL');
        $results = $service->backupAll($this->adminId());

        return view('central.tenants.ops-result', [
            'title'   => 'Backup All Tenants — Result',
            'columns' => ['tenant_code', 'status', 'file', 'size', 'error'],
            'results' => $results,
        ]);
    }

    /** Reset all demo tenants (requires typing RESET DEMOS). Backup each first. */
    public function resetDemoTenants(Request $request, TenantOpsService $service)
    {
        $this->assertConfirmed($request, 'confirm', 'RESET DEMOS');
        $results = $service->resetDemoTenants($this->adminId());

        return view('central.tenants.ops-result', [
            'title'   => 'Reset Demo Tenants — Result',
            'columns' => ['tenant_code', 'status', 'plan'],
            'results' => $results,
        ]);
    }

    private function assertConfirmed(Request $request, string $field, string $expected): void
    {
        if (trim((string) $request->input($field)) !== $expected) {
            throw ValidationException::withMessages([$field => "Type {$expected} exactly to confirm this action."]);
        }
    }

    private function assertTenantCode(Request $request, Tenant $tenant): void
    {
        if (trim((string) $request->input('tenant_code')) !== $tenant->tenant_code) {
            throw ValidationException::withMessages(['tenant_code' => "Type the tenant code ({$tenant->tenant_code}) to confirm."]);
        }
    }
}
