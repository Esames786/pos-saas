<?php

namespace App\Services\Saas;

use App\Models\Master\Plan;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDomain;
use App\Services\Tenancy\TenancyManager;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SelfSignupService
{
    public function __construct(
        private TenantProvisioner $provisioner,
        private TenancyManager $tenancyManager,
    ) {}

    /**
     * Provision a brand-new tenant from a public trial signup, in one go.
     *
     * Kept for any caller that wants the old synchronous behaviour. The public signup no longer
     * uses it: TRIAL-SIGNUP-QUEUE-1 creates the master rows in the request (createPendingTrial)
     * and provisions on the queue worker (provisionPendingTrial).
     */
    public function registerTrial(array $data): Tenant
    {
        return $this->provisionPendingTrial($this->createPendingTrial($data), $data['password']);
    }

    /**
     * TRIAL-SIGNUP-QUEUE-1 — the fast half, run inside the signup request.
     *
     * Creates the master records (tenant `pending` + primary domain + trial subscription) in one
     * transaction. No database and no migrations, so the request returns in about a second.
     */
    public function createPendingTrial(array $data): Tenant
    {
        $plan = Plan::where('is_active', true)
            ->where('is_public', true)
            ->where('is_custom', false)
            ->findOrFail($data['plan_id']);

        $tenantCode = Str::of($data['tenant_code'])
            ->lower()
            ->replaceMatches('/[^a-z0-9_-]/', '-')
            ->trim('-')
            ->toString();

        $domain = $tenantCode . '.' . config('tenancy.tenant_base_domain');

        $trialDays   = (int) ($plan->trial_days ?: config('saas.default_trial_days', 14));
        $trialEndsAt = $trialDays > 0 ? now()->addDays($trialDays) : null;

        return DB::connection('master')->transaction(function () use ($data, $plan, $tenantCode, $domain, $trialEndsAt) {
            $tenant = Tenant::create([
                'tenant_code'   => $tenantCode,
                'business_name' => $data['business_name'],
                'owner_name'    => $data['owner_name'],
                'owner_email'   => $data['owner_email'],
                'currency_code' => $data['currency_code'] ?? 'PKR',
                'status'        => 'pending',
                'trial_ends_at' => $trialEndsAt,
            ]);

            TenantDomain::create([
                'tenant_id'  => $tenant->id,
                'domain'     => $domain,
                'is_primary' => true,
                'status'     => 'pending',
            ]);

            Subscription::create([
                'tenant_id'              => $tenant->id,
                'plan_id'                => $plan->id,
                'status'                 => 'trial',
                'billing_period'         => ($data['billing_period'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly',
                'trial_ends_at'          => $trialEndsAt,
                'current_period_ends_at' => null,
            ]);

            return $tenant;
        });
    }

    /**
     * TRIAL-SIGNUP-QUEUE-1 — the slow half: the tenant database, migrations and owner.
     *
     * On any failure the half-created tenant — and its database — are removed so no orphans are
     * left behind, exactly as before. The caller decides what to tell the customer.
     */
    public function provisionPendingTrial(Tenant $tenant, string $ownerPassword, bool $passwordIsHashed = false): Tenant
    {
        try {
            return $this->provisioner
                ->provisionTenant($tenant->fresh(), $ownerPassword, $passwordIsHashed)
                ->fresh(['domains', 'database', 'subscription.plan']);
        } catch (Throwable $e) {
            $this->cleanupFailedSignup($tenant);

            throw $e;
        }
    }

    /** Remove a signup that will not complete. Safe to call twice, or for a tenant already gone. */
    public function discardFailedTrial(?Tenant $tenant): void
    {
        $this->cleanupFailedSignup($tenant);
    }

    /**
     * Remove a tenant whose provisioning failed: drop its database and delete
     * the master rows. Safe to call with a partially-created tenant.
     */
    private function cleanupFailedSignup(?Tenant $tenant): void
    {
        if (!$tenant || !$tenant->exists) {
            return;
        }

        // Make sure we are back on the master connection before touching it.
        $this->tenancyManager->deactivate();
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));

        try {
            $this->dropTenantDatabaseIfExists($tenant);

            $tenant->database()->delete();
            $tenant->domains()->delete();
            $tenant->subscription()->delete();
            $tenant->delete();
        } catch (Throwable $e) {
            // Swallow cleanup errors so the original failure is what surfaces.
        }
    }

    /**
     * Drop only the deterministic pos_tenant_{safe_code} database for this
     * tenant — never an arbitrary name.
     */
    private function dropTenantDatabaseIfExists(Tenant $tenant): void
    {
        $safeCode = Str::of($tenant->tenant_code)
            ->lower()
            ->replaceMatches('/[^a-z0-9_]/', '_')
            ->trim('_')
            ->toString();

        if ($safeCode === '') {
            return;
        }

        $dbName = 'pos_tenant_' . $safeCode;

        DB::connection('master')->statement("DROP DATABASE IF EXISTS `{$dbName}`");
    }
}
