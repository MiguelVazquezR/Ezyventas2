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
    protected $description = 'Migra los productos y sus imágenes desde la estructura de la base de datos vieja a la nueva.';

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
            // --- MODIFICADO: Limpiar solo la media de productos ---
            DB::table('media')->where('model_type', Product::class)->delete();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $oldProducts = DB::connection($oldDatabaseConnection)->table('products')->get();
            $progressBar = $this->output->createProgressBar($oldProducts->count());
            $progressBar->start();

            foreach ($oldProducts as $oldProduct) {
                if (!isset($storeToBranchMap[$oldProduct->store_id])) {
                    $progressBar->advance();
                    continue;
                }
                $branchId = $storeToBranchMap[$oldProduct->store_id];

                if (!isset($branchToSubscriptionMap[$branchId])) {
                    $progressBar->advance();
                    continue;
                }
                $subscriptionId = $branchToSubscriptionMap[$branchId];

                $newBrandId = null;
                if ($oldProduct->brand_id) {
                    $oldBrand = DB::connection($oldDatabaseConnection)->table('brands')->find($oldProduct->brand_id);
                    if ($oldBrand) {
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
                            ['parent_id' => null]
                        );
                        $newCategoryId = $newCategory->id;
                    }
                }

                $additionalData = json_decode($oldProduct->additional, true);
                $isVariantProduct = !empty($additionalData) && (isset($additionalData['size']) || isset($additionalData['color']));

                $newProductData = [
                    'name' => $oldProduct->name,
                    'description' => $oldProduct->description,
                    'sku' => $oldProduct->code,
                    'branch_id' => $branchId,
                    'category_id' => $newCategoryId,
                    'brand_id' => $newBrandId,
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

                // --- INICIO: Lógica modificada para migrar el registro de la imagen ---
                $oldModelType = 'App\\Models\\Product'; // ¡IMPORTANTE! Ajustar si el namespace era diferente.
                
                $oldMedia = DB::connection($oldDatabaseConnection)
                    ->table('media')
                    ->where('model_type', $oldModelType)
                    ->where('model_id', $oldProduct->id)
                    ->where('collection_name', 'imageCover') // CORREGIDO a mayúscula como se indicó
                    ->first();

                if ($oldMedia) {
                    // Insertar directamente el registro en la nueva tabla de media
                    DB::connection('mysql')->table('media')->insert([
                        'id' => $oldMedia->id, // Usar el ID antiguo para mantener la ruta de la carpeta
                        'model_type' => Product::class,
                        'model_id' => $newProduct->id,
                        'uuid' => $oldMedia->uuid ?? (string) Str::uuid(),
                        'collection_name' => 'product-general-images',
                        'name' => pathinfo($oldMedia->file_name, PATHINFO_FILENAME),
                        'file_name' => $oldMedia->file_name,
                        'mime_type' => $oldMedia->mime_type,
                        'disk' => 'public',
                        'conversions_disk' => $oldMedia->conversions_disk ?? 'public',
                        'size' => $oldMedia->size,
                        'manipulations' => '[]',
                        'custom_properties' => '[]',
                        'generated_conversions' => $oldMedia->generated_conversions ?? '[]',
                        'responsive_images' => '[]',
                        'order_column' => $oldMedia->order_column ?? 1,
                        'created_at' => $oldMedia->created_at,
                        'updated_at' => $oldMedia->updated_at,
                    ]);
                }
                // --- FIN: Lógica modificada ---


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
        
        $this->info("\n¡Migración de productos e imágenes completada exitosamente!");
        return 0;
    }
}