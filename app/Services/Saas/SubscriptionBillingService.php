<?php

namespace App\Services\Saas;

use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\SubscriptionPayment;
use App\Models\Master\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SubscriptionBillingService
{
    public function createInvoice(Tenant $tenant, array $data): SubscriptionInvoice
    {
        return DB::connection('master')->transaction(function () use ($tenant, $data) {
            $subscription = $tenant->subscription;
            $planId = $data['plan_id'] ?? $subscription?->plan_id;

            $subtotal = round((float) ($data['subtotal'] ?? 0), 2);
            $discount = round((float) ($data['discount_amount'] ?? 0), 2);
            $tax      = round((float) ($data['tax_amount'] ?? 0), 2);
            $total    = max($subtotal - $discount + $tax, 0);

            $status = ($data['status'] ?? 'issued') === 'draft' ? 'draft' : 'issued';

            return SubscriptionInvoice::create([
                'invoice_no'         => $this->nextInvoiceNo(),
                'tenant_id'          => $tenant->id,
                'subscription_id'    => $subscription?->id,
                'plan_id'            => $planId,
                'invoice_type'       => $data['invoice_type'] ?? 'subscription',
                'status'             => $status,
                'currency_code'      => strtoupper($data['currency_code'] ?? 'PKR'),
                'subtotal'           => $subtotal,
                'discount_amount'    => $discount,
                'tax_amount'         => $tax,
                'total_amount'       => $total,
                'paid_amount'        => 0,
                'balance_amount'     => $total,
                'period_start'       => $data['period_start'] ?? null,
                'period_end'         => $data['period_end'] ?? null,
                'due_date'           => $data['due_date'] ?? null,
                'issued_at'          => $status === 'issued' ? now() : null,
                'notes'              => $data['notes'] ?? null,
                'created_by_user_id' => Auth::guard('central')->id(),
            ]);
        });
    }

    public function recordPayment(SubscriptionInvoice $invoice, array $data): SubscriptionPayment
    {
        if ($invoice->isVoid()) {
            throw new RuntimeException('Cannot record a payment against a void invoice.');
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        return DB::connection('master')->transaction(function () use ($invoice, $data, $amount) {
            $status = $data['status'] ?? 'verified';

            $payment = SubscriptionPayment::create([
                'subscription_invoice_id' => $invoice->id,
                'tenant_id'               => $invoice->tenant_id,
                'payment_gateway_id'      => $data['payment_gateway_id'] ?? null,
                'payment_method_code'     => $data['payment_method_code'] ?? null,
                'amount'                  => $amount,
                'currency_code'           => strtoupper($data['currency_code'] ?? $invoice->currency_code),
                'payment_date'            => $data['payment_date'] ?? now()->toDateString(),
                'reference_no'            => $data['reference_no'] ?? null,
                'status'                  => $status,
                'notes'                   => $data['notes'] ?? null,
                'verified_by_user_id'     => $status === 'verified' ? Auth::guard('central')->id() : null,
                'verified_at'             => $status === 'verified' ? now() : null,
            ]);

            $this->refreshInvoicePaymentState($invoice->fresh());

            return $payment;
        });
    }

    public function refreshInvoicePaymentState(SubscriptionInvoice $invoice): void
    {
        // Only verified payments count toward the balance.
        $paid = round((float) $invoice->payments()->where('status', 'verified')->sum('amount'), 2);
        $total = (float) $invoice->total_amount;
        $balance = max($total - $paid, 0);

        $updates = [
            'paid_amount'    => $paid,
            'balance_amount' => $balance,
        ];

        if ($invoice->status !== 'void') {
            if ($paid >= $total && $total > 0) {
                $updates['status'] = 'paid';
                $updates['paid_at'] = now();
                $updates['balance_amount'] = 0;
            } elseif ($paid > 0) {
                $updates['status'] = 'partially_paid';
            } elseif ($invoice->status === 'partially_paid') {
                // verified payments removed/rejected back to zero
                $updates['status'] = 'issued';
            }
        }

        $invoice->update($updates);

        if (($updates['status'] ?? null) === 'paid') {
            $this->activateSubscriptionFromPaidInvoice($invoice->fresh());
        }
    }

    public function activateSubscriptionFromPaidInvoice(SubscriptionInvoice $invoice): void
    {
        $subscription = $invoice->subscription;

        if (!$subscription) {
            return;
        }

        $subscription->update([
            'status'                 => 'active',
            'plan_id'                => $invoice->plan_id ?: $subscription->plan_id,
            'current_period_ends_at' => $invoice->period_end ?: $subscription->current_period_ends_at,
        ]);

        // Close out any plan-change request that this invoice was created for.
        // Resolved lazily to avoid a circular constructor dependency.
        app(SubscriptionChangeRequestService::class)->markInvoiceRequestPaid($invoice);
    }

    public function recordTenantProofPayment(
        SubscriptionInvoice $invoice,
        Tenant $tenant,
        array $data,
        UploadedFile $proof
    ): SubscriptionPayment {
        if ((int) $invoice->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('Invoice not found for this tenant.');
        }

        if ($invoice->isPaid() || $invoice->isVoid()) {
            throw new RuntimeException('This invoice is not accepting payment proofs.');
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        return DB::connection('master')->transaction(function () use ($invoice, $tenant, $data, $proof, $amount) {
            $payment = SubscriptionPayment::create([
                'subscription_invoice_id'   => $invoice->id,
                'tenant_id'                 => $tenant->id,
                'payment_gateway_id'        => null,
                'payment_method_code'       => $data['payment_method_code'] ?? 'manual',
                'amount'                    => $amount,
                'currency_code'             => strtoupper($data['currency_code'] ?? $invoice->currency_code),
                'payment_date'              => $data['payment_date'] ?? now()->toDateString(),
                'reference_no'              => $data['reference_no'] ?? null,
                'status'                    => 'pending',
                'notes'                     => $data['notes'] ?? null,
                'proof_uploaded_by_user_id' => Auth::guard('tenant')->id(),
                'proof_uploaded_at'         => now(),
            ]);

            $extension = $proof->getClientOriginalExtension();
            $safeName  = Str::uuid()->toString() . ($extension ? '.' . $extension : '');

            // Private local disk — proofs are sensitive and served only via authorized routes.
            $path = $proof->storeAs(
                'billing-proofs/' . $tenant->id . '/' . $invoice->id,
                $safeName,
                'local'
            );

            $payment->update([
                'proof_path'          => $path,
                'proof_original_name' => $proof->getClientOriginalName(),
            ]);

            return $payment;
        });
    }

    public function verifyPayment(SubscriptionPayment $payment): void
    {
        DB::connection('master')->transaction(function () use ($payment) {
            $payment->update([
                'status'              => 'verified',
                'verified_by_user_id' => Auth::guard('central')->id(),
                'verified_at'         => now(),
            ]);

            $this->refreshInvoicePaymentState($payment->invoice()->firstOrFail());
        });
    }

    public function rejectPayment(SubscriptionPayment $payment, ?string $notes = null): void
    {
        DB::connection('master')->transaction(function () use ($payment, $notes) {
            $payment->update([
                'status' => 'rejected',
                'notes'  => $notes ?: $payment->notes,
            ]);

            $this->refreshInvoicePaymentState($payment->invoice()->firstOrFail());
        });
    }

    public function voidInvoice(SubscriptionInvoice $invoice): void
    {
        if ($invoice->isPaid()) {
            throw new RuntimeException('A paid invoice cannot be voided.');
        }

        $invoice->update(['status' => 'void']);
    }

    public function nextInvoiceNo(): string
    {
        $prefix = 'INV-' . now()->format('Ymd') . '-';

        do {
            $seq = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $candidate = $prefix . $seq;
        } while (SubscriptionInvoice::where('invoice_no', $candidate)->exists());

        return $candidate;
    }
}
