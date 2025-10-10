<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\GlobalProduct; // Asegúrate de que este es tu nuevo modelo
use Illuminate\Support\Str; // --- AÑADIDO ---

class MigrateGlobalProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:global-products';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migra los productos globales y sus imágenes de la base de datos antigua a la nueva.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando la migración de productos globales...');

        $oldDatabaseConnection = 'mysql_old';

        // Mapeo de type (viejo) a business_type_id (nuevo)
        $businessTypeMap = [
            'Abarrotes / Supermercado' => 4,
            'Papelería' => 8,
            'Ferretería' => 6,
        ];

        $this->line('Limpiando productos globales y su media existente...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        // Borrar media asociada a GlobalProduct para evitar duplicados al re-ejecutar
        DB::table('media')->where('model_type', GlobalProduct::class)->delete();
        GlobalProduct::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $oldProducts = DB::connection($oldDatabaseConnection)->table('global_products')->get();

        if ($oldProducts->isEmpty()) {
            $this->warn('No se encontraron productos globales en la base de datos antigua.');
            return 0;
        }

        $progressBar = $this->output->createProgressBar($oldProducts->count());
        $progressBar->start();

        foreach ($oldProducts as $oldProduct) {
            $sku = !empty($oldProduct->code) ? $oldProduct->code : 'GP-MIG-' . $oldProduct->id;

            $newCategoryId = null;
            if ($oldProduct->category_id) {
                $oldCategoryName = DB::connection('mysql_old')->table('categories')->where('id', $oldProduct->category_id)->value('name');
                if ($oldCategoryName) {
                    $newCategoryId = DB::connection('mysql')->table('categories')
                        ->where('name', $oldCategoryName)
                        ->whereNull('subscription_id')
                        ->value('id');
                }
            }

            $newBrandId = null;
            if ($oldProduct->brand_id) {
                $oldBrandName = DB::connection('mysql_old')->table('brands')->where('id', $oldProduct->brand_id)->value('name');
                if ($oldBrandName) {
                    $newBrandId = DB::connection('mysql')->table('brands')
                        ->where('name', $oldBrandName)
                        ->whereNull('subscription_id')
                        ->value('id');
                }
            }

            $newBusinessTypeId = isset($oldProduct->type) ? ($businessTypeMap[$oldProduct->type] ?? null) : null;

            $createdAt = ($oldProduct->created_at && $oldProduct->created_at !== '0000-00-00 00:00:00') ? $oldProduct->created_at : now();
            $updatedAt = ($oldProduct->updated_at && $oldProduct->updated_at !== '0000-00-00 00:00:00') ? $oldProduct->updated_at : now();

            $productData = [
                'name' => $oldProduct->name,
                'description' => $oldProduct->description,
                'selling_price' => $oldProduct->public_price,
                'category_id' => $newCategoryId,
                'brand_id' => $newBrandId,
                'business_type_id' => $newBusinessTypeId,
                'measure_unit' => 'pieza',
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ];

            $newGlobalProduct = GlobalProduct::updateOrCreate(
                ['sku' => $sku],
                $productData
            );

            // --- INICIO: Lógica modificada para migrar el registro de la imagen ---
            $oldModelType = 'App\\Models\\GlobalProduct'; // ¡IMPORTANTE! Ajustar si el namespace era diferente en la versión vieja.
            
            $oldMedia = DB::connection($oldDatabaseConnection)
                ->table('media')
                ->where('model_type', $oldModelType)
                ->where('model_id', $oldProduct->id)
                ->where('collection_name', 'imageCover')
                ->first();

            if ($oldMedia) {
                // Insertar directamente el registro en la nueva tabla de media
                DB::connection('mysql')->table('media')->insert([
                    'id' => $oldMedia->id, // Usar el ID antiguo para mantener la ruta de la carpeta
                    'model_type' => GlobalProduct::class,
                    'model_id' => $newGlobalProduct->id,
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

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info("\n\n¡Migración de productos globales completada exitosamente!");

        return 0;
    }
}

