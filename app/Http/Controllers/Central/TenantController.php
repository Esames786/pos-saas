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
use App\Models\Master\TenantDomain;
use App\Services\Central\TenantBackupService;
use App\Services\Central\TenantOpsService;
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

        $tenant = Tenant::create([
            'tenant_code'   => $request->tenant_code,
            'business_name' => $request->business_name,
            'owner_name'    => $request->owner_name,
            'owner_email'   => $request->owner_email,
            'currency_code' => $request->currency_code,
            'status'        => 'pending',
            'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
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

    public function show(Tenant $tenant)
    {
        $tenant->load(['domains', 'database', 'subscription.plan']);

        $recentInvoices = $tenant->invoices()
            ->with('plan')
            ->latest()
            ->take(5)
            ->get();

        return view('central.tenants.show', [
            'tenant'         => $tenant,
            'plans'          => Plan::where('is_active', true)->orderBy('name')->get(),
            'recentInvoices' => $recentInvoices,
        ]);
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

        return view('central.tenants.backups', compact('tenant', 'backups'));
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
