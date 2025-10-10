<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Product;
use App\Models\AttributeDefinition;
use App\Models\AttributeOption;
use App\Models\ProductAttribute;
use App\Models\Branch;
// --- AÑADIDO: Importar modelos de Brand y Category ---
use App\Models\Brand;
use App\Models\Category;

class MigrateProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:products';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migra los productos desde la estructura de la base de datos vieja a la nueva, incluyendo variantes desde el campo JSON "additional".';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando la migración de productos...');

        $oldDatabaseConnection = 'mysql_old';

        try {
            DB::connection($oldDatabaseConnection)->getPdo();
        } catch (\Exception $e) {
            $this->error("No se pudo conectar a la base de datos antigua ('{$oldDatabaseConnection}').");
            $this->error("Por favor, configura la conexión en config/database.php.");
            return 1;
        }
        
        $storeToBranchMap = [
            24 => 2,
            25 => 3,
            30 => 4,
        ];

        $branchToSubscriptionMap = [
            2 => 2,
            3 => 2,
            4 => 3,
        ];

        DB::transaction(function () use ($oldDatabaseConnection, $storeToBranchMap, $branchToSubscriptionMap) {
            $this->line('Limpiando tablas de la nueva estructura...');
            
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            Product::truncate();
            ProductAttribute::truncate();
            // No limpiamos AttributeDefinition/Option para no borrar datos si se corre múltiples veces
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $oldProducts = DB::connection($oldDatabaseConnection)->table('products')->get();
            $progressBar = $this->output->createProgressBar($oldProducts->count());
            $progressBar->start();

            foreach ($oldProducts as $oldProduct) {
                if (!isset($storeToBranchMap[$oldProduct->store_id])) {
                    // $this->warn("\nSaltando producto '{$oldProduct->name}' (ID: {$oldProduct->id}) porque su store_id ({$oldProduct->store_id}) no tiene un branch_id correspondiente.");
                    $progressBar->advance();
                    continue;
                }
                $branchId = $storeToBranchMap[$oldProduct->store_id];

                if (!isset($branchToSubscriptionMap[$branchId])) {
                    // $this->warn("\nSaltando producto '{$oldProduct->name}' (ID: {$oldProduct->id}) porque su branch_id ({$branchId}) no tiene un subscription_id correspondiente.");
                    $progressBar->advance();
                    continue;
                }
                $subscriptionId = $branchToSubscriptionMap[$branchId];

                // --- MODIFICADO: Lógica para encontrar o crear Brand y Category ---
                $newBrandId = null;
                if ($oldProduct->brand_id) {
                    $oldBrand = DB::connection($oldDatabaseConnection)->table('brands')->find($oldProduct->brand_id);
                    if ($oldBrand) {
                        // Asumiendo que las tablas 'brands' y 'categories' tienen 'subscription_id'
                        $newBrand = Brand::firstOrCreate(
                            ['name' => $oldBrand->name, 'subscription_id' => $subscriptionId]
                        );
                        $newBrandId = $newBrand->id;
                    }
                }

                $newCategoryId = null;
                if ($oldProduct->category_id) {
                    $oldCategory = DB::connection($oldDatabaseConnection)->table('categories')->find($oldProduct->category_id);
                    if ($oldCategory) {
                        $newCategory = Category::firstOrCreate(
                            ['name' => $oldCategory->name, 'subscription_id' => $subscriptionId],
                            ['parent_id' => null] // Asumir que son categorías padre si no hay más info
                        );
                        $newCategoryId = $newCategory->id;
                    }
                }
                // --- FIN DE LA MODIFICACIÓN ---

                $additionalData = json_decode($oldProduct->additional, true);
                $isVariantProduct = !empty($additionalData) && (isset($additionalData['size']) || isset($additionalData['color']));

                $newProductData = [
                    'name' => $oldProduct->name,
                    'description' => $oldProduct->description,
                    'sku' => $oldProduct->code,
                    'branch_id' => $branchId,
                    'category_id' => $newCategoryId, // Usar el nuevo ID de categoría
                    'brand_id' => $newBrandId,       // Usar el nuevo ID de marca
                    'selling_price' => $oldProduct->public_price,
                    'cost_price' => $oldProduct->cost,
                    'currency' => ltrim($oldProduct->currency, '$'),
                    'current_stock' => $oldProduct->current_stock ?? 0,
                    'min_stock' => $oldProduct->min_stock,
                    'max_stock' => $oldProduct->max_stock,
                    'measure_unit' => 'pieza',
                    'show_online' => $oldProduct->show_in_online_store,
                    'delivery_days' => $oldProduct->days_for_delivery,
                    'requires_shipping' => true,
                    'created_at' => $oldProduct->created_at,
                    'updated_at' => $oldProduct->updated_at,
                ];

                $baseSlug = Str::slug($oldProduct->name);
                $slug = $baseSlug;
                $counter = 1;
                while (Product::where('branch_id', $branchId)->where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $counter++;
                }
                $newProductData['slug'] = $slug;

                $newProduct = Product::create($newProductData);

                if ($isVariantProduct) {
                    $attributesForVariant = [];
                    $skuSuffixParts = [];

                    foreach ($additionalData as $attributeName => $optionData) {
                        if (!is_array($optionData) || !isset($optionData['name'])) continue;
                        $normalizedAttributeName = Str::ucfirst($attributeName == 'size' ? 'Talla' : $attributeName);

                        $attributeDefinition = AttributeDefinition::firstOrCreate(
                            ['category_id' => $newProduct->category_id, 'name' => $normalizedAttributeName, 'subscription_id' => $subscriptionId]
                        );

                        $attributeOption = AttributeOption::firstOrCreate(
                            ['attribute_definition_id' => $attributeDefinition->id, 'value' => $optionData['name']]
                        );
                        
                        $attributesForVariant[$normalizedAttributeName] = $optionData['name'];
                        $skuSuffixParts[] = Str::slug($optionData['name']);
                    }

                    if (!empty($attributesForVariant)) {
                        $newProduct->productAttributes()->create([
                            'attributes' => $attributesForVariant,
                            'selling_price_modifier' => 0,
                            'current_stock' => $oldProduct->current_stock,
                            'min_stock' => $oldProduct->min_stock,
                            'max_stock' => $oldProduct->max_stock,
                            'sku_suffix' => implode('-', $skuSuffixParts),
                        ]);
                    }
                }

                $progressBar->advance();
            }

            $progressBar->finish();
        });
        
        $this->info("\n¡Migración completada exitosamente!");
        return 0;
    }
}

