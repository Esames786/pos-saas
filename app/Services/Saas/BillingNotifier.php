<?php

namespace App\Services\Saas;

use App\Mail\Billing\BillingMail;
use App\Mail\Billing\FirstInvoiceIssuedMail;
use App\Mail\Billing\PaymentProofReceivedMail;
use App\Mail\Billing\PaymentRejectedMail;
use App\Mail\Billing\PaymentVerifiedMail;
use App\Mail\Billing\SubscriptionActivatedMail;
use App\Mail\Billing\SubscriptionPastDueMail;
use App\Mail\Billing\TrialEndingMail;
use App\Models\Master\BillingNotificationLog;
use App\Models\Master\Subscription;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\SubscriptionPayment;
use App\Models\Master\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * CLOUD-BILLING-3A — the one place transactional billing emails are sent.
 *
 * Contract:
 *   • at-most-once: a UNIQUE (event, subject) row is claimed before sending, so retries/overlaps
 *     never double-send;
 *   • never rolls back billing state: mail is sent OUTSIDE any billing transaction and a delivery
 *     failure is reported, never rethrown — a mail outage cannot undo a payment or an activation.
 * Callers invoke these AFTER the billing state is committed.
 */
class BillingNotifier
{
    public function notifyOnce(string $event, string $subjectType, int $subjectId, ?string $recipient, BillingMail $mail): bool
    {
        if (! $recipient) {
            return false;
        }

        // Claim first — the unique index makes a second claim (retry/overlap) fail cleanly.
        try {
            BillingNotificationLog::create([
                'event' => $event,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'recipient' => $recipient,
                'sent_at' => now(),
            ]);
        } catch (QueryException $e) {
            return false; // already sent
        }

        try {
            Mail::to($recipient)->send($mail);
        } catch (Throwable $e) {
            // Billing state is authoritative — a mail failure must never bubble up and roll it back.
            report($e);
        }

        return true;
    }

    public function firstInvoiceIssued(SubscriptionInvoice $invoice): bool
    {
        $tenant = $invoice->tenant;
        if (! $tenant) {
            return false;
        }

        return $this->notifyOnce('first_invoice_issued', 'invoice', $invoice->id, $tenant->owner_email,
            new FirstInvoiceIssuedMail(
                businessName: $tenant->business_name,
                invoiceNo: $invoice->invoice_no,
                amount: number_format((float) $invoice->total_amount, 2),
                currency: $invoice->currency_code,
                dueDate: optional($invoice->due_date)->toFormattedDateString() ?? '—',
                billingUrl: $this->tenantUrl($tenant, '/billing/invoices/'.$invoice->id),
            ));
    }

    public function paymentProofReceived(SubscriptionPayment $payment): bool
    {
        $invoice = $payment->invoice;
        $tenant = $invoice?->tenant;
        if (! $invoice || ! $tenant) {
            return false;
        }

        $reviewer = config('saas.contact.support_email', 'support@bingoopos.com');

        return $this->notifyOnce('payment_proof_received', 'payment', $payment->id, $reviewer,
            new PaymentProofReceivedMail(
                businessName: $tenant->business_name,
                invoiceNo: $invoice->invoice_no,
                amount: number_format((float) $payment->amount, 2),
                currency: $payment->currency_code,
                reviewUrl: $this->centralInvoiceUrl($invoice),
            ));
    }

    public function paymentVerified(SubscriptionPayment $payment): bool
    {
        $invoice = $payment->invoice;
        $tenant = $invoice?->tenant;
        if (! $invoice || ! $tenant) {
            return false;
        }

        return $this->notifyOnce('payment_verified', 'payment', $payment->id, $tenant->owner_email,
            new PaymentVerifiedMail(
                businessName: $tenant->business_name,
                invoiceNo: $invoice->invoice_no,
                amount: number_format((float) $payment->amount, 2),
                currency: $payment->currency_code,
                billingUrl: $this->tenantUrl($tenant, '/billing/invoices/'.$invoice->id),
            ));
    }

    public function paymentRejected(SubscriptionPayment $payment, ?string $reason = null): bool
    {
        $invoice = $payment->invoice;
        $tenant = $invoice?->tenant;
        if (! $invoice || ! $tenant) {
            return false;
        }

        return $this->notifyOnce('payment_rejected', 'payment', $payment->id, $tenant->owner_email,
            new PaymentRejectedMail(
                businessName: $tenant->business_name,
                invoiceNo: $invoice->invoice_no,
                amount: number_format((float) $payment->amount, 2),
                currency: $payment->currency_code,
                billingUrl: $this->tenantUrl($tenant, '/billing/invoices/'.$invoice->id),
                reason: $reason,
            ));
    }

    public function trialEnding(Subscription $subscription): bool
    {
        $tenant = $subscription->tenant;
        if (! $tenant || ! $subscription->trial_ends_at) {
            return false;
        }

        return $this->notifyOnce('trial_ending', 'subscription', $subscription->id, $tenant->owner_email,
            new TrialEndingMail(
                businessName: $tenant->business_name,
                trialEndsDate: $subscription->trial_ends_at->toFormattedDateString(),
                billingUrl: $this->tenantUrl($tenant, '/billing'),
            ));
    }

    public function subscriptionActivated(Subscription $subscription): bool
    {
        $tenant = $subscription->tenant;
        if (! $tenant) {
            return false;
        }

        $subscription->loadMissing('plan');

        return $this->notifyOnce('subscription_activated', 'subscription', $subscription->id, $tenant->owner_email,
            new SubscriptionActivatedMail(
                businessName: $tenant->business_name,
                planName: $subscription->plan?->name ?? 'your',
                periodEndsDate: optional($subscription->current_period_ends_at)->toFormattedDateString() ?? '—',
                loginUrl: $this->tenantUrl($tenant, '/login'),
            ));
    }

    public function subscriptionPastDue(Subscription $subscription): bool
    {
        $tenant = $subscription->tenant;
        if (! $tenant) {
            return false;
        }

        return $this->notifyOnce('subscription_past_due', 'subscription', $subscription->id, $tenant->owner_email,
            new SubscriptionPastDueMail(
                businessName: $tenant->business_name,
                billingUrl: $this->tenantUrl($tenant, '/billing'),
            ));
    }

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $tenant->loadMissing('domains');
        $domain = $tenant->domains->firstWhere('is_primary', true)?->domain
            ?? $tenant->domains->first()?->domain;

        return $domain ? 'https://'.$domain.$path : url($path);
    }

    private function centralInvoiceUrl(SubscriptionInvoice $invoice): string
    {
        try {
            return route('central.invoices.show', $invoice);
        } catch (Throwable $e) {
            return url('/invoices/'.$invoice->id);
        }
    }
}
