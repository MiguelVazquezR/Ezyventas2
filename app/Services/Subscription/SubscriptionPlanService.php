<?php

namespace App\Services\Subscription;

use App\Enums\BillingPeriod;
use App\Enums\PlanItemType;
use App\Models\PlanItem;
use App\Models\Subscription;
use App\Models\SubscriptionVersion;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for the free plan declared in config/subscription.php.
 *
 * Every new account starts on this plan: fixed limits, the essential modules
 * and a version that spans the whole plan horizon, so there is no trial period
 * and nothing has to be renewed to keep operating.
 */
class SubscriptionPlanService
{
    /**
     * Create the free plan for a brand new subscription: open-ended version,
     * fixed limits and default modules.
     */
    public function initializeFreePlan(Subscription $subscription): SubscriptionVersion
    {
        $this->ensureOpenEndedVersion($subscription);
        $this->applyFixedLimits($subscription);
        $this->syncModules($subscription, $this->defaultModuleKeys());

        return $this->resolveVersion($subscription);
    }

    /**
     * Resolve the active version of the subscription, extending its end date to
     * the plan horizon so the free plan never expires.
     */
    public function ensureOpenEndedVersion(Subscription $subscription): SubscriptionVersion
    {
        $version = $this->resolveVersion($subscription);
        $horizonEndDate = $this->horizonEndDate();

        if ($version->end_date === null || $version->end_date->lessThan($horizonEndDate)) {
            $version->update(['end_date' => $horizonEndDate]);
        }

        return $version;
    }

    /**
     * Stamp the fixed limits of the free plan on the active version, replacing
     * any value that may have been sent by the client.
     */
    public function applyFixedLimits(Subscription $subscription): SubscriptionVersion
    {
        $version = $this->resolveVersion($subscription);
        $planItems = PlanItem::whereIn('key', array_keys($this->fixedLimits()))->get()->keyBy('key');

        foreach ($this->fixedLimits() as $key => $quantity) {
            $planItem = $planItems->get($key);

            $version->items()->updateOrCreate(
                ['item_key' => $key],
                [
                    'item_type' => PlanItemType::LIMIT->value,
                    'name' => $planItem?->name ?? $key,
                    'quantity' => $quantity,
                    'unit_price' => $planItem?->monthly_price ?? 0,
                    'billing_period' => BillingPeriod::MONTHLY,
                ]
            );
        }

        return $version;
    }

    /**
     * Replace the modules of the active version with the given keys, always
     * keeping the free modules active.
     *
     * @param  array<int, string>  $moduleKeys
     */
    public function syncModules(Subscription $subscription, array $moduleKeys): SubscriptionVersion
    {
        $version = $this->resolveVersion($subscription);
        $moduleKeys = array_unique(array_merge($moduleKeys, $this->freeModuleKeys()));

        foreach (PlanItem::where('type', PlanItemType::MODULE->value)->get() as $planItem) {
            if (! in_array($planItem->key, $moduleKeys, true)) {
                $version->items()->where('item_key', $planItem->key)->delete();

                continue;
            }

            $version->items()->updateOrCreate(
                ['item_key' => $planItem->key],
                [
                    'item_type' => PlanItemType::MODULE->value,
                    'name' => $planItem->name,
                    'quantity' => 1,
                    'unit_price' => $planItem->monthly_price,
                    'billing_period' => BillingPeriod::MONTHLY,
                ]
            );
        }

        return $version;
    }

    /**
     * Re-apply the modules already activated on the subscription plus the free
     * ones. Used when the user skips the onboarding wizard.
     */
    public function refreshModules(Subscription $subscription): SubscriptionVersion
    {
        $version = $this->resolveVersion($subscription);

        $currentModuleKeys = $version->items()
            ->where('item_type', PlanItemType::MODULE->value)
            ->pluck('item_key')
            ->all();

        return $this->syncModules($subscription, $currentModuleKeys);
    }

    /**
     * Activate or deactivate a single module on the active version without
     * touching the limits or any other plan item.
     */
    public function setModuleActive(Subscription $subscription, string $moduleKey, bool $isActive): PlanItem
    {
        $planItem = PlanItem::query()
            ->where('key', $moduleKey)
            ->where('type', PlanItemType::MODULE->value)
            ->firstOrFail();

        $version = $this->resolveVersion($subscription);

        if (! $isActive) {
            $version->items()->where('item_key', $planItem->key)->delete();

            return $planItem;
        }

        $version->items()->updateOrCreate(
            ['item_key' => $planItem->key],
            [
                'item_type' => PlanItemType::MODULE->value,
                'name' => $planItem->name,
                'quantity' => 1,
                'unit_price' => $planItem->monthly_price,
                'billing_period' => BillingPeriod::MONTHLY,
            ]
        );

        return $planItem;
    }

    /**
     * Whether the active version is the open-ended free plan, which means the
     * account has nothing to renew.
     */
    public function isOpenEnded(Subscription $subscription): bool
    {
        return $this->isOpenEndedVersion($subscription->versions()->latest('id')->first());
    }

    /**
     * Whether the version spans the whole plan horizon instead of a single paid
     * billing period (one month or one year).
     *
     * The covered span is measured against the plan horizon instead of the
     * current date, so a free plan version is still recognized as open-ended
     * years after the account was created.
     */
    public function isOpenEndedVersion(?SubscriptionVersion $version): bool
    {
        if (! $version || $version->start_date === null || $version->end_date === null) {
            return false;
        }

        $coveredYears = abs($version->start_date->diffInYears($version->end_date));

        return $coveredYears >= $this->horizonYears() - 1;
    }

    /**
     * User-facing name of the free plan.
     */
    public function planName(): string
    {
        return config('subscription.plan.name');
    }

    /**
     * Number of years covered by a free plan version.
     */
    public function horizonYears(): int
    {
        return (int) config('subscription.plan.horizon_years');
    }

    /**
     * End date assigned to a free plan version.
     */
    public function horizonEndDate(): Carbon
    {
        return now()->addYears($this->horizonYears());
    }

    /**
     * Fixed quantities included in the free plan, keyed by plan item key.
     *
     * @return array<string, int>
     */
    public function fixedLimits(): array
    {
        return collect(config('subscription.limits'))
            ->map(fn ($quantity) => (int) $quantity)
            ->all();
    }

    /**
     * Plan item keys of the modules enabled by default.
     *
     * @return array<int, string>
     */
    public function defaultModuleKeys(): array
    {
        return config('subscription.default_modules');
    }

    /**
     * Plan item keys of the modules that cost nothing, which must always stay
     * active on any subscription.
     *
     * @return array<int, string>
     */
    public function freeModuleKeys(): array
    {
        return PlanItem::where('type', PlanItemType::MODULE->value)
            ->where('monthly_price', '<=', 0)
            ->pluck('key')
            ->all();
    }

    /**
     * Active version of the subscription, created on demand with the plan
     * horizon when the subscription has none yet.
     */
    private function resolveVersion(Subscription $subscription): SubscriptionVersion
    {
        $version = $subscription->versions()->latest('id')->first();

        if ($version) {
            return $version;
        }

        return $subscription->versions()->create([
            'start_date' => now(),
            'end_date' => $this->horizonEndDate(),
        ]);
    }
}
