<?php

namespace App\Mail\Billing;

class TrialEndingMail extends BillingMail
{
    public function __construct(string $businessName, string $trialEndsDate, string $billingUrl)
    {
        parent::__construct(
            subjectLine: 'Your free trial is ending soon',
            heading: 'Your trial ends on '.$trialEndsDate,
            lines: [
                "Hi {$businessName},",
                "Your free trial ends on {$trialEndsDate}. To keep your workspace active, please pay your subscription invoice before then.",
            ],
            ctaUrl: $billingUrl,
            ctaLabel: 'View invoice & pay',
        );
    }
}
