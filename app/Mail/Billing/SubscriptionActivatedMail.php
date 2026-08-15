<?php

namespace App\Mail\Billing;

class SubscriptionActivatedMail extends BillingMail
{
    public function __construct(string $businessName, string $planName, string $periodEndsDate, string $loginUrl)
    {
        parent::__construct(
            subjectLine: 'Your subscription is active',
            heading: 'Your subscription is now active',
            lines: [
                "Hi {$businessName},",
                "Your {$planName} subscription is active. Your current billing period runs through {$periodEndsDate}.",
                'Thank you for choosing us.',
            ],
            ctaUrl: $loginUrl,
            ctaLabel: 'Open your workspace',
        );
    }
}
