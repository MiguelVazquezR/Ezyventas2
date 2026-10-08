<?php

namespace App\Services\Catalog;

use App\Enums\PromotionEffectType;
use App\Enums\PromotionType;
use App\Models\Product;
use App\Models\Promotion;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds the POS catalog read model: branch aware inventory, prices,
 * promotions, variants and components.
 *
 * The web POS (PointOfSaleController) and the mobile API both consume this
 * service, so a price, a stock or a promotion can never differ between clients.
 */
class ProductCatalogService
{
    /**
     * Products available in the POS of the given branch.
     *
     * @param  array{search?: string|null, category_id?: int|null, updated_since?: string|null}  $filters
     */
    public function queryForBranch(int $branchId, array $filters = []): Builder
    {
        $query = Product::query()
            ->whereHas('branches', fn (Builder $q) => $q->where('branches.id', $branchId))
            ->where('show_in_pos', true)
            ->with([
                'media',
                'category:id,name',
                'branches',
                'productAttributes.branches',
                'components.componentable',
            ]);

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        // Incremental synchronization: only rows changed after the given moment.
        if (!empty($filters['updated_since'])) {
            $query->where('updated_at', '>=', Carbon::parse($filters['updated_since']));
        }

        return $query->orderBy('name');
    }

    /**
     * Finds one product of the branch, or null when it is not part of its catalog.
     */
    public function findForBranch(int $productId, int $branchId): ?Product
    {
        return $this->queryForBranch($branchId)->find($productId);
    }

    /**
     * Mobile API payload: identical to the POS payload but with the money
     * fields formatted as decimal strings, as stated by the API contract.
     */
    public function apiPayload(Product $product, int $branchId): array
    {
        return array_merge($this->payload($product, $branchId), [
            'selling_price' => (string) $product->selling_price,
        ]);
    }

