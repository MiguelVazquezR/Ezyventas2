<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Traits\OptimizeMediaLocal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateProduct
{
    use OptimizeMediaLocal;

    /**
     * Ejecuta la lógica para crear un producto y sus relaciones.
     */
    public function execute(array $productData, array $compositeItems, array $branchesToSync, $user, array $files = []): Product
    {
        return DB::transaction(function () use ($productData, $compositeItems, $branchesToSync, $user, $files) {
            
            // Preparar y crear el producto base
            $productData['branch_id'] = $user->branch_id;
            $productData['slug'] = Str::slug($productData['name'] . '-' . uniqid());
            
            // Asignamos la bandera de venta a granel
            $productData['is_bulk'] = ($productData['product_type'] ?? '') === 'bulk';

            $product = Product::create(collect($productData)->except([
                'product_type', 'current_stock', 'min_stock', 'max_stock', 'location', 'variants_matrix'
            ])->toArray());

            // 1. Sincronizar Sucursales
            $this->syncBranches($product, $productData, $branchesToSync, $user);

            // 2. Manejar tipos específicos (Compuesto/Kits o Variantes)
            $this->handleProductTypeSpecifics($product, $productData, $compositeItems, $branchesToSync, $user);

            // 3. Subir e intentar optimizar Imágenes
            $this->handleMedia($product, $files);

            return $product;
        });
    }

    private function syncBranches(Product $product, array $productData, array $branchesToSync, $user): void
    {
        // Un producto simple o a granel (bulk) se guarda en la pivot con su inventario principal
        $isSimpleOrBulk = in_array($productData['product_type'] ?? '', ['simple', 'bulk']);
        $syncData = [];

        foreach ($branchesToSync as $bId) {
            $syncData[$bId] = [
                'current_stock' => ($bId == $user->branch_id && $isSimpleOrBulk) ? ($productData['current_stock'] ?? 0) : 0,
                'reserved_stock' => 0,
                'min_stock' => ($bId == $user->branch_id && $isSimpleOrBulk) ? ($productData['min_stock'] ?? null) : null,
                'max_stock' => ($bId == $user->branch_id && $isSimpleOrBulk) ? ($productData['max_stock'] ?? null) : null,
                'location' => ($bId == $user->branch_id && $isSimpleOrBulk) ? ($productData['location'] ?? null) : null,
            ];
        }
        $product->branches()->sync($syncData);
    }

    private function handleProductTypeSpecifics(Product $product, array $productData, array $compositeItems, array $branchesToSync, $user): void
    {
        if ($productData['product_type'] === 'composite' && !empty($compositeItems)) {
            $product->syncComponents($compositeItems); // Usando el método que creamos en el Modelo
        } 
        elseif ($productData['product_type'] === 'variant' && !empty($productData['variants_matrix'])) {
            foreach ($productData['variants_matrix'] as $variantData) {
                $variant = $product->productAttributes()->create([
                    'attributes' => $variantData['attributes'],
                    'sku_suffix' => $variantData['sku'] ?? null,
                    'selling_price_modifier' => $variantData['selling_price_modifier'] ?? 0,
                ]);

                $variantSyncData = [];
                foreach ($branchesToSync as $bId) {
                    $variantSyncData[$bId] = [
                        'current_stock' => ($bId == $user->branch_id) ? ($variantData['current_stock'] ?? 0) : 0,
                        'reserved_stock' => 0,
                        'min_stock' => ($bId == $user->branch_id) ? ($variantData['min_stock'] ?? null) : null,
                        'max_stock' => ($bId == $user->branch_id) ? ($variantData['max_stock'] ?? null) : null,
                        'location' => ($bId == $user->branch_id) ? ($variantData['location'] ?? null) : null,
                    ];
                }
                $variant->branches()->sync($variantSyncData);
            }
        }
    }

    private function handleMedia(Product $product, array $files): void
    {
        if (!empty($files['general_images'])) {
            foreach ($files['general_images'] as $file) {
                $mediaItem = $product->addMedia($file)->toMediaCollection('product-general-images');
                $this->optimizeMediaLocal($mediaItem);
            }
        }

        if (!empty($files['variant_images'])) {
            foreach ($files['variant_images'] as $key => $file) {
                $mediaItem = $product->addMedia($file)
                    ->withCustomProperties(['variant_key' => $key])
                    ->toMediaCollection('product-variant-images');
                $this->optimizeMediaLocal($mediaItem);
            }
        }
    }
}