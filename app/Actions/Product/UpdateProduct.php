<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Traits\OptimizeMediaLocal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateProduct
{
    use OptimizeMediaLocal;

    /**
     * Ejecuta la lógica para actualizar un producto y todas sus dependencias.
     */
    public function execute(Product $product, array $productData, array $compositeItems, array $branchesToSync, $user, array $files = [], array $deletedMediaIds = []): Product
    {
        return DB::transaction(function () use ($product, $productData, $compositeItems, $branchesToSync, $user, $files, $deletedMediaIds) {
            
            // 1. Actualizar datos base
            if ($product->name !== $productData['name']) {
                $productData['slug'] = Str::slug($productData['name'] . '-' . uniqid());
            }

            $product->update(collect($productData)->except([
                'product_type', 'current_stock', 'min_stock', 'max_stock', 'location', 'variants_matrix'
            ])->toArray());

            // 2. Sincronizar sucursales
            $this->syncBranches($product, $productData, $branchesToSync, $user);

            // 3. Manejar tipos (Kits o Variantes)
            $this->handleProductTypeSpecifics($product, $productData, $compositeItems, $branchesToSync, $user);

            // 4. Manejo de media
            $this->handleMedia($product, $files, $deletedMediaIds);

            return $product;
        });
    }

    private function syncBranches(Product $product, array $productData, array $branchesToSync, $user): void
    {
        $existingBranches = $product->branches->keyBy('id');
        $syncData = [];
        $isSimple = $productData['product_type'] === 'simple';

        foreach ($branchesToSync as $bId) {
            if ($existingBranches->has($bId)) {
                $syncData[$bId] = [
                    'current_stock' => ($bId == $user->branch_id && $isSimple && isset($productData['current_stock'])) ? $productData['current_stock'] : ($isSimple ? $existingBranches[$bId]->pivot->current_stock : 0),
                    'min_stock' => ($bId == $user->branch_id && $isSimple) ? ($productData['min_stock'] ?? null) : ($isSimple ? $existingBranches[$bId]->pivot->min_stock : null),
                    'max_stock' => ($bId == $user->branch_id && $isSimple) ? ($productData['max_stock'] ?? null) : ($isSimple ? $existingBranches[$bId]->pivot->max_stock : null),
                    'location' => ($bId == $user->branch_id && $isSimple) ? ($productData['location'] ?? null) : ($isSimple ? $existingBranches[$bId]->pivot->location : null),
                ];
            } else {
                $syncData[$bId] = [
                    'current_stock' => 0, 'reserved_stock' => 0, 'min_stock' => null, 'max_stock' => null, 'location' => null,
                ];
            }
        }
        $product->branches()->sync($syncData);
    }

    private function handleProductTypeSpecifics(Product $product, array $productData, array $compositeItems, array $branchesToSync, $user): void
    {
        // Limpieza previa y delegación a modelos
        if ($productData['product_type'] === 'composite') {
            $product->productAttributes()->delete();
            $product->syncComponents($compositeItems); 
        } else {
            $product->components()->delete();
        }

        if ($productData['product_type'] === 'variant' && !empty($productData['variants_matrix'])) {
            $this->syncVariants($product, $productData['variants_matrix'], $branchesToSync, $user);
        } elseif ($productData['product_type'] !== 'variant') {
            $product->productAttributes()->delete();
        }
    }

    private function syncVariants(Product $product, array $variantsMatrix, array $branchesToSync, $user): void
    {
        $existingVariantIds = [];

        foreach ($variantsMatrix as $variantData) {
            if (isset($variantData['id']) && $variantData['id']) {
                $variant = $product->productAttributes()->find($variantData['id']);
                if ($variant) {
                    $variant->update([
                        'attributes' => $variantData['attributes'],
                        'sku_suffix' => $variantData['sku'] ?? null,
                        'selling_price_modifier' => $variantData['selling_price_modifier'] ?? 0,
                    ]);
                    $existingVariantIds[] = $variant->id;

                    $vExistingBranches = $variant->branches->keyBy('id');
                    $vSyncData = [];
                    foreach ($branchesToSync as $bId) {
                        if ($vExistingBranches->has($bId)) {
                            $vSyncData[$bId] = [
                                'current_stock' => ($bId == $user->branch_id && isset($variantData['current_stock'])) ? $variantData['current_stock'] : $vExistingBranches[$bId]->pivot->current_stock,
                                'min_stock' => ($bId == $user->branch_id && array_key_exists('min_stock', $variantData)) ? $variantData['min_stock'] : $vExistingBranches[$bId]->pivot->min_stock,
                                'max_stock' => ($bId == $user->branch_id && array_key_exists('max_stock', $variantData)) ? $variantData['max_stock'] : $vExistingBranches[$bId]->pivot->max_stock,
                                'location' => ($bId == $user->branch_id && array_key_exists('location', $variantData)) ? $variantData['location'] : $vExistingBranches[$bId]->pivot->location,
                            ];
                        } else {
                            $vSyncData[$bId] = ['current_stock' => 0, 'reserved_stock' => 0, 'min_stock' => null, 'max_stock' => null, 'location' => null];
                        }
                    }
                    $variant->branches()->sync($vSyncData);
                }
            } else {
                $newVariant = $product->productAttributes()->create([
                    'attributes' => $variantData['attributes'],
                    'sku_suffix' => $variantData['sku'] ?? null,
                    'selling_price_modifier' => $variantData['selling_price_modifier'] ?? 0,
                ]);
                $existingVariantIds[] = $newVariant->id;

                $vSyncData = [];
                foreach ($branchesToSync as $bId) {
                    $vSyncData[$bId] = [
                        'current_stock' => ($bId == $user->branch_id) ? ($variantData['current_stock'] ?? 0) : 0,
                        'reserved_stock' => 0,
                        'min_stock' => ($bId == $user->branch_id) ? ($variantData['min_stock'] ?? null) : null,
                        'max_stock' => ($bId == $user->branch_id) ? ($variantData['max_stock'] ?? null) : null,
                        'location' => ($bId == $user->branch_id) ? ($variantData['location'] ?? null) : null,
                    ];
                }
                $newVariant->branches()->sync($vSyncData);
            }
        }
        $product->productAttributes()->whereNotIn('id', $existingVariantIds)->delete();
    }

    private function handleMedia(Product $product, array $files, array $deletedMediaIds): void
    {
        if (!empty($deletedMediaIds)) {
            $product->media()->whereIn('id', $deletedMediaIds)->delete();
        }

        if (!empty($files['general_images'])) {
            foreach ($files['general_images'] as $file) {
                $mediaItem = $product->addMedia($file)->toMediaCollection('product-general-images');
                $this->optimizeMediaLocal($mediaItem);
            }
        }

        if (!empty($files['variant_images'])) {
            foreach ($files['variant_images'] as $key => $file) {
                // Eliminar previas de esta variante
                $product->getMedia('product-variant-images')
                    ->filter(fn($media) => $media->getCustomProperty('variant_key') === $key)
                    ->each->delete();

                $mediaItem = $product->addMedia($file)
                    ->withCustomProperties(['variant_key' => $key])
                    ->toMediaCollection('product-variant-images');
                $this->optimizeMediaLocal($mediaItem);
            }
        }
    }
}