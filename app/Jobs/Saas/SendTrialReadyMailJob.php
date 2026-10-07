<?php

namespace App\Jobs\Saas;

use App\Mail\TrialWorkspaceCreatedMail;
use App\Models\Master\Tenant;
use App\Services\Saas\TrialHttpsProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * TRIAL-SIGNUP-QUEUE-1 — the "your workspace is ready" email, sent when its link actually works.
 *
 * A new subdomain joins the SSL certificate through `bingoo-cert-sync`, a root cron on the server.
 * Until it does, the link in this email opens on a "Not secure" warning — the first thing a new
 * customer would see. So the job checks the address the way a browser does and waits, 30 seconds
 * at a time. It waits at most 15 minutes: a late certificate must never cost the customer the email.
 */
class SendTrialReadyMailJob implements ShouldQueue
{
    use Queueable;

    public const RECHECK_SECONDS = 30;

    public const WAIT_AT_MOST_SECONDS = 900;

    public $tries = 40;   // 15 minutes of 30-second waits, and room for a few mail-server hiccups

    public int $sendAnywayAt;

    public function __construct(
        public int $tenantId,
        public string $loginUrl,
    ) {
        $this->sendAnywayAt = now()->addSeconds(self::WAIT_AT_MOST_SECONDS)->timestamp;
    }

    public function handle(TrialHttpsProbe $probe): void
    {
        $tenant = Tenant::with('subscription')->find($this->tenantId);
        if (! $tenant || $tenant->status !== 'active') {
            return;
        }

        $host = (string) parse_url($this->loginUrl, PHP_URL_HOST);
        $needsHttps = str_starts_with($this->loginUrl, 'https://');
        if ($needsHttps && now()->timestamp < $this->sendAnywayAt && ! $probe->serves($host)) {
            $this->release(self::RECHECK_SECONDS);

            return;
        }

        $trialEnds = $tenant->subscription?->trial_ends_at;
        Mail::to($tenant->owner_email)->send(new TrialWorkspaceCreatedMail(
            brand: config('saas.brand_name', 'Bingoo'),
            businessName: $tenant->business_name,
            loginUrl: $this->loginUrl,
            ownerEmail: $tenant->owner_email,
            trialEnds: $trialEnds ? Carbon::parse($trialEnds)->format('F j, Y') : null,
            supportEmail: config('saas.contact.support_email', 'support@bingoopos.com'),
        ));
    }
}
