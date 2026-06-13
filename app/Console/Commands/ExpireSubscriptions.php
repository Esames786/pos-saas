<?php

namespace App\Console\Commands;

use App\Services\Saas\SubscriptionLifecycleService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'saas:subscriptions-expire';

    protected $description = 'Mark expired active SaaS subscriptions as past due.';

    public function handle(SubscriptionLifecycleService $lifecycle): int
    {
        $result = $lifecycle->markExpiredSubscriptionsPastDue();

        $this->info('Expired subscriptions marked past_due: ' . $result['expired']);

        if (($result['demo_skipped'] ?? 0) > 0) {
            $this->line('Demo tenants skipped from expiry sweep: ' . $result['demo_skipped']);
        }

        return self::SUCCESS;
    }
}
