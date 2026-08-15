<?php

namespace App\Mail\Billing;

class PaymentVerifiedMail extends BillingMail
{
    public function __construct(string $businessName, string $invoiceNo, string $amount, string $currency, string $billingUrl)
    {
        parent::__construct(
            subjectLine: "Payment verified — {$invoiceNo}",
            heading: 'Your payment is verified',
            lines: [
                "Hi {$businessName},",
                "We have verified your payment of {$currency} {$amount} for invoice {$invoiceNo}. Thank you.",
            ],
            ctaUrl: $billingUrl,
            ctaLabel: 'View billing',
        );
    }
}
