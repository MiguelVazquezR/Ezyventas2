<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Brand; // <-- Asegúrate de que este es tu modelo para la tabla 'brands'

class MigrateBrands extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:brands';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migra las marcas desde la base de datos antigua y las asocia con su tipo de negocio a través de una tabla pivote.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando la migración de marcas...');

        // Conectamos a la base de datos antigua y obtenemos las marcas
        $oldBrands = DB::connection('mysql_old')->table('brands')->get();

        if ($oldBrands->isEmpty()) {
            $this->warn('No se encontraron marcas en la tabla antigua. No hay nada que migrar.');
            return 0;
        }

        $progressBar = $this->output->createProgressBar($oldBrands->count());
        $progressBar->start();

        foreach ($oldBrands as $oldBrand) {
            $subscriptionId = null;
            $businessTypeId = null;

            // Lógica de transformación basada en 'business_line_name'
            if ($oldBrand->business_line_name === '24') {
                // Caso especial: '24' se convierte en subscription_id 2
                $subscriptionId = 2;
            } elseif ($oldBrand->business_line_name === 'Abarrotes / Supermercado') {
                // Las marcas de catálogo base se asocian al business_type_id 4
                $businessTypeId = 4;
                $subscriptionId = null;
            }
            // Para cualquier otro valor, ambas columnas serán null como se definió por defecto.
            
            // 1. Creamos o actualizamos la marca en la tabla 'brands'
            $newBrand = Brand::updateOrCreate(
                [
                    'name' => $oldBrand->name,
                    'subscription_id' => $subscriptionId,
                ],
                [
                    'created_at' => $oldBrand->created_at,
                    'updated_at' => $oldBrand->updated_at,
                ]
            );

            // 2. Si se determinó un businessTypeId, lo asociamos en la tabla pivote
            if ($businessTypeId) {
                // Usamos syncWithoutDetaching para crear la relación sin borrar las existentes
                // y evitar duplicados si el script se corre varias veces.
                $newBrand->businessTypes()->syncWithoutDetaching([$businessTypeId]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info("\n\n¡Migración de marcas completada exitosamente!");

        return 0;
    }
}