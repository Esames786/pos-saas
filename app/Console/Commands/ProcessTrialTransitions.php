<?php

namespace App\Console\Commands;

use App\Services\Saas\SubscriptionTrialTransitionService;
use Illuminate\Console\Command;

class ProcessTrialTransitions extends Command
{
    protected $signature = 'saas:process-trial-transitions';

    protected $description = 'Activate trial subscriptions whose trial has ended and whose first invoice is paid.';

    public function handle(SubscriptionTrialTransitionService $transitions): int
    {
        $result = $transitions->processDueTrialTransitions();

        $this->info('Trials activated: '.$result['activated']);

        if (($result['waiting_unpaid'] ?? 0) > 0) {
            $this->line('Trials ended but awaiting payment: '.$result['waiting_unpaid']);
        }

        if (($result['demo_skipped'] ?? 0) > 0) {
            $this->line('Demo tenants skipped: '.$result['demo_skipped']);
        }

        return self::SUCCESS;
    }
}
