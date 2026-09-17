<?php

namespace App\Jobs\Catering;

use App\Mail\Catering\CateringCustomerMail;
use App\Models\Master\Tenant;
use App\Models\Tenant\CateringEmailLog;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * CATERING-MAIL-QUEUE-1 — the customer email leaves the request.
 *
 * Reported from the live trial on 2026-09-17: recording an advance spun the
 * button forever, and refreshing showed the advance had saved. Both halves were
 * true. The advance commits in its own transaction and THEN the controller sent
 * the customer email with `Mail::to()->send()` — a real SMTP conversation inside
 * the HTTP request. The data was safe; the operator was left watching a spinner
 * until the request timed out.
 *
 * The evidence was in `catering_email_logs`: three rows to an external address
 * with `sent_at` NULL and `error` NULL — claimed, never finished, and no error
 * recorded because the request died before the catch block could run. Meanwhile
 * every row addressed to the tenant's own domain had sent, because those go out
 * from the scheduler rather than from somebody's browser.
 *
 * WHY A JOB AND NOT `implements ShouldQueue` ON THE MAILABLE
 *
 * The mailable holds CateringEvent and CateringEstimate and uses
 * SerializesModels, which stores a class and an id and re-fetches on the worker.
 * The worker has NO tenant connection: `queue:work` runs one process for every
 * tenant in the system. Re-fetching would either fail outright or — far worse —
 * read whichever tenant database happened to be configured, and email one
 * customer's booking to another. So this job carries the tenant id and ACTIVATES
 * the tenant before it touches a single model.
 *
 * Only ids travel. Nothing about the booking is serialised into the queue
 * payload, so a booking edited between queueing and sending is described by the
 * email as it stands when it is actually sent.
 */
class SendCateringCustomerMailJob implements ShouldQueue
{
    use Queueable;

    /** Three attempts, then `failed()` frees the claim so a human can retry. */
    public $tries = 3;

    public $backoff = 30;

    public function __construct(
        public int $tenantId,
        public string $emailType,
        public int $eventId,
        public ?int $estimateId,
        public array $context,
        public string $dedupeKey,
        public string $recipient,
        public string $businessName,
    ) {}

    public function handle(TenancyManager $tenancy): void
    {
        // Master connection — Tenant declares it explicitly, so this is safe
        // whatever the previous job on this worker left behind.
        $tenant = Tenant::find($this->tenantId);

        if (! $tenant) {
            // The tenant is gone. Nothing to send, and nothing to retry.
            return;
        }

        $tenancy->activate($tenant);

        $event = CateringEvent::find($this->eventId);

        if (! $event) {
            return;
        }

        $estimate = $this->estimateId ? CateringEstimate::find($this->estimateId) : null;
        $estimate?->loadMissing('lines');

        Mail::to($this->recipient)->send(new CateringCustomerMail(
            $this->emailType,
            $this->businessName,
            $event,
            $estimate,
            $this->context,
        ));

        $this->logRow()->update(['sent_at' => now(), 'error' => null]);
    }

    /**
     * Free the claim when the job gives up for good.
     *
     * The claim exists so two operators cannot email the same customer twice.
     * If the send never happens the claim must not outlive it, or the email can
     * never be attempted again — which is exactly the state the live tenant was
     * found in: three rows claimed by requests that died, blocking their own
     * retry for good.
     */
    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (! $tenant) {
            return;
        }

        app(TenancyManager::class)->activate($tenant);

        $this->logRow()->whereNull('sent_at')->delete();

        report($e ?? new \RuntimeException(
            'Catering customer mail failed permanently: '.$this->emailType.' event '.$this->eventId
        ));
    }

    private function logRow()
    {
        return CateringEmailLog::query()
            ->where('catering_event_id', $this->eventId)
            ->where('email_type', $this->emailType)
            ->where('dedupe_key', $this->dedupeKey);
    }
}
