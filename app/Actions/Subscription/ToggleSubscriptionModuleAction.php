<?php

namespace App\Actions\Subscription;

use App\Models\PlanItem;
use App\Models\Subscription;
use App\Services\Subscription\SubscriptionPlanService;

/**
 * Activates or deactivates a single module on the subscription's active
 * version, so the owner can adjust the plan without going through checkout.
 */
class ToggleSubscriptionModuleAction
{
    public function __construct(
        private readonly SubscriptionPlanService $planService,
    ) {}

    public function execute(Subscription $subscription, string $moduleKey, bool $isActive): PlanItem
    {
        return $this->planService->setModuleActive($subscription, $moduleKey, $isActive);
    }
}
