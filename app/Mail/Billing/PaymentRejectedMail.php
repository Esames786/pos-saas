<?php

namespace App\Mail\Billing;

class PaymentRejectedMail extends BillingMail
{
    public function __construct(string $businessName, string $invoiceNo, string $amount, string $currency, string $billingUrl, ?string $reason = null)
    {
        $lines = [
            "Hi {$businessName},",
            "We could not verify your payment of {$currency} {$amount} for invoice {$invoiceNo}.",
        ];
        if ($reason) {
            $lines[] = "Reason: {$reason}";
        }
        $lines[] = 'Please re-check the details and upload a corrected proof.';

        parent::__construct(
            subjectLine: "Payment could not be verified — {$invoiceNo}",
            heading: 'Your payment needs attention',
            lines: $lines,
            ctaUrl: $billingUrl,
            ctaLabel: 'Upload a new proof',
        );
    }
}
