<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait for models that belong to a subscription scope.
 *
 * Provides a standardized method to retrieve the subscription ID
 * regardless of the relationship path (direct column, through branch, etc.).
 *
 * Default lookup order:
 *  1. Direct 'subscription_id' attribute
 *  2. 'branch()' BelongsTo relationship → branch.subscription_id
 *
 * Override getSubscriptionId() in models with non-standard paths.
 */
trait HasSubscription
{
    /**
     * Returns the subscription ID that owns this model.
     * Returns null if the model is not yet persisted or has no subscription path.
     */
    public function getSubscriptionId(): ?int
    {
        // 1. Direct subscription_id column
        if (array_key_exists('subscription_id', $this->attributes) && $this->attributes['subscription_id'] !== null) {
            return (int) $this->attributes['subscription_id'];
        }

        // 2. Through branch relationship
        if (method_exists($this, 'branch')) {
            $branchRelation = $this->branch();

            if ($branchRelation instanceof BelongsTo) {
                $branch = $this->relationLoaded('branch')
                    ? $this->branch
                    : $branchRelation->getResults();

                if ($branch && isset($branch->subscription_id)) {
                    return (int) $branch->subscription_id;
                }
            }
        }

        return null;
    }

    /**
     * Query scope to filter models belonging to a given subscription.
     */
    public function scopeBySubscription($query, int $subscriptionId)
    {
        // If the model has a direct subscription_id column
        if (in_array('subscription_id', $this->getFillable()) || array_key_exists('subscription_id', $this->attributes)) {
            return $query->where('subscription_id', $subscriptionId);
        }

        // If the model has a branch_id column, filter through branch relationship
        if (in_array('branch_id', $this->getFillable()) || array_key_exists('branch_id', $this->attributes)) {
            return $query->whereHas('branch', fn($q) => $q->where('subscription_id', $subscriptionId));
        }

        return $query;
    }
}
