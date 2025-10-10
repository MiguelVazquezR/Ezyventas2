<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\GlobalProduct; // Asegúrate de que este es tu nuevo modelo
use Carbon\Carbon; // <-- Importamos Carbon para manejar fechas

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
    protected $description = 'Migra los productos globales de la base de datos antigua a la nueva.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando la migración de productos globales...');

        // Mapeo de type (viejo) a business_type_id (nuevo)
        $businessTypeMap = [
            'Abarrotes / Supermercado' => 4,
            'Papelería' => 8,
            'Ferretería' => 6,
        ];

        $oldProducts = DB::connection('mysql_old')->table('global_products')->get();

        if ($oldProducts->isEmpty()) {
            $this->warn('No se encontraron productos globales en la base de datos antigua.');
            return 0;
        }

        $progressBar = $this->output->createProgressBar($oldProducts->count());
        $progressBar->start();

        foreach ($oldProducts as $oldProduct) {
            // 1. Manejo del SKU: asegurar que sea único y no nulo.
            $sku = !empty($oldProduct->code) ? $oldProduct->code : 'GP-MIG-' . $oldProduct->id;

            // 2. Mapeo de Category ID
            $newCategoryId = null;
            if ($oldProduct->category_id) {
                $oldCategoryName = DB::connection('mysql_old')->table('categories')->where('id', $oldProduct->category_id)->value('name');
                if ($oldCategoryName) {
                    // Buscamos la categoría global (sin subscription_id) por su nombre en la nueva DB
                    $newCategoryId = DB::connection('mysql')->table('categories')
                        ->where('name', $oldCategoryName)
                        ->whereNull('subscription_id')
                        ->value('id');
                }
            }

            // 3. Mapeo de Brand ID
            $newBrandId = null;
            if ($oldProduct->brand_id) {
                $oldBrandName = DB::connection('mysql_old')->table('brands')->where('id', $oldProduct->brand_id)->value('name');
                if ($oldBrandName) {
                    // Buscamos la marca global (sin subscription_id) por su nombre en la nueva DB
                    $newBrandId = DB::connection('mysql')->table('brands')
                        ->where('name', $oldBrandName)
                        ->whereNull('subscription_id')
                        ->value('id');
                }
            }

            // 4. Mapeo de Business Type ID
            $newBusinessTypeId = isset($oldProduct->type) ? ($businessTypeMap[$oldProduct->type] ?? null) : null;

            // --- CORRECCIÓN DE FECHAS ---
            // Verificamos si las fechas son nulas o '0000-00-00...'. Si es así, usamos la fecha actual.
            $createdAt = ($oldProduct->created_at && $oldProduct->created_at !== '0000-00-00 00:00:00')
                ? $oldProduct->created_at
                : now();

            $updatedAt = ($oldProduct->updated_at && $oldProduct->updated_at !== '0000-00-00 00:00:00')
                ? $oldProduct->updated_at
                : now();
            // --- FIN DE LA CORRECCIÓN ---


            // 5. Ensamblaje de datos para el nuevo producto
            $productData = [
                'name' => $oldProduct->name,
                'description' => $oldProduct->description,
                'selling_price' => $oldProduct->public_price,
                'category_id' => $newCategoryId,
                'brand_id' => $newBrandId,
                'business_type_id' => $newBusinessTypeId,
                'measure_unit' => 'pieza', // Asignamos 'pz' como unidad por defecto
                'created_at' => $createdAt, // Usamos la fecha validada
                'updated_at' => $updatedAt,   // Usamos la fecha validada
            ];

            // 6. Usamos updateOrCreate con el SKU para evitar duplicados
            GlobalProduct::updateOrCreate(
                ['sku' => $sku],
                $productData
            );

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info("\n\n¡Migración de productos globales completada exitosamente!");

        return 0;
    }
}

