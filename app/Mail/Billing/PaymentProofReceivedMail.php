<?php

namespace App\Mail\Billing;

class PaymentProofReceivedMail extends BillingMail
{
    public function __construct(string $businessName, string $invoiceNo, string $amount, string $currency, string $reviewUrl)
    {
        parent::__construct(
            subjectLine: "Payment proof received — {$invoiceNo}",
            heading: 'A payment proof needs review',
            lines: [
                "{$businessName} uploaded a payment proof of {$currency} {$amount} for invoice {$invoiceNo}.",
                'Please review and verify or reject it in the central billing portal.',
            ],
            ctaUrl: $reviewUrl,
            ctaLabel: 'Review payment',
        );
    }
}
