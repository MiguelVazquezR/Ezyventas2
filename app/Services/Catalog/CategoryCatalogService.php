<?php

namespace App\Services\Catalog;

use App\Models\Category;

/**
 * Read model of the categories of a subscription.
 */
class CategoryCatalogService
{
    /**
     * Categories of the subscription filtered by type ("product" or "service").
     *
     * @return array<int, array{id: int, name: string, type: string}>
     */
    public function forSubscription(int $subscriptionId, string $type = 'product'): array
    {
        return Category::where('subscription_id', $subscriptionId)
            ->where('type', $type)
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'type' => $category->type,
            ])
            ->all();
    }
}
