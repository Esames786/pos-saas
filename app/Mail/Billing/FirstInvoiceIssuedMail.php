<?php

namespace App\Mail\Billing;

class FirstInvoiceIssuedMail extends BillingMail
{
    public function __construct(string $businessName, string $invoiceNo, string $amount, string $currency, string $dueDate, string $billingUrl)
    {
        parent::__construct(
            subjectLine: 'Your first invoice is ready',
            heading: 'Your subscription invoice is ready',
            lines: [
                "Hi {$businessName},",
                "Invoice {$invoiceNo} for {$currency} {$amount} has been issued. It is due on {$dueDate} — the day your free trial ends.",
                'You can pay any time during your trial. Your trial is never shortened by paying early.',
            ],
            ctaUrl: $billingUrl,
            ctaLabel: 'View invoice & pay',
        );
    }
}
