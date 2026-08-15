<?php

namespace App\Mail\Billing;

class SubscriptionPastDueMail extends BillingMail
{
    public function __construct(string $businessName, string $billingUrl)
    {
        parent::__construct(
            subjectLine: 'Your subscription is past due',
            heading: 'Your subscription is past due',
            lines: [
                "Hi {$businessName},",
                'Your billing period has ended and your subscription is now past due. Please pay your latest invoice to restore full access.',
            ],
            ctaUrl: $billingUrl,
            ctaLabel: 'View billing & pay',
        );
    }
}
