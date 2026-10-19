<?php

namespace App\Jobs\Saas;

use App\Mail\TrialWorkspaceFailedMail;
use App\Models\Master\Tenant;
use App\Services\Saas\SelfSignupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * TRIAL-SIGNUP-QUEUE-1 — build a trial workspace off the signup request.
 *
 * Measured on prod, 7 Oct: the public "Start Free Trial" button held the browser for 70 seconds
 * while one request created the database, ran every tenant migration and seeded the owner, and
 * only then sent the first email. The request now creates the master rows and returns; this job
 * does the rest on the queue worker.
 *
 * WORKER FACTS THIS RELIES ON (bingoo-queue.service, one process, `--queue=default`, no --timeout)
 * - The worker's default timeout is 60s and a build takes ~70s, so the job carries its own.
 * - One attempt only. A failed build is removed (database dropped, master rows deleted), so a
 *   second attempt would have nothing to build. If the worker dies mid-build the re-delivered job
 *   exceeds its one attempt and goes straight to failed(), which removes the half-made workspace.
 * - The worker is one long process for every tenant. Activating the new tenant rewrites the
 *   `tenant` connection config and deactivate() does not restore it, so this job does.
 * - Only the password HASH is carried: the queue payload is a row in the `jobs` table.
 */
class ProvisionTrialWorkspaceJob implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 900;

    public $failOnTimeout = true;

    public function __construct(
        public int $tenantId,
        public string $ownerPasswordHash,
        public string $loginUrl,
        public string $businessName,
        public string $ownerEmail,
        // WEBSITE-I18N-GEO-1 P3: the signup's language, for the failure email (the tenant row may be gone
        // by then). Optional, so a job queued before this field existed still runs.
        public ?string $locale = null,
    ) {}

    public function handle(SelfSignupService $signup): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || $tenant->status !== 'pending') {
            return;   // removed, or already built — nothing to do
        }

        $tenantConnection = config('database.connections.tenant');
        try {
            $signup->provisionPendingTrial($tenant, $this->ownerPasswordHash, passwordIsHashed: true);
        } finally {
            config(['database.connections.tenant' => $tenantConnection]);
            DB::purge('tenant');
            DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        }

        SendTrialReadyMailJob::dispatch($this->tenantId, $this->loginUrl);
    }

    /**
     * The build failed or timed out. An exception has already removed the half-made workspace;
     * a timeout kills the job before it could, so remove it here too (safe twice). A workspace that
     * DID finish is never removed, whatever failed after it.
     */
    public function failed(?Throwable $e): void
    {
        $signup = app(SelfSignupService::class);
        $tenant = Tenant::find($this->tenantId);
        if ($tenant && $tenant->status === 'active') {
            return;
        }
        $locale = $tenant?->locale ?: ($this->locale ?: 'en');
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $signup->discardFailedTrial($tenant);

        try {
            Mail::to($this->ownerEmail)->locale($locale)->send(new TrialWorkspaceFailedMail(
                brand: config('saas.brand_name', 'Bingoo'),
                businessName: $this->businessName,
                tryAgainUrl: \App\Support\PublicLocale::url('/start-trial', $locale),
                supportEmail: config('saas.contact.support_email', 'support@bingoopos.com'),
            ));
        } catch (Throwable $mailError) {
            report($mailError);
        }
    }
}