    /**
     * Canonical product payload for a branch (consumed by the web POS props).
     */
    public function payload(Product $product, int $branchId): array
    {
        $pricing = $this->pricing($product);
        $generalImages = $product->getMedia('product-general-images')->map->getUrl();
        [$currentStock, $reservedStock] = $this->stockForBranch($product, $branchId);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $product->description,
            'category' => $product->category->name ?? 'Sin categoría',
            'image' => $generalImages->first() ?: 'https://placehold.co/400x400/EBF8FF/3182CE?text=' . urlencode($product->name),
            'general_images' => $generalImages,
            'selling_price' => (float) $product->selling_price,
            'price' => $pricing['price'],
            'original_price' => $pricing['original_price'],
            'price_tiers' => $product->price_tiers ?? [],
            // Stock shown to the user is always the available one (never negative).
            'stock' => (float) max(0, $currentStock - $reservedStock),
            'reserved_stock' => (float) $reservedStock,
            'show_in_pos' => (bool) $product->show_in_pos,
            'is_bulk' => (bool) $product->is_bulk,
            'measure_unit' => $product->measure_unit,
            'promotions' => $pricing['promotions'],
            'variants' => $this->variants($product, $branchId),
            'variant_combinations' => $this->variantCombinations($product, $branchId),
            'components' => $this->components($product),
        ];
    }

    /**
     * Active promotions of the subscription that are not item discounts.
     */
    public function activePromotions(int $subscriptionId): Collection
    {
        $now = Carbon::now();

        return Promotion::where('subscription_id', $subscriptionId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('start_date', '<=', $now)->orWhereNull('start_date'))
            ->where(fn ($q) => $q->where('end_date', '>=', $now)->orWhereNull('end_date'))
            ->where('type', '!=', PromotionType::ITEM_DISCOUNT)
            ->with(['rules.itemable:id,name', 'effects.itemable:id,name'])
            ->get();
    }

    /**
     * Final price of the product after applying the best item promotion.
     *
     * @return array{price: float, original_price: float, promotions: array<int, array<string, mixed>>}
     */
    private function pricing(Product $product): array
    {
        $now = Carbon::now();
        $basePrice = (float) $product->selling_price;

        $promotions = Promotion::where('is_active', true)
            ->where(fn ($q) => $q->where('start_date', '<=', $now)->orWhereNull('start_date'))
            ->where(fn ($q) => $q->where('end_date', '>=', $now)->orWhereNull('end_date'))
            ->where(function ($query) use ($product) {
                $query->whereHas('rules', function ($q) use ($product) {
                    $q->where('itemable_type', Product::class)->where('itemable_id', $product->id);
                })->orWhereHas('effects', function ($q) use ($product) {
                    $q->where('itemable_type', Product::class)->where('itemable_id', $product->id);
                });
            })
            ->with(['rules.itemable:id,name', 'effects.itemable:id,name'])
            ->orderBy('priority', 'desc')
            ->get();

        if ($promotions->isEmpty()) {
            return ['price' => $basePrice, 'original_price' => $basePrice, 'promotions' => []];
        }

        $bestPriceAfterDiscount = $basePrice;

        foreach ($promotions->where('type', PromotionType::ITEM_DISCOUNT) as $promo) {
            $effect = $promo->effects->where('itemable_id', $product->id)->first();
            if (!$effect) {
                continue;
            }

            $promoPrice = match ($effect->type) {
                PromotionEffectType::FIXED_DISCOUNT => $basePrice - $effect->value,
                PromotionEffectType::PERCENTAGE_DISCOUNT => $basePrice * (1 - ($effect->value / 100)),
                PromotionEffectType::SET_PRICE => (float) $effect->value < $basePrice ? (float) $effect->value : $basePrice,
                default => $basePrice,
            };

            $promoPrice = max(0, (float) $promoPrice);

            if ($promoPrice < $bestPriceAfterDiscount) {
                $bestPriceAfterDiscount = $promoPrice;
            }
        }

        $formattedPromotions = $promotions->map(function ($promo) {
            return [
                'name' => $promo->name,
                'description' => $promo->description,
                'type' => $promo->type->value,
                'rules' => $promo->rules->map(fn ($rule) => [
                    'type' => $rule->type->value,
                    'value' => $rule->value,
                    'itemable' => $rule->itemable ? ['name' => $rule->itemable->name] : null,
                ]),
                'effects' => $promo->effects->map(fn ($effect) => [
                    'type' => $effect->type->value,
                    'value' => $effect->value,
                    'itemable' => $effect->itemable ? ['name' => $effect->itemable->name] : null,
                ]),
            ];
        })->values()->all();

        return [
            'price' => $bestPriceAfterDiscount,
            'original_price' => $basePrice,
            'promotions' => $formattedPromotions,
        ];
    }

    /**
     * Current and reserved stock of the product in the branch.
     * Products with variants use the sum of their variants.
     *
     * @return array{0: float, 1: float}
     */
    private function stockForBranch(Product $product, int $branchId): array
    {
        if ($product->productAttributes->isEmpty()) {
            $pivot = $product->branches->where('id', $branchId)->first()?->pivot;

            return $pivot
                ? [(float) $pivot->current_stock, (float) $pivot->reserved_stock]
                : [0.0, 0.0];
        }

        $currentStock = $product->productAttributes->sum(
            fn ($variant) => (float) ($variant->branches->where('id', $branchId)->first()?->pivot->current_stock ?? 0)
        );

        $reservedStock = $product->productAttributes->sum(
            fn ($variant) => (float) ($variant->branches->where('id', $branchId)->first()?->pivot->reserved_stock ?? 0)
        );

        return [$currentStock, $reservedStock];
    }

    /**
     * Available stock grouped by attribute: { "Talla": [{ value, stock }] }.
     * Returns an empty object when the product has no variants.
     */
    private function variants(Product $product, int $branchId): array|\stdClass
    {
        if ($product->productAttributes->isEmpty()) {
            return new \stdClass();
        }

        $grouped = [];

        foreach ($product->productAttributes as $variant) {
            $pivot = $variant->branches->where('id', $branchId)->first()?->pivot;
            $availableStock = $pivot ? max(0, (float) $pivot->current_stock - (float) $pivot->reserved_stock) : 0.0;

            foreach ($variant->attributes ?? [] as $attribute => $value) {
                if (!isset($grouped[$attribute])) {
                    $grouped[$attribute] = [];
                }

                if (!isset($grouped[$attribute][$value])) {
                    $grouped[$attribute][$value] = ['value' => $value, 'stock' => 0.0];
                }

                $grouped[$attribute][$value]['stock'] += $availableStock;
            }
        }

        return array_map('array_values', $grouped);
    }

    /**
     * Sellable combinations of a product with variants. The `id` of each
     * combination is the `product_attribute_id` used to sell it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function variantCombinations(Product $product, int $branchId): array
    {
        $variantImages = $product->getMedia('product-variant-images');
        $basePrice = (float) $product->selling_price;

        return $product->productAttributes->map(function ($variant) use ($variantImages, $branchId, $basePrice) {
            $imageUrl = null;

            if ($variantImages->isNotEmpty()) {
                foreach ($variant->attributes ?? [] as $attribute => $optionValue) {
                    $foundImage = $variantImages->first(
                        fn ($media) => $media->getCustomProperty('variant_key') === "{$attribute}_{$optionValue}"
                            || $media->getCustomProperty('variant_option') === $optionValue
                    );

                    if ($foundImage) {
                        $imageUrl = $foundImage->getUrl();
                        break;
                    }
                }
            }

            $pivot = $variant->branches->where('id', $branchId)->first()?->pivot;
            $stock = $pivot ? (float) $pivot->current_stock : 0.0;
            $reserved = $pivot ? (float) $pivot->reserved_stock : 0.0;
            $priceModifier = (float) $variant->selling_price_modifier;

            return [
                'id' => $variant->id,
                'attributes' => $variant->attributes,
                'price_modifier' => $priceModifier,
                'price' => $basePrice + $priceModifier,
                'stock' => max(0.0, $stock - $reserved),
                'reserved_stock' => $reserved,
                'sku_suffix' => $variant->sku_suffix,
                'image_url' => $imageUrl,
            ];
        })->values()->all();
    }

    /**
     * Kits and combos: the items the product consumes when it is sold.
     *
     * @return array<int, array<string, mixed>>
     */
    private function components(Product $product): array
    {
        return $product->components->map(function ($component) {
            return [
                'id' => $component->id,
                'component_id' => $component->componentable_id,
                'component_type' => $component->componentable_type,
                'name' => $component->componentable?->name ?? $component->componentable?->sku_suffix,
                'quantity' => (float) $component->quantity,
            ];
        })->values()->all();
    }
}
