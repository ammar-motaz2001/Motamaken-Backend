<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscriptions:renew')]
#[Description('Charge the subscriptions that are due and expire the ones that cannot be renewed')]
class RenewSubscriptions extends Command
{
    /**
     * Charge the subscriptions due for renewal and print how many renewed, failed or expired.
     */
    public function handle(SubscriptionService $subscriptions): int
    {
        $result = $subscriptions->renewDue();
        $this->info("Renewed {$result['renewed']}, failed {$result['failed']}, expired {$result['expired']}.");

        return self::SUCCESS;
    }
}
