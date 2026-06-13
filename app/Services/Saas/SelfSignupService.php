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
     * Provision a brand-new tenant from a public trial signup.
     *
     * Creates the master records (tenant + primary domain + trial subscription)
     * in one transaction, then provisions the tenant DB / owner user via the
     * existing TenantProvisioner. On any failure the half-created tenant — and
     * its database — are removed so no orphans are left behind.
     */
    public function registerTrial(array $data): Tenant
    {
        $tenant = null;

        try {
            $plan = Plan::where('is_active', true)
                ->where('is_public', true)
                ->findOrFail($data['plan_id']);

            $tenantCode = Str::of($data['tenant_code'])
                ->lower()
                ->replaceMatches('/[^a-z0-9_-]/', '-')
                ->trim('-')
                ->toString();

            $domain = $tenantCode . '.' . config('tenancy.tenant_base_domain');

            $trialDays   = (int) ($plan->trial_days ?: config('saas.default_trial_days', 14));
            $trialEndsAt = $trialDays > 0 ? now()->addDays($trialDays) : null;

            $tenant = DB::connection('master')->transaction(function () use ($data, $plan, $tenantCode, $domain, $trialEndsAt) {
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
                    'trial_ends_at'          => $trialEndsAt,
                    'current_period_ends_at' => null,
                ]);

                return $tenant;
            });

            return $this->provisioner
                ->provisionTenant($tenant->fresh(), $data['password'])
                ->fresh(['domains', 'database', 'subscription.plan']);
        } catch (Throwable $e) {
            $this->cleanupFailedSignup($tenant);

            throw $e;
        }
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
